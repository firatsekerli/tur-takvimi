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
	 * Configured countries as an ordered code => custom-label map. The label is
	 * an empty string when the admin did not supply one (use name() to resolve).
	 * Accepts the stored map, a list of codes, or a "DE:Almanya, NL" string.
	 *
	 * @return array<string,string> ISO-2 => label ('' when unset).
	 */
	public static function map(): array {
		$raw    = Settings::get( 'countries', array() );
		$tokens = array();
		if ( is_string( $raw ) ) {
			$tokens = array_map( 'trim', explode( ',', $raw ) );
		} else {
			foreach ( (array) $raw as $key => $value ) {
				$tokens[] = is_string( $key ) && preg_match( '/^[A-Za-z]{2}$/', $key )
					? $key . ':' . $value      // Stored assoc map.
					: (string) $value;          // Legacy list of codes.
			}
		}

		$map = array();
		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}
			$parts = explode( ':', $token, 2 );
			$code  = strtoupper( trim( $parts[0] ) );
			if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
				$map[ $code ] = isset( $parts[1] ) ? trim( $parts[1] ) : '';
			}
		}

		$default = self::default_code();
		if ( ! isset( $map[ $default ] ) ) {
			$map = array( $default => '' ) + $map;
		}
		return $map;
	}

	/**
	 * Countries the business operates in (always includes the default).
	 *
	 * @return string[] ISO-2 codes.
	 */
	public static function supported(): array {
		return array_keys( self::map() );
	}

	/**
	 * Human-readable country name: the admin's custom label, else a built-in
	 * translatable name, else the bare ISO-2 code.
	 *
	 * @param string $code ISO-2.
	 * @return string
	 */
	public static function name( string $code ): string {
		$code  = strtoupper( $code );
		$map   = self::map();
		if ( ! empty( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		$names = apply_filters(
			'tur_takvimi_country_names',
			array(
				'DE' => __( 'Germany', 'tur-takvimi' ),
				'NL' => __( 'Netherlands', 'tur-takvimi' ),
				'BE' => __( 'Belgium', 'tur-takvimi' ),
				'FR' => __( 'France', 'tur-takvimi' ),
				'AT' => __( 'Austria', 'tur-takvimi' ),
				'LU' => __( 'Luxembourg', 'tur-takvimi' ),
			)
		);
		return (string) ( $names[ $code ] ?? $code );
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
