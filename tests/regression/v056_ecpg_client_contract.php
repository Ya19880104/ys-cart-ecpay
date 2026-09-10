<?php
/**
 * v056 — 站內付 2.0 客戶端契約：信封、結果分類、送出前置條件（v0.5.0）
 *
 * 三件事各有一個安靜的失敗形態，本檔對著 production `EcpgClient` 驗證：
 *
 *   1. 信封形狀：`RqHeader` 只有 `Timestamp`（整數 Unix 秒）。多帶 `Revision`（電子發票／
 *      物流的欄位）綠界直接解析失敗；Timestamp 帶字串同理。
 *   2. 結果分類：TransCode 與 RtnCode **兩層都看**。逾時、HTTP 非 200、Data 解不開、
 *      RtnCode 10300066 一律 indeterminate——把它們壓成失敗，下一次扣款就可能重複收錢。
 *   3. 送出前置：憑證不完整／維護鎖／「送出意圖落不了盤」時**什麼都不能送**；會授權的
 *      端點送出前必須把 dispatch 標成 submitted，不授權的端點不得去碰 dispatch。
 *
 * WordPress 與核心以替身提供；`wp_remote_post` 的替身記錄每一次請求並回傳腳本化回應。
 *
 * Run: php tests/regression/v056_ecpg_client_contract.php
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	final class WP_Error {
		public function __construct( private string $message ) {}
		public function get_error_message(): string { return $this->message; }
	}

	/** @var array<int,array{url:string,args:array}> */
	$GLOBALS['ys_ecpg_requests'] = [];
	/** @var mixed 下一次 wp_remote_post 要回的東西 */
	$GLOBALS['ys_ecpg_next_response'] = null;

	function wp_remote_post( string $url, array $args ) {
		$GLOBALS['ys_ecpg_requests'][] = [ 'url' => $url, 'args' => $args ];
		return $GLOBALS['ys_ecpg_next_response'];
	}
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
	function wp_remote_retrieve_response_code( $response ): int { return (int) ( $response['code'] ?? 0 ); }
	function wp_remote_retrieve_body( $response ): string { return (string) ( $response['body'] ?? '' ); }
}

namespace YangSheep\YSCartEcpay\Support {
	final class Settings {
		public static array $credentials = [ 'test_mode' => true, 'merchant_id' => '3002607', 'hash_key' => 'pwFHCqoQZGmho4w6', 'hash_iv' => 'EkRm7iFT261dpevs' ];
		public static function payment_credentials(): array { return self::$credentials; }
	}
	final class ProviderMaintenanceLock {
		public static bool $lease_available = true;
		public static bool $fence_ok = true;
		public static function reader_lease(): ?object { return self::$lease_available ? (object) [ 'token' => 'fixture-lease' ] : null; }
		public static function reader_fence( string $token ): bool { return self::$fence_ok && 'fixture-lease' === $token; }
	}
}

namespace YangSheep\Ecommerce\Services\Payment {
	final class YSPaymentDispatch {
		public static bool $submitted_result = true;
		public static int $submitted_calls = 0;
		public static function mark_submitted_from_context(): bool {
			++self::$submitted_calls;
			return self::$submitted_result;
		}
	}
}

namespace {
	$root = str_replace( '\\', '/', dirname( __DIR__, 2 ) );
	require_once $root . '/src/Ecpg/EcpgAesCodec.php';
	require_once $root . '/src/Ecpg/EcpgClient.php';

	use YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch;
	use YangSheep\YSCartEcpay\Ecpg\EcpgAesCodec;
	use YangSheep\YSCartEcpay\Ecpg\EcpgClient;
	use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
	use YangSheep\YSCartEcpay\Support\Settings;

