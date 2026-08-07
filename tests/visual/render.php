<?php
/**
 * Build one of the plugin's screens into a page somebody can look at.
 *
 * Phase 17 wrote this in a scratch directory, used it to find two defects no
 * suite could have found — `.screen-reader-text` being a theme dependency since
 * 8.4, and an input/button height reading that was wrong in both magnitude and
 * direction — and then deleted it with the session. 18.1 is the same tool,
 * committed, so the next person does not have to rebuild it before they can see
 * anything.
 *
 * Usage:
 *
 *     php tests/visual/render.php account
 *     php tests/visual/render.php contact
 *     php tests/visual/render.php --all
 *     php tests/visual/render.php account --stdout > /tmp/card.html
 *
 * Output lands in build/visual/, which .gitignore already covers.
 *
 * **The stylesheet is inlined, not linked.** A rendered file has to open from
 * anywhere — a temp directory, an attachment on a bug report, a phone — without
 * the CSS resolving beside it. A page that only works in one directory is a page
 * nobody sends to anybody.
 *
 * **The fixtures are the smoke test's.** tests/template-fixtures.php has two
 * readers now, and that is the point: a picture built from a second set of
 * arguments is a picture of a screen no suite has ever executed, which is the
 * one thing a visual tool must not be.
 *
 * **This is not a WordPress page.** It renders templates against stubs. It does
 * not replace tests/integration/, and a green picture says nothing about what a
 * live database holds.
 *
 * @package SmartLogin
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$sl_root = dirname( __DIR__, 2 ) . '/';

require $sl_root . 'tests/stubs.php';
require $sl_root . 'tests/template-stubs.php';

use SmartLogin\Auth\Providers\ProviderRegistry;
use SmartLogin\Settings;

/*
 * Everything on, because the job is to see the screen at its fullest. A surface
 * that only renders with a setting off is a case worth naming explicitly rather
 * than reaching by accident.
 */
Settings::update(
	array(
		'identity.mode'   => 'both',
		'otp.length'      => 6,
		'address.enabled' => true,
		'profile.dob'     => true,
		'profile.gender'  => true,
	)
);

/* The entry screen only draws a provider button for a provider that is
 * *configured*, and ProviderCredentials::is_configured() reads these constants.
 * Same values run-template-tests.php uses; nothing here dials anything. */
define( 'SMART_LOGIN_GOOGLE_CLIENT_ID', 'google-client-for-render' );
define( 'SMART_LOGIN_GOOGLE_CLIENT_SECRET', 'google-secret-for-render' );
define( 'SMART_LOGIN_ZALO_APP_ID', 'zalo-app-for-render' );
define( 'SMART_LOGIN_ZALO_APP_SECRET', 'zalo-secret-for-render' );

$GLOBALS['sl_logged_in']       = true;
$GLOBALS['sl_current_user_id'] = 7;

$sl_fixtures = require $sl_root . 'tests/template-fixtures.php';

/**
 * The provider rows the account card draws, which the smoke fixtures leave
 * deliberately thin — `partials/account/providers` is fixtured with an empty
 * link list, because "everything is already linked" is the case the WooCommerce
 * copy got wrong for six phases. A picture wants both halves.
 */
$sl_identity = static function ( string $channel, string $label, bool $federated, bool $primary = false ): array {
	return array(
		'channel'     => $channel,
		'subject'     => 'sub-1',
		'masked'      => 'sub-••••••',
		'display'     => 'ng.hoa@gmail.com',
		'label'       => $label,
		'federated'   => $federated,
		'is_primary'  => $primary,
		'linked_by'   => $federated ? 'oauth' : 'otp',
		'verified_at' => '2026-07-30 08:00:00',
		'removable'   => true,
	);
};

$sl_zalo = ( new ProviderRegistry() )->get( 'zalo' );

$sl_providers = array(
	'sl_identities'     => array(
		$sl_identity( 'email', 'Email', false, true ),
		$sl_identity( 'google', 'Google', true ),
	),
	'sl_can_unlink'     => true,
	'sl_redirect'       => 'https://example.test/my-account/',
	'sl_link_providers' => null === $sl_zalo ? array() : array( $sl_zalo ),
);

/**
 * Surface name => [ template, arguments ].
 *
 * Every `templates/partials/account/*.php` appears here, and the rendered-surface
 * suite fails when one does not — the mechanism 8.2 built into the template
 * suite, which is what caught `card-head` in 17.8 before anything else did.
 *
 * Arguments default to the smoke fixture and are overridden only where a picture
 * needs a fuller case than a smoke test does.
 */
