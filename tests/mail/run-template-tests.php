<?php
/**
 * Mail template guard rails.
 *
 * Normative spec: docs/mail-templates.md.
 * Brief: docs/mail-templates/11.0-guard-rails.md.
 *
 * This suite lands **red on purpose**. Two of its rules describe defects in the
 * tree today; three describe a model that does not exist yet and report PENDING
 * rather than passing for want of a subject; one pins behaviour that already
 * holds, before 11.2 gives it something to break.
 *
 * A rule written after its fix cannot fail, and a rule that has never failed is
 * a comment. Registered `spec` in run-all.php; promoted to `required` the moment
 * it goes green, for the reason Phase 5 promoted the identity suites.
 *
 * Run with:  php tests/mail/run-template-tests.php
 *
 * @package OmniWP
 */

require __DIR__ . '/../stubs.php';
// The HTML layout goes through TemplateLoader, which asks the theme first —
// so this suite needs the same locate_template() the template suite uses.
require __DIR__ . '/../template-stubs.php';
// 13.0 renders the mail screen to assert the message list against the registry
// that generates it. admin_url() and friends live here.
require __DIR__ . '/../admin-stubs.php';
require __DIR__ . '/../harness.php';

use OmniWP\Auth\AuthAction;
use OmniWP\FieldRegistry;
use OmniWP\OTP\Transports\MailTransport;
use OmniWP\Settings;

const ow_MAIL_REGISTRY = 'OmniWP\\Mail\\MailRegistry';

// =====================================================================
ow_section( 'Rule 1 — every message the plugin sends comes from the registry (11.3)' );

/*
 * Two callers compose their own subject and body inline, so an administrator
 * cannot reword, redirect or silence either — and both are the messages most
 * likely to arrive during an incident. 11.3 moves them behind Mailer, which
 * becomes the second and last entry on this list.
 */
/**
 * Find real calls to a function, not the times its name appears in a string.
 *
 * A regex over the source flags `<code>wp_mail()</code>` in help text and a
 * translator string explaining why wp_mail() returned false — both legitimate,
 * and allowlisting their files would silently cover a real call added there
 * later. 10.3 hit the same thing and fixed it by rewording a docblock; that
 * worked because the docblock lost nothing, and it would not work here, because
 * naming the function is the whole point of those two strings.
 *
 * Tokenising costs a few milliseconds and answers the question actually being
 * asked.
 *
 * @return string[] `relative/path.php:line` for each call site.
 */
function ow_find_calls( string $function, array $allowed_files = array() ): array {
	$offenders = array();

	foreach ( ow_plugin_sources() as $relative => $contents ) {
		if ( in_array( $relative, $allowed_files, true ) ) {
			continue;
		}

		$tokens = token_get_all( $contents );
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || $function !== $tokens[ $i ][1] ) {
				continue;
			}

			// A method call — `$this->wp_mail(` — is not this function.
			$previous = $tokens[ $i - 1 ] ?? null;

			if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}

			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}

				if ( '(' === $tokens[ $j ] ) {
					$offenders[] = $relative . ':' . $tokens[ $i ][2];
				}

				break;
			}
		}
	}

	return $offenders;
}

$ow_mail_callers = ow_find_calls(
	'wp_mail',
	array(
		// Delivers a code. Its subject and body come from the registry.
		'includes/OTP/Transports/class-mail-transport.php',
		// Sends everything that is not a code — the operational alerts. Also
		// from the registry, and 11.3 moved the two inline callers behind it.
		// Two senders, and the point of this rule is that there is never a third.
		'includes/Mail/class-mailer.php',
	)
);

ow_assert(
	'no file composes its own mail outside the mail layer',
	array() === $ow_mail_callers,
	'A message written inline is a message no screen can reach. 11.3 adds Mailer for operational alerts; until then these are the defect: ' . implode( ', ', $ow_mail_callers )
);

// =====================================================================
ow_section( 'Rule 2 — every intent resolves to a template (11.1)' );

$ow_has_registry = class_exists( ow_MAIL_REGISTRY );

ow_assert(
	'a mail registry exists',
	$ow_has_registry,
	'One array must declare every message: its id, group, when it fires, its defaults and the tokens it may use. Four hand-written field pairs is the four-way drift FieldRegistry was written to remove.'
);

