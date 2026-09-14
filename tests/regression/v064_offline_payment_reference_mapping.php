<?php
/**
 * v064 — ECPay ATM/CVS payment references stay type-correct.
 *
 * This executes the production callback and reconciliation mappers through
 * reflection.  BankCode is bank metadata, never a payment reference, and a
 * response which omits the current account/code must not erase a known value.
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

namespace YangSheep\Ecommerce\DTOs {
	final class YSPaymentDetailDTO {
		/** @param array<string,mixed> $fields */
		private function __construct( private array $fields ) {}
		/** @param array<string,mixed> $fields */
		public static function from_legacy_array( array $fields, string $gateway_id_hint = '' ): self {
			unset( $gateway_id_hint );
			return new self( $fields );
		}
		/** @return array<string,mixed> */
		public function to_array(): array {
			return array_filter( $this->fields, static fn( mixed $value ): bool => null !== $value && '' !== $value );
		}
	}
}

namespace YangSheep\Ecommerce\Services\Payment {
	interface YSPaymentReconcilerInterface {}
	final class YSPaymentReconcileResult {
		public function __construct( public ?\YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO $detail = null ) {}
		public static function __callStatic( string $name, array $arguments ): self {
			unset( $name );
			foreach ( $arguments as $argument ) {
				if ( $argument instanceof \YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO ) {
					return new self( $argument );
				}
			}
			return new self();
		}
	}
}

namespace YangSheep\YSCartEcpay\Payment {
	final class EcpayPaymentClient {
		/** @var array<string,mixed> */
		public static array $response = [];
		/** @return array<string,mixed> */
		public function query_trade( string $merchant_trade_no ): array {
			unset( $merchant_trade_no );
			return self::$response;
		}
	}
}

namespace {
	use YangSheep\YSCartEcpay\Api\EcpayPaymentController;
	use YangSheep\YSCartEcpay\Payment\EcpayPaymentClient;
	use YangSheep\YSCartEcpay\Payment\EcpayPaymentReconciler;

	$root = dirname( __DIR__, 2 );
	require_once $root . '/src/Api/EcpayPaymentController.php';
	require_once $root . '/src/Payment/EcpayPaymentReconciler.php';

	$pass = 0;
	$fail = 0;
	$assert = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
		if ( $ok ) {
			++$pass;
			echo "PASS {$label}\n";
			return;
		}
		++$fail;
		echo "FAIL {$label}\n";
	};

	$callback_method = new \ReflectionMethod( EcpayPaymentController::class, 'detail_from_payload' );
	$query_method    = new \ReflectionMethod( EcpayPaymentReconciler::class, 'detail_from_query' );
	$controller      = new EcpayPaymentController();
	$reconciler      = new EcpayPaymentReconciler();
	$map_callback    = static fn( array $params, string $gateway_id = '' ): array => $callback_method
		->invoke( $controller, $params, $gateway_id )
		->to_array();
	$map_query       = static fn( array $params, string $gateway_id = '' ): array => $query_method
		->invoke( $reconciler, $params, $gateway_id )
		->to_array();

	$atm = [
		'PaymentType' => 'ATM_TAISHIN',
		'vAccount'    => '0012345678901234',
		'PaymentNo'   => 'WRONG-CVS-VALUE',
		'BankCode'    => '812',
	];
	$callback_atm = $map_callback( $atm, 'ys_ec_ecpay_atm' );
	$query_atm    = $map_query( $atm, 'ys_ec_ecpay_atm' );
	$assert(
		'0012345678901234' === ( $callback_atm['pay_no'] ?? null )
			&& '0012345678901234' === ( $query_atm['pay_no'] ?? null )
			&& '812' === ( $callback_atm['bank_type'] ?? null ),
		'A1 ATM callback/query use vAccount byte-for-byte and retain BankCode only as bank_type'
	);

	$cvs = [
		'PaymentType' => 'CVS_CVS',
		'PaymentNo'   => '009988776655',
		'vAccount'    => 'WRONG-ATM-VALUE',
		'BankCode'    => '013',
	];
	$callback_cvs = $map_callback( $cvs, 'ys_ec_ecpay_cvs' );
	$query_cvs    = $map_query( $cvs, 'ys_ec_ecpay_cvs' );
	$assert(
		'009988776655' === ( $callback_cvs['pay_no'] ?? null )
			&& '009988776655' === ( $query_cvs['pay_no'] ?? null ),
		'A2 CVS callback/query use PaymentNo byte-for-byte'
	);

	$stored = [ 'pay_no' => '0000111122223333', 'bank_type' => '007' ];
	$atm_without_account = [ 'PaymentType' => 'ATM_TAISHIN', 'BankCode' => '812', 'PaymentNo' => 'WRONG-CVS-VALUE' ];
	$cvs_without_code    = [ 'PaymentType' => 'CVS_CVS', 'BankCode' => '013', 'vAccount' => 'WRONG-ATM-VALUE' ];
	foreach ( [
		'callback ATM' => $map_callback( $atm_without_account, 'ys_ec_ecpay_atm' ),
		'query ATM'    => $map_query( $atm_without_account, 'ys_ec_ecpay_atm' ),
		'callback CVS' => $map_callback( $cvs_without_code, 'ys_ec_ecpay_cvs' ),
		'query CVS'    => $map_query( $cvs_without_code, 'ys_ec_ecpay_cvs' ),
	] as $label => $fresh ) {
		$assert(
			! array_key_exists( 'pay_no', $fresh )
				&& '0000111122223333' === ( array_replace( $stored, $fresh )['pay_no'] ?? null ),
			'A3 ' . $label . ' omits an unavailable reference and preserves the stored value'
		);
	}

	$gateway_only_atm = $map_query( [ 'vAccount' => '0000001234567890', 'BankCode' => '004' ], 'ys_ec_ecpay_atm' );
	$gateway_only_cvs = $map_query( [ 'PaymentNo' => '000001234567', 'BankCode' => '004' ], 'ys_ec_ecpay_cvs' );
	$assert(
		'0000001234567890' === ( $gateway_only_atm['pay_no'] ?? null )
			&& '000001234567' === ( $gateway_only_cvs['pay_no'] ?? null ),
		'A4 gateway identity supplies the ATM/CVS type when QueryTrade omits PaymentType'
	);

	EcpayPaymentClient::$response = [
		'success' => true,
		'data'    => [
			'MerchantTradeNo' => 'YSLEGACYATM001',
			'TradeStatus'      => '0',
			'vAccount'         => '0000009988776655',
			'BankCode'         => '812',
		],
	];
	$legacy_atm_order = (object) [
		'gateway_id'     => null,
		'payment_method' => 'ys_ec_ecpay_atm',
		'payment_detail' => json_encode( [ 'ecpay_merchant_trade_no' => 'YSLEGACYATM001' ] ),
	];
	$legacy_atm_result = $reconciler->reconcile( $legacy_atm_order );
	$assert(
		$reconciler->supports( $legacy_atm_order )
			&& '0000009988776655' === ( $legacy_atm_result->detail?->to_array()['pay_no'] ?? null ),
		'A4b production reconcile call-site falls back from empty gateway_id to legacy ATM payment_method'
	);

	$bank_only_callback = $map_callback( [ 'BankCode' => '004' ] );
	$bank_only_query    = $map_query( [ 'BankCode' => '004' ], 'ys_ec_ecpay_credit' );
	$assert(
		! array_key_exists( 'pay_no', $bank_only_callback )
			&& ! array_key_exists( 'pay_no', $bank_only_query ),
		'A5 BankCode alone never becomes pay_no'
	);

	echo "\noffline payment reference mapping: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
