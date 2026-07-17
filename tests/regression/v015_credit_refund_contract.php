<?php
/**
 * v0.3.0 信用卡退刷（CreditDetail/DoAction）契約。
 *
 * Run: php tests/regression/v015_credit_refund_contract.php
 */

$root = dirname( __DIR__, 2 );

$read = static function ( string $relative ) use ( $root ): string {
	$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
	return is_file( $path ) ? (string) file_get_contents( $path ) : '';
};

$pass = 0;
$fail = 0;

$assert = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
	if ( $ok ) {
		++$pass;
		echo "  PASS  {$label}\n";
		return;
	}

	++$fail;
	echo "  FAIL  {$label}\n";
};

$settings = $read( 'src/Support/Settings.php' );
$client   = $read( 'src/Payment/EcpayPaymentClient.php' );
$credit   = $read( 'src/Payment/EcpayCreditGateway.php' );
$base     = $read( 'src/Payment/EcpayGatewayBase.php' );

$assert(
	str_contains( $settings, 'function payment_do_action_endpoint' )
	&& str_contains( $settings, 'payment-stage.ecpay.com.tw/CreditDetail/DoAction' )
	&& str_contains( $settings, 'payment.ecpay.com.tw/CreditDetail/DoAction' ),
	'Settings 提供 DoAction 端點（stage/production 依 test_mode 切換）'
);

$assert(
	str_contains( $client, 'function do_action_refund' )
	&& str_contains( $client, "string \$action = 'R'" )
	&& str_contains( $client, "in_array( \$action, [ 'R', 'N' ], true )" )
	&& str_contains( $client, 'CheckMacValue::generate' ),
	'client DoAction 支援 R／N 動作參數（白名單驗證）+ CheckMacValue 簽章'
);

$assert(
	str_contains( $client, '@deferred-sandbox' ),
	'退刷標記 @deferred-sandbox（對拍前不得宣稱支援）'
);

$assert(
	str_contains( $client, "'1' !== (string) ( \$data['RtnCode'] ?? '' )" )
	&& str_contains( $client, '退刷金額必須為正數' )
	&& str_contains( $client, '缺少綠界交易識別碼' ),
	'client fail-closed：RtnCode!=1／金額<=0／識別碼缺失一律失敗'
);

$assert(
	str_contains( $credit, 'function process_refund' )
	&& str_contains( $credit, "\$payment_detail['trade_no']" )
	&& str_contains( $credit, "\$payment_detail['mer_trade_no']" ),
	'credit gateway 退刷識別碼取自訂單付款紀錄（不信任外部輸入）'
);

$assert(
	str_contains( $credit, '$refundable = $total - $refunded;' )
	&& str_contains( $credit, 'round( $amount, 2 ) > round( $refundable, 2 )' ),
	'credit gateway 金額驗證：正數且不得超過可退餘額'
);

$assert(
	str_contains( $credit, 'function supports_gateway_refund' ),
	'credit gateway 宣告 supports_gateway_refund（core 退款 UI 能力協定）'
);

$assert(
	str_contains( $base, '此版本尚未提供綠界退款功能' ),
	'base（ATM／超商／barcode）維持不支援自動退款（產品決策：走人工）'
);

$assert(
	str_contains( $credit, "\$context['refund_request_id']" )
	&& str_contains( $credit, "'pending' === ( \$entry['status'] ?? '' )" )
	&& str_contains( $credit, '冪等重放' )
	&& str_contains( $credit, '拒絕重送' ),
	'crash-safe 冪等：pending 拒盲重送、done 冪等重放、failed 可重試'
);

$assert(
	str_contains( $credit, 'UNCLOSED_RTN_CODES' )
	&& str_contains( $credit, "do_action_refund( \$merchant_trade_no, \$trade_no, \$amount, 'N' )" )
	&& str_contains( $credit, '不支援部分退刷' ),
	'Action 分流：未關帳全額 → N fallback；未關帳部分金額 → 明確拒絕'
);

$gate_doc = $read( 'docs/credit-refund-sandbox-gate.md' );
$changelog = $read( 'CHANGELOG.md' );
$assert(
	str_contains( $gate_doc, 'G-2' ) && str_contains( $gate_doc, 'UNCLOSED_RTN_CODES' )
	&& str_contains( $changelog, '[Unreleased]' ) && str_contains( $changelog, '待綠界測試環境對拍' ),
	'sandbox gate 文件（含未關帳碼鎖定項）與 CHANGELOG 就位'
);

echo "\nv0.3.0 credit refund contract: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 ? 1 : 0 );
