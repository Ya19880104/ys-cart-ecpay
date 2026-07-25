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
	&& str_contains( $client, "'已授權'  => 'authorized'" )
	&& str_contains( $client, "'要關帳'  => 'to_close'" )
	&& str_contains( $client, "'已關帳'  => 'closed'" )
	&& str_contains( $client, "'操作取消' => 'cancelled'" )
	&& str_contains( $client, "\$state_map[ \$status_text ] ?? 'unknown'" ),
	'client 關帳狀態查詢：官方狀態映射（含操作取消）＋未映射一律 unknown（fail-closed）'
);

// F1（round-4）：查詢前置欄位齊備
$controller_src = $read( 'src/Api/EcpayPaymentController.php' );
$settings_admin = $read( 'src/Admin/EcpaySettings.php' );
$template_src   = $read( 'templates/admin/ecpay-settings.php' );
$assert(
	str_contains( $client, "'CreditCheckCode' => \$credit_check_code" )
	&& str_contains( $client, '尚未設定「信用卡查詢檢查碼」' )
	&& str_contains( $settings, "'credit_check_code' => 'ys_ec_ecpay_payment_credit_check_code'" )
	&& str_contains( $settings_admin, "'credit_check_code' ] as \$secret_key" )
	&& str_contains( $template_src, 'ys_ec_ecpay_payment_credit_check_code' ),
	'CreditCheckCode：必填檢查＋加密儲存設定鏈（Settings／save／UI 欄位）齊備'
);
$assert(
	str_contains( $client, "'NeedExtraPaidInfo' => 'Y'" )
	&& str_contains( $controller_src, "\$params['gwsr']" )
	&& 1 === preg_match( '/CheckMacValue.*?gwsr/su', $controller_src ),
	'建單送 NeedExtraPaidInfo=Y；notify 於 CheckMacValue 驗證後持久化 gwsr'
);

// F2（round-4）：close_data 最後一筆正金額判定
$assert(
	str_contains( $client, "close_data" )
	&& str_contains( $client, '最後一筆' )
	&& 1 === preg_match( '/foreach \( \$close_rows as \$row \).*?\$row_amount > 0/su', $client ),
	'關帳狀態以 close_data 最後一筆正金額紀錄判定（頂層 status 僅 fallback）'
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
	&& str_contains( $credit, "case 'cancelled':" )
	&& str_contains( $credit, '僅支援全額取消授權' )
	&& str_contains( $credit, "\$plan = [ 'N' ];" )
	&& str_contains( $credit, "\$plan = \$is_full ? [ 'E', 'N' ] : [ 'R' ];" )
	&& 1 === preg_match( '/case \'closed\':.*?\$plan = \[ \'R\' \];/su', $credit ),
	'官方狀態機：已授權/操作取消→N（部分拒絕）；要關帳全額→E,N／部分→R；已關帳→R'
);

