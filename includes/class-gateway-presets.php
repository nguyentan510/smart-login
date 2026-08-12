<?php
/**
 * Known SMS gateways, as configuration rather than documentation.
 *
 * The webhook tab asked for eleven fields, one of them a free-text JSON body —
 * the single most error-prone control in the plugin, and one whose mistakes only
 * surface after saving and pressing Gửi thử. The README carried a table of
 * worked examples per gateway, which is the clearest possible sign that the
 * knowledge belonged in the code.
 *
 * A preset supplies the URL, method, content type, body and success condition.
 * The administrator supplies only the credentials the gateway actually needs.
 * `{{cred:name}}` in any template is replaced at save time, so the values that
 * reach WebhookTransport are ordinary settings and nothing downstream has to
 * know presets exist.
 *
 * Adding a gateway is one entry here. Two ship: eSMS, whose parameters are the
 * ones this project has already verified against a live account, and a generic
 * JSON webhook that covers n8n, Make and Zapier, where the receiving end is
 * defined by whoever built it. Others are deliberately absent rather than
 * guessed at — a preset with the wrong parameter names is worse than no preset,
 * because it looks authoritative while failing.
 *
 * @package OmniWP
 */

namespace OmniWP;

defined( 'ABSPATH' ) || exit;

final class GatewayPresets {

	const CUSTOM = 'custom';

	/** A provider whose body is built in code and signed, not rendered from a template. */
	const ENVELOPE_SIGNED = 'signed';

	/**
	 * The wire format a provider speaks, or '' for the templated default.
	 *
	 * The one question a caller has to ask before deciding whether there is a
	 * body template at all.
	 */
	public static function envelope( string $slug ): string {
		return (string) ( self::get( $slug )['envelope'] ?? '' );
	}

	/**
	 * @return array<string,array>
	 */
	public static function all(): array {
		$presets = array(
			self::CUSTOM => array(
				'label'       => __( 'Tuỳ chỉnh — tự khai báo API', 'omniwp' ),
				'credentials' => array(),
			),
			'esms'       => array(
				'label'         => 'eSMS.vn',
				'url'           => 'https://rest.esms.vn/MainService.svc/json/SendMultipleMessage_V4_post_json/',
				'method'        => 'POST',
				'content_type'  => 'application/json',
				'body'          => '{"ApiKey":"{{cred:api_key}}","SecretKey":"{{cred:secret_key}}","Brandname":"{{cred:brandname}}","SmsType":"2","Phone":"{{phone_local}}","Content":"{{code}} la ma xac thuc cua ban tai {{site_name}}. Ma co hieu luc {{ttl_minutes}} phut."}',
				'success_path'  => 'CodeResult',
				'success_value' => '100',
				'credentials'   => array(
					'api_key'    => array(
						'label'  => 'ApiKey',
						'secret' => false,
					),
					'secret_key' => array(
						'label'  => 'SecretKey',
						'secret' => true,
					),
					'brandname'  => array(
						'label'  => __( 'Brandname đã đăng ký', 'omniwp' ),
						'secret' => false,
					),
				),
			),
			// Not a template, and therefore not shaped like its neighbours. A
			// signed provider declares an envelope instead of a body, because the
			// payload is built in code where it is signed — see D2 in
			// docs/sending-a-code.md for the four controls a template loses. Its
			// endpoint and key are ordinary fields rather than credentials, so
			// they can carry `https_url` and `secret`, which this array cannot.
			'signed'     => array(
				'label'       => __( 'Envelope ký HMAC (chuẩn OmniWP)', 'omniwp' ),
				'envelope'    => self::ENVELOPE_SIGNED,
				'credentials' => array(),
			),
			'generic'    => array(
				'label'        => __( 'Gửi JSON tới endpoint của bạn (n8n / Make / Zapier)', 'omniwp' ),
				'url'          => '{{cred:endpoint}}',
				'method'       => 'POST',
				'content_type' => 'application/json',
				'body'         => '{"phone":"{{phone_local}}","code":"{{code}}","minutes":"{{ttl_minutes}}","site":"{{site_name}}"}',
				'credentials'  => array(
					'endpoint' => array(
						'label'  => __( 'URL nhận yêu cầu', 'omniwp' ),
						'secret' => false,
					),
				),
			),
		);

		/**
		 * Register another SMS gateway preset.
		 *
		 * @param array $presets
		 */
		return (array) apply_filters( 'omniwp_gateway_presets', $presets );
	}

	/**
	 * @return array<string,string> Slug => label, for the select.
	 */
	public static function choices(): array {
		return array_map(
			static fn( array $preset ): string => (string) $preset['label'],
			self::all()
		);
	}

	public static function get( string $slug ): array {
		return self::all()[ $slug ] ?? self::all()[ self::CUSTOM ];
	}

	public static function is_custom( string $slug ): bool {
		return self::CUSTOM === $slug || ! isset( self::all()[ $slug ] );
	}

	/**
	 * The credential inputs a preset asks for.
	 *
	 * @return array<string,array{label:string,secret:bool}>
	 */
	public static function credentials( string $slug ): array {
		return (array) ( self::get( $slug )['credentials'] ?? array() );
	}

	/**
	 * Turn a preset plus its credentials into the concrete webhook settings.
	 *
	 * Returns dot path => value, ready to be planted onto the option. Only the
	 * transport fields are produced; nothing here touches whether the channel is
	 * enabled or how long it may take.
	 *
	 * @param string               $slug        Preset slug.
	 * @param array<string,string> $credentials Name => value, already sanitised.
	 * @return array<string,mixed>
	 */
	public static function resolve( string $slug, array $credentials ): array {
		// A signed provider derives nothing, for the same reason it is not a
		// preset: there is no body template to fill. Writing empty strings over
		// `sms.url` and friends would also make the derived-values panel show a
		// blank request that never gets sent.
		if ( self::is_custom( $slug ) || '' !== self::envelope( $slug ) ) {
			return array();
		}

		$preset = self::get( $slug );
		$out    = array();

		foreach (
			array(
				'url'           => 'sms.url',
				'method'        => 'sms.method',
				'content_type'  => 'sms.content_type',
				'body'          => 'sms.body',
				'success_path'  => 'sms.success_path',
				'success_value' => 'sms.success_value',
			) as $key => $path
		) {
			$out[ $path ] = self::fill( (string) ( $preset[ $key ] ?? '' ), $credentials );
		}

		$headers = array();

		foreach ( (array) ( $preset['headers'] ?? array() ) as $name => $value ) {
			$headers[] = array(
				'key'   => (string) $name,
				'value' => self::fill( (string) $value, $credentials ),
			);
		}

		$out['sms.headers'] = $headers;

		return $out;
	}

	/**
	 * Replace `{{cred:name}}`. Unknown names become empty rather than being left
	 * in place, so a half-filled preset produces a request that visibly fails
	 * instead of one carrying a literal placeholder to the gateway.
	 *
	 * @param string               $template    Text containing `{{cred:name}}`.
	 * @param array<string,string> $credentials Name => value.
	 */
	private static function fill( string $template, array $credentials ): string {
		return (string) preg_replace_callback(
			'/\{\{cred:([a-z0-9_]+)\}\}/',
			static fn( array $found ): string => (string) ( $credentials[ $found[1] ] ?? '' ),
			$template
		);
	}
}
