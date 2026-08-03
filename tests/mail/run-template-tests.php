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

if ( ! class_exists( 'SmartLogin\\Mail\\MailLayout' ) ) {
	sl_pending(
		'a body that already contains the layout is not wrapped again',
		'MailLayout — 11.2'
	);
} else {
	sl_note( 'MailLayout exists — replace this pending with the live assertion from the 11.2 brief.' );
}

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

sl_summary( 'Mail templates' );