$sl_surfaces = array(
	'status'    => array( 'partials/account/status', $sl_fixtures['partials/account/status'] ),
	'profile'   => array( 'partials/account/profile', $sl_fixtures['partials/account/profile'] ),
	'contact'   => array(
		'partials/account/contact',
		array( 'sl_providers' => $sl_providers ) + $sl_fixtures['partials/account/contact'],
	),
	'providers' => array(
		'partials/account/providers',
		$sl_providers,
	),
	'address'   => array( 'partials/account/address', $sl_fixtures['partials/account/address'] ),
	'password'  => array(
		'partials/account/password',
		array( 'sl_user' => new WP_User( 7, 'Nguyễn Như' ), 'sl_has_contact' => true ),
	),
	'card-head' => array( 'partials/account/card-head', array( 'sl_section' => 'profile' ) ),

	/*
	 * The sign-in screens, added in P5. Phase 18's spec left pointing the tool at
	 * them to "the next reader's call"; P5 is the change that needed to look at
	 * them, having just converted 51 declarations in the stylesheet they share
	 * with the account card.
	 *
	 * Rule 1 only requires the account partials, so these are here because they
	 * are useful rather than because anything fails without them.
	 */
	'sign-in'   => array( 'form-auth', $sl_fixtures['form-auth'], 'identify' ),
	'sign-in-password' => array( 'form-password', $sl_fixtures['form-password'], 'password' ),
	'signup'    => array( 'form-signup', $sl_fixtures['form-signup'], 'signup' ),
	'otp'       => array( 'form-otp', $sl_fixtures['form-otp'], 'otp' ),
	'forgot'    => array( 'form-forgot', $sl_fixtures['form-forgot'], 'forgot' ),
	'onboarding' => array( 'onboarding', $sl_fixtures['onboarding'], 'onboarding' ),
);

/**
 * Composites: several surfaces rendered as one page.
 *
 * `account` is the one anybody actually asks for. Reading a card in isolation
 * says nothing about whether the four of them look like one screen, which is
 * the question Phase 17 was about.
 */
$sl_composites = array(
	'account' => array( 'status', 'profile', 'contact', 'address', 'password' ),
);

/**
 * Render one template with the stubs loaded, returning its markup.
 */
$sl_render = static function ( string $template, array $args ) use ( $sl_root ): string {
	ob_start();

	try {
		( static function ( string $sl_file, array $sl_args ): void {
			extract( $sl_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $sl_file;
		} )( $sl_root . 'templates/' . $template . '.php', $args );

		return (string) ob_get_clean();
	} catch ( Throwable $sl_error ) {
		ob_end_clean();

		return '<pre style="color:#b42318">' . htmlspecialchars( get_class( $sl_error ) . ': ' . $sl_error->getMessage() ) . '</pre>';
	}
};

/**
 * Wrap markup in a standalone document carrying the real stylesheet inline.
 */
$sl_page = static function ( string $name, string $body, string $modifier = 'account' ) use ( $sl_root ): string {
	$css = (string) file_get_contents( $sl_root . 'assets/css/smart-login.css' );

	return "<!doctype html>\n"
		. '<html lang="vi"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<title>' . htmlspecialchars( $name ) . ' — smart-login</title>'
		. '<style>' . $css . '</style>'
		/*
		 * The page chrome is deliberately plain and deliberately not the
		 * plugin's. A theme's own background and font would make this a picture
		 * of a theme; a grey page and the system font make it a picture of the
		 * plugin, which is the only thing this tool can honestly show.
		 */
		. '<style>body{margin:0;padding:24px;background:#f1f2f4;'
		. 'font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}</style>'
				. '</head><body><div class="smart-login smart-login--' . $modifier . '">'
		. $body
		. '</div></body></html>';
};

// ---------------------------------------------------------------------

$sl_argv   = array_slice( $argv, 1 );
$sl_stdout = in_array( '--stdout', $sl_argv, true );
$sl_names  = array_values( array_filter( $sl_argv, static fn( string $a ): bool => '-' !== substr( $a, 0, 1 ) ) );

if ( in_array( '--all', $sl_argv, true ) ) {
	$sl_names = array_merge( array_keys( $sl_composites ), array_keys( $sl_surfaces ) );
}

if ( ! $sl_names ) {
	printf(
		"Usage: php tests/visual/render.php <surface> [--stdout]\n\n  composites: %s\n  surfaces:   %s\n  --all       every one of them\n\n",
		implode( ', ', array_keys( $sl_composites ) ),
		implode( ', ', array_keys( $sl_surfaces ) )
	);
	exit( 1 );
}

$sl_out_dir = $sl_root . 'build/visual';

if ( ! $sl_stdout && ! is_dir( $sl_out_dir ) && ! mkdir( $sl_out_dir, 0777, true ) && ! is_dir( $sl_out_dir ) ) {
	fwrite( STDERR, "Cannot create {$sl_out_dir}\n" );
	exit( 1 );
}

foreach ( $sl_names as $sl_name ) {
	if ( isset( $sl_composites[ $sl_name ] ) ) {
		$sl_body     = '';
		$sl_modifier = 'account';

		foreach ( $sl_composites[ $sl_name ] as $sl_part ) {
			$sl_body .= $sl_render( $sl_surfaces[ $sl_part ][0], $sl_surfaces[ $sl_part ][1] );
		}
	} elseif ( isset( $sl_surfaces[ $sl_name ] ) ) {
		$sl_body     = $sl_render( $sl_surfaces[ $sl_name ][0], $sl_surfaces[ $sl_name ][1] );
		$sl_modifier = $sl_surfaces[ $sl_name ][2] ?? 'account';
	} else {
		fwrite( STDERR, "Unknown surface: {$sl_name}\n" );
		exit( 1 );
	}

	$sl_html = $sl_page( $sl_name, $sl_body, $sl_modifier );

	if ( $sl_stdout ) {
		echo $sl_html;
		continue;
	}

	$sl_file = $sl_out_dir . '/' . $sl_name . '.html';
	file_put_contents( $sl_file, $sl_html );

	printf( "%s  (%d bytes)\n", $sl_file, strlen( $sl_html ) );
}
