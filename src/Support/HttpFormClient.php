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
		if ( $code > 0 && ( $code < 200 || $code >= 300 ) ) {
			return [
				'success' => false,
				'outcome' => 'indeterminate',
				'body'    => (string) wp_remote_retrieve_body( $response ),
				'params'  => [],
				'message' => sprintf( '供應商回應 HTTP %d，無法判定這次請求是否已被處理。', $code ),
			];
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$params = self::parse_body( $body );

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
		$body = trim( $body );
		if ( '' === $body ) {
			return [];
		}

		if ( preg_match( '/^([01])\|(.*)$/s', $body, $matches ) ) {
			$payload = trim( $matches[2] );
			if ( '' === $payload || false === strpos( $payload, '=' ) ) {
				return [
					'_status_prefix' => $matches[1],
					'RtnCode'        => $matches[1],
					'RtnMsg'         => $payload,
				];
			}

			$params = [];
			parse_str( html_entity_decode( $payload, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $params );
			$params = array_map( 'strval', $params );
			$params['_status_prefix'] = $matches[1];
			return $params;
		}

		$params = [];
		parse_str( $body, $params );
		if ( $params ) {
			return array_map( 'strval', $params );
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
}
