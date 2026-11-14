<?php
/**
 * PaymentHub classes for Tutor LMS (loaded after Tutor's PaymentHub autoloader).
 *
 * @license MIT
 */

defined( 'ABSPATH' ) || exit;

use Ollyo\PaymentHub\Contracts\Payment\ConfigContract;
use Ollyo\PaymentHub\Core\Payment\BaseConfig;
use Ollyo\PaymentHub\Core\Payment\BasePayment;
use Tutor\PaymentGateways\Configs\PaymentUrlsTrait;

if ( ! class_exists( 'ArionPay_Tutor_Config' ) ) {

	class ArionPay_Tutor_Config extends BaseConfig implements ConfigContract {
		use PaymentUrlsTrait;

		protected $name = 'arionpay';

		public function getName(): string {
			return $this->name;
		}

		public function getMode(): string {
			return ArionPay_Tutor_Gateway::is_test() ? 'test' : 'live';
		}

		public function getTitle(): string {
			return 'ArionPay (crypto)';
		}

		public function is_configured() {
			$s = ArionPay_Tutor_Gateway::settings();
			return ! empty( $s['api_key'] ) && ! empty( $s['api_secret'] ) && ! empty( $s['webhook_secret'] );
		}
	}

	class ArionPay_Tutor_Payment extends BasePayment {

		public function setup(): void {
		}

		public function check(): bool {
			return ( new ArionPay_Tutor_Config() )->is_configured();
		}

		/** Make (or reuse) the ArionPay invoice for the Tutor order and send the student to it. */
		public function createPayment() {
			$data   = $this->getData();
			$config = $this->getConfig();
			$amount = 0.0;
			foreach ( (array) $data->items as $item ) {
				$item    = (array) $item;
				$price   = ( isset( $item['discounted_price'] ) && null !== $item['discounted_price'] && '' !== $item['discounted_price'] ) ? (float) $item['discounted_price'] : (float) $item['regular_price'];
				$amount += $price * (int) ( $item['quantity'] ?? 1 );
			}
			$url = ArionPay_Tutor_Gateway::payment_page_url(
				(int) $data->order_id,
				$amount,
				(string) $data->currency->code,
				(string) $config->get( 'success_url' ),
				(string) $config->get( 'cancel_url' ),
				(string) $config->get( 'webhook_url' )
			);
			header( 'Location: ' . ( $url ?? $config->get( 'success_url' ) ), true, 302 );
			exit;
		}

		/**
		 * Webhook (wp-json/tutor/v1/ecommerce-webhook?payment_method=arionpay). Returns the paid order for Tutor, or
		 * answers and stops for everything that must not change an order.
		 */
		public function verifyAndCreateOrderData( object $payload ): object {
			$raw = (string) ( $payload->stream ?? '' );
			if ( strlen( $raw ) > ArionPay_Tutor_Gateway::MAX_BODY ) {
				$this->reply( 413, array( 'error' => 'too large' ) );
			}
			$secret = ArionPay_Tutor_Gateway::settings()['webhook_secret'] ?? '';
			if ( '' === $secret ) {
				$this->reply( 503, array( 'error' => 'webhook secret not set' ) );
			}
			$header = isset( $payload->server['HTTP_ARIONPAY_SIGNATURE'] ) ? (string) $payload->server['HTTP_ARIONPAY_SIGNATURE'] : '';
			try {
				$event = \ArionPay\Webhook::verify( $raw, $header, $secret );
			} catch ( \ArionPay\SignatureException $e ) {
				$this->reply( 401, array( 'error' => 'invalid signature' ) );
			}
			if ( \ArionPay\Webhook::isTestEvent( $event ) ) {
				$this->reply( 200, array( 'received' => true, 'test' => true ) );
			}
			$invoice = $event['data']['invoice'];
			$meta    = (array) ( $invoice['metadata'] ?? array() );
			if ( empty( $meta['tutor_order_id'] ) || ( $meta['tutor_site'] ?? '' ) !== ArionPay_Tutor_Gateway::site_id() ) {
				$this->reply( 200, array( 'received' => true, 'ignored' => 'not an order of this site' ) );
			}
			$state = ArionPay_Tutor_Gateway::state( (int) $meta['tutor_order_id'] );
			if ( (bool) ( $invoice['test'] ?? false ) !== (bool) ( $state['test'] ?? ArionPay_Tutor_Gateway::is_test() ) ) {
				$this->reply( 409, array( 'error' => 'test/live mismatch' ) );
			}
			if ( 'invoice.paid' !== $event['type'] ) {
				$this->reply( 200, array( 'received' => true ) ); // payment seen, expired, review: the order stays pending
			}
			$data = ArionPay_Tutor_Gateway::paid_order_data( $invoice, 'webhook' );
			if ( ! is_object( $data ) ) {
				$this->reply( 200, array( 'received' => true, 'result' => $data ) );
			}
			// Tutor applies it ('tutor_order_payment_updated') and then answers 200.
			return $data;
		}

		private function reply( $status, array $body ) {
			status_header( (int) $status );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $body );
			exit;
		}
	}
}
