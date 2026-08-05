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
 * @package SmartLogin
 */

require __DIR__ . '/../stubs.php';
// The HTML layout goes through TemplateLoader, which asks the theme first —
// so this suite needs the same locate_template() the template suite uses.
require __DIR__ . '/../template-stubs.php';
// 13.0 renders the mail screen to assert the message list against the registry
// that generates it. admin_url() and friends live here.
require __DIR__ . '/../admin-stubs.php';
require __DIR__ . '/../harness.php';

use SmartLogin\Auth\AuthAction;
use SmartLogin\FieldRegistry;
use SmartLogin\OTP\Transports\MailTransport;
use SmartLogin\Settings;

const SL_MAIL_REGISTRY = 'SmartLogin\\Mail\\MailRegistry';

// =====================================================================
sl_section( 'Rule 1 — every message the plugin sends comes from the registry (11.3)' );

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
function sl_find_calls( string $function, array $allowed_files = array() ): array {
	$offenders = array();

	foreach ( sl_plugin_sources() as $relative => $contents ) {
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

$sl_mail_callers = sl_find_calls(
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

sl_assert(
	'no file composes its own mail outside the mail layer',
	array() === $sl_mail_callers,
	'A message written inline is a message no screen can reach. 11.3 adds Mailer for operational alerts; until then these are the defect: ' . implode( ', ', $sl_mail_callers )
);

// =====================================================================
sl_section( 'Rule 2 — every intent resolves to a template (11.1)' );

$sl_has_registry = class_exists( SL_MAIL_REGISTRY );

sl_assert(
	'a mail registry exists',
	$sl_has_registry,
	'One array must declare every message: its id, group, when it fires, its defaults and the tokens it may use. Four hand-written field pairs is the four-way drift FieldRegistry was written to remove.'
);

$sl_intents = array(
	AuthAction::REGISTER,
	AuthAction::LOGIN,
	AuthAction::RECOVER,
	AuthAction::ADD_IDENTITY,
);

if ( ! $sl_has_registry ) {
	foreach ( $sl_intents as $sl_intent ) {
		sl_pending(
			sprintf( 'intent "%s" resolves to a subject and a body', $sl_intent ),
			'MailRegistry — 11.1'
		);
	}
} else {
	foreach ( $sl_intents as $sl_intent ) {
		$sl_resolved = call_user_func( array( SL_MAIL_REGISTRY, 'resolve_intent' ), $sl_intent );

		sl_assert(
			sprintf( 'intent "%s" resolves to a subject and a body', $sl_intent ),
			is_array( $sl_resolved )
				&& '' !== trim( (string) ( $sl_resolved['subject'] ?? '' ) )
				&& '' !== trim( (string) ( $sl_resolved['body'] ?? '' ) ),
			'Falling back to the shared pair is a resolution; being absent is not. This guards the fallback against being dropped once four overrides exist.'
		);
	}
}

// =====================================================================
sl_section( 'Rule 3 — a template uses only the tokens its row declares (11.1)' );

/*
 * The brief said this rule would "pass vacuously and say so". That is the
 * mistake 10.0 made with its own rule 5 and had to correct: a rule that passes
 * because its subject does not exist states the opposite of the truth. Pending.
 */
if ( ! $sl_has_registry ) {
	sl_pending(
		'no shipped default uses a token its message does not declare',
		'MailRegistry — 11.1'
	);
} else {
	$sl_undeclared = array();

	foreach ( call_user_func( array( SL_MAIL_REGISTRY, 'all' ) ) as $sl_id => $sl_row ) {
		$sl_allowed = (array) ( $sl_row['tokens'] ?? array() );

		foreach ( array( 'subject', 'body' ) as $sl_part ) {
			if ( ! preg_match_all( '/\{\{([a-z_:]+)\}\}/', (string) ( $sl_row[ $sl_part ] ?? '' ), $sl_found ) ) {
				continue;
			}

			foreach ( $sl_found[1] as $sl_token ) {
				if ( ! in_array( $sl_token, $sl_allowed, true ) ) {
					$sl_undeclared[] = $sl_id . '.' . $sl_part . ' → {{' . $sl_token . '}}';
				}
			}
		}
	}

	sl_assert(
		'no shipped default uses a token its message does not declare',
		array() === $sl_undeclared,
		'A token outside the message\'s set renders as a silent empty string, which is the failure this phase exists to prevent: ' . implode( ', ', $sl_undeclared )
	);
}

// =====================================================================
sl_section( 'Rule 4 — every declared message is reachable from a screen (11.4)' );

if ( ! $sl_has_registry ) {
	sl_pending(
		'every generated template field is declared and drawn',
		'MailRegistry::fields() — 11.1'
	);
} else {
	$sl_declared = FieldRegistry::all();
	$sl_orphans  = array();

	foreach ( call_user_func( array( SL_MAIL_REGISTRY, 'fields' ) ) as $sl_path => $sl_field ) {
		if ( ! isset( $sl_declared[ $sl_path ] ) ) {
			$sl_orphans[] = $sl_path;
		}
	}

	sl_assert(
		'every generated template field is declared and drawn',
		array() === $sl_orphans,
		'A generated field missing from the registry is stored by nothing and dropped on the next read: ' . implode( ', ', $sl_orphans )
	);
}

// =====================================================================
sl_section( 'Rule 5 — the layout wraps exactly once (11.2)' );

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

$GLOBALS['sl_mails'] = array();

( new MailTransport() )->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'recover' ) );

$sl_html = $GLOBALS['sl_mails'][0]['message'] ?? '';

sl_check(
	'an HTML message is wrapped exactly once',
	1,
	substr_count( $sl_html, SmartLogin\Mail\MailLayout::MARKER )
);

sl_assert(
	'the configured accent and footer reach the message',
	false !== strpos( $sl_html, '#123456' ) && false !== strpos( $sl_html, 'Cửa hàng ví dụ' ),
	'The three settings exist to be visible; if they are not in the output they are decoration.'
);

sl_assert(
	'the body survived inside the layout',
	false !== strpos( $sl_html, '482913' ),
	'A layout that loses the code is worse than no layout.'
);

// The shipped bodies are written as plain text, so their blank lines are the
// only structure they have. Handing them to a layout unconverted is what made
// "turn HTML on" produce one run-on paragraph.
sl_assert(
	'plain-text line breaks became paragraphs',
	substr_count( $sl_html, '<p style=' ) > 1,
	'The default bodies have blank lines between blocks; without conversion the whole message renders as one paragraph.'
);

// An administrator pasting a complete document must not get it nested inside
// another one.
$GLOBALS['sl_mails'] = array();

Settings::update(
	array( 'email.templates.recover.body' => "<html><body><p>Mã: {{code}}</p></body></html>" )
);

( new MailTransport() )->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'recover' ) );

