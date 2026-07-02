<?php
/**
 * WooCommerce commerce layer (pre-order checkout).
 *
 * Loaded only when WooCommerce is active. Connects the core tour data to the
 * Woo checkout:
 *   - An automatic pre-order discount (Settings → "Upfront discount (%)"),
 *     applied to the cart as a negative fee.
 *   - A Teslimat section on the classic checkout: ülke → bölge → şehir
 *     cascade. Picking a city shows its delivery details (next eligible date
 *     honoring the order cutoff, plus the pickup point when one is marked).
 *   - The chosen city, date and pickup address are validated server-side and
 *     saved on the order, shown in the admin order screen, the customer's
 *     order pages and the order emails.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-order checkout integration for WooCommerce.
 */
class Commerce {

	/**
	 * Order meta keys.
	 */
	const META_LOCATION_ID = '_tt_location_id';
	const META_LOCATION    = '_tt_location';
	const META_DATE        = '_tt_delivery_date';
	const META_ADDRESS     = '_tt_pickup_address';
	const META_TIME        = '_tt_pickup_time';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_discount' ) );

		add_action( 'woocommerce_before_order_notes', array( $this, 'checkout_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 10, 2 );

		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'admin_order_box' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_details' ) );
		add_action( 'woocommerce_email_order_meta', array( $this, 'email_meta' ), 10, 3 );

		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'minicart_fragment' ) );
		add_shortcode( 'tur_takvimi_minicart_discount', array( $this, 'minicart_discount_html' ) );

