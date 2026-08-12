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
 * @package OmniWP
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$ow_root = dirname( __DIR__, 2 ) . '/';

require $ow_root . 'tests/stubs.php';
require $ow_root . 'tests/template-stubs.php';

use OmniWP\Auth\Providers\ProviderRegistry;
use OmniWP\Settings;

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
define( 'OMNIWP_GOOGLE_CLIENT_ID', 'google-client-for-render' );
define( 'OMNIWP_GOOGLE_CLIENT_SECRET', 'google-secret-for-render' );

$GLOBALS['ow_logged_in']       = true;
$GLOBALS['ow_current_user_id'] = 7;

$ow_fixtures = require $ow_root . 'tests/template-fixtures.php';

/**
 * The provider rows the account card draws, which the smoke fixtures leave
 * deliberately thin — `partials/account/providers` is fixtured with an empty
 * link list, because "everything is already linked" is the case the WooCommerce
 * copy got wrong for six phases. A picture wants both halves.
 */
$ow_identity = static function ( string $channel, string $label, bool $federated, bool $primary = false ): array {
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

$ow_offerable = ( new ProviderRegistry() )->get( 'google' );

$ow_providers = array(
	'ow_identities'     => array(
		$ow_identity( 'email', 'Email', false, true ),
		$ow_identity( 'google', 'Google', true ),
	),
	'ow_can_unlink'     => true,
	'ow_redirect'       => 'https://example.test/my-account/',
	'ow_link_providers' => null === $ow_offerable ? array() : array( $ow_offerable ),
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
$ow_surfaces = array(
	'status'    => array( 'partials/account/status', $ow_fixtures['partials/account/status'] ),
	'profile'   => array( 'partials/account/profile', $ow_fixtures['partials/account/profile'] ),
	'contact'   => array(
		'partials/account/contact',
		array( 'ow_providers' => $ow_providers ) + $ow_fixtures['partials/account/contact'],
	),
	'providers' => array(
		'partials/account/providers',
		$ow_providers,
	),
	'address'   => array( 'partials/account/address', $ow_fixtures['partials/account/address'] ),
	'password'  => array(
		'partials/account/password',
		array( 'ow_user' => new WP_User( 7, 'Nguyễn Như' ), 'ow_has_contact' => true ),
	),
	'card-head' => array( 'partials/account/card-head', array( 'ow_section' => 'profile' ) ),

	/*
	 * The whole standalone surface, wrapper and save bar included.
	 *
	 * Added when a report came in that "Cập nhật" was unusable: the save bar is
	 * markup no *partial* carries, so the `account` composite above — which glues
	 * the five cards together itself — could not show it. A composite of parts is
	 * not the page.
	 */
	'page'      => array( 'account', $ow_fixtures['account'], null ),

	/*
	 * The sign-in screens, added in P5. Phase 18's spec left pointing the tool at
	 * them to "the next reader's call"; P5 is the change that needed to look at
	 * them, having just converted 51 declarations in the stylesheet they share
	 * with the account card.
	 *
	 * Rule 1 only requires the account partials, so these are here because they
	 * are useful rather than because anything fails without them.
	 */
	'sign-in'   => array( 'form-auth', $ow_fixtures['form-auth'], 'identify' ),
	'sign-in-password' => array( 'form-password', $ow_fixtures['form-password'], 'password' ),
	'signup'    => array( 'form-signup', $ow_fixtures['form-signup'], 'signup' ),
	'otp'       => array( 'form-otp', $ow_fixtures['form-otp'], 'otp' ),
	'forgot'    => array( 'form-forgot', $ow_fixtures['form-forgot'], 'forgot' ),
	'onboarding' => array( 'onboarding', $ow_fixtures['onboarding'], 'onboarding' ),

	/*
	 * The dialog, added in 19.7. It is the one surface here that is not a
	 * fragment of a page but a container *around* one, so it is dispatched
	 * specially below: the shell wraps the sign-in step, both stylesheets are
	 * inlined, and the only line of JavaScript this tool has ever emitted calls
	 * showModal().
	 *
	 * That line is load-bearing rather than decorative. A `<dialog>` that has
	 * not been opened modally is `display:none`, and one opened with the `open`
	 * attribute renders without a backdrop and without the top layer — so a
	 * picture taken that way would be a picture of a different element.
	 */
	'dialog'    => array( 'login-dialog', $ow_fixtures['login-dialog'], null ),
);

/**
 * Composites: several surfaces rendered as one page.
 *
 * `account` is the one anybody actually asks for. Reading a card in isolation
 * says nothing about whether the four of them look like one screen, which is
 * the question Phase 17 was about.
 */
$ow_composites = array(
	'account' => array( 'status', 'profile', 'contact', 'address', 'password' ),
);

/**
 * Render one template with the stubs loaded, returning its markup.
 */
$ow_render = static function ( string $template, array $args ) use ( $ow_root ): string {
	ob_start();

	try {
		( static function ( string $ow_file, array $ow_args ): void {
			extract( $ow_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $ow_file;
		} )( $ow_root . 'templates/' . $template . '.php', $args );

		return (string) ob_get_clean();
	} catch ( Throwable $ow_error ) {
		ob_end_clean();

		return '<pre style="color:#b42318">' . htmlspecialchars( get_class( $ow_error ) . ': ' . $ow_error->getMessage() ) . '</pre>';
	}
};

/**
 * Wrap markup in a standalone document carrying the real stylesheet inline.
 */
/*
 * $modifier null means the surface already carries its own `.omniwp`
 * wrapper — account.php does — and nesting a second one would apply the
 * max-width and the padding twice, which is a picture of a page that does not
 * exist.
 */
$ow_page = static function ( string $name, string $body, ?string $modifier = 'account' ) use ( $ow_root ): string {
	/*
	 * Tokens first, and separately, since 21.1 split them out of
	 * `.omniwp`. In a real page WordPress resolves this from the registered
	 * dependency; here there is no dependency graph, so a picture built without
	 * this file is a picture with every colour, space and font size unresolved.
	 */
	$css = (string) file_get_contents( $ow_root . 'assets/css/omniwp-tokens.css' );
	$css .= "\n" . (string) file_get_contents( $ow_root . 'assets/css/omniwp-base.css' );
	$css .= "\n" . (string) file_get_contents( $ow_root . 'assets/css/omniwp.css' );

	/*
	 * The dialog ships its own stylesheet, and the two-stage asset load is why:
	 * the shell has to be styled before the fragment arrives, so its rules live
	 * apart from the form's. A picture built from only the main stylesheet is a
	 * picture of an unstyled container — which is exactly what the first run of
	 * this surface produced, and it read as a defect in the CSS rather than as a
	 * gap in the tool.
	 */
	if ( 'dialog' === $name ) {
		$css .= "\n" . (string) file_get_contents( $ow_root . 'assets/css/omniwp-dialog.css' );
	}

	return "<!doctype html>\n"
		. '<html lang="vi"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<title>' . htmlspecialchars( $name ) . ' — omniwp</title>'
		. '<style>' . $css . '</style>'
		/*
		 * The page chrome is deliberately plain and deliberately not the
		 * plugin's. A theme's own background and font would make this a picture
		 * of a theme; a grey page and the system font make it a picture of the
		 * plugin, which is the only thing this tool can honestly show.
		 */
		. '<style>body{margin:0;padding:24px;background:#f1f2f4;'
		. 'font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}</style>'
				. '</head><body>'
		. ( null === $modifier ? '' : '<div class="omniwp omniwp--' . $modifier . '">' )
		. $body
		. ( null === $modifier ? '' : '</div>' )
		. ( 'dialog' === $name ? '<script>document.querySelector("dialog").showModal()</script>' : '' )
		. '</body></html>';
};

// ---------------------------------------------------------------------

$ow_argv   = array_slice( $argv, 1 );
$ow_stdout = in_array( '--stdout', $ow_argv, true );
$ow_names  = array_values( array_filter( $ow_argv, static fn( string $a ): bool => '-' !== substr( $a, 0, 1 ) ) );

if ( in_array( '--all', $ow_argv, true ) ) {
	$ow_names = array_merge( array_keys( $ow_composites ), array_keys( $ow_surfaces ) );
}

if ( ! $ow_names ) {
	printf(
		"Usage: php tests/visual/render.php <surface> [--stdout]\n\n  composites: %s\n  surfaces:   %s\n  --all       every one of them\n\n",
		implode( ', ', array_keys( $ow_composites ) ),
		implode( ', ', array_keys( $ow_surfaces ) )
	);
	exit( 1 );
}

$ow_out_dir = $ow_root . 'build/visual';

if ( ! $ow_stdout && ! is_dir( $ow_out_dir ) && ! mkdir( $ow_out_dir, 0777, true ) && ! is_dir( $ow_out_dir ) ) {
	fwrite( STDERR, "Cannot create {$ow_out_dir}\n" );
	exit( 1 );
}

foreach ( $ow_names as $ow_name ) {
	if ( isset( $ow_composites[ $ow_name ] ) ) {
		$ow_body     = '';
		$ow_modifier = 'account';

		foreach ( $ow_composites[ $ow_name ] as $ow_part ) {
			$ow_body .= $ow_render( $ow_surfaces[ $ow_part ][0], $ow_surfaces[ $ow_part ][1] );
		}
	} elseif ( isset( $ow_surfaces[ $ow_name ] ) ) {
		$ow_body     = $ow_render( $ow_surfaces[ $ow_name ][0], $ow_surfaces[ $ow_name ][1] );
		$ow_modifier = array_key_exists( 2, $ow_surfaces[ $ow_name ] ) ? $ow_surfaces[ $ow_name ][2] : 'account';
	} else {
		fwrite( STDERR, "Unknown surface: {$ow_name}\n" );
		exit( 1 );
	}

	if ( 'dialog' === $ow_name ) {
		/*
		 * The shell holds a step, because a picture of an empty container says
		 * nothing about the thing anybody looks at — and it holds the *dialog*
		 * rendering of that step, not the page one. `Flow::set_base()` is the
		 * switch the template reads: without it this would picture the page
		 * variant inside the dialog's chrome, which is a screen that does not
		 * exist.
		 */
		\OmniWP\Frontend\Flow::set_base( 'https://example.test/san-pham/ao-thun/' );

		/*
		 * Three benefits, so the row can be looked at. The plugin ships none —
		 * see partials/dialog-benefits.php for why — so a picture taken without
		 * this would be a picture of the default, which is a dialog with a gap
		 * where the row is not.
		 */
		add_filter( 'omniwp_dialog_benefits',
			static function (): array {
				return array(
					array(
						'icon'  => '🚚',
						'label' => 'Miễn phí vận chuyển',
					),
					array(
						'icon'  => '⭐',
						'label' => 'Ưu đãi riêng cho thành viên',
					),
					array(
						'icon'  => '⚡',
						'label' => 'Giao nhanh trong 1 giờ',
					),
				);
			}
		);

		$ow_step = $ow_render( 'form-auth', $ow_fixtures['form-auth'] );

		\OmniWP\Frontend\Flow::set_base( '' );

		$ow_body = str_replace(
			'<p class="sl-dialog__loading">Đang tải…</p>',
			$ow_step,
			$ow_body
		);
	}

	$ow_html = $ow_page( $ow_name, $ow_body, $ow_modifier );

	if ( $ow_stdout ) {
		echo $ow_html;
		continue;
	}

	$ow_file = $ow_out_dir . '/' . $ow_name . '.html';
	file_put_contents( $ow_file, $ow_html );

	printf( "%s  (%d bytes)\n", $ow_file, strlen( $ow_html ) );
}
