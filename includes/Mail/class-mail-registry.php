<?php
/**
 * Every message this plugin can send, declared exactly once.
 *
 * Before this, one `email.subject` / `email.body` pair served all four OTP
 * intents, so a password reset arrived worded identically to a login code —
 * `{{intent}}` was exposed to templates but could only be printed, never branched
 * on. The obvious fix is four more pairs of fields; the reason that is the wrong
 * fix is the one FieldRegistry's own docblock gives, one level up: four
 * hand-written pairs are four places that have to agree about what exists, and
 * nothing checks them.
 *
 * Here a row declares the message and the settings are generated from it. A
 * message cannot be editable without being declared, and cannot be declared
 * without being editable, because both read this array.
 *
 * @package OmniWP
 */

namespace OmniWP\Mail;

use OmniWP\Auth\AuthAction;
use OmniWP\Settings;

defined( 'ABSPATH' ) || exit;

final class MailRegistry {

	/**
	 * Tokens every OTP message may use.
	 *
	 * A named constant rather than a global list, so a message added later
	 * inherits nothing by accident. That is the whole of D3: a token outside a
	 * message's set renders as a silent empty string, and silence is how this
	 * project has lost five renames.
	 */
	const OTP_TOKENS = array(
		'destination',
		'phone',
		'phone_local',
		'phone_plus',
		'email',
		'code',
		'intent',
		'transport',
		'ttl_seconds',
		'ttl_minutes',
		'expires_at',
		'site_name',
		'site_url',
		'user_name',
		'delivery_id',
		// Structure rather than a value: expanded by MailStructure after the
		// placeholders, into a block in HTML and the bare digits in text.
		'code_block',
	);

	/**
	 * Tokens the operational alerts may use.
	 *
	 * Deliberately not `OTP_TOKENS` plus extras: an alert has no code, no
	 * destination and no expiry, and offering those would put four tokens that
	 * always render empty in front of the administrator writing the message.
	 */
	const ADMIN_TOKENS = array(
		'site_name',
		'site_url',
		'ceiling',
		'window',
		'halt_minutes',
		'transport',
		'cooldown',
	);

	/** Where an override for a message is stored. */
	const PATH_PREFIX = 'email.templates.';

	/**
	 * The shared pair every message falls back to.
	 *
	 * Kept rather than replaced by four copies of the same text. It is what makes
	 * this phase a no-op for any site that never opens the screen, and deleting a
	 * fallback in favour of copies is how drift starts.
	 */
	const FALLBACK_SUBJECT = 'email.subject';
	const FALLBACK_BODY    = 'email.body';