$ow_intents = array(
	AuthAction::REGISTER,
	AuthAction::LOGIN,
	AuthAction::RECOVER,
	AuthAction::ADD_IDENTITY,
);

if ( ! $ow_has_registry ) {
	foreach ( $ow_intents as $ow_intent ) {
		ow_pending(
			sprintf( 'intent "%s" resolves to a subject and a body', $ow_intent ),
			'MailRegistry — 11.1'
		);
	}
} else {
	foreach ( $ow_intents as $ow_intent ) {
		$ow_resolved = call_user_func( array( ow_MAIL_REGISTRY, 'resolve_intent' ), $ow_intent );

		ow_assert(
			sprintf( 'intent "%s" resolves to a subject and a body', $ow_intent ),
			is_array( $ow_resolved )
				&& '' !== trim( (string) ( $ow_resolved['subject'] ?? '' ) )
				&& '' !== trim( (string) ( $ow_resolved['body'] ?? '' ) ),
			'Falling back to the shared pair is a resolution; being absent is not. This guards the fallback against being dropped once four overrides exist.'
		);
	}
}

// =====================================================================
ow_section( 'Rule 3 — a template uses only the tokens its row declares (11.1)' );

/*
 * The brief said this rule would "pass vacuously and say so". That is the
 * mistake 10.0 made with its own rule 5 and had to correct: a rule that passes
 * because its subject does not exist states the opposite of the truth. Pending.
 */
if ( ! $ow_has_registry ) {
	ow_pending(
		'no shipped default uses a token its message does not declare',
		'MailRegistry — 11.1'
	);
} else {
	$ow_undeclared = array();

	foreach ( call_user_func( array( ow_MAIL_REGISTRY, 'all' ) ) as $ow_id => $ow_row ) {
		$ow_allowed = (array) ( $ow_row['tokens'] ?? array() );

		// The preheader is rendered through the same expander, so a token it
		// declares nothing about fails the same silent way.
		foreach ( array( 'subject', 'body', 'preheader' ) as $ow_part ) {
			if ( ! preg_match_all( '/\{\{([a-z_:]+)\}\}/', (string) ( $ow_row[ $ow_part ] ?? '' ), $ow_found ) ) {
				continue;
			}

			foreach ( $ow_found[1] as $ow_token ) {
				if ( ! in_array( $ow_token, $ow_allowed, true ) ) {
					$ow_undeclared[] = $ow_id . '.' . $ow_part . ' → {{' . $ow_token . '}}';
				}
			}
		}
	}

	ow_assert(
		'no shipped default uses a token its message does not declare',
		array() === $ow_undeclared,
		'A token outside the message\'s set renders as a silent empty string, which is the failure this phase exists to prevent: ' . implode( ', ', $ow_undeclared )
	);
}

// =====================================================================
ow_section( 'Rule 4 — every declared message is reachable from a screen (11.4)' );

if ( ! $ow_has_registry ) {
	ow_pending(
		'every generated template field is declared and drawn',
		'MailRegistry::fields() — 11.1'
	);
} else {
	$ow_declared = FieldRegistry::all();
	$ow_orphans  = array();

	foreach ( call_user_func( array( ow_MAIL_REGISTRY, 'fields' ) ) as $ow_path => $ow_field ) {
		if ( ! isset( $ow_declared[ $ow_path ] ) ) {
			$ow_orphans[] = $ow_path;
		}
	}

	ow_assert(
		'every generated template field is declared and drawn',
		array() === $ow_orphans,
		'A generated field missing from the registry is stored by nothing and dropped on the next read: ' . implode( ', ', $ow_orphans )
	);
}

// =====================================================================
ow_section( 'Rule 5 — the layout wraps exactly once (11.2)' );

Settings::update(
	array(
		'email.enabled'      => 1,
		'email.is_html'      => 1,
		'email.accent_color' => '#123456',
		'email.footer_text'  => 'Cửa hàng ví dụ, 12 Nguyễn Trãi',
		'email.subject'      => '',
		'email.body'         => '',
	)
);

$GLOBALS['ow_mails'] = array();

( new MailTransport() )->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'recover' ) );

$ow_html = $GLOBALS['ow_mails'][0]['message'] ?? '';

ow_check(
	'an HTML message is wrapped exactly once',
	1,
	substr_count( $ow_html, OmniWP\Mail\MailLayout::MARKER )
);

