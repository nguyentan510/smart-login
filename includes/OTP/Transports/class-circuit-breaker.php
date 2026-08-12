<?php
/**
 * Stop calling a transport that has stopped answering.
 *
 * This is not the site budget. The budget caps what the site *spends*; this caps
 * what a failing gateway *costs in workers*. A site can be spending nothing and
 * still be falling over because the gateway is slow rather than expensive, and it
 * can be perfectly healthy while being drained. Both instruments are needed and
 * neither substitutes for the other — see docs/abuse-boundary.md.
 *
 * @package OmniWP
 */

namespace OmniWP\OTP\Transports;

use OmniWP\Mail\Mailer;
use OmniWP\Security\AuditLog;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

class CircuitBreaker {

	/** @var string Transport id this breaker guards. */
	private $transport;

	public function __construct( string $transport ) {
		$this->transport = $transport;
	}

	/**
	 * State lives in a transient, deliberately.
	 *
	 * An object-cache flush resets the breaker, which fails *safe* here: it
	 * reopens the circuit and retries rather than staying stuck shut. That is the
	 * opposite of the kill switch in RateLimiter, where eviction would fail open
	 * on a spend limit — same storage question, opposite right answer, because the
	 * failure modes point in opposite directions.
	 */
	private function key(): string {
		return 'OMNIWP_breaker_' . $this->transport;
	}

	private function threshold(): int {
		return Settings::get_int( 'security.breaker_threshold', 5 );
	}

	private function cooldown(): int {
		return max( 30, Settings::get_int( 'security.breaker_cooldown', 300 ) );
	}

	/**
	 * @return array{failures:int,open_until:int}
	 */
	private function read(): array {
		$data = get_transient( $this->key() );
		$data = is_array( $data ) ? $data : array();

		return array(
			'failures'   => (int) ( $data['failures'] ?? 0 ),
			'open_until' => (int) ( $data['open_until'] ?? 0 ),
		);
	}

	private function write( array $state ): void {
		// Outlives the cooldown so the one-strike step-down below survives, and
		// still expires on its own if nothing ever touches this transport again.
		set_transient( $this->key(), $state, max( HOUR_IN_SECONDS, $this->cooldown() * 2 ) );
	}

	/**
	 * Is the circuit currently refusing calls?
	 *
	 * When the cooldown has elapsed this closes the circuit but leaves the
	 * failure count one below the threshold. The next send is therefore the
	 * half-open probe: if it fails, a single failure puts the breaker straight
	 * back without waiting for another five. Encoding "probe" as a count rather
	 * than a flag avoids a race two concurrent requests would otherwise lose.
	 */
	public function is_open(): bool {
		if ( $this->threshold() <= 0 ) {
			return false;
		}

		$state = $this->read();

		if ( $state['open_until'] <= 0 ) {
			return false;
		}

		if ( $state['open_until'] > time() ) {
			return true;
		}

		$this->write(
			array(
				'failures'   => max( 0, $this->threshold() - 1 ),
				'open_until' => 0,
			)
		);

		return false;
	}

	public function record_failure(): void {
		$threshold = $this->threshold();

		if ( $threshold <= 0 ) {
			return;
		}

		$state = $this->read();
		++$state['failures'];

		if ( $state['failures'] >= $threshold ) {
			$state['open_until'] = time() + $this->cooldown();
			$state['failures']   = 0;

			$this->announce();
		}

		$this->write( $state );
	}

	/**
	 * One good send closes the circuit and forgets the history.
	 */
	public function record_success(): void {
		delete_transient( $this->key() );
	}

	/**
	 * Seconds until the circuit reopens, 0 when it is closed.
	 */
	public function opens_in(): int {
		$state = $this->read();

		return max( 0, $state['open_until'] - time() );
	}

	/**
	 * Record and report the opening — once, on the transition.
	 *
	 * Called from inside record_failure() at the moment the count crosses, so a
	 * burst of concurrent failures produces one mail rather than one each. Same
	 * discipline as RateLimiter::halt().
	 */
	private function announce(): void {
		AuditLog::record(
			AuditLog::TRANSPORT_BREAKER_OPEN,
			'',
			array(
				'transport' => $this->transport,
				'cooldown'  => $this->cooldown(),
			)
		);

		// The audit record above is the log; this is the notification. Turning
		// the mail off leaves the record — and the bus event — untouched.
		Mailer::send(
			'breaker_open',
			Mailer::admin_address(),
			array(
				'transport' => $this->transport,
				'cooldown'  => (string) $this->cooldown(),
			)
		);
	}
}