$sl_pasted = $GLOBALS['sl_mails'][0]['message'] ?? '';

sl_check(
	'a body that is already a document is not wrapped again',
	0,
	substr_count( $sl_pasted, SmartLogin\Mail\MailLayout::MARKER )
);

sl_check( 'and it is sent as written', 1, substr_count( $sl_pasted, '<html>' ) );

// A colour that would escape the style attribute must not reach it.
Settings::update(
	array(
		'email.templates.recover.body' => '',
		'email.accent_color'           => 'red;background:url(http://evil.test/x)',
	)
);

sl_check(
	'an invalid accent falls back rather than reaching the style attribute',
	'#2271b1',
	SmartLogin\Mail\MailLayout::accent()
);

Settings::update( array( 'email.accent_color' => '#2271b1' ) );

// =====================================================================
sl_section( 'Rule 6 — plain text never carries markup (11.2)' );

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

$GLOBALS['sl_mails'] = array();

( new MailTransport() )->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'login' ) );

$sl_sent = $GLOBALS['sl_mails'][0]['message'] ?? '';

sl_check(
	'a text message carries no markup',
	true,
	'' !== $sl_sent && $sl_sent === wp_strip_all_tags( $sl_sent )
);

sl_check(
	'and the code still reached it',
	true,
	false !== strpos( $sl_sent, '482913' )
);

// =====================================================================
sl_section( 'Resolution — three levels, and each one reachable (11.1)' );