ow_assert(
	'the configured accent and footer reach the message',
	false !== strpos( $ow_html, '#123456' ) && false !== strpos( $ow_html, 'Cửa hàng ví dụ' ),
	'The three settings exist to be visible; if they are not in the output they are decoration.'
);

ow_assert(
	'the body survived inside the layout',
	false !== strpos( $ow_html, '482913' ),
	'A layout that loses the code is worse than no layout.'
);

// The shipped bodies are written as plain text, so their blank lines are the
// only structure they have. Handing them to a layout unconverted is what made
// "turn HTML on" produce one run-on paragraph.
ow_assert(
	'plain-text line breaks became paragraphs',
	substr_count( $ow_html, '<p style=' ) > 1,
	'The default bodies have blank lines between blocks; without conversion the whole message renders as one paragraph.'
);

// An administrator pasting a complete document must not get it nested inside
// another one.
$GLOBALS['ow_mails'] = array();

Settings::update(
	array( 'email.templates.recover.body' => "<html><body><p>Mã: {{code}}</p></body></html>" )
);

( new MailTransport() )->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'recover' ) );

$ow_pasted = $GLOBALS['ow_mails'][0]['message'] ?? '';

ow_check(
	'a body that is already a document is not wrapped again',
	0,
	substr_count( $ow_pasted, OmniWP\Mail\MailLayout::MARKER )
);

ow_check( 'and it is sent as written', 1, substr_count( $ow_pasted, '<html>' ) );

// A colour that would escape the style attribute must not reach it.
Settings::update(
	array(
		'email.templates.recover.body' => '',
		'email.accent_color'           => 'red;background:url(http://evil.test/x)',
	)
);

ow_check(
	'an invalid accent falls back rather than reaching the style attribute',
	'#2271b1',
	OmniWP\Mail\MailLayout::accent()
);

Settings::update( array( 'email.accent_color' => '#2271b1' ) );

// =====================================================================
ow_section( 'Rule 6 — plain text never carries markup (11.2)' );

/*
 * Assertable today, and the brief was wrong to predict PENDING for it:
 * MailTransport already strips tags when is_html is off, so this pins the
 * behaviour *before* the layout gives it something to strip. A rule that only
 * arrives with the feature it guards cannot catch the feature breaking it.
 */
Settings::update(
	array(
		'email.enabled' => 1,
		'email.is_html' => 0,
		'email.subject' => 'Mã {{code}}',
		'email.body'    => "<p>Xin chào <strong>bạn</strong></p>\nMã: {{code}}",
	)
);

$GLOBALS['ow_mails'] = array();

( new MailTransport() )->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'login' ) );

$ow_sent = $GLOBALS['ow_mails'][0]['message'] ?? '';

ow_check(
	'a text message carries no markup',
	true,
	'' !== $ow_sent && $ow_sent === wp_strip_all_tags( $ow_sent )
);

ow_check(
	'and the code still reached it',
	true,
	false !== strpos( $ow_sent, '482913' )
);

// =====================================================================
ow_section( 'Resolution — three levels, and each one reachable (11.1)' );

