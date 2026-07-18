<?php
/**
 * v0.3.0 信用卡退款（query-first 狀態機 + crash-safe 冪等）契約。
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
$gate_doc = $read( 'docs/credit-refund-sandbox-gate.md' );
$changelog = $read( 'CHANGELOG.md' );

// ── 端點與 client 契約 ──
$assert(
	str_contains( $settings, 'function payment_do_action_endpoint' )
	&& str_contains( $settings, 'function payment_credit_query_endpoint' )
	&& str_contains( $settings, 'CreditDetail/QueryTrade/V2' )
	&& str_contains( $settings, 'DoAction 不可用' ),
	'Settings：DoAction＋QueryTrade V2 端點齊備、明載 stage DoAction 官方不可用'
);

$assert(
	str_contains( $client, 'function query_credit_close_status' )
	&& str_contains( $client, "'已授權' => 'authorized'" )
	&& str_contains( $client, "'要關帳' => 'to_close'" )
	&& str_contains( $client, "'已關帳' => 'closed'" )
	&& str_contains( $client, "\$state_map[ \$status_text ] ?? 'unknown'" ),
	'client 關帳狀態查詢：官方狀態映射＋未映射一律 unknown（fail-closed）'
);

$assert(
	str_contains( $client, "in_array( \$action, [ 'R', 'N', 'E' ], true )" )
	&& str_contains( $client, 'CheckMacValue::generate' ),
	'client DoAction 支援 R／N／E 動作白名單 + CheckMacValue 簽章'
);

// ── F4：傳輸不確定三分類（indeterminate ≠ failed）──
$assert(
	substr_count( $client, "'indeterminate' => true" ) >= 3
	&& str_contains( $client, '傳輸層失敗' )
	&& str_contains( $client, '無 RtnCode' )
	&& 1 === preg_match( '/provider 明確拒絕.*?\'indeterminate\' => false/su', $client ),
	'client 三分類：timeout／非 2xx／無 RtnCode＝indeterminate；RtnCode≠1 才是明確拒絕'
);

// ── F2：query-first 狀態機 ──
$assert(
	1 === preg_match( '/query_credit_close_status.*?do_action_refund/su', $credit )
	&& ! str_contains( $credit, 'UNCLOSED_RTN_CODES' ),
	'gateway query-first：先查關帳狀態再 DoAction（舊「先試 R 再猜」已移除）'
);

$assert(
	str_contains( $credit, "case 'authorized':" )
	&& str_contains( $credit, '僅支援全額取消授權' )
	&& str_contains( $credit, "\$plan = [ 'N' ];" )
	&& str_contains( $credit, "\$plan = \$is_full ? [ 'E', 'N' ] : [ 'R' ];" )
	&& 1 === preg_match( '/case \'closed\':.*?\$plan = \[ \'R\' \];/su', $credit ),
	'官方狀態機：已授權→N（部分拒絕）；要關帳全額→E,N／部分→R；已關帳→R'
);

$assert(
	str_contains( $credit, "'unknown' === ( \$close['state'] ?? 'unknown' )" )
	&& str_contains( $credit, '已中止退款操作' ),
	'關帳狀態 unknown → 拒絕操作（不猜、不送 DoAction）'
);

$assert(
	str_contains( $credit, "\$payment_detail['gwsr']" )
	&& str_contains( $credit, "\$query['data']['gwsr']" )
	&& str_contains( $credit, '無法取得綠界授權單號' ),
	'gwsr 取得鏈：付款紀錄 → QueryTradeInfo 補查回寫 → 皆無則人工處理'
);

// ── F4：不確定結果凍結（維持 pending、禁重送）──
$assert(
	str_contains( $credit, "! empty( \$result['indeterminate'] )" )
	&& str_contains( $credit, '為避免重複退款已凍結此請求' )
	&& 1 === preg_match( '/indeterminate.*?\$persist\( \[ \'executed\'/su', $credit )
	&& ! preg_match( '/indeterminate[^}]*?\'status\'\s*=>\s*\'failed\'/su', $credit ),
	'傳輸不確定 → attempt 維持 pending（不標 failed、不開放重試）'
);

// ── crash-safe 冪等（沿前版） ──
$assert(
	str_contains( $credit, "\$context['refund_request_id']" )
	&& str_contains( $credit, "'pending' === ( \$entry['status'] ?? '' )" )
	&& str_contains( $credit, '冪等重放' )
	&& str_contains( $credit, '拒絕重送' ),
	'crash-safe 冪等：pending 拒盲重送、done 冪等重放、failed 可重試'
);

// ── 能力宣告與金額/識別碼防護（沿前版） ──
$assert(
	str_contains( $credit, 'function supports_gateway_refund' )
	&& str_contains( $credit, '$refundable = $total - $refunded;' )
	&& str_contains( $credit, "\$payment_detail['trade_no']" ),
	'能力宣告＋金額上限＋識別碼取自訂單付款紀錄'
);

$assert(
	str_contains( $base, '此版本尚未提供綠界退款功能' ),
	'base（ATM／超商／barcode）維持不支援自動退款（產品決策：走人工）'
);

// ── F3：gate 文件改「受控正式商店」──
$assert(
	str_contains( $gate_doc, '不使用 stage 對拍' )
	&& str_contains( $gate_doc, '受控正式商店' )
	&& str_contains( $gate_doc, 'G-Q' )
	&& str_contains( $gate_doc, '結構測試' )
	&& str_contains( $client, '@deferred-live-verification' )
	&& str_contains( $credit, '@deferred-live-verification' )
	&& ! str_contains( $client, '@deferred-sandbox' )
	&& str_contains( $changelog, '受控正式商店' ),
	'gate 改受控正式環境驗證（stage 不可用）；mock 定位為結構測試；標記改 @deferred-live-verification'
);

echo "\nv0.3.0 credit refund contract: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 ? 1 : 0 );
