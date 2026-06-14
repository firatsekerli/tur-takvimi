<?php
/**
 * Multi-country helpers.
 *
 * The plugin can serve several countries at once (e.g. Germany and the
 * Netherlands). Each Şehir/Rota carries a `_tt_country` ISO-2 code; this class
 * centralizes the supported list, the default, postcode-format detection and
 * per-post country resolution so the rest of the code stays simple.
 *
 * @package TurTakvimi
 */

namespace TurTakvimi;

defined( 'ABSPATH' ) || exit;

/**
 * Country configuration and postcode-format detection.
 */
class Country {

	/**
	 * The site's default country (used for new posts and as a fallback).
	 *
	 * @return string ISO-2.
	 */
	public static function default_code(): string {
		return strtoupper( (string) Settings::get( 'country', 'DE' ) );
	}

	/**
	 * Countries the business operates in (always includes the default).
	 *
	 * @return string[] ISO-2 codes.
	 */
	public static function supported(): array {
		$list = Settings::get( 'countries', array() );
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY );
		}
		$list = array_filter(
			array_map( 'strtoupper', (array) $list ),
			static fn( $c ) => (bool) preg_match( '/^[A-Z]{2}$/', $c )
		);
		$list = array_values( array_unique( $list ) );
		if ( ! in_array( self::default_code(), $list, true ) ) {
			array_unshift( $list, self::default_code() );
		}
		return $list;
	}

	/**
	 * Postcode format patterns per country (filterable for new countries).
	 *
	 * @return array<string,string> ISO-2 => PCRE.
	 */
	public static function patterns(): array {
		return apply_filters(
			'tur_takvimi_postcode_patterns',
			array(
				'DE' => '/^\d{5}$/',          // 12345
				'NL' => '/^\d{4}\s?[A-Z]{0,2}$/', // 1234 or 1234 AB
			)
		);
	}

	/**
	 * Guess a country from a typed postcode's format. Returns '' when no
	 * enabled country's pattern matches (or the format is ambiguous).
	 *
	 * @param string   $raw     Typed postcode.
	 * @param string[] $enabled Restrict to these ISO-2 codes (default: supported).
	 * @return string ISO-2 or ''.
	 */
	public static function detect( string $raw, array $enabled = array() ): string {
		$raw = strtoupper( trim( $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		$enabled  = $enabled ? array_map( 'strtoupper', $enabled ) : self::supported();
		$patterns = self::patterns();
		foreach ( $enabled as $code ) {
			if ( isset( $patterns[ $code ] ) && preg_match( $patterns[ $code ], $raw ) ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * A post's country, falling back to the default.
	 *
	 * @param int $post_id Post ID.
	 * @return string ISO-2.
	 */
	public static function of_post( int $post_id ): string {
		$code = strtoupper( (string) get_post_meta( $post_id, '_tt_country', true ) );
		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : self::default_code();
	}

	/**
	 * Example postcode for a country (for input placeholders).
	 *
	 * @param string $code ISO-2.
	 * @return string
	 */
	public static function example( string $code ): string {
		$examples = apply_filters(
			'tur_takvimi_postcode_examples',
			array(
				'DE' => '45134',
				'NL' => '1234 AB',
			)
		);
		return (string) ( $examples[ strtoupper( $code ) ] ?? '' );
	}
}