if ( ! $sl_has_registry ) {
	sl_pending( 'a reset code is worded differently from a login code', 'MailRegistry — 11.1' );
} else {
	// Nothing customised anywhere: each message uses its own default.
	delete_option( Settings::OPTION );
	Settings::flush_cache();

	$sl_login   = call_user_func( array( SL_MAIL_REGISTRY, 'resolve' ), 'login' );
	$sl_recover = call_user_func( array( SL_MAIL_REGISTRY, 'resolve' ), 'recover' );

	sl_assert(
		'a reset code is worded differently from a login code',
		$sl_login['subject'] !== $sl_recover['subject'] && $sl_login['body'] !== $sl_recover['body'],
		'This is the defect the phase exists for: PasswordResetHandler goes through issue() with intent recover and arrived reading "Mã xác thực của bạn là…", identical to a login code.'
	);

	sl_assert(
		'the reset wording actually mentions the password',
		false !== strpos( $sl_recover['subject'] . $sl_recover['body'], 'mật khẩu' ),
		'Different is not enough; it has to say what it is.'
	);

	// A site that edited the shared pair keeps that wording everywhere, which is
	// the no-migration property. Asserted rather than assumed.
	Settings::update( array( 'email.subject' => 'Mã của {{site_name}}: {{code}}' ) );

	sl_check(
		'an edited shared subject still governs every message',
		'Mã của {{site_name}}: {{code}}',
		call_user_func( array( SL_MAIL_REGISTRY, 'resolve' ), 'recover' )['subject']
	);

	// And an override beats both.
	Settings::update( array( 'email.templates.recover.subject' => 'Đặt lại mật khẩu: {{code}}' ) );

	sl_check(
		'a per-message override beats the shared pair',
		'Đặt lại mật khẩu: {{code}}',
		call_user_func( array( SL_MAIL_REGISTRY, 'resolve' ), 'recover' )['subject']
	);

	sl_check(
		'and its siblings are unaffected',
		'Mã của {{site_name}}: {{code}}',
		call_user_func( array( SL_MAIL_REGISTRY, 'resolve' ), 'login' )['subject']
	);

	// Clearing the override restores inheritance rather than emptying the mail.
	Settings::update( array( 'email.templates.recover.subject' => '' ) );

	sl_check(
		'clearing an override restores inheritance',
		'Mã của {{site_name}}: {{code}}',
		call_user_func( array( SL_MAIL_REGISTRY, 'resolve' ), 'recover' )['subject']
	);

	// The tester sends intent `test`, which has no row. It must still render.
	delete_option( Settings::OPTION );
	Settings::flush_cache();

	$sl_test = call_user_func( array( SL_MAIL_REGISTRY, 'resolve_intent' ), 'test' );

	sl_assert(
		'an intent with no row still resolves, so the admin tester works',
		'' !== trim( $sl_test['subject'] ) && '' !== trim( $sl_test['body'] ),
		'The Gửi thử button sends intent "test". A tester that cannot render is a tester nobody can check a gateway with.'
	);
}

// =====================================================================
sl_section( 'Token scoping (11.1)' );

if ( $sl_has_registry ) {
	$sl_scoped = \SmartLogin\OTP\Placeholders::available_tokens( 'recover' );
	$sl_global = \SmartLogin\OTP\Placeholders::available_tokens();

	sl_assert(
		'a message shows only the tokens it declares',
		count( $sl_scoped ) > 0 && count( $sl_scoped ) <= count( $sl_global ),
		'Showing every token beside every template is how {{ip}} ends up in an OTP mail and renders as nothing.'
	);

	sl_check(
		'and the unscoped list is unchanged for the SMS section',
		true,
		isset( $sl_global['{{code}}'] ) && isset( $sl_global['{{phone_local}}'] )
	);
}

// =====================================================================
sl_section( 'The operational alerts still fire, and can now be silenced (11.3)' );

delete_option( Settings::OPTION );
Settings::flush_cache();
update_option( 'admin_email', 'quantri@example.com' );

$GLOBALS['sl_mails'] = array();

// The breaker opens on the threshold-th consecutive failure and announces once.
$sl_breaker = new SmartLogin\OTP\Transports\CircuitBreaker( 'sms' );

for ( $sl_i = 0; $sl_i < Settings::get_int( 'security.breaker_threshold', 5 ); $sl_i++ ) {
	$sl_breaker->record_failure();
}

sl_check( 'opening the breaker sends exactly one mail', 1, count( $GLOBALS['sl_mails'] ) );

$sl_alert = $GLOBALS['sl_mails'][0] ?? array();

sl_check( 'and it goes to the site admin', 'quantri@example.com', $sl_alert['to'] ?? '' );