	$pass   = 0;
	$fail   = 0;
	$assert = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
		if ( $ok ) { ++$pass; echo "  PASS  {$label}\n"; return; }
		++$fail; echo "  FAIL  {$label}\n";
	};
	$key = Settings::$credentials['hash_key'];
	$iv  = Settings::$credentials['hash_iv'];
	/** 綠界會回給我們的信封（Data 用同一把金鑰加密）。 */
	$reply = static function ( array $data, int $trans_code = 1, string $trans_msg = 'Success' ) use ( $key, $iv ): array {
		return [ 'MerchantID' => '3002607', 'RpHeader' => [ 'Timestamp' => 1234564848 ], 'TransCode' => $trans_code, 'TransMsg' => $trans_msg, 'Data' => EcpgAesCodec::encrypt( $data, $key, $iv ) ];
	};
	$http = static function ( array $outer, int $code = 200 ): array {
		return [ 'code' => $code, 'body' => json_encode( $outer ) ];
	};
	$reset = static function (): void {
		$GLOBALS['ys_ecpg_requests'] = [];
		YSPaymentDispatch::$submitted_calls  = 0;
		YSPaymentDispatch::$submitted_result = true;
		ProviderMaintenanceLock::$lease_available = true;
		ProviderMaintenanceLock::$fence_ok = true;
		Settings::$credentials = [ 'test_mode' => true, 'merchant_id' => '3002607', 'hash_key' => 'pwFHCqoQZGmho4w6', 'hash_iv' => 'EkRm7iFT261dpevs' ];
	};

	echo "## v056 ECPG client contract\n";

	// ── A. 信封 ──────────────────────────────────────────────────────────────
	$envelope = EcpgClient::build_envelope( '3002607', [ 'MerchantTradeNo' => 'YSABC' ], $key, $iv, 1700000000 );
	$assert( [ 'MerchantID', 'RqHeader', 'Data' ] === array_keys( $envelope ), 'A1 envelope has exactly MerchantID / RqHeader / Data' );
	$assert( [ 'Timestamp' => 1700000000 ] === $envelope['RqHeader'], 'A2 RqHeader carries only an integer Timestamp (no Revision)' );
	$assert( EcpgAesCodec::decrypt( $envelope['Data'], $key, $iv ) === [ 'MerchantTradeNo' => 'YSABC' ], 'A3 Data decrypts back to the payload' );
	$auto = EcpgClient::build_envelope( '3002607', [], $key, $iv );
	$assert( is_int( $auto['RqHeader']['Timestamp'] ) && abs( $auto['RqHeader']['Timestamp'] - time() ) < 5, 'A4 default Timestamp is now, as an int' );

	// ── B. 分類 ──────────────────────────────────────────────────────────────
	$c = EcpgClient::classify_response( null, $key, $iv );
	$assert( EcpgClient::OUTCOME_INDETERMINATE === $c['outcome'] && null === $c['data'], 'B1 non-array body → indeterminate' );
	$c = EcpgClient::classify_response( [ 'TransCode' => 10200052, 'TransMsg' => 'Decrypt failed' ], $key, $iv );
	$assert( EcpgClient::OUTCOME_PROVIDER_FAILED === $c['outcome'] && 10200052 === $c['trans_code'] && null === $c['rtn_code'], 'B2 TransCode≠1 → provider_failed (nothing was processed)' );
	$c = EcpgClient::classify_response( [ 'TransCode' => 1, 'Data' => 'AAAA' ], $key, $iv );
	$assert( EcpgClient::OUTCOME_INDETERMINATE === $c['outcome'] && 1 === $c['trans_code'], 'B3 TransCode 1 but undecryptable Data → indeterminate' );
	$c = EcpgClient::classify_response( $reply( [ 'RtnCode' => 1, 'RtnMsg' => 'Success', 'Token' => 'tok' ] ), $key, $iv );
	$assert( EcpgClient::OUTCOME_SUCCESS === $c['outcome'] && 1 === $c['rtn_code'] && 'tok' === ( $c['data']['Token'] ?? null ), 'B4 TransCode 1 + RtnCode 1 → success with decrypted data' );
	$c = EcpgClient::classify_response( $reply( [ 'RtnCode' => '1', 'RtnMsg' => 'Success' ] ), $key, $iv );
	$assert( EcpgClient::OUTCOME_SUCCESS === $c['outcome'], 'B5 numeric-string RtnCode "1" is still success' );
	$c = EcpgClient::classify_response( $reply( [ 'RtnCode' => 10300066, 'RtnMsg' => '交易付款結果待確認中' ] ), $key, $iv );
	$assert( EcpgClient::OUTCOME_INDETERMINATE === $c['outcome'] && 10300066 === $c['rtn_code'], 'B6 RtnCode 10300066 → indeterminate, never failed' );
	$c = EcpgClient::classify_response( $reply( [ 'RtnCode' => 10100252, 'RtnMsg' => '額度不足' ] ), $key, $iv );
	$assert( EcpgClient::OUTCOME_PROVIDER_FAILED === $c['outcome'] && 10100252 === $c['rtn_code'] && str_contains( $c['message'], '額度不足' ), 'B7 other RtnCode → provider_failed carrying code and message' );
	$c = EcpgClient::classify_response( $reply( [ 'RtnMsg' => 'no code' ] ), $key, $iv );
	$assert( EcpgClient::OUTCOME_PROVIDER_FAILED === $c['outcome'] && null === $c['rtn_code'], 'B8 missing RtnCode is not success' );

	// ── C. 回呼解碼 ──────────────────────────────────────────────────────────
	$cb = $reply( [ 'RtnCode' => 1, 'OrderInfo' => [ 'MerchantTradeNo' => 'YSX', 'TradeStatus' => '1' ], 'ThreeDInfo' => [ 'ThreeDURL' => ' https://3d.example/ ' ] ] );
	$decoded = EcpgClient::decode_callback( json_encode( $cb ), $key, $iv );
	$assert( is_array( $decoded ) && 'YSX' === $decoded['OrderInfo']['MerchantTradeNo'], 'C1 ResultData JSON string decodes to Data' );
	$assert( is_array( EcpgClient::decode_callback( $cb, $key, $iv ) ), 'C2 already-decoded array decodes too' );
	$assert( null === EcpgClient::decode_callback( json_encode( $reply( [ 'RtnCode' => 1 ], 0, 'bad' ) ), $key, $iv ), 'C3 TransCode≠1 callback → null' );
	$assert( null === EcpgClient::decode_callback( '{not json', $key, $iv ), 'C4 garbage → null' );
	$assert( null === EcpgClient::decode_callback( json_encode( $cb ), 'ejCk326UnaZWKisg', 'q9jcZX8Ib9LM8wYk' ), 'C5 another merchant key → null' );
	$assert( '1' === EcpgClient::trade_status( $decoded ) && 'https://3d.example/' === EcpgClient::three_d_url( $decoded ), 'C6 trade_status / three_d_url read nested fields (ThreeDURL trimmed)' );
	$assert( null === EcpgClient::trade_status( [ 'RtnCode' => 1 ] ) && '' === EcpgClient::three_d_url( [ 'ThreeDInfo' => [] ] ), 'C7 missing nested fields → null / empty string' );

	// ── D. hosts ─────────────────────────────────────────────────────────────
	$assert( 'https://ecpg-stage.ecpay.com.tw' === EcpgClient::token_host( true ) && 'https://ecpg.ecpay.com.tw' === EcpgClient::token_host( false ), 'D1 token host follows test_mode' );
	$assert( 'https://ecpayment-stage.ecpay.com.tw' === EcpgClient::trade_host( true ) && 'https://ecpayment.ecpay.com.tw' === EcpgClient::trade_host( false ), 'D2 trade host follows test_mode' );
	$assert( 'https://ecpg-stage.ecpay.com.tw/Scripts/sdk-1.0.0.js?t=20210121100116' === EcpgClient::sdk_url( true ) && str_starts_with( EcpgClient::sdk_url( false ), 'https://ecpg.ecpay.com.tw/' ), 'D3 SDK script comes from the matching ecpg host' );

	// ── E. 送出前置 ──────────────────────────────────────────────────────────
	$client = new EcpgClient();
	$reset();
	Settings::$credentials['hash_key'] = '';
	$r = $client->create_payment( 'pt', 'YSX' );
	$assert( EcpgClient::OUTCOME_REJECTED === $r['outcome'] && false === $r['sent'] && [] === $GLOBALS['ys_ecpg_requests'], 'E1 incomplete credentials → rejected, nothing sent' );

	$reset();
	ProviderMaintenanceLock::$lease_available = false;
	$r = $client->create_payment( 'pt', 'YSX' );
	$assert( EcpgClient::OUTCOME_REJECTED === $r['outcome'] && [] === $GLOBALS['ys_ecpg_requests'], 'E2 no reader lease → rejected, nothing sent' );

	$reset();
	ProviderMaintenanceLock::$fence_ok = false;
	$r = $client->create_payment( 'pt', 'YSX' );
	$assert( EcpgClient::OUTCOME_REJECTED === $r['outcome'] && [] === $GLOBALS['ys_ecpg_requests'] && 0 === YSPaymentDispatch::$submitted_calls, 'E3 fence lost → rejected before touching dispatch or network' );

	$reset();
	YSPaymentDispatch::$submitted_result = false;
	$r = $client->create_payment_with_card_id( [ 'BindCardID' => 'b' ] );
	$assert( EcpgClient::OUTCOME_REJECTED === $r['outcome'] && [] === $GLOBALS['ys_ecpg_requests'] && 1 === YSPaymentDispatch::$submitted_calls, 'E4 authorising endpoint: submitted-intent not durable → rejected, nothing sent' );

	$reset();
	$GLOBALS['ys_ecpg_next_response'] = $http( $reply( [ 'RtnCode' => 1, 'Token' => 'm12' ] ) );
	$r = $client->get_token_by_trade( [ 'RememberCard' => 0 ] );
	$assert( EcpgClient::OUTCOME_SUCCESS === $r['outcome'] && 0 === YSPaymentDispatch::$submitted_calls, 'E5 non-authorising endpoint never touches the dispatch' );
	$req = $GLOBALS['ys_ecpg_requests'][0];
	$assert( 'https://ecpg-stage.ecpay.com.tw/Merchant/GetTokenbyTrade' === $req['url'], 'E6 token endpoint goes to the ecpg stage host' );
	$assert( 20 === $req['args']['timeout'], 'E7 non-authorising timeout is 20s' );
	$sent = json_decode( (string) $req['args']['body'], true );
	$assert( [ 'MerchantID', 'RqHeader', 'Data' ] === array_keys( $sent ) && '3002607' === $sent['MerchantID'] && is_int( $sent['RqHeader']['Timestamp'] ), 'E8 request body is the three-key envelope' );
	$payload = EcpgAesCodec::decrypt( $sent['Data'], $key, $iv );
	$assert( [ 'PlatformID' => '', 'MerchantID' => '3002607', 'RememberCard' => 0 ] === $payload, 'E9 PlatformID (empty) and MerchantID are injected ahead of the business fields' );
	$assert( str_starts_with( (string) $req['args']['headers']['Content-Type'], 'application/json' ), 'E10 Content-Type is application/json' );

	$reset();
	$GLOBALS['ys_ecpg_next_response'] = $http( $reply( [ 'RtnCode' => 1, 'OrderInfo' => [ 'TradeNo' => 'T1', 'TradeStatus' => '1' ] ] ) );
	$r = $client->create_payment_with_card_id( [ 'BindCardID' => 'b' ] );
	$req = $GLOBALS['ys_ecpg_requests'][0];
	$assert( EcpgClient::OUTCOME_SUCCESS === $r['outcome'] && 1 === YSPaymentDispatch::$submitted_calls && 35 === $req['args']['timeout'], 'E11 authorising endpoint marks submitted first and uses the 35s timeout' );
	$assert( 'https://ecpg-stage.ecpay.com.tw/Merchant/CreatePaymentWithCardID' === $req['url'], 'E12 CreatePaymentWithCardID path' );

	$reset();
	Settings::$credentials['test_mode'] = false;
	$GLOBALS['ys_ecpg_next_response'] = $http( $reply( [ 'RtnCode' => 1 ] ) );
	$client->query_trade( 'YSX' );
	$assert( 'https://ecpayment.ecpay.com.tw/1.0.0/Cashier/QueryTrade' === $GLOBALS['ys_ecpg_requests'][0]['url'], 'E13 QueryTrade goes to the ecpayment LIVE host when test_mode is off' );

	$reset();
	$GLOBALS['ys_ecpg_next_response'] = $http( $reply( [ 'RtnCode' => 1 ] ) );
	$client->get_member_bind_card( 'YSC3', 'YSX' );
	$payload = EcpgAesCodec::decrypt( json_decode( (string) $GLOBALS['ys_ecpg_requests'][0]['args']['body'], true )['Data'], $key, $iv );
	$assert( 'YSC3' === $payload['MerchantMemberID'] && 'YSX' === $payload['MerchantTradeNo'], 'E14 GetMemberBindCard carries member id and trade no' );
	$reset();
	$GLOBALS['ys_ecpg_next_response'] = $http( $reply( [ 'RtnCode' => 1 ] ) );
	$client->create_bind_card( 'bcpt', 'YSC3' );
	$payload = EcpgAesCodec::decrypt( json_decode( (string) $GLOBALS['ys_ecpg_requests'][0]['args']['body'], true )['Data'], $key, $iv );
	$assert( 'bcpt' === $payload['BindCardPayToken'] && 'YSC3' === $payload['MerchantMemberID'] && 1 === YSPaymentDispatch::$submitted_calls, 'E15 CreateBindCard is an authorising call carrying BindCardPayToken + MerchantMemberID' );

	// ── F. 傳輸層結果 ────────────────────────────────────────────────────────
	$reset();
	$GLOBALS['ys_ecpg_next_response'] = new WP_Error( 'cURL error 28: timeout' );
	$r = $client->create_payment( 'pt', 'YSX' );
	$assert( EcpgClient::OUTCOME_INDETERMINATE === $r['outcome'] && true === $r['sent'] && str_contains( $r['message'], 'timeout' ), 'F1 transport error → indeterminate, sent=true' );
	$reset();
	$GLOBALS['ys_ecpg_next_response'] = [ 'code' => 502, 'body' => 'Bad Gateway' ];
	$r = $client->create_payment( 'pt', 'YSX' );
	$assert( EcpgClient::OUTCOME_INDETERMINATE === $r['outcome'] && 502 === $r['http_status'], 'F2 HTTP 502 → indeterminate with status' );
	$reset();
	$GLOBALS['ys_ecpg_next_response'] = [ 'code' => 200, 'body' => '<html>maintenance</html>' ];
	$r = $client->create_payment( 'pt', 'YSX' );
	$assert( EcpgClient::OUTCOME_INDETERMINATE === $r['outcome'], 'F3 200 with non-JSON body → indeterminate' );
	$reset();
	$GLOBALS['ys_ecpg_next_response'] = $http( $reply( [ 'RtnCode' => 10100251, 'RtnMsg' => '卡片過期' ] ) );
	$r = $client->create_payment( 'pt', 'YSX' );
	$assert( EcpgClient::OUTCOME_PROVIDER_FAILED === $r['outcome'] && 10100251 === $r['rtn_code'] && true === $r['sent'], 'F4 definitive RtnCode failure → provider_failed, sent=true' );
	$reset();
	$GLOBALS['ys_ecpg_next_response'] = $http( [ 'MerchantID' => '3002607', 'TransCode' => 10200073, 'TransMsg' => 'Timestamp expired' ] );
	$r = $client->create_payment( 'pt', 'YSX' );
	$assert( EcpgClient::OUTCOME_PROVIDER_FAILED === $r['outcome'] && 10200073 === $r['trans_code'], 'F5 TransCode failure → provider_failed with trans_code' );

	echo "\nv056: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
