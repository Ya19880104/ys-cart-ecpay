<?php
/**
 * v062 — AIO browser-return URLs satisfy the Core thank-you contract.
 *
 * Run: php tests/regression/v062_aio_thankyou_return_contract.php
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}
	if ( ! defined( 'YS_ECOMMERCE_TABLE_PREFIX' ) ) {
		define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
	}

	final class WP_REST_Request {
		/** @param array<string,string> $params */
		public function __construct( private array $params ) {}
		/** @return array<string,string> */
		public function get_body_params(): array { return $this->params; }
		/** @return array<string,string> */
		public function get_params(): array { return $this->params; }
		/** @return array<string,string> */
		public function get_query_params(): array { return []; }
	}

	function current_time( string $type ): string {
		unset( $type );
		return '2026-09-13 04:00:00';
	}

	function rest_url( string $path = '' ): string {
		return 'https://fixture.invalid/wp-json/' . ltrim( $path, '/' );
	}

	function home_url( string $path = '' ): string {
		return 'https://fixture.invalid' . $path;
	}

	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}

	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}

	/** @param array<string,int|string> $args */
	function add_query_arg( array $args, string $url ): string {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
	}

	function wp_safe_redirect( string $url, int $status = 302 ): void {
		$GLOBALS['ys_ecpay_redirect'] = [ $url, $status ];
		throw new \RuntimeException( '__redirect__' );
	}
}

namespace YangSheep\Ecommerce\Models {
	final class YSOrder {
		public static int $tks_calls = 0;

		public static function find( int $id ): ?object {
			if ( 7 !== $id ) {
				return null;
			}
			return (object) [
				'id'             => 7,
				'order_number'   => 'YS-7',
				'total'          => 93,
				'payment_detail' => json_encode( [ 'mer_trade_no' => 'YS7TLOCAL' ] ),
			];
		}

		public static function generate_order_key( int $order_id, string $order_number ): string {
			return 'key-' . $order_id . '-' . $order_number;
		}

		public static function issue_tks_token( int $order_id ): void {
			if ( 7 === $order_id ) {
				++self::$tks_calls;
			}
		}
	}
}

namespace YangSheep\Ecommerce\Services\Setup {
	final class YSPageResolver {
		public static function url( string $page, string $fallback = '' ): string {
			return 'https://fixture.invalid/' . ( 'thankyou' === $page ? 'shop-confirmation/' : ltrim( $fallback, '/' ) );
		}

		/** @param array<string,int|string> $args */
		public static function thankyou_url( array $args = [] ): string {
			return $args ? \add_query_arg( $args, 'https://fixture.invalid/thankyou/' ) : 'https://fixture.invalid/thankyou/';
		}

		public static function checkout_url( array $args = [] ): string {
			return $args ? \add_query_arg( $args, 'https://fixture.invalid/checkout/' ) : 'https://fixture.invalid/checkout/';
		}
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class Settings {
		/** @return array{merchant_id:string,hash_key:string,hash_iv:string,test_mode:bool} */
		public static function payment_credentials(): array {
			return [
				'merchant_id' => 'LOCAL-MERCHANT',
				'hash_key'    => 'local-hash-key',
				'hash_iv'     => 'local-hash-iv',
				'test_mode'   => true,
			];
		}

		public static function payment_endpoint(): string {
			return 'https://payment.fixture.invalid/aio';
		}
	}

	final class ProviderMaintenanceLock {
		public static function reader_lease(): object {
			return (object) [ 'token' => 'fixture-lease' ];
		}

		public static function reader_fence( string $token ): bool {
			return 'fixture-lease' === $token;
		}
	}
}

namespace {
	use YangSheep\Ecommerce\Models\YSOrder;
	use YangSheep\YSCartEcpay\Api\EcpayPaymentController;
	use YangSheep\YSCartEcpay\Payment\EcpayPaymentClient;
	use YangSheep\YSCartEcpay\Support\CheckMacValue;

	$root = dirname( __DIR__, 2 );
	require_once $root . '/src/Support/Utf8Text.php';
	require_once $root . '/src/Support/CheckMacValue.php';
	require_once $root . '/src/Payment/EcpayPaymentClient.php';
	require_once $root . '/src/Ecpg/EcpgOrderContext.php';
	require_once $root . '/src/Api/EcpayPaymentController.php';

	$pass   = 0;
	$fail   = 0;
	$assert = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
		if ( $ok ) {
			++$pass;
			echo "  PASS  {$label}\n";
			return;
		}
		++$fail;
		echo "  FAIL  {$label}\n";
	};

	$order    = YSOrder::find( 7 );
	$expected = 'https://fixture.invalid/shop-confirmation/?order=7&key=key-7-YS-7';
	$form     = ( new EcpayPaymentClient() )->build_aio_form( $order, 'YS7TLOCAL', 'Credit' );
	$assert( $expected === ( $form['fields']['ClientBackURL'] ?? '' ), 'ClientBackURL carries the exact order id and generated key' );
	$assert( 0 === YSOrder::$tks_calls, 'form construction cannot mint a TKS token before a verified payment return' );

	$params = [
		'MerchantID'      => 'LOCAL-MERCHANT',
		'MerchantTradeNo' => 'YS7TLOCAL',
		'TradeAmt'        => '93',
		'RtnCode'         => '1',
	];
	$params['CheckMacValue'] = CheckMacValue::generate( $params, 'local-hash-key', 'local-hash-iv', 'sha256' );
	$GLOBALS['ys_ecpay_redirect'] = null;
	try {
		( new EcpayPaymentController() )->return_page( new WP_REST_Request( $params ) );
	} catch ( \RuntimeException $e ) {
		if ( '__redirect__' !== $e->getMessage() ) {
			throw $e;
		}
	}
	$assert( [ $expected, 302 ] === $GLOBALS['ys_ecpay_redirect'], 'signed OrderResultURL callback redirects to the exact Core thank-you URL' );
	$assert( 1 === YSOrder::$tks_calls, 'signed OrderResultURL callback issues one TKS token before redirect' );

	$params['CheckMacValue'] = 'INVALID';
	$GLOBALS['ys_ecpay_redirect'] = null;
	try {
		( new EcpayPaymentController() )->return_page( new WP_REST_Request( $params ) );
	} catch ( \RuntimeException $e ) {
		if ( '__redirect__' !== $e->getMessage() ) {
			throw $e;
		}
	}
	$assert( [ 'https://fixture.invalid/checkout/', 302 ] === $GLOBALS['ys_ecpay_redirect'], 'invalid callback fails closed to the generic checkout page' );
	$assert( 1 === YSOrder::$tks_calls, 'invalid callback cannot issue another TKS token' );

	echo "\nAIO thank-you return: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
