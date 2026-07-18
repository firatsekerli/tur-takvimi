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
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Morning site time, so "we're coming" lands at a sensible hour.
			wp_schedule_event( strtotime( 'tomorrow 09:00', current_time( 'timestamp' ) ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Stored API settings with defaults.
	 *
	 * @return array{enabled:bool,token:string,phone_id:string,template:string,lang:string}
	 */
	public static function settings(): array {
		$s = (array) get_option( self::OPTION, array() );
		return array(
			'enabled'  => ! empty( $s['enabled'] ),
			'token'    => (string) ( $s['token'] ?? '' ),
			'phone_id' => (string) ( $s['phone_id'] ?? '' ),
			'template' => (string) ( $s['template'] ?? '' ),
			'lang'     => (string) ( $s['lang'] ?? 'tr' ),
		);
	}

	/**
	 * Settings section rendered inside the Tur Takvimi → WhatsApp page form.
	 */
	public static function settings_section(): void {
		$s = self::settings();
		?>
		<h2 style="margin-top:2rem"><?php esc_html_e( 'Delivery reminders (WhatsApp Cloud API)', 'tur-takvimi' ); ?></h2>
		<p class="description" style="max-width:50rem">
			<?php esc_html_e( 'Sends opted-in subscribers a template message 7 days and 2 days before a tour reaches their postcode. Requires a Meta WhatsApp Business account: create a message template with three variables ({{1}} name, {{2}} city, {{3}} date), get it approved, and paste your credentials here.', 'tur-takvimi' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable reminders', 'tur-takvimi' ); ?></th>
				<td><label><input type="checkbox" name="wa_api[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Send WhatsApp reminders automatically (daily)', 'tur-takvimi' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="tt_wa_token"><?php esc_html_e( 'Access token', 'tur-takvimi' ); ?></label></th>
				<td><input type="password" id="tt_wa_token" class="regular-text" name="wa_api[token]" value="<?php echo esc_attr( $s['token'] ); ?>" autocomplete="off">
				<p class="description"><?php esc_html_e( 'A permanent System User token from Meta Business Manager with whatsapp_business_messaging permission.', 'tur-takvimi' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="tt_wa_phone_id"><?php esc_html_e( 'Phone number ID', 'tur-takvimi' ); ?></label></th>
				<td><input type="text" id="tt_wa_phone_id" class="regular-text" name="wa_api[phone_id]" value="<?php echo esc_attr( $s['phone_id'] ); ?>">
				<p class="description"><?php esc_html_e( 'From WhatsApp → API Setup (the numeric ID, not the phone number itself).', 'tur-takvimi' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="tt_wa_template"><?php esc_html_e( 'Template name / language', 'tur-takvimi' ); ?></label></th>
				<td>
					<input type="text" id="tt_wa_template" name="wa_api[template]" value="<?php echo esc_attr( $s['template'] ); ?>" placeholder="teslimat_hatirlatma">
					<input type="text" name="wa_api[lang]" size="6" value="<?php echo esc_attr( $s['lang'] ); ?>" placeholder="tr" aria-label="<?php esc_attr_e( 'Template language code', 'tur-takvimi' ); ?>">
					<p class="description"><?php esc_html_e( 'The approved template and its language code (e.g. tr, de, nl). Body variables: {{1}} name, {{2}} city, {{3}} visit date.', 'tur-takvimi' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist the API settings submitted with the WhatsApp page form.
	 */
	public static function save_from_post(): void {
		// Caller (Whatsapp::save_page) has verified capability + nonce.
		$in = isset( $_POST['wa_api'] ) && is_array( $_POST['wa_api'] ) ? wp_unslash( $_POST['wa_api'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing

		$lang = strtolower( sanitize_text_field( (string) ( $in['lang'] ?? 'tr' ) ) );
		update_option(
			self::OPTION,
			array(
				'enabled'  => ! empty( $in['enabled'] ),
				'token'    => sanitize_text_field( (string) ( $in['token'] ?? '' ) ),
				'phone_id' => preg_replace( '/\D/', '', (string) ( $in['phone_id'] ?? '' ) ),
				'template' => sanitize_key( (string) ( $in['template'] ?? '' ) ),
				'lang'     => preg_match( '/^[a-z]{2}(_[A-Za-z]{2})?$/', $lang ) ? $lang : 'tr',
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
		$s = self::settings();
		if ( ! $s['enabled'] || '' === $s['token'] || '' === $s['phone_id'] || '' === $s['template'] ) {
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
	 * Send one approved-template message via the Cloud API.
	 *
	 * @param string   $to     Recipient phone (international digits, no +).
	 * @param string[] $params Body variables ({{1}}, {{2}}, {{3}}…).
	 * @return bool
	 */
	public function send_template( string $to, array $params ): bool {
		$this->last_error = '';
		$s                = self::settings();

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

		/**
		 * Short-circuit filter so a different provider (Twilio, 360dialog…)
		 * can take over sending. Return true/false to skip the Cloud API call.
		 *
		 * @param bool|null $handled Null when unhandled.
		 * @param string    $to      Recipient phone.
		 * @param array     $body    Prepared Cloud API payload.
		 */
		$handled = apply_filters( 'tur_takvimi_send_whatsapp', null, $to, $body );
		if ( null !== $handled ) {
			return (bool) $handled;
		}

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