	/**
	 * @return array<string,array> Keyed by the path segment the override uses.
	 */
	public static function all(): array {
		$messages = array(
			'register'      => array(
				'group'     => 'otp',
				'intent'    => AuthAction::REGISTER,
				'label'     => __( 'Mã đăng ký', 'omniwp' ),
				'when'      => __( 'Khi người dùng bắt đầu tạo tài khoản mới.', 'omniwp' ),
				'preheader' => 'Mã xác thực để hoàn tất đăng ký, hiệu lực {{ttl_minutes}} phút.',
				'tokens'    => self::OTP_TOKENS,
				'subject'   => 'Mã xác thực đăng ký {{code}} - {{site_name}}',
				'body'      => 'Xin chào,

Dùng mã dưới đây để hoàn tất việc tạo tài khoản tại {{site_name}}.

{{code_block}}

Mã có hiệu lực trong {{ttl_minutes}} phút và chỉ dùng được một lần.

Nếu bạn không yêu cầu tạo tài khoản, hãy bỏ qua email này — không có gì được tạo cho tới khi mã được nhập.',
			),
			'login'         => array(
				'group'     => 'otp',
				'intent'    => AuthAction::LOGIN,
				'label'     => __( 'Mã đăng nhập', 'omniwp' ),
				'when'      => __( 'Khi người dùng đăng nhập bằng mã thay cho mật khẩu, hoặc từ thiết bị lạ.', 'omniwp' ),
				'preheader' => 'Mã đăng nhập một lần, hiệu lực {{ttl_minutes}} phút.',
				'tokens'    => self::OTP_TOKENS,
				'subject'   => 'Mã đăng nhập {{code}} - {{site_name}}',
				'body'      => 'Xin chào,

Đây là mã đăng nhập của bạn tại {{site_name}}.

{{code_block}}

Mã có hiệu lực trong {{ttl_minutes}} phút và chỉ dùng được một lần.

Nếu không phải bạn đang đăng nhập, hãy đổi mật khẩu ngay — có người đang biết địa chỉ email này của bạn.',
			),
			'recover'       => array(
				'group'     => 'otp',
				'intent'    => AuthAction::RECOVER,
				'label'     => __( 'Đặt lại mật khẩu', 'omniwp' ),
				'when'      => __( 'Khi người dùng bấm Quên mật khẩu.', 'omniwp' ),
				'preheader' => 'Mã xác nhận để đặt lại mật khẩu, hiệu lực {{ttl_minutes}} phút.',
				'tokens'    => self::OTP_TOKENS,
				'subject'   => 'Mã đặt lại mật khẩu {{code}} - {{site_name}}',
				'body'      => 'Xin chào,

Bạn vừa yêu cầu đặt lại mật khẩu tại {{site_name}}. Nhập mã dưới đây để chọn mật khẩu mới.

{{code_block}}

Mã có hiệu lực trong {{ttl_minutes}} phút.

Nếu bạn không yêu cầu, hãy bỏ qua email này. Mật khẩu hiện tại vẫn giữ nguyên và không ai có thể đổi nó nếu không có mã trên.',
			),
			'add_identity'  => array(
				'group'     => 'otp',
				'intent'    => AuthAction::ADD_IDENTITY,
				'label'     => __( 'Xác minh liên hệ mới', 'omniwp' ),
				'when'      => __( 'Khi người dùng thêm hoặc đổi số điện thoại, email trong tài khoản.', 'omniwp' ),
				'preheader' => 'Mã xác minh liên hệ mới, hiệu lực {{ttl_minutes}} phút.',
				'tokens'    => self::OTP_TOKENS,
				'subject'   => 'Mã xác minh {{destination}} - {{site_name}}',
				'body'      => 'Xin chào,

Dùng mã dưới đây để xác minh {{destination}} cho tài khoản của bạn tại {{site_name}}.

{{code_block}}

Mã có hiệu lực trong {{ttl_minutes}} phút.

Nếu bạn không thêm liên hệ này, hãy kiểm tra lại tài khoản của mình — ai đó có thể đang cố gắn số điện thoại hoặc email của họ vào đó.',
			),
			'budget_halted' => array(
				'group'      => 'admin',
				'switchable' => true,
				'label'      => __( 'Cảnh báo chạm trần gửi', 'omniwp' ),
				'when'       => __( 'Gửi tới email quản trị một lần mỗi lần site chạm trần và tạm dừng gửi mã.', 'omniwp' ),
				'preheader'  => 'Site đã chạm trần gửi mã và đang tạm dừng.',
				'tokens'     => self::ADMIN_TOKENS,
				'subject'    => '[{{site_name}}] Đã tạm dừng gửi mã xác thực',
				'body'       => 'OmniWP đã chạm trần {{ceiling}} mã xác thực trong một {{window}} và tạm dừng gửi trong {{halt_minutes}} phút.

Trong lúc này không ai đăng ký hoặc đăng nhập bằng mã được.

Đây thường là dấu hiệu bị lạm dụng để đốt tin nhắn. Hãy xem lưu lượng gần đây trước khi nâng trần — nâng trần khi đang bị tấn công chỉ làm hoá đơn to hơn.

Nhật ký: {{site_url}}wp-admin/admin.php?page=omniwp-audit',
			),
			'breaker_open'  => array(
				'group'      => 'admin',
				'switchable' => true,
				'label'      => __( 'Cảnh báo ngắt mạch kênh gửi', 'omniwp' ),
				'when'       => __( 'Gửi một lần khi một kênh gửi bị tạm ngắt sau nhiều lần thất bại liên tiếp.', 'omniwp' ),
				'preheader'  => 'Một kênh gửi mã vừa bị tạm ngắt vì lỗi liên tiếp.',
				'tokens'     => self::ADMIN_TOKENS,
				'subject'    => '[{{site_name}}] Kênh gửi mã đang lỗi liên tục',
				'body'       => 'OmniWP đã tạm ngắt kênh "{{transport}}" sau nhiều lần gửi thất bại liên tiếp, và sẽ tự thử lại sau {{cooldown}} giây.

Trong lúc này người dùng không nhận được mã qua kênh đó.

Hãy kiểm tra nhà cung cấp, rồi dùng nút Gửi thử ở tab Gửi mã để xác nhận — nút đó không bị ngắt mạch chặn, nên nó là cách biết kênh đã sống lại chưa.

Cấu hình: {{site_url}}wp-admin/admin.php?page=omniwp&tab=delivery',
			),
		);

		/**
		 * Register additional messages.
		 *
		 * A row needs `group`, `label`, `tokens`, `subject` and `body`; `intent`
		 * only when an OTP should route to it. The settings are generated, so
		 * nothing else has to be edited to make one editable.
		 *
		 * @param array<string,array> $messages
		 */
		return (array) apply_filters( 'OMNIWP_mail_messages', $messages );
	}

	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * The settings the registry contributes, in FieldRegistry's own shape.
	 *
	 * Defaults are **empty**, not the row's text. Empty means "use the
	 * fallback"; pre-filling every box would make the fallback dead on arrival
	 * and turn one wording into five copies for the administrator to maintain.
	 *
	 * @return array<string,array>
	 */
	public static function fields(): array {
		$fields = array();

		foreach ( self::all() as $id => $row ) {
			if ( ! empty( $row['switchable'] ) ) {
				$fields[ self::PATH_PREFIX . $id . '.enabled' ] = array(
					'type'    => 'checkbox',
					'default' => 1,
					'tab'     => 'delivery-mail',
					'section' => self::section_for( $row ),
					/* translators: %s: message name. */
					'label'   => sprintf( __( '%s — gửi email', 'omniwp' ), $row['label'] ),
					'help'    => __( 'Tắt nếu bạn đã nhận sự kiện này qua tab Thông báo & Tích hợp. Nhật ký và sự kiện vẫn được ghi như cũ — đây chỉ là email.', 'omniwp' ),
				);
			}

			$fields[ self::PATH_PREFIX . $id . '.subject' ] = array(
				'type'    => 'text',
				'default' => '',
				'tab'     => 'delivery-mail',
				'section' => self::section_for( $row ),
				/* translators: %s: message name. */
				'label'   => sprintf( __( '%s — tiêu đề', 'omniwp' ), $row['label'] ),
				'help'    => $row['when'],
				// Read by FieldRenderer to show what this box inherits while it is
				// empty, and which tokens the message actually understands.
				'message' => $id,
				'part'    => 'subject',
			);

			$fields[ self::PATH_PREFIX . $id . '.body' ] = array(
				'type'     => 'textarea',
				'rows'     => 8,
				'default'  => '',
				'tab'      => 'delivery-mail',
				'section'  => self::section_for( $row ),
				/* translators: %s: message name. */
				'label'    => sprintf( __( '%s — nội dung', 'omniwp' ), $row['label'] ),
				'sanitize' => 'rich_text',
				'help'     => __( 'Để trống để dùng mẫu đang hiển thị mờ bên trong ô.', 'omniwp' ),
				'message'  => $id,
				'part'     => 'body',
			);
		}

		return $fields;
	}

