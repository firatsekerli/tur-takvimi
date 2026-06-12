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

		$url = add_query_arg(
			array(
				'q'     => rawurlencode( $query ),
				'limit' => max( 1, min( 10, $limit ) ),
				'lang'  => 'de',
			),
			self::endpoint()
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 6,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body     = json_decode( wp_remote_retrieve_body( $response ), true );
		$features = $body['features'] ?? array();
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
			$city   = (string) ( $p['city'] ?? $p['town'] ?? $p['village'] ?? $p['name'] ?? '' );
			$out[]  = array(
				'label'    => self::label( $p ),
				'street'   => $street,
				'city'     => $city,
				'postcode' => (string) ( $p['postcode'] ?? '' ),
				'lat'      => (float) $coords[1],
				'lng'      => (float) $coords[0],
			);
		}
		return $out;
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