if ( ! $ow_has_registry ) {
	ow_pending( 'a reset code is worded differently from a login code', 'MailRegistry — 11.1' );
} else {
	// Nothing customised anywhere: each message uses its own default.
	delete_option( Settings::OPTION );
	Settings::flush_cache();

	$ow_login   = call_user_func( array( ow_MAIL_REGISTRY, 'resolve' ), 'login' );
	$ow_recover = call_user_func( array( ow_MAIL_REGISTRY, 'resolve' ), 'recover' );

	ow_assert(
		'a reset code is worded differently from a login code',
		$ow_login['subject'] !== $ow_recover['subject'] && $ow_login['body'] !== $ow_recover['body'],
		'This is the defect the phase exists for: PasswordResetHandler goes through issue() with intent recover and arrived reading "Mã xác thực của bạn là…", identical to a login code.'
	);

	ow_assert(
		'the reset wording actually mentions the password',
		false !== strpos( $ow_recover['subject'] . $ow_recover['body'], 'mật khẩu' ),
		'Different is not enough; it has to say what it is.'
	);

	// A site that edited the shared pair keeps that wording everywhere, which is
	// the no-migration property. Asserted rather than assumed.
	Settings::update( array( 'email.subject' => 'Mã của {{site_name}}: {{code}}' ) );

	ow_check(
		'an edited shared subject still governs every message',
		'Mã của {{site_name}}: {{code}}',
		call_user_func( array( ow_MAIL_REGISTRY, 'resolve' ), 'recover' )['subject']
	);

	// And an override beats both.
	Settings::update( array( 'email.templates.recover.subject' => 'Đặt lại mật khẩu: {{code}}' ) );

	ow_check(
		'a per-message override beats the shared pair',
		'Đặt lại mật khẩu: {{code}}',
		call_user_func( array( ow_MAIL_REGISTRY, 'resolve' ), 'recover' )['subject']
	);

	ow_check(
		'and its siblings are unaffected',
		'Mã của {{site_name}}: {{code}}',
		call_user_func( array( ow_MAIL_REGISTRY, 'resolve' ), 'login' )['subject']
	);

	// Clearing the override restores inheritance rather than emptying the mail.
	Settings::update( array( 'email.templates.recover.subject' => '' ) );

	ow_check(
		'clearing an override restores inheritance',
		'Mã của {{site_name}}: {{code}}',
		call_user_func( array( ow_MAIL_REGISTRY, 'resolve' ), 'recover' )['subject']
	);

	// The tester sends intent `test`, which has no row. It must still render.
	delete_option( Settings::OPTION );
	Settings::flush_cache();

	$ow_test = call_user_func( array( ow_MAIL_REGISTRY, 'resolve_intent' ), 'test' );

	ow_assert(
		'an intent with no row still resolves, so the admin tester works',
		'' !== trim( $ow_test['subject'] ) && '' !== trim( $ow_test['body'] ),
		'The Gửi thử button sends intent "test". A tester that cannot render is a tester nobody can check a gateway with.'
	);
}

// =====================================================================
ow_section( 'Token scoping (11.1)' );

if ( $ow_has_registry ) {
	$ow_scoped = \OmniWP\OTP\Placeholders::available_tokens( 'recover' );
	$ow_global = \OmniWP\OTP\Placeholders::available_tokens();

	ow_assert(
		'a message shows only the tokens it declares',
		count( $ow_scoped ) > 0 && count( $ow_scoped ) <= count( $ow_global ),
		'Showing every token beside every template is how {{ip}} ends up in an OTP mail and renders as nothing.'
	);

	ow_check(
		'and the unscoped list is unchanged for the SMS section',
		true,
		isset( $ow_global['{{code}}'] ) && isset( $ow_global['{{phone_local}}'] )
	);
}

// =====================================================================
ow_section( 'The operational alerts still fire, and can now be silenced (11.3)' );

delete_option( Settings::OPTION );
Settings::flush_cache();
update_option( 'admin_email', 'quantri@example.com' );

$GLOBALS['ow_mails'] = array();

// The breaker opens on the threshold-th consecutive failure and announces once.
$ow_breaker = new OmniWP\OTP\Transports\CircuitBreaker( 'sms' );

for ( $ow_i = 0; $ow_i < Settings::get_int( 'security.breaker_threshold', 5 ); $ow_i++ ) {
	$ow_breaker->record_failure();
}

ow_check( 'opening the breaker sends exactly one mail', 1, count( $GLOBALS['ow_mails'] ) );

$ow_alert = $GLOBALS['ow_mails'][0] ?? array();

ow_check( 'and it goes to the site admin', 'quantri@example.com', $ow_alert['to'] ?? '' );

ow_assert(
	'the wording survived the move to the registry',
	false !== strpos( (string) ( $ow_alert['subject'] ?? '' ), 'Kênh gửi mã đang lỗi liên tục' )
		&& false !== strpos( (string) ( $ow_alert['message'] ?? '' ), 'ngắt mạch chặn' ),
	'Moving a message behind a registry must not reword it: ' . ( $ow_alert['subject'] ?? '' )
);

ow_assert(
	'its tokens expanded rather than printing as braces',
	false === strpos( (string) ( $ow_alert['subject'] ?? '' ) . ( $ow_alert['message'] ?? '' ), '{{' ),
	'An unexpanded token is the silent-empty-string failure with the braces left in: ' . ( $ow_alert['message'] ?? '' )
);

// Off. This is the part that did not exist: both events already reach an
// automation endpoint through the 10.4 bus, so a configured site was receiving
// each alert twice and could silence neither.
Settings::update( array( 'email.templates.breaker_open.enabled' => 0 ) );

$GLOBALS['ow_mails'] = array();
$GLOBALS['ow_transients'] = array();