// F3（round-4）：全單凍結 + pending 寫入失敗中止
$assert(
	str_contains( $credit, 'foreach ( $history as $frozen_id => $frozen_entry )' )
	&& str_contains( $credit, '拒絕所有新的退款操作' ),
	'全單凍結：任何結果未明的 attempt（不分 request_id）→ 拒絕一切新退款操作'
);
$assert(
	str_contains( $credit, '$persisted = YSOrder::update(' )
	&& str_contains( $credit, '冪等防線寫入失敗' ),
	'pending 持久化失敗 → 中止（未執行金流）'
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
	&& str_contains( $credit, '冪等重放' )
	&& str_contains( $credit, '全單凍結」統一擋' ),
	'crash-safe 冪等：done 冪等重放、pending 由全單凍結統一擋、failed 可重試'
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

// ── F4（round-5）：人工核定入口 + persist 檢查 + request_id 必填 ──
$cli_src    = $read( 'src/Cli/EcpayRefundAttemptCommand.php' );
$plugin_src = $read( 'src/Plugin.php' );
$assert(
	str_contains( $cli_src, "add_command( 'ys-ecpay refund-attempts'" )
	&& str_contains( $cli_src, "'pending' !== ( \$entry['status'] ?? '' )" )
	&& str_contains( $cli_src, "in_array( \$mark, [ 'done', 'failed' ], true )" )
	&& str_contains( $plugin_src, 'EcpayRefundAttemptCommand::register()' ),
	'CLI 核定入口：wp ys-ecpay refund-attempts（僅 pending 可核定）並於 Plugin 註冊'
);
// ── R6-F6：resolve 必須是真 CAS（conditional UPDATE），不得是 read-modify-write ──
$assert(
	str_contains( $cli_src, 'SELECT payment_detail FROM' )
	&& str_contains( $cli_src, 'AND payment_detail = %s' )
	&& str_contains( $cli_src, '1 !== (int) $updated' )
	&& str_contains( $cli_src, 'CAS 失敗' )
	&& ! str_contains( $cli_src, 'YSOrder::update(' ),
	'resolve＝真 CAS：raw 讀取＋conditional UPDATE（WHERE payment_detail=舊值）＋rows==1 檢查，無 YSOrder::update 無條件覆寫'
);
$assert(
	str_contains( $credit, '缺少 refund_request_id（冪等鍵），已拒絕退款操作' ),
	'refund_request_id 必填（缺 key 一律拒絕）'
);
$assert(
	str_contains( $credit, 'function ( array $entry ) use ( $order_id, $request_id ): bool' )
	&& 3 === substr_count( $credit, 'if ( ! $persist(' )
	&& str_contains( $credit, 'done-status persist failed' ),
	'persist 回傳檢查：三個 call site 全檢查、done 寫失敗 CRITICAL＋CLI 導引'
);

// ── R7-F1：process_refund 各失敗點回 typed outcome（indeterminate vs rejected_terminal）──
$assert(
	str_contains( $credit, "'outcome' => 'indeterminate'" )
	&& str_contains( $credit, "'outcome' => 'rejected_terminal'" ),
	'R7-F1：process_refund 回 typed outcome（DoAction 不確定→indeterminate 凍結、明確拒絕→rejected_terminal）'
);
// pre-DoAction 業務拒絕（訂單/金額/識別碼/gwsr/unknown/尚未請款/persist）皆 terminal——
// 至少 6 處 rejected_terminal（金流未動、可安全重試）。
$assert(
	substr_count( $credit, "'outcome' => 'rejected_terminal'" ) >= 6,
	'R7-F1：pre-DoAction 業務拒絕全標 rejected_terminal（金流未動、可重試）'
);
// 全單凍結（既有 pending attempt）與 DoAction 傳輸不確定＝indeterminate（core 凍結）。
$assert(
	substr_count( $credit, "'outcome' => 'indeterminate'" ) >= 2,
	'R7-F1：全單凍結＋DoAction 不確定＝indeterminate（core 維持凍結）'
);

// ── R8-F3：雙 ledger 協調——監聽核心核定同步本外掛 ledger（解除死結）──
$assert(
	str_contains( $cli_src, 'public static function register_core_sync' )
	// R9-F3：改用 filter 回報 typed 結果（不再是單向 action）。
	&& str_contains( $cli_src, "add_filter( 'ys_ec_refund_finalization_sync'" )
	&& str_contains( $cli_src, 'core-finalization-sync' )
	&& str_contains( $plugin_src, 'register_core_sync()' ),
	'R9-F3：以 filter ys_ec_refund_finalization_sync 同步本外掛 attempt（Plugin 已註冊）'
);
$assert(
	str_contains( $cli_src, "'provider' => 'ecpay'" )
	&& str_contains( $cli_src, "'success'  => false" )
	&& str_contains( $cli_src, "'success'  => true" )
	&& str_contains( $cli_src, 'wp ys-ecpay refund-attempts resolve --order=' ),
	'R9-F3：CAS 成功/失敗皆回報 typed result；失敗時附手動補救指令'
);
$assert(
	str_contains( $cli_src, 'AND payment_detail = %s' )
	&& str_contains( $cli_src, "'pending' !== ( \$entry['status'] ?? '' )" ),
	'R8-F3：同步走真 CAS＋僅 pending 才改（不覆蓋他人變更）'
);
$assert(
	str_contains( $cli_src, 'wp ys-cart refund-finalization resolve' )
	&& ! str_contains( $cli_src, '請於後台以相同退款操作補齊核心帳務' ),
	'R8-F3：CLI 訊息改指向核心 CLI（舊「相同退款操作」指引已移除——核心凍結會擋住）'
);
// R10-F4：宣告 requires_sync——listener 缺席（零回報）不得被 core 當同步成功。
$assert(
	str_contains( $cli_src, "add_filter( 'ys_ec_refund_finalization_requires_sync'" )
	&& str_contains( $cli_src, "'ys_ec_ecpay_credit' === \$gateway_id" ),
	'R10-F4：宣告 requires_sync（core 據此把零回報判為同步失敗）'
);

echo "\nv0.3.0 credit refund contract: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 ? 1 : 0 );
