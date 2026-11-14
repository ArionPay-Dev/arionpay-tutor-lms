<?php
/**
 * The Tutor LMS side: gateway registration, settings fields, and the shared payment logic.
 *
 * Tutor LMS builds every gateway on its bundled PaymentHub library: a gateway class (this one), a config class and a
 * payment class (includes/payment-classes.php, loaded after Tutor's PaymentHub autoloader).
 *
 * @license MIT
 */

defined( 'ABSPATH' ) || exit;

use Tutor\Ecommerce\Settings;
use Tutor\Models\OrderModel;
use Tutor\PaymentGateways\GatewayBase;

class ArionPay_Tutor_Gateway extends GatewayBase {

	const SLUG       = 'arionpay';
	const CURRENCIES = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR' );
	const OPEN       = array( 'pending', 'paid_partial', 'claim_pending' );
	const MAX_BODY   = 262144;

	public function get_root_dir_name(): string {
		return 'ArionPay';
	}

	public function get_payment_class(): string {
		return ArionPay_Tutor_Payment::class;
	}

	public function get_config_class(): string {
		return ArionPay_Tutor_Config::class;
	}

	/** Tutor's PaymentHub autoloader first, then the classes that extend it. */
	public static function get_autoload_file() {
		return array( tutor()->path . 'ecommerce/PaymentGateways/Paypal/vendor/autoload.php', ARIONPAY_TUTOR_DIR . '/includes/payment-classes.php' );
	}

	// ------------------------------------------------------------------ registration

	public static function hooks() {
		add_filter( 'tutor_payment_gateways_with_class', array( __CLASS__, 'with_class' ) );
		add_filter( 'tutor_gateways_with_class', array( __CLASS__, 'with_class' ) );
		add_filter( 'tutor_payment_gateways', array( __CLASS__, 'settings_entry' ) );
		add_action( 'tutor_order_placement_success', array( __CLASS__, 'on_return' ) );
	}

	public static function with_class( $gateways ) {
		$gateways[ self::SLUG ] = array(
			'gateway_class' => self::class,
			'config_class'  => ArionPay_Tutor_Config::class,
		);
		return $gateways;
	}

	/** The entry in Tutor LMS > Settings > Payment Methods. */
	public static function settings_entry( $gateways ) {
		$gateways[] = array(
			'name'                 => self::SLUG,
			'label'                => __( 'ArionPay (crypto)', 'arionpay-for-tutor' ),
			'is_installed'         => true,
			'is_plugin_active'     => true,
			'is_active'            => false,
			'icon'                 => plugins_url( 'includes/arionpay.svg', ARIONPAY_TUTOR_DIR . '/arionpay-for-tutor.php' ),
			'support_subscription' => false,
			'fields'               => self::fields(),
		);
		return $gateways;
	}

	public static function fields() {
		return array(
			array( 'name' => 'environment', 'type' => 'select', 'label' => __( 'Mode', 'arionpay-for-tutor' ), 'options' => array( 'test' => __( 'Test (no real money)', 'arionpay-for-tutor' ), 'live' => __( 'Live', 'arionpay-for-tutor' ) ), 'value' => 'test' ),
			array( 'name' => 'api_key', 'type' => 'text', 'label' => __( 'API key (pk_...)', 'arionpay-for-tutor' ), 'value' => '' ),
			array( 'name' => 'api_secret', 'type' => 'secret_key', 'label' => __( 'API secret (sk_...)', 'arionpay-for-tutor' ), 'value' => '' ),
			array( 'name' => 'webhook_secret', 'type' => 'secret_key', 'label' => __( 'Webhook signing secret (whsec_...)', 'arionpay-for-tutor' ), 'value' => '' ),
			array( 'name' => 'base_url', 'type' => 'text', 'label' => __( 'ArionPay address (leave empty)', 'arionpay-for-tutor' ), 'value' => '' ),
		);
	}