$ow_off = new OmniWP\OTP\Transports\CircuitBreaker( 'sms' );

for ( $ow_i = 0; $ow_i < Settings::get_int( 'security.breaker_threshold', 5 ); $ow_i++ ) {
	$ow_off->record_failure();
}

ow_check( 'switching the alert off stops the mail', 0, count( $GLOBALS['ow_mails'] ) );

// The record is not the notification. Turning the mail off must not blind the
// log, which is the evidence an operator reads afterwards.
$ow_logged = false;

foreach ( (array) ( $GLOBALS['wpdb']->writes ?? array() ) as $ow_write ) {
	if ( 'insert' === ( $ow_write['op'] ?? '' ) && 'transport_breaker_open' === ( $ow_write['data']['event'] ?? '' ) ) {
		$ow_logged = true;
	}
}

ow_check( 'and the audit record is still written', true, $ow_logged );

// An override reaches an operational alert like any other message.
Settings::update(
	array(
		'email.templates.breaker_open.enabled' => 1,
		'email.templates.breaker_open.subject' => 'GẤP: kênh {{transport}} chết',
	)
);

$GLOBALS['ow_mails']      = array();
$GLOBALS['ow_transients'] = array();

$ow_custom = new OmniWP\OTP\Transports\CircuitBreaker( 'automation' );

for ( $ow_i = 0; $ow_i < Settings::get_int( 'security.breaker_threshold', 5 ); $ow_i++ ) {
	$ow_custom->record_failure();
}

ow_check(
	'an administrator override reaches an operational alert',
	'GẤP: kênh automation chết',
	$GLOBALS['ow_mails'][0]['subject'] ?? ''
);

// No recipient configured is not an error, and must not be a fatal.
update_option( 'admin_email', '' );
$GLOBALS['ow_mails'] = array();

ow_check(
	'no admin address means no mail and no failure',
	false,
	OmniWP\Mail\Mailer::send( 'budget_halted', OmniWP\Mail\Mailer::admin_address(), array() )
);

ow_check( 'and nothing was sent', 0, count( $GLOBALS['ow_mails'] ) );

// =====================================================================
ow_section( 'Rule 1 — every message is reachable from the list (13.1)' );

delete_option( Settings::OPTION );
Settings::flush_cache();

$ow_screen = ow_capture(
	static function (): void {
		( new \OmniWP\Admin\Screens\SettingsScreen() )->render( 'delivery-mail' );
	}
);

ow_assert( 'the mail screen renders', null === $ow_screen['error'], (string) $ow_screen['error'] );

$ow_mail_markup = $ow_screen['html'];
$ow_unlisted    = array();

foreach ( array_keys( call_user_func( array( ow_MAIL_REGISTRY, 'all' ) ) ) as $ow_id ) {
	if ( false === strpos( $ow_mail_markup, 'data-mail-message="' . $ow_id . '"' ) ) {
		$ow_unlisted[] = $ow_id;
	}
}

ow_check(
	'every registry row appears in the list',
	array(),
	$ow_unlisted
);

// =====================================================================
ow_section( 'Rule 2 — hiding a panel does not hide it from the save (13.1)' );

/*
 * Passes today, and that is the point of landing it now: rendering only the
 * open panel is the obvious optimisation once a list exists, and it would
 * silently stop five messages being saved — sanitize() reads an absent field as
 * "not on this tab" and leaves the stored value alone.
 *
 * A rule that arrives alongside the feature it guards cannot catch that feature
 * breaking it. 11.0's rule 6 made the same argument.
 */
$ow_missing_inputs = array();

foreach ( array_keys( call_user_func( array( ow_MAIL_REGISTRY, 'all' ) ) ) as $ow_id ) {
	foreach ( array( 'subject', 'body' ) as $ow_part ) {
		$ow_path = \OmniWP\Mail\MailRegistry::PATH_PREFIX . $ow_id . '.' . $ow_part;

		if ( false === strpos( $ow_mail_markup, 'name="' . \OmniWP\Admin\FieldRenderer::name( $ow_path ) . '"' ) ) {
			$ow_missing_inputs[] = $ow_path;
		}
	}
}

ow_check(
	'every message posts its fields whether or not its panel is open',
	array(),
	$ow_missing_inputs
);

