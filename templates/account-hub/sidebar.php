<?php
/**
 * Sidebar for Account Hub.
 *
 * @var \WP_User $user
 * @var array    $tabs
 * @var string   $active_tab
 *
 * @package OmniWP
 */

use OmniWP\Auth\ProfileCompletionService;
use OmniWP\Frontend\IconSet;

defined( 'ABSPATH' ) || exit;

$display_name = $user->display_name ?: $user->user_login;
$initial      = mb_strtoupper( mb_substr( $display_name, 0, 1, 'UTF-8' ) );
$avatar_url   = get_avatar_url( $user->ID, array( 'size' => 160 ) );

$ow_status = ( new ProfileCompletionService() )->status( $user->ID );
$ow_total  = (int) ( $ow_status['total'] ?? 0 );
$ow_done   = (int) ( $ow_status['done'] ?? 0 );
$ow_pct    = $ow_total > 0 ? round( ( $ow_done / $ow_total ) * 100 ) : 100;
?>

<div class="sl-hub-card">
	<!-- User Profile Header Banner (Desktop/Tablet) -->
	<div class="sl-hub-user">
		<div class="sl-hub-user__avatar-wrap">
			<?php if ( $avatar_url ) : ?>
				<img class="sl-hub-user__avatar" src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" />
			<?php else : ?>
				<div class="sl-hub-user__avatar"><?php echo esc_html( $initial ); ?></div>
			<?php endif; ?>
			<button type="button" class="sl-hub-user__avatar-edit sl-btn" title="<?php esc_attr_e( 'Đổi ảnh đại diện', 'omniwp' ); ?>" aria-label="<?php esc_attr_e( 'Đổi ảnh đại diện', 'omniwp' ); ?>">
				<?php echo IconSet::get( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>

		<h3 class="sl-hub-user__name"><?php echo esc_html( $display_name ); ?></h3>

		<div class="sl-hub-user__badge-wrap">
			<span class="sl-hub-user__badge-icon">
				<?php echo IconSet::get( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
			<span><?php esc_html_e( 'Thành viên Vàng', 'omniwp' ); ?></span>
		</div>

		<?php if ( $ow_total > 0 && $ow_done < $ow_total ) : ?>
			<?php
			/* translators: 1: Completed fields, 2: Total fields */
			$progress_title = sprintf( __( 'Đã hoàn thiện %1$d/%2$d thông tin', 'omniwp' ), (int) $ow_done, (int) $ow_total );
			?>
			<div class="sl-hub-user__progress" title="<?php echo esc_attr( $progress_title ); ?>">
				<div class="sl-hub-user__progress-bar">
					<span class="sl-hub-user__progress-fill" style="width: <?php echo esc_attr( (string) $ow_pct ); ?>%"></span>
				</div>
				<span class="sl-hub-user__progress-label">
					<?php
					/* translators: 1: Completed fields, 2: Total fields, 3: Percentage */
					echo esc_html( sprintf( __( 'Hồ sơ %1$d/%2$d (%3$s%%)', 'omniwp' ), (int) $ow_done, (int) $ow_total, (string) $ow_pct ) );
					?>
				</span>
			</div>
		<?php endif; ?>
	</div>

	<!-- Compact User Header Banner (Mobile Only) -->
	<div class="sl-hub-user-compact">
		<div class="sl-hub-user-compact__avatar-wrap">
			<?php if ( $avatar_url ) : ?>
				<img class="sl-hub-user-compact__avatar" src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" />
			<?php else : ?>
				<div class="sl-hub-user-compact__avatar"><?php echo esc_html( $initial ); ?></div>
			<?php endif; ?>
		</div>
		<div class="sl-hub-user-compact__meta">
			<h4 class="sl-hub-user-compact__name"><?php echo esc_html( $display_name ); ?></h4>
			<div class="sl-hub-user__badge-wrap sl-hub-user-compact__badge">
				<span class="sl-hub-user__badge-icon">
					<?php echo IconSet::get( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<span><?php esc_html_e( 'Thành viên Vàng', 'omniwp' ); ?></span>
			</div>
		</div>
	</div>

	<!-- Navigation Menu Links / Mobile Sticky Pill Tab Bar -->
	<ul class="sl-hub-nav">
		<?php foreach ( $tabs as $tab_key => $nav_tab_item ) : ?>
			<?php
			$is_active = ( $tab_key === $active_tab );
			$is_logout = ! empty( $nav_tab_item['is_logout'] );
			$class     = 'sl-hub-nav__item' . ( $is_active ? ' is-active' : '' ) . ( $is_logout ? ' sl-hub-nav__item--logout' : '' );
			?>
			<li>
				<a href="<?php echo $is_logout ? esc_url( wp_logout_url( home_url( '/' ) ) ) : '#' . esc_attr( $tab_key ); ?>"
					class="<?php echo esc_attr( $class ); ?>"
					data-sl-hub-tab="<?php echo esc_attr( $tab_key ); ?>"
					<?php echo $is_logout ? 'data-sl-logout-trigger' : ''; ?>>
					<span class="sl-hub-nav__icon">
						<?php echo IconSet::get( $nav_tab_item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<span><?php echo esc_html( $nav_tab_item['label'] ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
