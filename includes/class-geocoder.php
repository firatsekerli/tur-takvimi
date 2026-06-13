<?php
/**
 * Geocoding via Photon (Komoot) — a free, keyless, worldwide OSM geocoder.
 *
 * Centralizes all address/postcode lookups so both the admin autocomplete
 * and the public "nearest stop" search use one provider. Country-agnostic,
 * which keeps the plugin resellable across markets.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Thin geocoding client with result normalization and caching.
 */
class Geocoder {

	/**
	 * Diagnostics from the last upstream call (for the admin geocode proxy).
	 *
	 * @var array{status:int,error:string}
	 */
	public static $last_debug = array(
		'status' => 0,
		'error'  => '',
	);

	/**
	 * Default provider endpoint (filterable).
	 */
	private static function endpoint(): string {
		return (string) apply_filters( 'tur_takvimi_geocoder_url', 'https://photon.komoot.io/api/' );
	}

	/**
	 * Autocomplete search: normalized address candidates.
	 *
	 * @param string $query   Free-text address.
	 * @param int    $limit   Max results.
	 * @param string $country Optional ISO-2 country filter (e.g. DE).
	 * @return array<int,array<string,mixed>>
	 */
	public static function search( string $query, int $limit = 6, string $country = '' ): array {
		$query = trim( $query );
		if ( strlen( $query ) < 3 ) {
			return array();
		}

		self::$last_debug = array(
			'status' => 0,
			'error'  => '',
		);

		if ( 'locationiq' === (string) Settings::get( 'geocoder_provider', 'photon' ) ) {
			return self::search_locationiq( $query, $limit, $country );
		}
		return self::search_photon( $query, $limit, $country );
	}