sl_assert(
	'the wording survived the move to the registry',
	false !== strpos( (string) ( $sl_alert['subject'] ?? '' ), 'Kênh gửi mã đang lỗi liên tục' )
		&& false !== strpos( (string) ( $sl_alert['message'] ?? '' ), 'ngắt mạch chặn' ),
	'Moving a message behind a registry must not reword it: ' . ( $sl_alert['subject'] ?? '' )
);

sl_assert(
	'its tokens expanded rather than printing as braces',
	false === strpos( (string) ( $sl_alert['subject'] ?? '' ) . ( $sl_alert['message'] ?? '' ), '{{' ),
	'An unexpanded token is the silent-empty-string failure with the braces left in: ' . ( $sl_alert['message'] ?? '' )
);

// Off. This is the part that did not exist: both events already reach an
// automation endpoint through the 10.4 bus, so a configured site was receiving
// each alert twice and could silence neither.
Settings::update( array( 'email.templates.breaker_open.enabled' => 0 ) );

$GLOBALS['sl_mails'] = array();
$GLOBALS['sl_transients'] = array();

$sl_off = new SmartLogin\OTP\Transports\CircuitBreaker( 'sms' );

for ( $sl_i = 0; $sl_i < Settings::get_int( 'security.breaker_threshold', 5 ); $sl_i++ ) {
	$sl_off->record_failure();
}

sl_check( 'switching the alert off stops the mail', 0, count( $GLOBALS['sl_mails'] ) );

// The record is not the notification. Turning the mail off must not blind the
// log, which is the evidence an operator reads afterwards.
$sl_logged = false;

foreach ( (array) ( $GLOBALS['wpdb']->writes ?? array() ) as $sl_write ) {
	if ( 'insert' === ( $sl_write['op'] ?? '' ) && 'transport_breaker_open' === ( $sl_write['data']['event'] ?? '' ) ) {
		$sl_logged = true;
	}
}

sl_check( 'and the audit record is still written', true, $sl_logged );

// An override reaches an operational alert like any other message.
Settings::update(
	array(
		'email.templates.breaker_open.enabled' => 1,
		'email.templates.breaker_open.subject' => 'GẤP: kênh {{transport}} chết',
	)
);

$GLOBALS['sl_mails']      = array();
$GLOBALS['sl_transients'] = array();

$sl_custom = new SmartLogin\OTP\Transports\CircuitBreaker( 'automation' );

for ( $sl_i = 0; $sl_i < Settings::get_int( 'security.breaker_threshold', 5 ); $sl_i++ ) {
	$sl_custom->record_failure();
}

sl_check(
	'an administrator override reaches an operational alert',
	'GẤP: kênh automation chết',
	$GLOBALS['sl_mails'][0]['subject'] ?? ''
);

// No recipient configured is not an error, and must not be a fatal.
update_option( 'admin_email', '' );
$GLOBALS['sl_mails'] = array();

sl_check(
	'no admin address means no mail and no failure',
	false,
	SmartLogin\Mail\Mailer::send( 'budget_halted', SmartLogin\Mail\Mailer::admin_address(), array() )
);

sl_check( 'and nothing was sent', 0, count( $GLOBALS['sl_mails'] ) );

// =====================================================================
sl_section( 'Rule 1 — every message is reachable from the list (13.1)' );

delete_option( Settings::OPTION );
Settings::flush_cache();

$sl_screen = sl_capture(
	static function (): void {
		( new \SmartLogin\Admin\Screens\SettingsScreen() )->render( 'delivery-mail' );
	}
);

sl_assert( 'the mail screen renders', null === $sl_screen['error'], (string) $sl_screen['error'] );

$sl_mail_markup = $sl_screen['html'];
$sl_unlisted    = array();

foreach ( array_keys( call_user_func( array( SL_MAIL_REGISTRY, 'all' ) ) ) as $sl_id ) {
	if ( false === strpos( $sl_mail_markup, 'data-mail-message="' . $sl_id . '"' ) ) {
		$sl_unlisted[] = $sl_id;
	}
}

sl_check(
	'every registry row appears in the list',
	array(),
	$sl_unlisted
);

// =====================================================================
sl_section( 'Rule 2 — hiding a panel does not hide it from the save (13.1)' );

