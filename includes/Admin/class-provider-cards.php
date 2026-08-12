<?php
/**
 * The Google setup card.
 *
 * Kept apart from the generic registry renderer because a provider needs more
 * than a list of controls: a readiness badge, a credential that is written but
 * never read back, a copyable callback URL, and setup instructions. The plain
 * fields (enabled, client id) still come from the registry, so they are declared
 * in the same place as everything else.
 *
 * @package OmniWP
 */

namespace OmniWP\Admin;

use OmniWP\Auth\ProviderAuthController;
use OmniWP\Auth\Providers\ProviderCredentials;
use OmniWP\Auth\Providers\ProviderRegistry;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderCards {

	/**
	 * @param array<string,array> $fields The registry rows in the provider section.
	 */
	public function render( array $fields ): void {
		?>
		<div class="notice notice-info inline sl-provider-intro">
			<p>
				<?php
				echo wp_kses_post(
					__( 'Bạn có thể cấu hình trực tiếp tại đây. Client secret được <strong>mã hóa trước khi lưu</strong> và không bao giờ hiển thị lại. Nếu website đã khai báo credentials trong <code>wp-config.php</code> hoặc biến môi trường, cấu hình triển khai đó sẽ được ưu tiên.', 'omniwp' )
				);
				?>
			</p>
		</div>

		<?php $this->shared_policy( $fields ); ?>

		<div class="sl-provider-grid">
			<?php
			$this->card( 'google', __( 'Google Login', 'omniwp' ), $fields, 'google_client_secret', 'google_clear_secret' );
			?>
		</div>
		<?php
	}

	/**
	 * Settings that govern every card, drawn above every card.
	 *
	 * `auto_link_email` decides whether a verified provider email may silently
	 * adopt an existing account — for all providers, and it is the most
	 * consequential control on this screen. It used to render as its own section
	 * *below* the grid, where it reads as a footnote to whichever card happened
	 * to be last.
	 *
	 * @param array<string,array> $fields Every registry row on this tab.
	 */
	private function shared_policy( array $fields ): void {
		$shared = array_filter(
			$fields,
			static fn( array $field ): bool => 'linking' === ( $field['section'] ?? '' ),
		);

		if ( ! $shared ) {
			return;
		}
		?>
		<div class="sl-provider-policy">
			<h3><?php esc_html_e( 'Áp dụng cho mọi nhà cung cấp bên dưới', 'omniwp' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php
				foreach ( $shared as $path => $field ) {
					FieldRenderer::render( $path, $field );
				}
				?>
			</table>
		</div>
		<?php
	}

	/**
	 * @param string              $provider     Provider slug.
	 * @param string              $label        Human name for the card heading.
	 * @param array<string,array> $fields       Every registry row in the section.
	 * @param string              $secret_field Request key the secret arrives under.
	 * @param string              $clear_field  Request key that erases a stored secret.
	 */
	private function card( string $provider, string $label, array $fields, string $secret_field, string $clear_field ): void {
		$configured    = ProviderCredentials::is_configured( $provider );
		$state         = $this->state( $provider, $configured );
		$has_secret    = '' !== ProviderCredentials::secret( $provider );
		$source        = ProviderCredentials::source( $provider );
		$source_labels = array(
			'environment' => __( 'wp-config.php / Environment', 'omniwp' ),
			'settings'    => __( 'Settings đã mã hóa', 'omniwp' ),
			'missing'     => __( 'Chưa cấu hình', 'omniwp' ),
		);
		$secret_label  = __( 'Google Client Secret', 'omniwp' );

		// Whatever the registry declares for this provider, in declared order —
		// minus the switch, which the header above now draws. Excluded here
		// rather than left to render twice: two inputs with the same name means
		// the second one wins the save, and which is second is markup order.
		$own = array_filter(
			$fields,
			static fn( string $path ): bool => 0 === strpos( $path, 'providers.' . $provider . '.' )
				&& 'providers.' . $provider . '.enabled' !== $path,
			ARRAY_FILTER_USE_KEY
		);
		?>
		<section class="sl-provider-card" data-provider-card="<?php echo esc_attr( $provider ); ?>">
			<header class="sl-provider-card__header">
				<?php $this->master_switch( $provider, $label ); ?>
				<div>
					<h3><?php echo esc_html( $label ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: %s: credential source. */
							esc_html__( 'Nguồn: %s', 'omniwp' ),
							esc_html( $source_labels[ $source ] ?? $source_labels['missing'] )
						);
						?>
					</p>
				</div>
				<span class="sl-provider-status <?php echo esc_attr( $state['class'] ); ?>">
					<?php echo esc_html( $state['label'] ); ?>
				</span>
			</header>

			<div class="sl-provider-tabs" role="tablist" aria-label="<?php echo esc_attr( $label ); ?>">
				<button type="button" class="button-link is-active" role="tab" aria-selected="true" data-provider-tab="setup">
					<?php esc_html_e( 'Thiết lập', 'omniwp' ); ?>
				</button>
				<button type="button" class="button-link" role="tab" aria-selected="false" data-provider-tab="check">
					<?php esc_html_e( 'Kiểm tra', 'omniwp' ); ?>
				</button>
				<button type="button" class="button-link" role="tab" aria-selected="false" data-provider-tab="docs">
					<?php esc_html_e( 'Hướng dẫn', 'omniwp' ); ?>
				</button>
			</div>

			<div class="sl-provider-panel" data-provider-panel="setup">
				<table class="form-table" role="presentation">
					<tbody>
						<?php
						foreach ( $own as $path => $field ) {
							FieldRenderer::render( $path, $field );
						}

						if ( 'environment' === $source ) {
							printf(
								'<tr><th scope="row"></th><td><p class="description">%s</p></td></tr>',
								esc_html__( 'Giá trị triển khai đang được ưu tiên; các ô trên là cấu hình dự phòng.', 'omniwp' )
							);
						}
						?>
						<tr>
							<th scope="row">
								<label for="sl-<?php echo esc_attr( $secret_field ); ?>"><?php echo esc_html( $secret_label ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="sl-<?php echo esc_attr( $secret_field ); ?>"
									name="<?php echo esc_attr( Settings::OPTION . '[' . $secret_field . ']' ); ?>"
									value=""
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo $has_secret ? esc_attr__( 'Đã lưu — để trống để giữ nguyên', 'omniwp' ) : esc_attr__( 'Nhập secret', 'omniwp' ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Secret chỉ dùng khi lưu; plugin không đưa secret hiện có trở lại HTML.', 'omniwp' ); ?>
								</p>
								<?php if ( $has_secret && 'environment' !== $source ) : ?>
									<label class="sl-provider-clear-secret">
										<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[' . $clear_field . ']' ); ?>" value="1" />
										<?php esc_html_e( 'Xóa secret đã lưu khi bấm Lưu thay đổi', 'omniwp' ); ?>
									</label>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Callback URL', 'omniwp' ); ?></th>
							<td>
								<input
									type="text"
									class="large-text code"
									value="<?php echo esc_attr( ProviderCredentials::redirect_uri( $provider ) ); ?>"
									readonly
									data-provider-callback
								/>
								<p class="description"><?php esc_html_e( 'Sao chép chính xác URL này sang cấu hình ứng dụng của provider.', 'omniwp' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="sl-provider-panel" data-provider-panel="check" hidden>
				<?php $this->check_panel( $provider, $configured ); ?>
			</div>

			<div class="sl-provider-panel sl-provider-docs" data-provider-panel="docs" hidden>
				<?php $this->docs( $provider ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * The on/off switch, in the header beside the badge that reports on it.
	 *
	 * Rendered here rather than by `FieldRenderer::checkbox()` because that emits
	 * a whole `<tr>`, and teaching it a second mode for one caller costs more
	 * than eight lines of markup.
	 *
	 * The hidden companion input comes with it and is not optional: without it an
	 * unticked box posts nothing, `Settings::sanitize()` reads absence as "this
	 * field is not on the posted tab", and a provider could be switched on but
	 * never off. Same trap 10.4's checkbox list hit, same answer.
	 */
	private function master_switch( string $provider, string $label ): void {
		// By variable, not by literal: the path is chosen by the provider, the
		// same way FieldRenderer reads. Phase 9's rule 8 forbids the other form.
		$path = 'providers.' . $provider . '.enabled';
		?>
		<label class="sl-provider-switch">
			<input type="hidden" name="<?php echo esc_attr( FieldRenderer::name( $path ) ); ?>" value="0" />
			<input
				type="checkbox"
				name="<?php echo esc_attr( FieldRenderer::name( $path ) ); ?>"
				value="1"
				<?php checked( Settings::is_on( $path ) ); ?>
			/>
			<span class="screen-reader-text">
				<?php
				printf(
					/* translators: %s: provider name. */
					esc_html__( 'Kích hoạt %s', 'omniwp' ),
					esc_html( $label )
				);
				?>
			</span>
		</label>
		<?php
	}

	/**
	 * Three states, because two could not tell the truth.
	 *
	 * The badge used to read `is_configured()` — credentials present — while what
	 * decides whether a provider runs is `is_available()`, which is
	 * `enabled && is_configured`. Filling the credentials in and leaving Kích
	 * hoạt off therefore produced a green **Sẵn sàng** on a provider whose button
	 * never rendered anywhere: the screen asserting a control that did not exist,
	 * which is the failure this project keeps finding in its own documentation.
	 *
	 * It asks the registry rather than recomputing the condition, so the badge
	 * and the front end cannot drift apart — a second copy of `enabled &&
	 * configured` here would be the same defect one refactor away.
	 *
	 * @return array{class:string,label:string}
	 */
	private function state( string $provider, bool $configured ): array {
		if ( array_key_exists( $provider, ( new ProviderRegistry() )->available() ) ) {
			return array(
				'class' => 'is-ready',
				'label' => __( 'Đang hoạt động', 'omniwp' ),
			);
		}

		if ( $configured ) {
			return array(
				'class' => 'is-idle',
				'label' => __( 'Đã cấu hình · chưa bật', 'omniwp' ),
			);
		}

		// Read by variable, not by literal: the path is chosen by the provider,
		// the same way FieldRenderer reads. A literal built by concatenation is a
		// key nothing can check against the registry, and the abuse suite's
		// rule 8 caught the first version of this line saying exactly that.
		$enabled_path = 'providers.' . $provider . '.enabled';

		if ( Settings::is_on( $enabled_path ) ) {
			return array(
				'class' => 'is-missing',
				'label' => __( 'Đã bật · thiếu credentials', 'omniwp' ),
			);
		}

		return array(
			'class' => 'is-missing',
			'label' => __( 'Chưa cấu hình', 'omniwp' ),
		);
	}

	/**
	 * A real round trip, because a redirect URI cannot be checked any other way.
	 *
	 * SMS and email both have Gửi thử; this screen had nothing, and its commonest
	 * failure — a redirect URI registered one character differently — is
	 * invisible until a real visitor meets it. No provider will answer "is this
	 * URI registered", so the only honest test is to perform the exchange and
	 * repeat what came back.
	 *
	 * Offered whether or not the provider is switched on. Configured-but-off is
	 * exactly the state a test exists to get out of, and requiring the switch
	 * first would mean showing a possibly-broken button to real visitors while
	 * the administrator checks.
	 */
	private function check_panel( string $provider, bool $configured ): void {
		if ( ! $configured ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Điền Client ID và Secret rồi Lưu thay đổi trước khi kiểm tra.', 'omniwp' )
			);

			return;
		}

		$result = ProviderAuthController::take_test_result();

		if ( is_array( $result ) && ( $result['provider'] ?? '' ) === $provider ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html__( 'Vòng đăng nhập chạy được. Không có tài khoản nào được tạo và không ai được đăng nhập — đây chỉ là phép thử.', 'omniwp' )
			);
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Mở một vòng đăng nhập thật với nhà cung cấp rồi dừng lại ngay khi nhận được danh tính. Không tạo tài khoản, không đăng nhập, không liên kết gì.', 'omniwp' ); ?>
		</p>
		<p>
			<a
				class="button"
				href="<?php echo esc_url( ProviderAuthController::test_url( $provider ) ); ?>"
				target="_blank"
				rel="noopener noreferrer"
			><?php esc_html_e( 'Chạy kiểm tra', 'omniwp' ); ?></a>
		</p>
		<p class="description">
			<?php esc_html_e( 'Nếu nhà cung cấp báo lỗi, thông báo của họ sẽ hiện ra — thường gặp nhất là redirect URI khai báo lệch so với URL dưới đây.', 'omniwp' ); ?>
		</p>
		<p><code><?php echo esc_html( ProviderCredentials::redirect_uri( $provider ) ); ?></code></p>
		<?php
	}

	private function docs( string $provider ): void {
		if ( 'google' !== $provider ) {
			return;
		}
		?>
			<h4><?php esc_html_e( 'Tạo OAuth Client trên Google Cloud', 'omniwp' ); ?></h4>
			<ol>
				<li>
					<?php
					printf(
						wp_kses(
							/* translators: %s: Google Cloud Console URL. */
							__( 'Mở <a href="%s" target="_blank" rel="noopener noreferrer">Google Cloud Console → Clients</a>, chọn đúng project.', 'omniwp' ),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						),
						esc_url( 'https://console.cloud.google.com/auth/clients' )
					);
					?>
				</li>
				<li><?php esc_html_e( 'Cấu hình OAuth consent screen; nếu ứng dụng đang ở Testing, thêm các tài khoản thử nghiệm vào Test users.', 'omniwp' ); ?></li>
				<li><?php esc_html_e( 'Tạo OAuth Client ID loại Web application.', 'omniwp' ); ?></li>
				<li><?php esc_html_e( 'Trong Authorized redirect URIs, dán chính xác Callback URL ở tab Thiết lập.', 'omniwp' ); ?></li>
				<li><?php esc_html_e( 'Sao chép Client ID và Client Secret vào các ô tương ứng, bật Kích hoạt rồi Lưu thay đổi.', 'omniwp' ); ?></li>
			</ol>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Không dùng JavaScript origin thay cho redirect URI. Google yêu cầu URI callback phải khớp chính xác với URI đã khai báo.', 'omniwp' ); ?></p>
			</div>
		<?php
	}
}