/*
 * The column that earns its width. Until the list existed, "which of these six
 * am I actually customising" took reading twelve boxes to answer, and it is the
 * question an administrator has on opening this screen.
 *
 * Asserted by flipping it: state that only ever reads one way is state that
 * could be hard-coded and nobody would notice.
 */
ow_assert(
	'a message with no override reads as inheriting',
	false !== strpos( $ow_mail_markup, 'sl-mail-state is-inherited' )
		&& false === strpos( $ow_mail_markup, 'sl-mail-state is-custom' ),
	'Nothing is overridden at this point, so no message should claim to be customised.'
);

Settings::update( array( 'email.templates.recover.subject' => 'Đặt lại: {{code}}' ) );

$ow_after_override = ow_capture(
	static function (): void {
		( new \OmniWP\Admin\Screens\SettingsScreen() )->render( 'delivery-mail' );
	}
)['html'];

ow_check(
	'and exactly one reads as customised once one is',
	1,
	substr_count( $ow_after_override, 'sl-mail-state is-custom' )
);

ow_assert(
	'the list agrees with the resolver, not with a guess',
	\OmniWP\Mail\MailRegistry::is_overridden( 'recover' )
		&& ! \OmniWP\Mail\MailRegistry::is_overridden( 'login' ),
	'is_overridden() reads the same stored values resolve() does; a list that computed its own answer could disagree with what the transport sends.'
);

Settings::update( array( 'email.templates.recover.subject' => '' ) );

// =====================================================================
ow_section( 'Rule 3 — copy-to-edit has a way back (13.2)' );

/*
 * A filled box has stopped inheriting. Pressed on all six messages — which is
 * what a tidy-minded administrator does on first sight — copy-to-edit produces
 * six copies of text that used to live in one place, and a later change to the
 * shared pair reaches none of them.
 *
 * Counted rather than checked for presence: one revert button on the screen
 * would satisfy "there is a way back" while five fields still had none.
 */
$ow_copies  = substr_count( $ow_mail_markup, 'data-mail-copy' );
$ow_reverts = substr_count( $ow_mail_markup, 'data-mail-revert' );

ow_assert(
	'every copy affordance is matched by a revert affordance',
	$ow_copies > 0 && $ow_copies === $ow_reverts,
	sprintf( 'copy: %d, revert: %d. Copy without revert is 11.4 undone.', $ow_copies, $ow_reverts )
);

/*
 * What gets copied is the resolver's answer, not the row's own default. The two
 * differ exactly when the shared pair has been edited — which is the case that
 * matters, because copying the row default there would put text in the box that
 * differs from what the message was sending a second earlier.
 */
Settings::update( array( 'email.subject' => 'Mã của {{site_name}}: {{code}}' ) );

$ow_with_shared = ow_capture(
	static function (): void {
		( new \OmniWP\Admin\Screens\SettingsScreen() )->render( 'delivery-mail' );
	}
)['html'];

$ow_resolved_login = \OmniWP\Mail\MailRegistry::resolve( 'login' )['subject'];

ow_check( 'the shared pair is what an un-overridden message resolves to', 'Mã của {{site_name}}: {{code}}', $ow_resolved_login );

ow_assert(
	'and that is what the copy button carries',
	false !== strpos( $ow_with_shared, 'data-mail-default="' . esc_attr( $ow_resolved_login ) . '"' ),
	'Copying the row default here would hand the administrator text the message was not sending.'
);

ow_assert(
	'the row default is not what is offered',
	false === strpos(
		$ow_with_shared,
		'data-mail-default="' . esc_attr( (string) \OmniWP\Mail\MailRegistry::get( 'login' )['subject'] ) . '"'
	),
	'With the shared pair edited, the row default is the wrong answer and must not appear.'
);

Settings::update( array( 'email.subject' => '' ) );

// Revert stores empty, and empty resolves back to the shared pair. That is the
// round trip 11.1 asserts, restated here because 13.2 is what makes an
// administrator able to reach it by accident.
Settings::update( array( 'email.templates.login.subject' => 'tuỳ chỉnh' ) );
ow_check( 'an override is stored', true, \OmniWP\Mail\MailRegistry::is_overridden( 'login' ) );

Settings::update( array( 'email.templates.login.subject' => '' ) );
ow_check( 'clearing it returns the message to inheriting', false, \OmniWP\Mail\MailRegistry::is_overridden( 'login' ) );

// =====================================================================
ow_section( 'Rule 4 — the structure tokens are opt-in (13.3)' );