		// Standard mini-cart widget: print the row between the subtotal and
		// the buttons. Builder mini carts that wrap woocommerce_mini_cart()
		// pick this up (and refresh it via fragments) automatically.
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( $this, 'minicart_widget_row' ), 5 );
	}

	/**
	 * Echo the discount row inside the standard mini-cart widget.
	 */
	public function minicart_widget_row(): void {
		echo $this->minicart_discount_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/* --------------------------------------------------------------------- *
	 * Pre-order discount
	 * --------------------------------------------------------------------- */

	/**
	 * Apply the upfront/pre-order discount as a negative cart fee.
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public function apply_discount( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		/**
		 * The pre-order discount percentage (0 disables it).
		 *
		 * @param float    $pct  Percentage from settings.
		 * @param \WC_Cart $cart Current cart.
		 */
		$pct = (float) apply_filters( 'tur_takvimi_preorder_discount_percent', (float) Settings::get( 'discount_percent', 10 ), $cart );
		if ( $pct <= 0 ) {
			return;
		}

		$amount = (float) $cart->get_subtotal() * $pct / 100;
		if ( $amount <= 0 ) {
			return;
		}

		$label = sprintf(
			/* translators: %s: discount percentage. */
			__( 'Pre-order discount (%s%%)', 'tur-takvimi' ),
			rtrim( rtrim( number_format( $pct, 1 ), '0' ), '.' )
		);
		$cart->add_fee( $label, -$amount );
	}

	/* --------------------------------------------------------------------- *
	 * Checkout fields
	 * --------------------------------------------------------------------- */

	/**
	 * Render the Teslimat section on the classic checkout.
	 *
	 * The dataset ships as inline JSON and the behaviour as an inline script,
	 * so the section keeps working regardless of theme/optimizer script
	 * handling (no external file, no load-order dependency).
	 */
	public function checkout_fields(): void {
		$payload = $this->payload();
		if ( empty( $payload['cities'] ) ) {
			return;
		}

		wp_enqueue_style( 'tur-takvimi' );

		// Preselect posted values so a validation error keeps the choice.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Woo checkout re-render.
		$sel_country = isset( $_POST['tt_checkout_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['tt_checkout_country'] ) ) ) : '';
		$sel_region  = isset( $_POST['tt_checkout_region'] ) ? sanitize_title( wp_unslash( $_POST['tt_checkout_region'] ) ) : '';
		$sel_city    = isset( $_POST['tt_checkout_city'] ) ? absint( $_POST['tt_checkout_city'] ) : 0;
		// phpcs:enable

		$countries = array();
		foreach ( $payload['cities'] as $c ) {
			$countries[ $c['country'] ] = true;
		}
		$countries = array_values( array_intersect( Country::supported(), array_keys( $countries ) ) );
		?>
		<div class="tt-checkout" data-tt-checkout>
			<h3><?php esc_html_e( 'Delivery', 'tur-takvimi' ); ?></h3>
			<p class="tt-checkout__hint"><?php esc_html_e( 'Choose your delivery city to see the delivery date and pickup address.', 'tur-takvimi' ); ?></p>

			<?php if ( count( $countries ) > 1 ) : ?>
				<p class="tt-checkout__row form-row">
					<label for="tt_checkout_country"><?php esc_html_e( 'Country', 'tur-takvimi' ); ?></label>
					<select id="tt_checkout_country" name="tt_checkout_country">
						<option value=""><?php esc_html_e( 'All countries', 'tur-takvimi' ); ?></option>
						<?php foreach ( $countries as $code ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"<?php selected( $sel_country, $code ); ?>><?php echo esc_html( Country::name( $code ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endif; ?>

			<?php if ( $payload['regions'] ) : ?>
				<p class="tt-checkout__row form-row">
					<label for="tt_checkout_region"><?php esc_html_e( 'Region', 'tur-takvimi' ); ?></label>
					<select id="tt_checkout_region" name="tt_checkout_region">
						<option value=""><?php esc_html_e( 'All regions', 'tur-takvimi' ); ?></option>
						<?php foreach ( $payload['regions'] as $r ) : ?>
							<option value="<?php echo esc_attr( $r['slug'] ); ?>"<?php selected( $sel_region, $r['slug'] ); ?>><?php echo esc_html( $r['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endif; ?>

			<p class="tt-checkout__row form-row">
				<label for="tt_checkout_city"><?php esc_html_e( 'City', 'tur-takvimi' ); ?> <abbr class="required" title="<?php esc_attr_e( 'required', 'tur-takvimi' ); ?>">*</abbr></label>
				<select id="tt_checkout_city" name="tt_checkout_city" required>
					<option value=""><?php esc_html_e( 'Select a city…', 'tur-takvimi' ); ?></option>
					<?php foreach ( $payload['cities'] as $c ) : ?>
						<option value="<?php echo esc_attr( (string) $c['id'] ); ?>"<?php selected( $sel_city, (int) $c['id'] ); ?>><?php echo esc_html( $c['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<div class="tt-checkout__info" data-tt-info style="display:none;">
				<strong class="tt-checkout__info-title"><?php esc_html_e( 'Delivery details', 'tur-takvimi' ); ?></strong>
				<p data-tt-row="date"><strong><?php esc_html_e( 'Delivery date', 'tur-takvimi' ); ?>:</strong> <span data-tt-value></span></p>
				<p data-tt-row="time"><strong><?php esc_html_e( 'Delivery time', 'tur-takvimi' ); ?>:</strong> <span data-tt-value></span></p>
				<p data-tt-row="address"><strong><?php esc_html_e( 'Delivery address', 'tur-takvimi' ); ?>:</strong> <span data-tt-value></span></p>
			</div>

			<script type="application/json" data-tt-data><?php echo wp_json_encode( array( 'cities' => $payload['cities'], 'regions' => $payload['regions'] ), JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</div>
		<script>
		( function () {
			'use strict';

			function bind( root ) {
				if ( root.dataset.ttBound ) {
					return;
				}
				var holder = root.querySelector( '[data-tt-data]' );
				var country = root.querySelector( 'select[name="tt_checkout_country"]' );
				var region = root.querySelector( 'select[name="tt_checkout_region"]' );
				var city = root.querySelector( 'select[name="tt_checkout_city"]' );
				var info = root.querySelector( '[data-tt-info]' );
				if ( ! holder || ! city ) {
					return;
				}
				var cfg;
				try {
					cfg = JSON.parse( holder.textContent );
				} catch ( e ) {
					return;
				}
				root.dataset.ttBound = '1';

				function currentCountry() {
					return country ? country.value : '';
				}
				function currentRegion() {
					return region ? region.value : '';
				}

				function rebuildRegions() {
					if ( ! region ) {
						return;
					}
					var keep = region.value;
					var co = currentCountry();
					region.length = 1; /* keep the "all" placeholder */
					cfg.regions.forEach( function ( r ) {
						if ( co && r.countries.indexOf( co ) === -1 ) {
							return;
						}
						region.add( new Option( r.name, r.slug, false, r.slug === keep ) );
					} );
				}

				function rebuildCities() {
					var keep = city.value;
					var co = currentCountry();
					var reg = currentRegion();
					city.length = 1; /* keep the "select a city" placeholder */
					cfg.cities.forEach( function ( c ) {
						if ( co && c.country !== co ) {
							return;
						}
						if ( reg && c.regions.indexOf( reg ) === -1 ) {
							return;
						}
						city.add( new Option( c.name, String( c.id ), false, String( c.id ) === keep ) );
					} );
					updateInfo();
				}

				function setRow( key, value ) {
					var row = info.querySelector( '[data-tt-row="' + key + '"]' );
					if ( ! row ) {
						return;
					}
					row.style.display = value ? '' : 'none';
					var v = row.querySelector( '[data-tt-value]' );
					if ( v ) {
						v.textContent = value || '';
					}
				}

				function updateInfo() {
					if ( ! info ) {
						return;
					}
					var picked = null;
					cfg.cities.forEach( function ( c ) {
						if ( String( c.id ) === city.value ) {
							picked = c;
						}
					} );
					if ( ! picked ) {
						info.style.display = 'none';
						return;
					}
					setRow( 'date', picked.dateLabel );
					setRow( 'time', picked.time );
					setRow( 'address', picked.address );
					info.style.display = '';
				}

				if ( country ) {
					country.addEventListener( 'change', function () {
						rebuildRegions();
						rebuildCities();
					} );
				}
				if ( region ) {
					region.addEventListener( 'change', rebuildCities );
				}
				city.addEventListener( 'change', updateInfo );

				rebuildRegions();
				rebuildCities();
			}

			function bindAll() {
				Array.prototype.forEach.call( document.querySelectorAll( '[data-tt-checkout]' ), bind );
			}

			bindAll();
			document.addEventListener( 'DOMContentLoaded', bindAll );
			/* Woo re-renders checkout fragments; re-bind fresh copies. */
			if ( window.jQuery ) {
				window.jQuery( document.body ).on( 'updated_checkout', bindAll );
			}
		}() );
		</script>
		<?php
	}

	/**
	 * Require a valid, orderable city.
	 */
	public function validate(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Woo checkout handles the nonce.
		$city = isset( $_POST['tt_checkout_city'] ) ? absint( $_POST['tt_checkout_city'] ) : 0;
		// phpcs:enable
		if ( ! $city ) {
			wc_add_notice( __( 'Please choose your delivery city.', 'tur-takvimi' ), 'error' );
			return;
		}
		if ( null === $this->eligible_date( $city ) ) {
			wc_add_notice( __( 'This city has no upcoming delivery date — please pick another.', 'tur-takvimi' ), 'error' );
		}
	}

	/**
	 * Stamp the delivery details onto the order (recomputed server-side; the
	 * posted city is only trusted as a choice, never for the date/address).
	 *
	 * @param \WC_Order $order Order being created.
	 * @param array     $data  Posted checkout data (unused).
	 */
	public function save_order_meta( $order, $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Woo checkout handles the nonce.
		$city = isset( $_POST['tt_checkout_city'] ) ? absint( $_POST['tt_checkout_city'] ) : 0;
		// phpcs:enable
		if ( ! $city ) {
			return;
		}
		$date = $this->eligible_date( $city );
		if ( null === $date ) {
			return;
		}

		$order->update_meta_data( self::META_LOCATION_ID, $city );
		$order->update_meta_data( self::META_LOCATION, get_the_title( $city ) );
		$order->update_meta_data( self::META_DATE, $date );

		$pickup = City_Page::pickup_address( $city );
		if ( $pickup ) {
			$street = trim( (string) ( $pickup['address'] ?? '' ) );
			$pc     = trim( (string) ( $pickup['postcode'] ?? '' ) );
			$order->update_meta_data( self::META_ADDRESS, trim( $street . ( '' !== $pc ? ', ' . $pc : '' ), ', ' ) );
			$order->update_meta_data( self::META_TIME, trim( (string) ( $pickup['time'] ?? '' ) ) );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Mini cart
	 * --------------------------------------------------------------------- */

	/**
	 * Keep a mini-cart discount row current via Woo's cart fragments. A theme
	 * (or custom mini cart) places <div data-tt-minicart-discount></div> in
	 * its markup — or the [tur_takvimi_minicart_discount] shortcode — and the
	 * row is filled/refreshed whenever the cart updates.
	 *
	 * @param array $fragments Fragment selector => replacement HTML.
	 * @return array
	 */
	public function minicart_fragment( $fragments ): array {
		$fragments['div[data-tt-minicart-discount]'] = $this->minicart_discount_html();
		return (array) $fragments;
	}

	/**
	 * The discount row markup (hidden container when no discount applies, so
	 * the fragment always has an element to replace).
	 *
	 * @return string
	 */
	public function minicart_discount_html(): string {
		$rows = '';
		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			if ( ! WC()->cart->get_fees() ) {
				WC()->cart->calculate_totals();
			}
			foreach ( WC()->cart->get_fees() as $fee ) {
				if ( (float) $fee->total >= 0 ) {
					continue; // Only discount (negative) fees belong here.
				}
				$rows .= sprintf(
					'<div class="tt-minicart-discount__row" style="display:flex;justify-content:space-between;gap:1rem;align-items:baseline;"><span class="tt-minicart-discount__label">%s</span><span class="tt-minicart-discount__amount" style="white-space:nowrap;">%s</span></div>',
					esc_html( (string) $fee->name ),
					wp_kses_post( wc_price( (float) $fee->total ) )
				);
			}
		}
		return '<div data-tt-minicart-discount class="tt-minicart-discount"' . ( '' === $rows ? ' style="display:none"' : '' ) . '>' . $rows . '</div>';
	}

	/* --------------------------------------------------------------------- *
	 * Order display (admin, customer, email)
	 * --------------------------------------------------------------------- */

	/**
	 * Delivery panel on the admin order screen.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function admin_order_box( $order ): void {
		$rows = $this->order_rows( $order );
		if ( ! $rows ) {
			return;
		}
		echo '<div class="tt-order-delivery"><h3>' . esc_html__( 'Delivery details', 'tur-takvimi' ) . '</h3>';
		foreach ( $rows as $label => $value ) {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</div>';
	}

	/**
	 * Delivery details on the customer's thank-you / order pages.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function order_details( $order ): void {
		$rows = $this->order_rows( $order );
		if ( ! $rows ) {
			return;
		}
		echo '<section class="tt-checkout__info tt-order-details">';
		echo '<strong class="tt-checkout__info-title">' . esc_html__( 'Delivery details', 'tur-takvimi' ) . '</strong>';
		foreach ( $rows as $label => $value ) {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</section>';
	}

	/**
	 * Delivery details in order emails.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether this is the admin copy.
	 * @param bool      $plain_text    Whether the email is plain text.
	 */
	public function email_meta( $order, $sent_to_admin, $plain_text ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$rows = $this->order_rows( $order );
		if ( ! $rows ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Delivery details', 'tur-takvimi' ) . "\n";
			foreach ( $rows as $label => $value ) {
				echo esc_html( $label . ': ' . $value ) . "\n";
			}
			return;
		}
		echo '<h2>' . esc_html__( 'Delivery details', 'tur-takvimi' ) . '</h2>';
		foreach ( $rows as $label => $value ) {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html( $label ), esc_html( $value ) );
		}
	}

	/**
	 * label => value rows for an order's stored delivery details.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,string>
	 */
	private function order_rows( $order ): array {
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}
		$rows = array();
		$city = (string) $order->get_meta( self::META_LOCATION );
		if ( '' !== $city ) {
			$rows[ __( 'City', 'tur-takvimi' ) ] = $city;
		}
		$date = (string) $order->get_meta( self::META_DATE );
		if ( '' !== $date ) {
			$rows[ __( 'Delivery date', 'tur-takvimi' ) ] = Rest_Api::format_day( $date );
		}
		$time = (string) $order->get_meta( self::META_TIME );
		if ( '' !== $time ) {
			$rows[ __( 'Delivery time', 'tur-takvimi' ) ] = $time;
		}
		$address = (string) $order->get_meta( self::META_ADDRESS );
		if ( '' !== $address ) {
			$rows[ __( 'Delivery address', 'tur-takvimi' ) ] = $address;
		}
		return $rows;
	}

	/* --------------------------------------------------------------------- *
	 * Data
	 * --------------------------------------------------------------------- */

	/**
	 * Earliest orderable delivery date: today + the configured cutoff.
	 *
	 * @return string Y-m-d.
	 */
	private function order_from_date(): string {
		$cutoff = max( 0, (int) Settings::get( 'order_cutoff_days', 2 ) );
		return ( new \DateTimeImmutable( current_time( 'Y-m-d' ) ) )->modify( '+' . $cutoff . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * The next orderable date for a city (cutoff applied), or null.
	 *
	 * @param int $id Location ID.
	 * @return string|null Y-m-d.
	 */
	private function eligible_date( int $id ): ?string {
		if ( Post_Types::LOCATION !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return null;
		}
		return ( new Schedule() )->next_tour_for_location( $id, $this->order_from_date() );
	}

	/**
	 * The checkout dataset: every orderable city with its next eligible date,
	 * regions and pickup details, plus the region list for the filter.
	 *
	 * @return array{cities:array,regions:array}
	 */
	private function payload(): array {
		$next  = ( new Schedule() )->next_tour_dates( $this->order_from_date() );
		$posts = get_posts(
			array(
				'post_type'      => Post_Types::LOCATION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$cities  = array();
		$regions = array();
		foreach ( $posts as $post ) {
			$id = (int) $post->ID;
			if ( empty( $next[ $id ] ) ) {
				continue; // No orderable date within the horizon.
			}

			$slugs = array();
			$terms = get_the_terms( $id, Post_Types::REGION );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$slugs[] = $term->slug;
					if ( ! isset( $regions[ $term->slug ] ) ) {
						$regions[ $term->slug ] = array(
							'slug'      => $term->slug,
							'name'      => $term->name,
							'countries' => array(),
						);
					}
				}
			}

			$country = Country::of_post( $id );
			foreach ( $slugs as $slug ) {
				if ( ! in_array( $country, $regions[ $slug ]['countries'], true ) ) {
					$regions[ $slug ]['countries'][] = $country;
				}
			}

			$address = '';
			$time    = '';
			$pickup  = City_Page::pickup_address( $id );
			if ( $pickup ) {
				$street  = trim( (string) ( $pickup['address'] ?? '' ) );
				$pc      = trim( (string) ( $pickup['postcode'] ?? '' ) );
				$address = trim( $street . ( '' !== $pc ? ', ' . $pc : '' ), ', ' );
				$time    = trim( (string) ( $pickup['time'] ?? '' ) );
			}

			$cities[] = array(
				'id'        => $id,
				'name'      => get_the_title( $id ),
				'country'   => $country,
				'regions'   => $slugs,
				'date'      => $next[ $id ],
				'dateLabel' => Rest_Api::format_day( $next[ $id ] ),
				'time'      => $time,
				'address'   => $address,
			);
		}

		$regions = array_values( $regions );
		usort( $regions, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

		return array(
			'cities'  => $cities,
			'regions' => $regions,
		);
	}
}