	/**
	 * Which heading a message sits under.
	 *
	 * Grouped the way an administrator thinks — the codes their customers get,
	 * then the alerts that come to them — rather than the way the registry
	 * happens to store them.
	 */
	private static function section_for( array $row ): string {
		return 'admin' === ( $row['group'] ?? '' ) ? 'mail_admin' : 'templates';
	}

	/**
	 * The subject and body actually sent for a message.
	 *
	 * Three levels, and the middle one is what keeps every existing install on
	 * exactly the text it has today: the administrator's override for this
	 * message, then the shared pair they may already have edited, then the row's
	 * own default.
	 *
	 * @return array{subject:string,body:string}
	 */
	public static function resolve( string $id ): array {
		$row = self::get( $id );

		return array(
			'subject' => self::pick(
				(string) Settings::get( self::PATH_PREFIX . $id . '.subject', '' ),
				self::FALLBACK_SUBJECT,
				(string) ( $row['subject'] ?? '' )
			),
			'body'    => self::pick(
				(string) Settings::get( self::PATH_PREFIX . $id . '.body', '' ),
				self::FALLBACK_BODY,
				(string) ( $row['body'] ?? '' )
			),
		);
	}

	/**
	 * The message an OTP of this intent uses.
	 *
	 * An intent with no row — the admin tester sends `test` — resolves to the
	 * shared pair rather than to nothing, because a tester that cannot render is
	 * a tester nobody can use to check a gateway.
	 *
	 * @return array{subject:string,body:string}
	 */
	public static function resolve_intent( string $intent ): array {
		$id = self::id_for_intent( $intent );

		if ( '' !== $id ) {
			return self::resolve( $id );
		}

		return array(
			'subject' => (string) Settings::get( self::FALLBACK_SUBJECT, '' ),
			'body'    => (string) Settings::get( self::FALLBACK_BODY, '' ),
		);
	}

