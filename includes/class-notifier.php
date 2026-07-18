<?php
/**
 * WhatsApp delivery reminders via the WhatsApp Business Cloud API (Meta).
 *
 * A daily job matches each opted-in subscriber's postcode to its nearest
 * location and sends a pre-approved template message when a tour is due
 * there in exactly 7 days and again 2 days before (offsets filterable).
 *
 * Business-initiated WhatsApp messages MUST use a template approved in the
 * Meta Business Manager; free-form text is only allowed inside a 24-hour
 * customer-service window. The template name/language are configured on the
 * Tur Takvimi → WhatsApp page.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Cloud API settings, the daily reminder cron and the sender.
 */
class Notifier {

	const OPTION    = 'tur_takvimi_wa_api';
	const CRON_HOOK = 'tur_takvimi_daily_notify';

	/**
	 * Max messages sent per daily run (filterable) — a safety valve for the
	 * provider rate limit and the cron request duration.
	 */
	const BATCH = 200;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_daily' ) );
		add_action( 'wp_ajax_tur_takvimi_wa_test', array( $this, 'ajax_test' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Morning site time, so "we're coming" lands at a sensible hour.
			wp_schedule_event( strtotime( 'tomorrow 09:00', current_time( 'timestamp' ) ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Stored API settings with defaults.
	 *
	 * @return array{enabled:bool,provider:string,token:string,phone_id:string,template:string,lang:string,twilio_sid:string,twilio_token:string,twilio_from:string,twilio_content_sid:string}
	 */
	public static function settings(): array {
		$s        = (array) get_option( self::OPTION, array() );
		$provider = (string) ( $s['provider'] ?? 'cloud' );
		return array(
			'enabled'            => ! empty( $s['enabled'] ),
			'provider'           => in_array( $provider, array( 'cloud', 'twilio' ), true ) ? $provider : 'cloud',
			'token'              => (string) ( $s['token'] ?? '' ),
			'phone_id'           => (string) ( $s['phone_id'] ?? '' ),
			'template'           => (string) ( $s['template'] ?? '' ),
			'lang'               => (string) ( $s['lang'] ?? 'tr' ),
			'twilio_sid'         => (string) ( $s['twilio_sid'] ?? '' ),
			'twilio_token'       => (string) ( $s['twilio_token'] ?? '' ),
			'twilio_from'        => (string) ( $s['twilio_from'] ?? '' ),
			'twilio_content_sid' => (string) ( $s['twilio_content_sid'] ?? '' ),
		);
	}

	/**
	 * Whether the active provider has every credential it needs (regardless
	 * of the enabled toggle — used by the manual test send too).
	 */
	public static function has_credentials(): bool {
		$s = self::settings();
		if ( 'twilio' === $s['provider'] ) {
			return '' !== $s['twilio_sid'] && '' !== $s['twilio_token'] && '' !== $s['twilio_from'] && '' !== $s['twilio_content_sid'];
		}
		return '' !== $s['token'] && '' !== $s['phone_id'] && '' !== $s['template'];
	}

	/**
	 * Whether reminders are enabled and the active provider has every
	 * credential it needs.
	 */
	public static function configured(): bool {
		return ! empty( self::settings()['enabled'] ) && self::has_credentials();
	}

	/**
	 * Send one immediate test message to a typed number using the saved
	 * settings, so the provider setup can be verified without waiting for the
	 * daily cron. Returns the provider's exact error on failure.
	 */
	public function ajax_test(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'tt_wa_test', '_wpnonce', false ) ) {
			wp_send_json(
				array(
					'ok'    => false,
					'error' => 'auth',
				),
				403
			);
		}

		$raw = isset( $_GET['phone'] ) ? sanitize_text_field( wp_unslash( $_GET['phone'] ) ) : '';
		$to  = self::normalize_phone( $raw, Country::default_code() );
		if ( '' === $to ) {
			wp_send_json(
				array(
					'ok'    => false,
					'error' => __( 'Enter a valid phone number in international format.', 'tur-takvimi' ),
				)
			);
		}
		if ( ! self::has_credentials() ) {
			wp_send_json(
				array(
					'ok'    => false,
					'error' => __( 'Fill in and save the provider settings first.', 'tur-takvimi' ),
				)
			);
		}

		$user = wp_get_current_user();
		try {
			$date = ( new \DateTimeImmutable( current_time( 'Y-m-d' ) ) )->modify( '+2 days' )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			$date = current_time( 'Y-m-d' );
		}
		$ok = $this->send_template(
			$to,
			array(
				$user && '' !== $user->display_name ? $user->display_name : 'Test',
				__( 'Test City', 'tur-takvimi' ),
				Rest_Api::format_day( $date ),
			)
		);

		wp_send_json(
			array(
				'ok'    => $ok,
				'error' => $ok ? '' : $this->last_error,
			)
		);
	}

	/**
	 * Settings section rendered inside the Tur Takvimi → WhatsApp page form.
	 */
	public static function settings_section(): void {
		$s = self::settings();
		?>
		<h2 style="margin-top:2rem"><?php esc_html_e( 'Delivery reminders (WhatsApp)', 'tur-takvimi' ); ?></h2>
		<p class="description" style="max-width:50rem">
			<?php esc_html_e( 'Sends opted-in subscribers a template message 7 days and 2 days before a tour reaches their postcode. Create a WhatsApp template with three variables ({{1}} name, {{2}} city, {{3}} date), get it approved, then connect either the Meta Cloud API directly or your Twilio account.', 'tur-takvimi' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable reminders', 'tur-takvimi' ); ?></th>
				<td><label><input type="checkbox" name="wa_api[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Send WhatsApp reminders automatically (daily)', 'tur-takvimi' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="tt_wa_provider"><?php esc_html_e( 'Provider', 'tur-takvimi' ); ?></label></th>
				<td>
					<select id="tt_wa_provider" name="wa_api[provider]">
						<option value="cloud" <?php selected( $s['provider'], 'cloud' ); ?>><?php esc_html_e( 'Meta Cloud API (direct)', 'tur-takvimi' ); ?></option>
						<option value="twilio" <?php selected( $s['provider'], 'twilio' ); ?>>Twilio</option>
					</select>
				</td>
			</tr>

			<tr class="tt-wa-cloud">
				<th scope="row"><label for="tt_wa_token"><?php esc_html_e( 'Access token', 'tur-takvimi' ); ?></label></th>
				<td><input type="password" id="tt_wa_token" class="regular-text" name="wa_api[token]" value="<?php echo esc_attr( $s['token'] ); ?>" autocomplete="off">
				<p class="description"><?php esc_html_e( 'A permanent System User token from Meta Business Manager with whatsapp_business_messaging permission.', 'tur-takvimi' ); ?></p></td>
			</tr>
			<tr class="tt-wa-cloud">
				<th scope="row"><label for="tt_wa_phone_id"><?php esc_html_e( 'Phone number ID', 'tur-takvimi' ); ?></label></th>
				<td><input type="text" id="tt_wa_phone_id" class="regular-text" name="wa_api[phone_id]" value="<?php echo esc_attr( $s['phone_id'] ); ?>">
				<p class="description"><?php esc_html_e( 'From WhatsApp → API Setup (the numeric ID, not the phone number itself).', 'tur-takvimi' ); ?></p></td>
			</tr>
			<tr class="tt-wa-cloud">
				<th scope="row"><label for="tt_wa_template"><?php esc_html_e( 'Template name / language', 'tur-takvimi' ); ?></label></th>
				<td>
					<input type="text" id="tt_wa_template" name="wa_api[template]" value="<?php echo esc_attr( $s['template'] ); ?>" placeholder="teslimat_hatirlatma">
					<input type="text" name="wa_api[lang]" size="6" value="<?php echo esc_attr( $s['lang'] ); ?>" placeholder="tr" aria-label="<?php esc_attr_e( 'Template language code', 'tur-takvimi' ); ?>">
					<p class="description"><?php esc_html_e( 'The approved template and its language code (e.g. tr, de, nl). Body variables: {{1}} name, {{2}} city, {{3}} visit date.', 'tur-takvimi' ); ?></p>
				</td>
			</tr>

			<tr class="tt-wa-twilio">
				<th scope="row"><label for="tt_wa_twilio_sid"><?php esc_html_e( 'Account SID', 'tur-takvimi' ); ?></label></th>
				<td><input type="text" id="tt_wa_twilio_sid" class="regular-text" name="wa_api[twilio_sid]" value="<?php echo esc_attr( $s['twilio_sid'] ); ?>" placeholder="AC…">
				<p class="description"><?php esc_html_e( 'From the Twilio Console dashboard.', 'tur-takvimi' ); ?></p></td>
			</tr>
			<tr class="tt-wa-twilio">
				<th scope="row"><label for="tt_wa_twilio_token"><?php esc_html_e( 'Auth token', 'tur-takvimi' ); ?></label></th>
				<td><input type="password" id="tt_wa_twilio_token" class="regular-text" name="wa_api[twilio_token]" value="<?php echo esc_attr( $s['twilio_token'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr class="tt-wa-twilio">
				<th scope="row"><label for="tt_wa_twilio_from"><?php esc_html_e( 'WhatsApp sender number', 'tur-takvimi' ); ?></label></th>
				<td><input type="text" id="tt_wa_twilio_from" name="wa_api[twilio_from]" value="<?php echo esc_attr( $s['twilio_from'] ); ?>" placeholder="+4915123456789">
				<p class="description"><?php esc_html_e( 'Your Twilio WhatsApp-enabled number in international format — or the Twilio Sandbox number while testing.', 'tur-takvimi' ); ?></p></td>
			</tr>
			<tr class="tt-wa-twilio">
				<th scope="row"><label for="tt_wa_twilio_content"><?php esc_html_e( 'Content template SID', 'tur-takvimi' ); ?></label></th>
				<td><input type="text" id="tt_wa_twilio_content" class="regular-text" name="wa_api[twilio_content_sid]" value="<?php echo esc_attr( $s['twilio_content_sid'] ); ?>" placeholder="HX…">
				<p class="description"><?php esc_html_e( 'The approved template\'s Content SID from Twilio\'s Content Template Builder (starts with HX). Use variables {{1}} name, {{2}} city, {{3}} visit date.', 'tur-takvimi' ); ?></p></td>
			</tr>

			<tr>
				<th scope="row"><label for="tt_wa_test_phone"><?php esc_html_e( 'Test message', 'tur-takvimi' ); ?></label></th>
				<td>
					<input type="text" id="tt_wa_test_phone" placeholder="+49 172 1234567" data-tt-wa-test-nonce="<?php echo esc_attr( wp_create_nonce( 'tt_wa_test' ) ); ?>">
					<button type="button" class="button" id="tt_wa_test_btn"><?php esc_html_e( 'Send test message', 'tur-takvimi' ); ?></button>
					<p class="description"><?php esc_html_e( 'Sends one template message with sample values using the saved settings above — save your changes first. With the Meta test number, the recipient must be one of your (up to 5) verified numbers.', 'tur-takvimi' ); ?></p>
					<p id="tt_wa_test_result" style="font-weight:600;"></p>
				</td>
			</tr>
		</table>
		<script>
		( function () {
			var btn = document.getElementById( 'tt_wa_test_btn' );
			var input = document.getElementById( 'tt_wa_test_phone' );
			var out = document.getElementById( 'tt_wa_test_result' );
			if ( ! btn || ! input || ! out ) {
				return;
			}
			var i18n = {
				sending: <?php echo wp_json_encode( __( 'Sending…', 'tur-takvimi' ) ); ?>,
				sent: <?php echo wp_json_encode( __( 'Test message sent — check the phone.', 'tur-takvimi' ) ); ?>,
				failed: <?php echo wp_json_encode( __( 'Sending failed:', 'tur-takvimi' ) ); ?>
			};
			btn.addEventListener( 'click', function () {
				var phone = input.value.trim();
				if ( ! phone ) {
					input.focus();
					return;
				}
				btn.disabled = true;
				out.style.color = '';
				out.textContent = i18n.sending;
				var url = ajaxurl + '?action=tur_takvimi_wa_test&_wpnonce=' + encodeURIComponent( input.getAttribute( 'data-tt-wa-test-nonce' ) ) + '&phone=' + encodeURIComponent( phone );
				fetch( url, { credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( d ) {
						btn.disabled = false;
						if ( d && d.ok ) {
							out.style.color = '#166534';
							out.textContent = i18n.sent;
						} else {
							out.style.color = '#b91c1c';
							out.textContent = i18n.failed + ' ' + ( ( d && d.error ) || 'unknown' );
						}
					} )
					.catch( function () {
						btn.disabled = false;
						out.style.color = '#b91c1c';
						out.textContent = i18n.failed + ' network';
					} );
			} );
		}() );
		</script>
		<script>
		( function () {
			var sel = document.getElementById( 'tt_wa_provider' );
			if ( ! sel ) {
				return;
			}
			function toggle() {
				var twilio = 'twilio' === sel.value;
				Array.prototype.forEach.call( document.querySelectorAll( '.tt-wa-cloud' ), function ( r ) {
					r.style.display = twilio ? 'none' : '';
				} );
				Array.prototype.forEach.call( document.querySelectorAll( '.tt-wa-twilio' ), function ( r ) {
					r.style.display = twilio ? '' : 'none';
				} );
			}
			sel.addEventListener( 'change', toggle );
			toggle();
		}() );
		</script>
		<?php
	}

	/**
	 * Persist the API settings submitted with the WhatsApp page form.
	 */
	public static function save_from_post(): void {
		// Caller (Whatsapp::save_page) has verified capability + nonce.
		$in = isset( $_POST['wa_api'] ) && is_array( $_POST['wa_api'] ) ? wp_unslash( $_POST['wa_api'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing

		// Canonicalize the template language: "en", "en_US" and "en-us" are all
		// accepted and stored in Meta's xx_XX form (codes are case-sensitive).
		$lang = sanitize_text_field( (string) ( $in['lang'] ?? 'tr' ) );
		if ( preg_match( '/^([A-Za-z]{2,3})(?:[_-]([A-Za-z]{2}))?$/', $lang, $m ) ) {
			$lang = strtolower( $m[1] ) . ( isset( $m[2] ) && '' !== $m[2] ? '_' . strtoupper( $m[2] ) : '' );
		} else {
			$lang = 'tr';
		}
		$provider = sanitize_key( (string) ( $in['provider'] ?? 'cloud' ) );
		$from     = preg_replace( '/[^0-9+]/', '', (string) ( $in['twilio_from'] ?? '' ) );
		update_option(
			self::OPTION,
			array(
				'enabled'            => ! empty( $in['enabled'] ),
				'provider'           => in_array( $provider, array( 'cloud', 'twilio' ), true ) ? $provider : 'cloud',
				'token'              => sanitize_text_field( (string) ( $in['token'] ?? '' ) ),
				'phone_id'           => preg_replace( '/\D/', '', (string) ( $in['phone_id'] ?? '' ) ),
				'template'           => sanitize_key( (string) ( $in['template'] ?? '' ) ),
				'lang'               => $lang,
				'twilio_sid'         => sanitize_text_field( (string) ( $in['twilio_sid'] ?? '' ) ),
				'twilio_token'       => sanitize_text_field( (string) ( $in['twilio_token'] ?? '' ) ),
				'twilio_from'        => $from,
				'twilio_content_sid' => sanitize_text_field( (string) ( $in['twilio_content_sid'] ?? '' ) ),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Phone normalization
	 * --------------------------------------------------------------------- */

	/**
	 * Normalize a typed phone number to international digits (no +) for the
	 * Cloud API. A national number (leading 0) gets the country calling code.
	 *
	 * @param string $raw     Typed phone number.
	 * @param string $country ISO-2 country used for national numbers.
	 * @return string Digits, or '' when clearly invalid.
	 */
	public static function normalize_phone( string $raw, string $country ): string {
		$raw = preg_replace( '/[\s\-().\/]/', '', trim( $raw ) );
		if ( '' === $raw ) {
			return '';
		}

		if ( 0 === strpos( $raw, '+' ) ) {
			$digits = preg_replace( '/\D/', '', $raw );
		} elseif ( 0 === strpos( $raw, '00' ) ) {
			$digits = preg_replace( '/\D/', '', substr( $raw, 2 ) );
		} elseif ( 0 === strpos( $raw, '0' ) ) {
			$codes = self::calling_codes();
			$cc    = (string) ( $codes[ strtoupper( $country ) ] ?? '' );
			if ( '' === $cc ) {
				return '';
			}
			$digits = $cc . preg_replace( '/\D/', '', substr( $raw, 1 ) );
		} else {
			// Assume the country code is already included.
			$digits = preg_replace( '/\D/', '', $raw );
		}

		return strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? $digits : '';
	}

	/**
	 * ISO-2 → international calling code map (filterable for new markets).
	 *
	 * @return array<string,string>
	 */
	public static function calling_codes(): array {
		return apply_filters(
			'tur_takvimi_calling_codes',
			array(
				'DE' => '49',
				'NL' => '31',
				'BE' => '32',
				'FR' => '33',
				'AT' => '43',
				'LU' => '352',
				'TR' => '90',
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Daily job
	 * --------------------------------------------------------------------- */

	/**
	 * Notify log table name.
	 */
	private static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tt_notify_log';
	}

	/**
	 * Match opted-in subscribers to upcoming visits and send the reminders.
	 */
	public function run_daily(): void {
		if ( ! self::configured() ) {
			return;
		}

		/**
		 * Days-before offsets for the reminders (default: a week before and
		 * two days before the visit).
		 *
		 * @param int[] $offsets Days before the tour date.
		 */
		$offsets = (array) apply_filters( 'tur_takvimi_notify_offsets', array( 7, 2 ) );
		$offsets = array_values( array_unique( array_map( 'intval', $offsets ) ) );

		try {
			$today = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
		} catch ( \Exception $e ) {
			return;
		}
		$targets = array(); // 'Y-m-d' => kind ('7d', '2d').
		foreach ( $offsets as $off ) {
			$targets[ $today->modify( '+' . $off . ' days' )->format( 'Y-m-d' ) ] = $off . 'd';
		}

		global $wpdb;
		$subs = $wpdb->get_results(
			'SELECT * FROM ' . Subscribers::table() . " WHERE wa_optin = 1 AND phone <> ''", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! $subs ) {
			return;
		}

		$schedule = new Schedule();
		$resolved = array(); // postcode|country => nearest result (or null) shared across subscribers.
		$sent     = 0;
		$limit    = (int) apply_filters( 'tur_takvimi_notify_batch', self::BATCH );

		foreach ( $subs as $sub ) {
			if ( $sent >= $limit ) {
				break;
			}

			$key = strtoupper( $sub['country'] . '|' . $sub['postcode'] );
			if ( ! array_key_exists( $key, $resolved ) ) {
				$resolved[ $key ] = Postcode::nearest( (string) $sub['postcode'], (string) $sub['country'] );
			}
			$nearest = $resolved[ $key ];
			if ( ! $nearest ) {
				continue; // Postcode not covered (yet).
			}

			$location_id = (int) $nearest['location_id'];
			$upcoming    = $schedule->upcoming_tours_for_location( $location_id, 12 );

			foreach ( $upcoming as $date ) {
				if ( ! isset( $targets[ $date ] ) || $sent >= $limit ) {
					continue;
				}

				// Claim the (subscriber, date, kind) slot first — the unique key
				// makes double-sends impossible across overlapping runs.
				$claimed = $wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO ' . self::log_table() . ' (subscriber_id, location_id, tour_date, kind, status, sent) VALUES (%d, %d, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL
						(int) $sub['id'],
						$location_id,
						$date,
						$targets[ $date ],
						'pending',
						current_time( 'mysql' )
					)
				);
				if ( 1 !== (int) $claimed ) {
					continue; // Already sent (or claimed) earlier.
				}

				$ok = $this->send_template(
					(string) $sub['phone'],
					array(
						(string) $sub['name'],
						(string) $nearest['title'],
						Rest_Api::format_day( $date ),
					)
				);

				$wpdb->update(
					self::log_table(),
					array(
						'status' => $ok ? 'sent' : 'failed',
						'detail' => $ok ? '' : substr( $this->last_error, 0, 200 ),
					),
					array(
						'subscriber_id' => (int) $sub['id'],
						'tour_date'     => $date,
						'kind'          => $targets[ $date ],
					)
				);

				++$sent;
				usleep( 200000 ); // Stay far below the Cloud API rate limit.
			}
		}
	}

	/**
	 * Last send error (for the log detail column).
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Send one approved-template message via the configured provider.
	 *
	 * @param string   $to     Recipient phone (international digits, no +).
	 * @param string[] $params Body variables ({{1}}, {{2}}, {{3}}…).
	 * @return bool
	 */
	public function send_template( string $to, array $params ): bool {
		$this->last_error = '';
		$s                = self::settings();

		/**
		 * Short-circuit filter so a custom provider (360dialog, MessageBird…)
		 * can take over sending. Return true/false to skip the built-in call.
		 *
		 * @param bool|null $handled  Null when unhandled.
		 * @param string    $to       Recipient phone (international digits, no +).
		 * @param string[]  $params   Template body variables in order.
		 * @param string    $provider The configured provider slug.
		 */
		$handled = apply_filters( 'tur_takvimi_send_whatsapp', null, $to, $params, $s['provider'] );
		if ( null !== $handled ) {
			return (bool) $handled;
		}

		return 'twilio' === $s['provider']
			? $this->send_twilio( $to, $params, $s )
			: $this->send_cloud( $to, $params, $s );
	}

	/**
	 * Meta WhatsApp Business Cloud API sender.
	 *
	 * @param string   $to     Recipient phone (international digits, no +).
	 * @param string[] $params Body variables.
	 * @param array    $s      Settings.
	 * @return bool
	 */
	private function send_cloud( string $to, array $params, array $s ): bool {
		$body = array(
			'messaging_product' => 'whatsapp',
			'to'                => $to,
			'type'              => 'template',
			'template'          => array(
				'name'       => $s['template'],
				'language'   => array( 'code' => $s['lang'] ),
				'components' => array(
					array(
						'type'       => 'body',
						'parameters' => array_map(
							static fn( $p ) => array(
								'type' => 'text',
								'text' => (string) $p,
							),
							$params
						),
					),
				),
			),
		);

		$url      = 'https://graph.facebook.com/v21.0/' . rawurlencode( $s['phone_id'] ) . '/messages';
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $s['token'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		return $this->check_response( $response );
	}

	/**
	 * Twilio sender: an approved Content Template addressed by its SID, with
	 * variables as a {"1": …} map (Twilio's Content API shape).
	 *
	 * @param string   $to     Recipient phone (international digits, no +).
	 * @param string[] $params Body variables.
	 * @param array    $s      Settings.
	 * @return bool
	 */
	private function send_twilio( string $to, array $params, array $s ): bool {
		$vars = array();
		foreach ( array_values( $params ) as $i => $p ) {
			$vars[ (string) ( $i + 1 ) ] = (string) $p;
		}

		$from = $s['twilio_from'];
		if ( 0 !== strpos( $from, '+' ) ) {
			$from = '+' . preg_replace( '/\D/', '', $from );
		}

		$response = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $s['twilio_sid'] ) . '/Messages.json',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $s['twilio_sid'] . ':' . $s['twilio_token'] ),
				),
				'body'    => array(
					'To'               => 'whatsapp:+' . $to,
					'From'             => 'whatsapp:' . $from,
					'ContentSid'       => $s['twilio_content_sid'],
					'ContentVariables' => wp_json_encode( $vars ),
				),
			)
		);
		return $this->check_response( $response );
	}

	/**
	 * Evaluate a provider HTTP response, recording the error detail on failure.
	 *
	 * @param array|\WP_Error $response wp_remote_post() result.
	 * @return bool
	 */
	private function check_response( $response ): bool {
		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$this->last_error = 'HTTP ' . $status . ': ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 300 );
			return false;
		}
		return true;
	}
}