	/**
	 * GET a URL, recording the upstream status/error and retrying once on 429.
	 *
	 * @param string $url        Request URL.
	 * @param bool   $allow_retry Retry once after a short pause on HTTP 429.
	 * @return string|null Response body on success, null on error.
	 */
	private static function remote_get( string $url, bool $allow_retry = true ): ?string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => 'TurTakvimi/1.0 (+' . home_url( '/' ) . ')',
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::$last_debug['error'] = $response->get_error_message();
			return null;
		}

		$status                     = (int) wp_remote_retrieve_response_code( $response );
		self::$last_debug['status'] = $status;
		if ( 429 === $status && $allow_retry ) {
			sleep( 1 );
			return self::remote_get( $url, false );
		}
		if ( $status < 200 || $status >= 300 ) {
			self::$last_debug['error'] = 'HTTP ' . $status . ': ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 200 );
			return null;
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Photon (Komoot) search — GeoJSON FeatureCollection.
	 *
	 * @param string $query   Free-text address.
	 * @param int    $limit   Max results.
	 * @param string $country ISO-2 filter.
	 * @return array<int,array<string,mixed>>
	 */
	private static function search_photon( string $query, int $limit, string $country ): array {
		$url = add_query_arg(
			array(
				'q'     => rawurlencode( $query ),
				'limit' => max( 1, min( 10, $limit ) ),
				'lang'  => 'de',
			),
			self::endpoint()
		);

		$body = self::remote_get( $url );
		if ( null === $body ) {
			return array();
		}

		$data     = json_decode( $body, true );
		$features = $data['features'] ?? array();
		$country  = strtoupper( $country );

		$out = array();
		foreach ( (array) $features as $f ) {
			$p      = $f['properties'] ?? array();
			$coords = $f['geometry']['coordinates'] ?? null; // [lng, lat].
			if ( ! is_array( $coords ) || count( $coords ) < 2 ) {
				continue;
			}
			if ( '' !== $country && strtoupper( (string) ( $p['countrycode'] ?? '' ) ) !== $country ) {
				continue;
			}

			$street = trim( ( $p['street'] ?? $p['name'] ?? '' ) . ' ' . ( $p['housenumber'] ?? '' ) );
			$out[]  = array(
				'label'    => self::label( $p ),
				'street'   => $street,
				'city'     => (string) ( $p['city'] ?? $p['town'] ?? $p['village'] ?? $p['name'] ?? '' ),
				'postcode' => (string) ( $p['postcode'] ?? '' ),
				'lat'      => (float) $coords[1],
				'lng'      => (float) $coords[0],
			);
		}
		return $out;
	}

	/**
	 * LocationIQ search (OSM/Nominatim data) — keyed, bulk-friendly.
	 *
	 * @param string $query   Free-text address.
	 * @param int    $limit   Max results.
	 * @param string $country ISO-2 filter.
	 * @return array<int,array<string,mixed>>
	 */
	private static function search_locationiq( string $query, int $limit, string $country ): array {
		$key = trim( (string) Settings::get( 'geocoder_api_key', '' ) );
		if ( '' === $key ) {
			self::$last_debug['error'] = 'LocationIQ API key is not set (Tur Takvimi → Settings).';
			return array();
		}

		$region = (string) Settings::get( 'geocoder_region', 'us1' );
		$base   = apply_filters( 'tur_takvimi_locationiq_url', 'https://' . $region . '.locationiq.com/v1/search' );
		$args   = array(
			'key'             => $key,
			'q'               => $query,
			'format'          => 'json',
			'addressdetails'  => 1,
			'normalizecity'   => 1,
			'limit'           => max( 1, min( 10, $limit ) ),
			'accept-language' => 'de',
		);
		$country = strtoupper( $country );
		if ( '' !== $country ) {
			$args['countrycodes'] = strtolower( $country );
		}
		$url = add_query_arg( array_map( 'rawurlencode', $args ), $base );

		$body = self::remote_get( $url );
		if ( null === $body ) {
			return array();
		}

		$rows = json_decode( $body, true );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			if ( ! isset( $r['lat'], $r['lon'] ) ) {
				continue;
			}
			$addr = isset( $r['address'] ) && is_array( $r['address'] ) ? $r['address'] : array();
			if ( '' !== $country && strtoupper( (string) ( $addr['country_code'] ?? '' ) ) !== $country ) {
				continue;
			}

			$street = trim( (string) ( $addr['road'] ?? '' ) . ' ' . (string) ( $addr['house_number'] ?? '' ) );
			if ( '' === $street ) {
				$parts  = explode( ',', (string) ( $r['display_name'] ?? '' ) );
				$street = trim( (string) ( $parts[0] ?? '' ) );
			}
			$out[] = array(
				'label'    => (string) ( $r['display_name'] ?? $street ),
				'street'   => $street,
				'city'     => (string) ( $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? '' ),
				'postcode' => (string) ( $addr['postcode'] ?? '' ),
				'lat'      => (float) $r['lat'],
				'lng'      => (float) $r['lon'],
			);
		}
		return $out;
	}

	/**
	 * Minimum pause (microseconds) between bulk lookups for the active provider
	 * — LocationIQ's free tier allows ~2 requests/second.
	 *
	 * @return int
	 */
	public static function min_interval_us(): int {
		return 'locationiq' === (string) Settings::get( 'geocoder_provider', 'photon' ) ? 600000 : 150000;
	}

	/**
	 * True when the last lookup failed for a transient reason (connection
	 * refused, rate limit, server error) rather than a genuine no-match.
	 *
	 * @return bool
	 */
	public static function last_was_transient_error(): bool {
		$status = (int) self::$last_debug['status'];
		return 0 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * Resolve a postcode to a coordinate, cached.
	 *
	 * @param string $postcode Postcode.
	 * @param string $country  ISO-2 country code.
	 * @return array{lat:float,lng:float}|null
	 */
	public static function geocode_postcode( string $postcode, string $country = '' ): ?array {
		$postcode = trim( $postcode );
		if ( '' === $postcode ) {
			return null;
		}

		$key    = 'tt_geo_' . md5( strtoupper( $country . '|' . $postcode ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'miss' === $cached ) {
			return null;
		}

		$results = self::search( $postcode, 1, $country );
		if ( empty( $results ) ) {
			set_transient( $key, 'miss', WEEK_IN_SECONDS );
			return null;
		}

		$point = array(
			'lat' => $results[0]['lat'],
			'lng' => $results[0]['lng'],
		);
		set_transient( $key, $point, MONTH_IN_SECONDS );
		return $point;
	}

	/**
	 * Build a human-readable label from Photon properties.
	 *
	 * @param array $p Feature properties.
	 * @return string
	 */
	private static function label( array $p ): string {
		$parts = array_filter(
			array(
				trim( ( $p['street'] ?? $p['name'] ?? '' ) . ' ' . ( $p['housenumber'] ?? '' ) ),
				trim( ( $p['postcode'] ?? '' ) . ' ' . ( $p['city'] ?? $p['town'] ?? $p['village'] ?? '' ) ),
			)
		);
		return implode( ', ', $parts );
	}
}
