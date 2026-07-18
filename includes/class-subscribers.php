<?php
/**
 * Delivery-reminder subscribers.
 *
 * The [tur_takvimi_signup] inline form collects name, email, phone and
 * postcode with an explicit WhatsApp opt-in. Subscribers are stored in the
 * wp_tt_subscribers table; the Notifier matches their postcode to upcoming
 * tour dates and sends the "we are coming" WhatsApp reminders.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Signup form, subscriber storage, REST endpoint and the admin list.
 */
class Subscribers {

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_shortcode( 'tur_takvimi_signup', array( $this, 'form' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_unsubscribe' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_menu' ), 25 );
			add_action( 'admin_post_tur_takvimi_subscriber_delete', array( $this, 'handle_delete' ) );
			add_action( 'admin_post_tur_takvimi_subscribers_export', array( $this, 'handle_export' ) );
		}
	}

	/**
	 * Subscribers table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tt_subscribers';
	}

	/* --------------------------------------------------------------------- *
	 * Front end: [tur_takvimi_signup]
	 * --------------------------------------------------------------------- */

	/**
	 * Render the inline signup form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function form( $atts ): string {
		$atts = shortcode_atts(
			array(
				'country' => '',
				'heading' => null,
				'class'   => '',
				'align'   => '',
			),
			$atts,
			'tur_takvimi_signup'
		);

		wp_enqueue_style( 'tur-takvimi' );
		wp_enqueue_script( 'tur-takvimi' );

		$heading = Shortcodes::heading_attr( $atts['heading'], __( 'Get a WhatsApp heads-up before we visit your area', 'tur-takvimi' ) );
		$align   = Shortcodes::align_value( $atts['align'] );
		$country = strtoupper( (string) $atts['country'] );
		$example = Country::example( '' !== $country ? $country : Country::default_code() );

		ob_start();
		?>
		<section class="tt-signup<?php echo esc_attr( Shortcodes::extra_class( $atts['class'] ) ); ?>" data-tt-signup data-country="<?php echo esc_attr( $country ); ?>"<?php echo '' !== $align ? ' data-tt-align="' . esc_attr( $align ) . '"' : ''; ?>>
			<?php if ( '' !== $heading ) : ?>
				<h2 class="tt-signup__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<form class="tt-signup__form" novalidate>
				<div class="tt-signup__grid">
					<div class="tt-signup__field">
						<label class="tt-signup__label" for="tt-su-name"><?php esc_html_e( 'Your name', 'tur-takvimi' ); ?></label>
						<input id="tt-su-name" class="tt-signup__input" type="text" autocomplete="name" data-tt-su-name required>
					</div>
					<div class="tt-signup__field">
						<label class="tt-signup__label" for="tt-su-email"><?php esc_html_e( 'Email', 'tur-takvimi' ); ?></label>
						<input id="tt-su-email" class="tt-signup__input" type="email" autocomplete="email" data-tt-su-email required>
					</div>
					<div class="tt-signup__field tt-signup__field--phone">
						<label class="tt-signup__label" for="tt-su-phone"><?php esc_html_e( 'Phone (WhatsApp)', 'tur-takvimi' ); ?></label>
						<input id="tt-su-phone" class="tt-signup__input" type="tel" autocomplete="tel" placeholder="+49 172 1234567" data-tt-su-phone required>
					</div>
					<div class="tt-signup__field tt-signup__field--pc">
						<label class="tt-signup__label" for="tt-su-postcode"><?php esc_html_e( 'Postcode', 'tur-takvimi' ); ?></label>
						<input id="tt-su-postcode" class="tt-signup__input" type="text" inputmode="numeric" autocomplete="postal-code" placeholder="<?php echo esc_attr( $example ); ?>" data-tt-su-postcode required>
					</div>
					<div class="tt-signup__field tt-signup__field--submit">
						<button type="submit" class="tt-signup__button"><?php esc_html_e( 'Sign up', 'tur-takvimi' ); ?></button>
					</div>
				</div>

				<?php // Honeypot: hidden from humans; a filled value means a bot. ?>
				<input type="text" name="website" class="tt-signup__hp" tabindex="-1" autocomplete="off" aria-hidden="true" data-tt-su-hp>

				<div class="tt-signup__consent">
					<label class="tt-signup__optin">
						<input type="checkbox" data-tt-su-optin>
						<span><?php esc_html_e( 'Notify me on WhatsApp before deliveries in my area.', 'tur-takvimi' ); ?></span>
					</label>
					<span class="tt-signup__note"><?php esc_html_e( 'We only use your details for delivery notifications. Unsubscribe anytime.', 'tur-takvimi' ); ?></span>
				</div>
			</form>
			<div class="tt-signup__result" data-tt-su-result aria-live="polite"></div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/* --------------------------------------------------------------------- *
	 * REST: POST /subscribe
	 * --------------------------------------------------------------------- */

	/**
	 * Register the public subscribe endpoint.
	 */
	public function routes(): void {
		register_rest_route(
			Rest_Api::NS,
			'/subscribe',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'subscribe' ),
				'args'                => array(
					'name'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'phone'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'postcode' => array(
						'type'     => 'string',
						'required' => true,
					),
					'country'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'optin'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'website'  => array( // Honeypot.
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Handle a signup submission.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function subscribe( \WP_REST_Request $req ): \WP_REST_Response {
		// A filled honeypot means a bot: pretend success, store nothing.
		if ( '' !== trim( (string) $req->get_param( 'website' ) ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => true,
					'message' => __( 'Thanks! You are signed up.', 'tur-takvimi' ),
				)
			);
		}

		// Light per-IP rate limit against form abuse.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'tt_sub_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 8 ) {
			return $this->error( __( 'Too many attempts. Please try again later.', 'tur-takvimi' ), 429 );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$name  = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$raw_postcode = sanitize_text_field( (string) $req->get_param( 'postcode' ) );
		$optin = (bool) $req->get_param( 'optin' );

		if ( '' === $name ) {
			return $this->error( __( 'Please enter your name.', 'tur-takvimi' ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return $this->error( __( 'Please enter a valid email address.', 'tur-takvimi' ) );
		}
		if ( '' === trim( $raw_postcode ) ) {
			return $this->error( __( 'Please enter your postcode.', 'tur-takvimi' ) );
		}

		// Country: explicit, else detected from the postcode format, else default.
		$country = strtoupper( sanitize_text_field( (string) $req->get_param( 'country' ) ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = Country::detect( $raw_postcode );
		}
		if ( '' === $country ) {
			$country = Country::default_code();
		}

		$phone = Notifier::normalize_phone( (string) $req->get_param( 'phone' ), $country );
		if ( $optin && '' === $phone ) {
			return $this->error( __( 'Please enter a valid phone number for WhatsApp reminders.', 'tur-takvimi' ) );
		}

		$this->upsert(
			array(
				'name'     => $name,
				'email'    => $email,
				'phone'    => $phone,
				'postcode' => $raw_postcode,
				'country'  => $country,
				'wa_optin' => $optin ? 1 : 0,
			)
		);

		// Confirm coverage right away: nearest stop + next visit date.
		$nearest = Postcode::nearest( $raw_postcode, $country );
		if ( $nearest && ! empty( $nearest['next_date'] ) ) {
			$message = sprintf(
				/* translators: 1: name, 2: city, 3: next visit date. */
				__( 'Thanks %1$s! %2$s is on our route — next visit: %3$s.', 'tur-takvimi' ),
				$name,
				$nearest['title'],
				Rest_Api::format_day( $nearest['next_date'] )
			);
			if ( $optin ) {
				$message .= ' ' . __( 'We\'ll send you a WhatsApp reminder before each visit.', 'tur-takvimi' );
			}
		} else {
			$message = __( 'Saved! Your postcode isn\'t on a route yet — we\'ll let you know when we add it.', 'tur-takvimi' );
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => $message,
			)
		);
	}

	/**
	 * Error response helper.
	 *
	 * @param string $message User-facing message.
	 * @param int    $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	private function error( string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'      => false,
				'message' => $message,
			),
			$status
		);
	}

	/**
	 * Insert or update a subscriber, matched by email first, then phone.
	 *
	 * @param array $data name, email, phone, postcode, country, wa_optin.
	 * @return int Subscriber ID.
	 */
	public function upsert( array $data ): int {
		global $wpdb;
		$table = self::table();

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, phone FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$data['email']
			),
			ARRAY_A
		);
		if ( ! $existing && '' !== $data['phone'] ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, phone FROM {$table} WHERE phone = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
					$data['phone']
				),
				ARRAY_A
			);
		}

		if ( $existing ) {
			// Keep a stored phone when the resubmission leaves it empty.
			if ( '' === $data['phone'] ) {
				$data['phone'] = (string) $existing['phone'];
			}
			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}

		$data['token']   = wp_generate_password( 20, false );
		$data['created'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/* --------------------------------------------------------------------- *
	 * Unsubscribe link (?tt_wa_unsub=TOKEN)
	 * --------------------------------------------------------------------- */

	/**
	 * Opt a subscriber out of WhatsApp notifications via their token link
	 * (include it in the message template's footer/button).
	 */
	public function maybe_unsubscribe(): void {
		if ( empty( $_GET['tt_wa_unsub'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['tt_wa_unsub'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		global $wpdb;
		$table = self::table();
		$id    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE token = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$token
			)
		);
		if ( $id ) {
			$wpdb->update( $table, array( 'wa_optin' => 0 ), array( 'id' => (int) $id ) );
		}

		// The token is the proof of identity, so respond either way.
		wp_die(
			esc_html__( 'You will no longer receive WhatsApp delivery notifications. You can sign up again on our website at any time.', 'tur-takvimi' ),
			esc_html__( 'Unsubscribed', 'tur-takvimi' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * Unsubscribe URL for a subscriber row.
	 *
	 * @param array $sub Subscriber row (needs `token`).
	 * @return string
	 */
	public static function unsubscribe_url( array $sub ): string {
		return add_query_arg( 'tt_wa_unsub', (string) ( $sub['token'] ?? '' ), home_url( '/' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Admin: Tur Takvimi → Subscribers
	 * --------------------------------------------------------------------- */

	/**
	 * Register the subscribers submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'tur-takvimi',
			__( 'Subscribers', 'tur-takvimi' ),
			__( 'Subscribers', 'tur-takvimi' ),
			'manage_options',
			'tur-takvimi-subscribers',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the subscriber list.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = self::table();

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$optin = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE wa_optin = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created DESC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=tur_takvimi_subscribers_export' ), 'tt_sub_export' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subscribers', 'tur-takvimi' ); ?></h1>
			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Subscriber deleted.', 'tur-takvimi' ); ?></p></div>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: 1: total subscribers, 2: WhatsApp opt-ins. */
					esc_html__( '%1$d subscribers, %2$d opted in to WhatsApp notifications.', 'tur-takvimi' ),
					(int) $total,
					(int) $optin
				);
				?>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'tur-takvimi' ); ?></a>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'tur-takvimi' ); ?></th>
						<th><?php esc_html_e( 'Email', 'tur-takvimi' ); ?></th>
						<th><?php esc_html_e( 'Phone', 'tur-takvimi' ); ?></th>
						<th><?php esc_html_e( 'Postcode', 'tur-takvimi' ); ?></th>
						<th><?php esc_html_e( 'Country', 'tur-takvimi' ); ?></th>
						<th><?php esc_html_e( 'WhatsApp', 'tur-takvimi' ); ?></th>
						<th><?php esc_html_e( 'Signed up', 'tur-takvimi' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No subscribers yet.', 'tur-takvimi' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( (array) $rows as $r ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $r['name'] ); ?></strong></td>
							<td><?php echo esc_html( $r['email'] ); ?></td>
							<td><?php echo esc_html( '' !== $r['phone'] ? '+' . $r['phone'] : '—' ); ?></td>
							<td><?php echo esc_html( $r['postcode'] ); ?></td>
							<td><?php echo esc_html( $r['country'] ); ?></td>
							<td><?php echo $r['wa_optin'] ? '✓' : '—'; ?></td>
							<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $r['created'] ) ); ?></td>
							<td>
								<a class="button-link button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tur_takvimi_subscriber_delete&id=' . (int) $r['id'] ), 'tt_sub_delete_' . (int) $r['id'] ) ); ?>" onclick="return confirm( '<?php echo esc_js( __( 'Delete this subscriber?', 'tur-takvimi' ) ); ?>' );"><?php esc_html_e( 'Delete', 'tur-takvimi' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $total > 500 ) : ?>
				<p class="description"><?php esc_html_e( 'Showing the latest 500 — use Export CSV for the full list.', 'tur-takvimi' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Delete one subscriber.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tur-takvimi' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'tt_sub_delete_' . $id );

		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'tt_notify_log', array( 'subscriber_id' => $id ), array( '%d' ) );

		wp_safe_redirect( add_query_arg( 'deleted', '1', admin_url( 'admin.php?page=tur-takvimi-subscribers' ) ) );
		exit;
	}

	/**
	 * Stream the full subscriber list as CSV.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tur-takvimi' ) );
		}
		check_admin_referer( 'tt_sub_export' );

		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT name, email, phone, postcode, country, wa_optin, created FROM ' . self::table() . ' ORDER BY created DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tur-takvimi-subscribers.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'name', 'email', 'phone', 'postcode', 'country', 'wa_optin', 'created' ) );
		foreach ( (array) $rows as $r ) {
			fputcsv( $out, $r );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