	/**
	 * Does this message have wording of its own?
	 *
	 * The question an administrator has on opening the mail screen, and until the
	 * list existed it could only be answered by reading twelve boxes. Reads the
	 * same stored values `resolve()` does, so the list cannot claim a message is
	 * inheriting while the transport disagrees.
	 */
	public static function is_overridden( string $id ): bool {
		foreach ( array( 'subject', 'body' ) as $part ) {
			if ( '' !== trim( (string) Settings::get( self::PATH_PREFIX . $id . '.' . $part, '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rows in the order the screen lists them, messages before alerts.
	 *
	 * @return array<string,array>
	 */
	public static function by_group(): array {
		$ordered = array();

		foreach ( array( 'otp', 'admin' ) as $group ) {
			foreach ( self::all() as $id => $row ) {
				if ( ( $row['group'] ?? '' ) === $group ) {
					$ordered[ $id ] = $row;
				}
			}
		}

		// A row from the filter with an unrecognised group still has to appear,
		// or it becomes a message nobody can edit.
		return $ordered + self::all();
	}

	/**
	 * The grey line an inbox shows after the subject.
	 *
	 * A registry row rather than a setting: one shared value would be wrong for
	 * six different messages, and six new fields would rebuild the wall Phase 13
	 * exists to remove. Reversible — it is one row key away from being editable.
	 *
	 * Without it, clients pull the first thing in the body, which is why every
	 * message this plugin sends currently previews as "Xin chào,".
	 */
	public static function preheader_for_intent( string $intent ): string {
		$id = self::id_for_intent( $intent );

		return '' === $id ? '' : (string) ( self::get( $id )['preheader'] ?? '' );
	}

	public static function id_for_intent( string $intent ): string {
		foreach ( self::all() as $id => $row ) {
			if ( ( $row['intent'] ?? '' ) === $intent ) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * Which tokens this message may use. Empty id means every token there is.
	 *
	 * @return string[]
	 */
	public static function tokens( string $id ): array {
		$row = self::get( $id );

		return $row ? (array) ( $row['tokens'] ?? array() ) : array();
	}

	/**
	 * Override, then a shared pair the administrator actually edited, then the
	 * message's own default.
	 *
	 * The middle level compares against the shared field's *registry default*,
	 * not against the empty string. The brief specified "override, else shared,
	 * else default" and that ordering is unusable: `email.subject` ships with a
	 * non-empty default, so the shared pair would always be non-empty and win,
	 * and no per-message default would ever be reachable. The whole phase would
	 * have shipped resolving every intent to the same wording it already had.
	 *
	 * Comparing against the declared default is what separates "the site has its
	 * own wording, keep using it everywhere" from "nobody has touched this".
	 *
	 * @param string $override    Stored value for this message.
	 * @param string $shared_path Registry path of the shared fallback field.
	 * @param string $own         The message's own default.
	 */
	private static function pick( string $override, string $shared_path, string $own ): string {
		if ( '' !== trim( $override ) ) {
			return $override;
		}

		$shared     = (string) Settings::get( $shared_path, '' );
		$ships_with = (string) ( \OmniWP\FieldRegistry::get( $shared_path )['default'] ?? '' );

		if ( '' !== trim( $shared ) && $shared !== $ships_with ) {
			return $shared;
		}

		return '' !== trim( $own ) ? $own : $shared;
	}
}
