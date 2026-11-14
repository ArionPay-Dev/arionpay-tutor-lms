<?php
/**
 * Plugin Name:       ArionPay for Tutor LMS
 * Plugin URI:        https://arionpay.com
 * Description:       Sell courses for Bitcoin, USDT, Litecoin, Ethereum, TRON and more with Tutor LMS's own checkout. The money goes straight to your own wallet.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ArionPay
 * License:           MIT
 * Text Domain:       arionpay-for-tutor
 *
 * Adds "ArionPay (crypto)" to Tutor LMS's native checkout (Tutor LMS > Settings > Payment Methods). The student pays
 * on the ArionPay page; only a signed ArionPay webhook, or a signed invoice read when the student comes back, marks
 * the order paid (Tutor then enrolls the student). ArionPay alone decides whether an invoice is paid.
 */

defined( 'ABSPATH' ) || exit;

define( 'ARIONPAY_TUTOR_VERSION', '1.0.0' );
define( 'ARIONPAY_TUTOR_DIR', __DIR__ );

add_action( 'plugins_loaded', 'arionpay_tutor_load', 20 );

function arionpay_tutor_load() {
	if ( ! function_exists( 'tutor' ) || ! class_exists( '\Tutor\PaymentGateways\GatewayBase' ) ) {
		return;
	}
	if ( ! class_exists( '\ArionPay\Client', true ) ) {
		require_once __DIR__ . '/lib/arionpay-php/autoload.php';
	}
	require_once __DIR__ . '/includes/class-arionpay-tutor-gateway.php';
	ArionPay_Tutor_Gateway::hooks();
}
