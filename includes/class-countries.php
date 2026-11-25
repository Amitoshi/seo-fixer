<?php
/**
 * ISO 3166-1 country list.
 *
 * Names come from PHP's intl extension in the user's language when available,
 * so no country names need translating; without intl the codes are shown.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Country codes and display names.
 */
final class Countries {

	/**
	 * All ISO 3166-1 alpha-2 codes.
	 *
	 * @return string[]
	 */
	public static function codes() {
		return explode(
			' ',
			'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'
		);
	}

	/**
	 * Code => display name, sorted by name.
	 *
	 * @return array<string, string>
	 */
	public static function options() {
		$locale  = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$options = array();

		foreach ( self::codes() as $code ) {
			$name             = class_exists( 'Locale' ) ? \Locale::getDisplayRegion( 'und_' . $code, $locale ) : '';
			$options[ $code ] = ( '' !== $name && $name !== $code ) ? $name : $code;
		}

		if ( class_exists( 'Collator' ) ) {
			$collator = new \Collator( $locale );
			uasort( $options, array( $collator, 'compare' ) );
		} else {
			asort( $options, SORT_STRING | SORT_FLAG_CASE );
		}

		return array( '' => __( '— Choose a country —', 'seo-fixer' ) ) + $options;
	}

	/**
	 * Best guess from the site language, e.g. en_IN → IN.
	 *
	 * @return string
	 */
	public static function guess_from_locale() {
		$parts = explode( '_', (string) get_locale() );
		$code  = isset( $parts[1] ) ? strtoupper( substr( $parts[1], 0, 2 ) ) : '';
		return in_array( $code, self::codes(), true ) ? $code : '';
	}
}