	/** Current settings of this gateway (field values by name). */
	public static function settings() {
		$out    = array();
		$config = Settings::get_payment_gateway_settings( self::SLUG );
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			if ( isset( $field['name'] ) ) {
				$out[ $field['name'] ] = is_string( $field['value'] ?? '' ) ? trim( $field['value'] ) : '';
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------ shared payment logic

	public static function client() {
		$s    = self::settings();
		$base = $s['base_url'] ?? '';
		return new \ArionPay\Client(
			(string) ( $s['api_key'] ?? '' ),
			(string) ( $s['api_secret'] ?? '' ),
			array(
				'baseUrl' => '' !== $base ? $base : \ArionPay\Client::DEFAULT_BASE_URL,
				'appInfo' => 'tutor-lms/' . ( defined( 'TUTOR_VERSION' ) ? TUTOR_VERSION : '?' ) . ' arionpay-tutor/' . ARIONPAY_TUTOR_VERSION,
			)
		);
	}

	public static function is_test() {
		return 'live' !== ( self::settings()['environment'] ?? 'test' );
	}

	/**
	 * The ArionPay page for a Tutor order: the open invoice when it still fits, otherwise a new one. Null when
	 * ArionPay already has it as paid (it is then recorded).
	 */
	public static function payment_page_url( $order_id, $amount, $currency, $success_url, $cancel_url, $webhook_url ) {
		$currency = strtoupper( (string) $currency );
		if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
			throw new \RuntimeException( sprintf( 'ArionPay does not take %s.', $currency ) );
		}
		$money   = number_format( (float) $amount, 2, '.', '' );
		$current = self::state( $order_id );
		$test    = self::is_test();
		$attempt = max( 1, (int) ( $current['attempt'] ?? 1 ) );
		$client  = self::client();

		if ( ! empty( $current['invoice_id'] ) ) {
			try {
				$read = $client->getInvoice( $current['invoice_id'] );
			} catch ( \ArionPay\ApiException $e ) {
				if ( 404 !== $e->getCode() ) {
					throw $e;
				}
				$read = array( 'status' => 'missing', 'raw' => array() );
			}
			if ( 'paid' === $read['status'] ) {
				self::record_paid( $read, 'signed read' );
				return null;
			}
			$expires = isset( $read['raw']['expiresAt'] ) ? strtotime( (string) $read['raw']['expiresAt'] ) : 0;
			if ( ( in_array( $read['status'], self::OPEN, true ) || ( 'new' === $read['status'] && ( 0 === $expires || $expires > time() + 300 ) ) )
				&& ( $current['amount'] ?? '' ) === $money && ( $current['currency'] ?? '' ) === $currency ) {
				return $current['url'];
			}
			$attempt++;
		}
		for ( $tries = 0; $tries < 20; $tries++, $attempt++ ) {
			$created = $client->createInvoice(
				array(
					'amount'         => $money,
					'currency'       => $currency,
					'orderId'        => (string) $order_id,
					'description'    => mb_substr( get_bloginfo( 'name' ) . ' order ' . $order_id, 0, 500 ),
					'successUrl'     => $success_url,
					'cancelUrl'      => $cancel_url,
					'callbackUrl'    => $webhook_url,
					'metadata'       => array(
						'tutor_order_id' => (int) $order_id,
						'tutor_amount'   => $money,
						'tutor_currency' => $currency,
						'tutor_site'     => self::site_id(),
						'platform'       => 'tutor-lms',
					),
					'idempotencyKey' => 'tutor-' . self::site_id() . '-' . (int) $order_id . '-' . str_replace( '.', '', $money ) . $currency . ( $test ? '-test' : '' ) . '-' . $attempt,
					'test'           => $test,
				)
			);
			if ( 'paid' === $created['status'] ) {
				return null;
			}
			$expires = $created['expiresAt'] ? strtotime( (string) $created['expiresAt'] ) : 0;
			if ( in_array( $created['status'], self::OPEN, true ) || ( 'new' === $created['status'] && ( 0 === $expires || $expires > time() + 300 ) ) ) {
				update_option(
					'arionpay_tutor_order_' . (int) $order_id,
					array(
						'invoice_id' => $created['id'],
						'url'        => $created['invoiceUrl'],
						'amount'     => $money,
						'currency'   => $currency,
						'test'       => $test,
						'attempt'    => $attempt,
					),
					false
				);
				return $created['invoiceUrl'];
			}
		}
		throw new \RuntimeException( 'Too many finished ArionPay invoices for this order.' );
	}

	public static function state( $order_id ) {
		return (array) get_option( 'arionpay_tutor_order_' . (int) $order_id, array() );
	}

	/**
	 * Mark the Tutor order paid for a paid ArionPay invoice (Tutor then enrolls the student). Tutor itself ignores
	 * updates to an order that is already paid. Returns paid | duplicate | mismatch | unknown.
	 */
	public static function record_paid( array $invoice, $source ) {
		$data = self::paid_order_data( $invoice, $source );
		if ( ! is_object( $data ) ) {
			return $data;
		}
		do_action( 'tutor_order_payment_updated', $data );
		return 'paid';
	}

	/** The object Tutor's 'tutor_order_payment_updated' expects, or a reason string. */
	public static function paid_order_data( array $invoice, $source ) {
		$meta     = (array) ( $invoice['metadata'] ?? array() );
		$order_id = (int) ( $meta['tutor_order_id'] ?? 0 );
		$state    = self::state( $order_id );
		$order    = $order_id ? ( new OrderModel() )->get_order_by_id( $order_id ) : null;
		if ( ! $order || ( $meta['tutor_site'] ?? '' ) !== self::site_id() || self::SLUG !== $order->payment_method ) {
			return 'unknown';
		}
		if ( OrderModel::PAYMENT_PAID === $order->payment_status ) {
			return 'duplicate';
		}
		$paid = is_numeric( $invoice['amountFiat'] ?? null ) ? number_format( (float) $invoice['amountFiat'], 2, '.', '' ) : '';
		if ( '' === $paid || $paid !== (string) ( $meta['tutor_amount'] ?? '' ) || $paid !== (string) ( $state['amount'] ?? '' )
			|| strtoupper( (string) ( $invoice['currency'] ?? '' ) ) !== strtoupper( (string) ( $meta['tutor_currency'] ?? '' ) ) ) {
			return 'mismatch';
		}
		return (object) array(
			'id'              => $order_id,
			'payment_status'  => OrderModel::PAYMENT_PAID,
			'transaction_id'  => (string) $invoice['id'],
			'payment_method'  => self::SLUG,
			'payment_payload' => wp_json_encode(
				array(
					'arionpay_invoice' => (string) $invoice['id'],
					'test'             => ! empty( $invoice['test'] ),
					'asset'            => (string) ( $invoice['asset'] ?? '' ),
					'network'          => (string) ( $invoice['network'] ?? '' ),
					'paid_crypto'      => (string) ( $invoice['paidCrypto'] ?? '' ),
					'late'             => ! empty( $invoice['late'] ),
					'source'           => $source,
				)
			),
			'earnings'        => (float) $paid,
			'fees'            => 0,
		);
	}

	/** The student came back to Tutor's "order placed" page: a signed read catches a payment whose webhook was lost. */
	public static function on_return( $order_id ) {
		$state = self::state( $order_id );
		if ( empty( $state['invoice_id'] ) ) {
			return;
		}
		try {
			$read = self::client()->getInvoice( $state['invoice_id'] );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( 'paid' === $read['status'] ) {
			self::record_paid( $read, 'signed read on return' );
		}
	}

	/** Random id of this site (made once), so two sites on one ArionPay store never touch each other's orders. */
	public static function site_id() {
		$id = (string) get_option( 'arionpay_tutor_site_id', '' );
		if ( ! preg_match( '/^[a-z0-9]{12}$/', $id ) ) {
			$id = strtolower( wp_generate_password( 12, false, false ) );
			if ( ! add_option( 'arionpay_tutor_site_id', $id, '', false ) ) {
				$id = (string) get_option( 'arionpay_tutor_site_id', $id );
			}
		}
		return $id;
	}
}