/*
 * Passes today, and that is the point of landing it now: rendering only the
 * open panel is the obvious optimisation once a list exists, and it would
 * silently stop five messages being saved — sanitize() reads an absent field as
 * "not on this tab" and leaves the stored value alone.
 *
 * A rule that arrives alongside the feature it guards cannot catch that feature
 * breaking it. 11.0's rule 6 made the same argument.
 */
$sl_missing_inputs = array();

foreach ( array_keys( call_user_func( array( SL_MAIL_REGISTRY, 'all' ) ) ) as $sl_id ) {
	foreach ( array( 'subject', 'body' ) as $sl_part ) {
		$sl_path = \SmartLogin\Mail\MailRegistry::PATH_PREFIX . $sl_id . '.' . $sl_part;

		if ( false === strpos( $sl_mail_markup, 'name="' . \SmartLogin\Admin\FieldRenderer::name( $sl_path ) . '"' ) ) {
			$sl_missing_inputs[] = $sl_path;
		}
	}
}

sl_check(
	'every message posts its fields whether or not its panel is open',
	array(),
	$sl_missing_inputs
);

/*
 * The column that earns its width. Until the list existed, "which of these six
 * am I actually customising" took reading twelve boxes to answer, and it is the
 * question an administrator has on opening this screen.
 *
 * Asserted by flipping it: state that only ever reads one way is state that
 * could be hard-coded and nobody would notice.
 */
sl_assert(
	'a message with no override reads as inheriting',
	false !== strpos( $sl_mail_markup, 'sl-mail-state is-inherited' )
		&& false === strpos( $sl_mail_markup, 'sl-mail-state is-custom' ),
	'Nothing is overridden at this point, so no message should claim to be customised.'
);

Settings::update( array( 'email.templates.recover.subject' => 'Đặt lại: {{code}}' ) );

$sl_after_override = sl_capture(
	static function (): void {
		( new \SmartLogin\Admin\Screens\SettingsScreen() )->render( 'delivery-mail' );
	}
)['html'];

sl_check(
	'and exactly one reads as customised once one is',
	1,
	substr_count( $sl_after_override, 'sl-mail-state is-custom' )
);

sl_assert(
	'the list agrees with the resolver, not with a guess',
	\SmartLogin\Mail\MailRegistry::is_overridden( 'recover' )
		&& ! \SmartLogin\Mail\MailRegistry::is_overridden( 'login' ),
	'is_overridden() reads the same stored values resolve() does; a list that computed its own answer could disagree with what the transport sends.'
);

Settings::update( array( 'email.templates.recover.subject' => '' ) );

// =====================================================================
sl_section( 'Rule 3 — copy-to-edit has a way back (13.2)' );

/*
 * A filled box has stopped inheriting. Pressed on all six messages — which is
 * what a tidy-minded administrator does on first sight — copy-to-edit produces
 * six copies of text that used to live in one place, and a later change to the
 * shared pair reaches none of them.
 *
 * Counted rather than checked for presence: one revert button on the screen
 * would satisfy "there is a way back" while five fields still had none.
 */
$sl_copies  = substr_count( $sl_mail_markup, 'data-mail-copy' );
$sl_reverts = substr_count( $sl_mail_markup, 'data-mail-revert' );

sl_assert(
	'every copy affordance is matched by a revert affordance',
	$sl_copies > 0 && $sl_copies === $sl_reverts,
	sprintf( 'copy: %d, revert: %d. Copy without revert is 11.4 undone.', $sl_copies, $sl_reverts )
);

// =====================================================================
sl_section( 'Rule 4 — the structure tokens are opt-in (13.3)' );

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

$GLOBALS['sl_mails'] = array();
( new MailTransport() )->send( 'nguoi.dung@example.com', '482913', array( 'intent' => 'login' ) );
$sl_plain_render = $GLOBALS['sl_mails'][0]['message'] ?? '';

sl_assert(
	'a body using neither token renders without their markup',
	'' !== $sl_plain_render
		&& false === strpos( $sl_plain_render, 'sl-mail-code' )
		&& false === strpos( $sl_plain_render, 'sl-mail-button' ),
	'The shipped bodies must not start emitting the new structures on their own; opt-in is what keeps 13.3 from being a migration.'
);

sl_assert(
	'and no token is left unexpanded in it',
	false === strpos( $sl_plain_render, '{{' ),
	'An unexpanded token is the silent-empty-string failure with the braces left in.'
);

sl_summary( 'Mail templates' );
