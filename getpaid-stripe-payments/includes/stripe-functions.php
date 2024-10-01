<?php
/**
 * Contains helper stripe functions.
 *
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks if a given currency is a zero decimal currency
 * or a decimal currency.
 *
 * @param string $currency
 * @return bool
 */
function wpinv_stripe_is_zero_decimal_currency( $currency = '' ) {
    $currency   = ! empty( $currency ) ? wpinv_get_currency() : strtoupper( $currency );
    $currencies = array(
        'BIF',
        'CLP',
        'DJF',
        'GNF',
        'JPY',
        'KMF',
        'KRW',
        'MGA',
        'PYG',
        'RWF',
        'UGX',
        'VND',
        'VUV',
        'XAF',
        'XOF',
        'XPF',
    );

    return in_array( $currency, $currencies, true );

}

/**
 * Returns supported Stripe locales.
 *
 * @link https://stripe.com/docs/js/appendix/supported_locales
 * @return array
 */
function wpinv_stripe_allowed_locales() {

    $locales = array(
        'auto'   => __( 'Auto', 'wpinv-stripe' ),
        'ar'     => __( 'Arabic', 'wpinv-stripe' ),
        'bg'     => __( 'Bulgarian (Bulgaria)', 'wpinv-stripe' ),
        'cs'     => __( 'Czech (Czech Republic)', 'wpinv-stripe' ),
        'da'     => __( 'Danish', 'wpinv-stripe' ),
        'de'     => __( 'German (Germany)', 'wpinv-stripe' ),
        'el'     => __( 'Greek (Greece)', 'wpinv-stripe' ),
        'en'     => __( 'English', 'wpinv-stripe' ),
        'en-GB'  => __( 'English (United Kingdom)', 'wpinv-stripe' ),
        'es'     => __( 'Spanish (Spain)', 'wpinv-stripe' ),
        'es-419' => __( 'Spanish (Latin America)', 'wpinv-stripe' ),
        'et'     => __( 'Estonian (Estonia)', 'wpinv-stripe' ),
        'fi'     => __( 'Finnish (Finland)', 'wpinv-stripe' ),
        'fr'     => __( 'French (France)', 'wpinv-stripe' ),
        'fr-CA'  => __( 'French (Canada)', 'wpinv-stripe' ),
        'he'     => __( 'Hebrew (Israel)', 'wpinv-stripe' ),
        'hu'     => __( 'Hungarian (Hungary)', 'wpinv-stripe' ),
        'id'     => __( 'Indonesian (Indonesia)', 'wpinv-stripe' ),
        'it'     => __( 'Italian (Italy)', 'wpinv-stripe' ),
        'ja'     => __( 'Japanese', 'wpinv-stripe' ),
        'lt'     => __( 'Lithuanian (Lithuania)', 'wpinv-stripe' ),
        'lv'     => __( 'Latvian (Latvia)', 'wpinv-stripe' ),
        'ms'     => __( 'Malay (Malaysia)', 'wpinv-stripe' ),
        'mt'     => __( 'Maltese (Malta)', 'wpinv-stripe' ),
        'nb'     => __( 'Norwegian Bokmål', 'wpinv-stripe' ),
        'nl'     => __( 'Dutch (Netherlands)', 'wpinv-stripe' ),
        'pl'     => __( 'Polish (Poland)', 'wpinv-stripe' ),
        'pt-BR'  => __( 'Portuguese (Brazil)', 'wpinv-stripe' ),
        'pt'     => __( 'Portuguese', 'wpinv-stripe' ),
        'ro'     => __( 'Romanian (Romania)', 'wpinv-stripe' ),
        'ru'     => __( 'Russian (Russia)', 'wpinv-stripe' ),
        'sk'     => __( 'Slovak (Slovakia)', 'wpinv-stripe' ),
        'sl'     => __( 'Slovenian (Slovenia)', 'wpinv-stripe' ),
        'sv'     => __( 'Swedish (Sweden)', 'wpinv-stripe' ),
        'tr'     => __( 'Turkish (Turkey)', 'wpinv-stripe' ),
        'zh'     => __( 'Chinese Simplified (China)', 'wpinv-stripe' ),
        'zh-HK'  => __( 'Chinese Traditional (Hong Kong)', 'wpinv-stripe' ),
        'zh-TW'  => __( 'Chinese Simplified (Taiwan)', 'wpinv-stripe' ),
    );

    return apply_filters( 'wpinv_stripe_allowed_locales', $locales );

}

/**
 * Retrieve the checkout locale.
 */
function wpinv_stripe_get_checkout_locale() {
    $locale = wpinv_get_option( 'stripe_locale' );

    if ( 'site' === $locale ) {
        $locale  = is_user_logged_in() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
        $locale  = apply_filters( 'plugin_locale', $locale, 'wpinv-stripe' );

        if ( ! empty( $locale ) ) {
            $locale = substr( strtolower( $locale ), 0, 2 );
        }
}

    if ( empty( $locale ) || ! in_array( $locale, array_keys( wpinv_stripe_allowed_locales() ) ) ) {
        $locale = 'auto';
    }

    return apply_filters( 'wpinv_stripe_get_checkout_locale', $locale );
}

/**
 * Returns the minimum amount allowed for the provided currency.
 *
 * @param string $currency.
 */
function wpinv_stripe_get_minimum_amount( $currency = 'USD' ) {
    $minimum_amounts = array(
        'USD' => '0.50',
        'AED' => '2.00',
        'AUD' => '0.50',
        'BGN' => '1.00',
        'BRL' => '0.50',
        'CAD' => '0.50',
        'CHF' => '0.50',
        'CZK' => '15.00',
        'DKK' => '2.50',
        'EUR' => '0.50',
        'GBP' => '0.30',
        'HKD' => '4.00',
        'HUF' => '175.00',
        'INR' => '0.50',
        'JPY' => '50',
        'MXN' => '10',
        'MYR' => '2',
        'NOK' => '3.00',
        'NZD' => '0.50',
        'PLN' => '2.00',
        'RON' => '2.00',
        'SEK' => '3.00',
        'SGD' => '0.50',
    );

    if ( isset( $minimum_amounts[ $currency ] ) ) {
        $minimum_amount = $minimum_amounts[ $currency ];
    } else {
        $minimum_amount = wpinv_stripe_is_zero_decimal_currency( $currency ) ? 0.5 : 50;
    }

	return (float) apply_filters( 'wpinv_stripe_minimum_amount', $minimum_amount, $currency );
}


/**
 * Get Stripe amount to pay.
 *
 * @param float  $amount Amount.
 * @param string $currency Accepted currency.
 *
 * @return float|int
 */
function getpaid_stripe_get_amount( $amount, $currency = '' ) {
	if ( ! $currency ) {
		$currency = wpinv_get_currency();
	}

	if ( wpinv_stripe_is_zero_decimal_currency( $currency ) ) {
		return absint( $amount );
	} else {
		return absint( wpinv_format_amount( ( (float) $amount * 100 ), wpinv_decimals(), true ) ); // In cents.
	}
}