/*
 * Also passes today — nothing uses the tokens yet — and must keep passing. It is
 * what makes 13.3 an addition rather than a migration, and it is the rule most
 * likely to be broken by rewriting the shipped bodies to use the new tokens in
 * the same commit that introduces them.
 */
Settings::update(
	array(
		'email.enabled' => 1,
		'email.is_html' => 1,
		'email.subject' => '',
		'email.body'    => '',
	)
);

$GLOBALS['ow_mails'] = array();
( new MailTransport() )->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'login' ) );
$ow_plain_render = $GLOBALS['ow_mails'][0]['message'] ?? '';

ow_assert(
	'a body using neither token renders without their markup',
	'' !== $ow_plain_render
		&& false === strpos( $ow_plain_render, 'sl-mail-code' )
		&& false === strpos( $ow_plain_render, 'sl-mail-button' ),
	'The shipped bodies must not start emitting the new structures on their own; opt-in is what keeps 13.3 from being a migration.'
);

ow_assert(
	'and no token is left unexpanded in it',
	false === strpos( $ow_plain_render, '{{' ),
	'An unexpanded token is the silent-empty-string failure with the braces left in.'
);

// =====================================================================
ow_section( 'Structure tokens (13.3)' );

/**
 * Send one message with a body written for this assertion, and return what
 * wp_mail() was handed.
 */
function ow_render_body( string $body, bool $is_html ): string {
	Settings::update(
		array(
			'email.enabled'                => 1,
			'email.is_html'                => $is_html ? 1 : 0,
			'email.templates.login.body'   => $body,
			'email.templates.login.subject' => 'Mã {{code}}',
		)
	);

	$GLOBALS['ow_mails'] = array();

	( new MailTransport() )->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'login' ) );

	return (string) ( $GLOBALS['ow_mails'][0]['message'] ?? '' );
}

$ow_code_html = ow_render_body( 'Mã của bạn:' . "\n\n" . '{{code_block}}', true );

ow_assert(
	'{{code_block}} renders the code in a block, not as running text',
	false !== strpos( $ow_code_html, 'letter-spacing' ) && false !== strpos( $ow_code_html, '482913' ),
	'An OTP email has one job. Rendering the digits mid-paragraph is that job done badly.'
);

ow_assert(
	'and the digits are one selectable run',
	false === strpos( $ow_code_html, '4</span>' ) && false === strpos( $ow_code_html, '4</td><td' ),
	'A span or cell per digit is prettier markup that copies as "4 8 2 9 1 3" on a phone, which defeats the block entirely.'
);

$ow_code_text = ow_render_body( 'Mã của bạn: {{code_block}}', false );

ow_check( 'in plain text it is the bare digits', true, false !== strpos( $ow_code_text, '482913' ) );
ow_check( 'and carries no markup', $ow_code_text, wp_strip_all_tags( $ow_code_text ) );

$ow_button_html = ow_render_body( '{{button:https://example.test/reset|Đặt lại mật khẩu}}', true );

ow_assert(
	'{{button:…}} renders a table, not a styled anchor',
	false !== strpos( $ow_button_html, '<table' ) && false !== strpos( $ow_button_html, 'https://example.test/reset' ),
	'Outlook ignores padding on inline elements, so a styled <a> arrives as underlined text.'
);

$ow_button_text = ow_render_body( '{{button:https://example.test/reset|Đặt lại mật khẩu}}', false );

ow_assert(
	'in plain text it is a label and a copyable URL',
	false !== strpos( $ow_button_text, 'Đặt lại mật khẩu: https://example.test/reset' ),
	'A link the reader cannot click has to be one they can copy: ' . $ow_button_text
);

// The preheader, which is the difference between an inbox preview reading
// "Xin chào," and reading what the message is about.
$ow_preheader = ow_render_body( 'Xin chào,' . "\n\n" . 'Mã: {{code}}', true );

ow_assert(
	'the preheader is present and rendered',
	false !== strpos( $ow_preheader, 'Mã đăng nhập một lần' )
		&& false === strpos( $ow_preheader, '{{ttl_minutes}}' ),
	'An unrendered token in the preheader is the one place a reader sees braces before opening anything.'
);

Settings::update(
	array(
		'email.templates.login.body'    => '',
		'email.templates.login.subject' => '',
	)
);

ow_summary( 'Mail templates' );
