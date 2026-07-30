<?php
/**
 * Push OTP to an external endpoint (SMS gateway, ZNS, n8n, Zapier…).
 *
 * @package SmartLogin
 */

namespace SmartLogin\OTP\Transports;

use SmartLogin\OTP\Placeholders;
use SmartLogin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class WebhookTransport implements TransportInterface {

	public function id(): string {
		return 'sms';
	}

	public function is_available(): bool {
		return Settings::is_on( 'webhook_enabled' ) && '' !== trim( (string) Settings::get( 'webhook_url', '' ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public function send( string $destination, string $code, array $ctx ) {
		$result = $this->dispatch( $destination, $code, $ctx );

		if ( $result['ok'] ) {
			return true;
		}

		return new WP_Error(
			'smart_login_webhook_failed',
			__( 'Không gửi được mã xác thực. Vui lòng thử lại sau ít phút.', 'smart-login' ),
			array(
				'detail' => $result['error'],
				'status' => $result['status'],
			)
		);
	}

	/**
	 * Perform the HTTP call and return everything needed for debugging.
	 *
	 * @return array{ok:bool,error:string,status:int,request:array,response:string,duration_ms:int}
	 */
	public function dispatch( string $destination, string $code, array $ctx ): array {
		if ( ! $this->is_available() ) {
			return $this->failure( __( 'Webhook chưa được bật hoặc chưa có URL.', 'smart-login' ) );
		}

		$delivery_id        = bin2hex( random_bytes( 16 ) );
		$ctx['delivery_id'] = $delivery_id;
		$map                = Placeholders::build( $destination, $code, $ctx );
		$method             = strtoupper( (string) Settings::get( 'webhook_method', 'POST' ) );
		$content_type       = (string) Settings::get( 'webhook_content_type', 'application/json' );
		$timeout            = Settings::get_int( 'webhook_timeout', 10 );
		$is_json            = ( 'application/json' === $content_type );

		// The URL itself may contain placeholders (common for GET-based gateways).
		$url = Placeholders::render( (string) Settings::get( 'webhook_url', '' ), $map, 'rawurlencode' );

		$body_template = (string) Settings::get( 'webhook_body', '' );

		// GET always builds a query string, so JSON escaping never applies there.
		$escaper = ( $is_json && 'GET' !== $method )
			? array( Placeholders::class, 'json_escape' )
			: 'rawurlencode';

		$body = Placeholders::render( $body_template, $map, $escaper );

		$headers = array();
		foreach ( (array) Settings::get( 'webhook_headers', array() ) as $row ) {
			if ( empty( $row['key'] ) ) {
				continue;
			}
			$headers[ $row['key'] ] = Placeholders::render( (string) ( $row['value'] ?? '' ), $map );
		}

		$idempotency_header = trim( (string) Settings::get( 'webhook_idempotency_header', '' ) );

		if ( '' !== $idempotency_header ) {
			$headers[ $idempotency_header ] = $delivery_id;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 2,
			'headers'     => $headers,
			'user-agent'  => 'SmartLogin/' . SMART_LOGIN_VERSION . '; ' . home_url( '/' ),
		);

		if ( 'GET' === $method ) {
			// Treat the rendered body as a query string.
			$query = ltrim( trim( $body ), '?&' );
			if ( '' !== $query ) {
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
			}
		} else {
			$args['headers']['Content-Type'] = $content_type;
			$args['body']                    = $body;
		}

		if ( $is_json && 'GET' !== $method && null === json_decode( $body ) && '' !== trim( $body ) ) {
			return $this->failure(
				__( 'Body template không tạo ra JSON hợp lệ. Kiểm tra lại dấu ngoặc kép và dấu phẩy.', 'smart-login' ),
				0,
				$this->redact_request( $url, $args, $code )
			);
		}

		/**
		 * Final chance to alter the outgoing request.
		 *
		 * @param array  $args
		 * @param string $url
		 * @param array  $ctx
		 */
		$args = (array) apply_filters( 'smart_login_webhook_args', $args, $url, $ctx );

		$has_idempotency = '' !== $idempotency_header
			&& ! empty( $args['headers'][ $idempotency_header ] );
		$attempts        = Settings::is_on( 'webhook_retry' ) && $has_idempotency ? 2 : 1;
		$started         = microtime( true );
		$response        = null;

		for ( $i = 0; $i < $attempts; $i++ ) {
			if ( $i > 0 ) {
				usleep( 2000000 ); // 2s backoff before the single retry.
			}

			$response = wp_remote_request( $url, $args );

			if ( ! is_wp_error( $response ) && $this->is_success( $response ) ) {
				break;
			}
		}

		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
		$request  = $this->redact_request( $url, $args, $code );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'          => false,
				'error'       => $response->get_error_message(),
				'status'      => 0,
				'request'     => $request,
				'response'    => '',
				'duration_ms' => $duration,
			);
		}

		$status    = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = (string) wp_remote_retrieve_body( $response );
		$succeeded = $this->is_success( $response );

		return array(
			'ok'          => $succeeded,
			'error'       => $succeeded ? '' : $this->explain_failure( $status, $raw_body ),
			'status'      => $status,
			'request'     => $request,
			'response'    => $this->redact( substr( $raw_body, 0, 4000 ), $code ),
			'duration_ms' => $duration,
		);
	}

	/**
	 * A 2xx is necessary but often not sufficient — several Vietnamese gateways
	 * answer 200 with an error code in the JSON body.
	 */
	private function is_success( $response ): bool {
		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return false;
		}

		$path = trim( (string) Settings::get( 'webhook_success_path', '' ) );

		if ( '' === $path ) {
			return true;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return false;
		}

		$actual   = $this->dig( $decoded, $path );
		$expected = (string) Settings::get( 'webhook_success_value', '' );

		return null !== $actual && (string) $actual === $expected;
	}

	/**
	 * Read a dotted path out of a decoded JSON structure.
	 *
	 * @return mixed|null
	 */
	private function dig( array $data, string $path ) {
		$cursor = $data;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return null;
			}
			$cursor = $cursor[ $segment ];
		}

		return is_scalar( $cursor ) ? $cursor : null;
	}

	private function explain_failure( int $status, string $body ): string {
		if ( $status < 200 || $status >= 300 ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Gateway trả về HTTP %d.', 'smart-login' ), $status );
		}

		$path = trim( (string) Settings::get( 'webhook_success_path', '' ) );

		return sprintf(
			/* translators: 1: JSON path, 2: expected value. */
			__( 'HTTP 2xx nhưng điều kiện thành công không khớp (cần "%1$s" = "%2$s").', 'smart-login' ),
			$path,
			(string) Settings::get( 'webhook_success_value', '' )
		);
	}

	/**
	 * @return array{ok:bool,error:string,status:int,request:array,response:string,duration_ms:int}
	 */
	private function failure( string $message, int $status = 0, array $request = array() ): array {
		return array(
			'ok'          => false,
			'error'       => $message,
			'status'      => $status,
			'request'     => $request,
			'response'    => '',
			'duration_ms' => 0,
		);
	}

	/**
	 * Build a shareable copy of the request with secrets and the code masked.
	 */
	private function redact_request( string $url, array $args, string $code ): array {
		$headers = array();

		foreach ( (array) ( $args['headers'] ?? array() ) as $key => $value ) {
			$headers[ $key ] = $this->is_secret_header( (string) $key )
				? '***'
				: $this->redact( (string) $value, $code );
		}

		return array(
			'method'  => $args['method'] ?? 'POST',
			'url'     => $this->redact( $url, $code ),
			'headers' => $headers,
			'body'    => $this->redact( (string) ( $args['body'] ?? '' ), $code ),
		);
	}

	private function is_secret_header( string $name ): bool {
		$name = strtolower( $name );

		foreach ( array( 'authorization', 'api-key', 'apikey', 'token', 'secret', 'password' ) as $needle ) {
			if ( false !== strpos( $name, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Never echo a live OTP back into an admin screen or a log file.
	 */
	private function redact( string $text, string $code ): string {
		if ( '' === $code ) {
			return $text;
		}

		return str_replace( $code, str_repeat( '*', strlen( $code ) ), $text );
	}
}
