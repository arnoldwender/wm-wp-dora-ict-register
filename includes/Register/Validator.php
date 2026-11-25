<?php
declare(strict_types=1);

namespace WMCyber\DORA\Register;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Input rules shared by the admin form, the REST routes and the CIF engine. Each method returns null for a
 * value that must be refused; nothing is replaced by a default.
 *
 * Until 2026-09-15 the admin form stored any string as LEI ("ABC" included), "DEU" as country, negative spend,
 * and every new supplier as ISO 27001 certified; REST checked only the LEI length and defaulted missing
 * countries to "DE".
 */
final class Validator {
	/** Service categories offered by the form and accepted by REST; older REST values are mapped. */
	public const SERVICE_CATEGORIES = [
		'cloud_hosting',
		'payment_processing',
		'software_as_a_service',
		'cybersecurity_service',
		'data_analytics',
		'other_ict_service',
	];

	private const CATEGORY_ALIASES = [
		'cloud_infrastructure' => 'cloud_hosting',
		'cloud_computing'      => 'cloud_hosting',
	];

	/**
	 * ISO 17442 LEI: 18 alphanumeric characters and two check digits, valid when the number formed by
	 * replacing letters with 10..35 gives remainder 1 modulo 97 (ISO 7064 MOD 97-10).
	 */
	public static function lei( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$lei = strtoupper( trim( $value ) );
		if ( ! preg_match( '/^[A-Z0-9]{18}[0-9]{2}$/', $lei ) ) {
			return null;
		}
		$remainder = 0;
		foreach ( str_split( $lei ) as $char ) {
			$digits = ctype_digit( $char ) ? $char : (string) ( ord( $char ) - 55 );
			foreach ( str_split( $digits ) as $digit ) {
				$remainder = ( $remainder * 10 + (int) $digit ) % 97;
			}
		}
		return 1 === $remainder ? $lei : null;
	}

	/** ISO 3166-1 alpha-2 format: two letters. The list of assigned codes is not checked. */
	public static function country( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$code = strtoupper( trim( $value ) );
		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : null;
	}

	public static function service_category( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$category = strtolower( trim( $value ) );
		$category = self::CATEGORY_ALIASES[ $category ] ?? $category;
		return in_array( $category, self::SERVICE_CATEGORIES, true ) ? $category : null;
	}

	/** A non-negative amount with at most two decimals. */
	public static function amount( mixed $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			$number = (float) $value;
		} elseif ( is_string( $value ) && preg_match( '/^\d+(?:\.\d{1,2})?$/', trim( $value ) ) ) {
			$number = (float) trim( $value );
		} else {
			return null;
		}
		return $number >= 0 ? round( $number, 2 ) : null;
	}

	/** A non-negative number (hours, counts). */
	public static function non_negative_number( mixed $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value >= 0 ? (float) $value : null;
		}
		if ( is_string( $value ) && is_numeric( trim( $value ) ) && (float) trim( $value ) >= 0 ) {
			return (float) trim( $value );
		}
		return null;
	}

	/** true/false, 1/0, "true"/"false", "yes"/"no", "on"/"off". Anything else, including missing, is null. */
	public static function boolean( mixed $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) && in_array( $value, [ 0, 1 ], true ) ) {
			return 1 === $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null; // filter_var() reads an empty string as false.
		}
		return filter_var( trim( $value ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}

	/** A Y-m-d calendar date. */
	public static function date( mixed $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $value ), $m ) ) {
			return null;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? trim( $value ) : null;
	}

	/** A printable single-line text of 1..$max characters. */
	public static function text( mixed $value, int $max ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$text = sanitize_text_field( $value );
		return ( '' !== $text && mb_strlen( $text ) <= $max ) ? $text : null;
	}
}
