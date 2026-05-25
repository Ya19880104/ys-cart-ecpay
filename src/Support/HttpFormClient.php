<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

final class HttpFormClient {
	/**
	 * @param array<string,mixed> $fields
	 * @return array{success:bool,body:string,params:array<string,string>,message:string}
	 */
	public function post( string $url, array $fields, int $timeout = 20 ): array {
		$response = wp_remote_post( $url, [
			'timeout' => $timeout,
			'body'    => $fields,
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'body'    => '',
				'params'  => [],
				'message' => $response->get_error_message(),
			];
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$params = self::parse_body( $body );

		return [
			'success' => true,
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
