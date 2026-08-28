<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

final class HttpFormClient {
	/**
	 * @param array<string,mixed> $fields
	 * @return array{success:bool,outcome:string,body:string,params:array<string,string>,message:string}
	 */
	public function post( string $url, array $fields, int $timeout = 20 ): array {
		return $this->post_with_parser( $url, $fields, $timeout, false );
	}

	/**
	 * Send a request whose response uses ECPay's VerifiedEncodedStrResponse
	 * decoder. That decoder preserves literal plus signs before CMV verification.
	 *
	 * 官方 SDK 的原文就是 `parse_str( str_replace( '+', '%2B', $response ), $parsed )`
	 * （`Ecpay\Sdk\Response\VerifiedEncodedStrResponse::toArray()`），這裡與它逐字一致。
	 *
	 * 🔴 **只有官方指定 `PostWithCmvVerifiedEncodedStrResponseService` 的端點可以用它**：
	 *   - AIO `/Cashier/QueryTradeInfo/V5`、`/Cashier/QueryPaymentInfo`
	 *   - 國內物流 `/Helper/QueryLogisticsTradeInfo`
	 * 建單（`/Express/Create`）與 `DoAction` 官方用的是一般 decoder，套錯會讓
	 * CheckMacValue 驗不過。路由契約見
	 * tests/regression/v030_logistics_response_decoder_routing.php。
	 *
	 * @param array<string,mixed> $fields
	 * @return array{success:bool,outcome:string,body:string,params:array<string,string>,message:string}
	 */
	public function post_verified( string $url, array $fields, int $timeout = 20 ): array {
		return $this->post_with_parser( $url, $fields, $timeout, true );
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array{success:bool,outcome:string,body:string,params:array<string,string>,message:string}
	 */
	private function post_with_parser( string $url, array $fields, int $timeout, bool $verified_encoded ): array {
		$response = wp_remote_post( $url, [
			'timeout' => $timeout,
			'body'    => $fields,
		] );

		// 🔴 傳輸層失敗**永遠是 indeterminate**，不是「失敗」。
		//
		// 逾時、連線中斷、DNS 失敗——這些只證明「我們沒收到回應」，不證明
		// 「對方沒收到請求」。把它壓成一個沒有 outcome 的 success=false，呼叫端
		// 會預設成 terminal_failed 而放行下一次建單；如果綠界其實已經收單，
		// 那就是同一張訂單出兩次貨。
		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'outcome' => 'indeterminate',
				'body'    => '',
				'params'  => [],
				'message' => $response->get_error_message(),
			];
		}

		// 非 2xx 同樣無法證明對方沒處理（5xx 尤其可能是「處理了但回應壞了」）。
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return [
				'success' => false,
				'outcome' => 'indeterminate',
				'body'    => (string) wp_remote_retrieve_body( $response ),
				'params'  => [],
				'message' => sprintf( '供應商回應 HTTP %d，無法判定這次請求是否已被處理。', $code ),
			];
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$params = $verified_encoded
			? self::parse_verified_body( $body )
			: self::parse_body( $body );

		return [
			'success' => true,
			'outcome' => '',
			'body'    => $body,
			'params'  => $params,
			'message' => '',
		];
	}

	/**
	 * @return array<string,string>
	 */
	public static function parse_body( string $body ): array {
		return self::parse_body_with_mode( $body, false );
	}

	/**
	 * Parse a response covered by CheckMacValue using the official SDK's
	 * VerifiedEncodedStrResponse plus-preservation rule.
	 *
	 * @return array<string,string>
	 */
	public static function parse_verified_body( string $body ): array {
		return self::parse_body_with_mode( $body, true );
	}

	/**
	 * @return array<string,string>
	 */
	private static function parse_body_with_mode( string $body, bool $preserve_literal_plus ): array {
		$body = trim( $body );
		if ( '' === $body ) {
			return [];
		}

		// 🔴 JSON body 不是表單回應（GetStoreList 之類）。丟給 parse_str 會把
		// JSON 的中括號解成巢狀陣列，後面的 strval 對陣列發 Warning——
		// 呼叫端要的是 `body` 原文，params 對 JSON 本來就沒有意義。
		if ( ( '{' === $body[0] || '[' === $body[0] ) && null !== json_decode( $body, true ) ) {
			return [];
		}

		// 🔴 v0.2.13：前綴不只 0/1。stage 實測官方的同步拒絕還有
		// `10500040|商品金額範圍為1~20000元` 這種**數字錯誤碼開頭**的形狀
		// （FINDINGS-STAGE-2026-08-13）。regex 只吃 [01] 的話，錯誤碼開頭的
		// 回應會掉進 parse_str 的 fallback，`_status_prefix` 消失。
		if ( preg_match( '/^([0-9]+)\|(.*)$/s', $body, $matches ) ) {
			$payload = trim( $matches[2] );
			if ( '' === $payload || false === strpos( $payload, '=' ) ) {
				return [
					'_status_prefix' => $matches[1],
					'RtnCode'        => $matches[1],
					'RtnMsg'         => $payload,
				];
			}

			$params = self::parse_encoded_query(
				html_entity_decode( $payload, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				$preserve_literal_plus
			);
			$params['_status_prefix'] = $matches[1];
			return $params;
		}

		$params = self::parse_encoded_query( $body, $preserve_literal_plus );
		if ( $params ) {
			return $params;
		}

		foreach ( explode( '|', $body ) as $part ) {
			if ( false === strpos( $part, '=' ) ) {
				continue;
			}
			[ $key, $value ] = array_map( 'trim', explode( '=', $part, 2 ) );
			$params[ $key ] = $value;
		}

		return $params;
	}

	/**
	 * Parse one ECPay encoded-string response using the selected official SDK
	 * decoder semantics. EncodedStrResponse treats plus as form-space;
	 * VerifiedEncodedStrResponse preserves it before CheckMacValue verification.
	 *
	 * @return array<string,string>
	 */
	private static function parse_encoded_query( string $encoded, bool $preserve_literal_plus ): array {
		$params = [];
		parse_str( $preserve_literal_plus ? str_replace( '+', '%2B', $encoded ) : $encoded, $params );

		// 只收 scalar：query string 帶中括號時 parse_str 會生出巢狀陣列，
		// strval(array) 是 Warning。非 scalar 的值對表單回應沒有意義，直接丟棄。
		return self::scalarize( $params );
	}

	/**
	 * 只保留 scalar 值並轉字串；巢狀陣列（來自帶中括號的輸入）直接丟棄。
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,string>
	 */
	private static function scalarize( array $params ): array {
		$out = [];
		foreach ( $params as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ (string) $key ] = (string) $value;
			}
		}

		return $out;
	}
}
