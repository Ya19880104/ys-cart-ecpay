<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Ecpg;

defined( 'ABSPATH' ) || exit;

/**
 * 綠界站內付 2.0（ECPG）`Data` 欄位的 AES 編解碼——與官方 PHP SDK `AesService` 逐字對應。
 *
 * 官方 `Ecpay\Sdk\Services\AesService`（skill 內附 SDK，`src/Services/AesService.php`）：
 *
 *   encrypt: json_encode → urlencode → openssl_encrypt('AES-128-CBC', OPENSSL_RAW_DATA, key=HashKey, iv=HashIV) → base64
 *   decrypt: base64_decode → openssl_decrypt → urldecode → json_decode(assoc)
 *
 * 🔴 這裡的 URL encode 是 PHP `urlencode()`（空格→`+`、`~`→`%7E`），**不是** AIO
 * CheckMacValue 用的 `ecpayUrlEncode`（urlencode 後再 lowercase＋.NET 字元替換）。
 * 兩者混用的症狀是綠界回 `TransCode ≠ 1`，從我們這邊看不出原因。
 *
 * 官方文件（9103.md／35676.md）與 skill guides/14 的測試向量釘在
 * tests/regression/v055_ecpg_aes_codec_vectors.php。
 *
 * 純函式、無 WordPress 依賴，測試可直接載入。
 */
final class EcpgAesCodec {
	private const METHOD = 'AES-128-CBC';

	/**
	 * @param array<string,mixed> $data 明文（巢狀陣列）
	 * @return string Base64 密文
	 * @throws \InvalidArgumentException 金鑰／IV 長度不對，或資料無法 JSON 序列化
	 * @throws \RuntimeException openssl 加密失敗
	 */
	public static function encrypt( array $data, string $hash_key, string $hash_iv ): string {
		self::assert_key_material( $hash_key, $hash_iv );

		// 官方 SDK 用預設 flags 的 json_encode：中文會變成 \uXXXX，斜線會被跳脫。
		// 綠界兩種都解得開；這裡與 SDK 一致，讓測試向量可以逐位元比對。
		$json = json_encode( $data );
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'ECPG payload cannot be JSON-encoded: ' . json_last_error_msg() );
		}

		$cipher = openssl_encrypt( urlencode( $json ), self::METHOD, $hash_key, OPENSSL_RAW_DATA, $hash_iv );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'ECPG AES encryption failed.' );
		}

		return base64_encode( $cipher );
	}

	/**
	 * @return array<string,mixed>|null null＝不是本金鑰加密的合法密文（Base64／AES／JSON 任一階段失敗）。
	 *                                  呼叫端不得把 null 當成空資料。
	 */
	public static function decrypt( string $cipher_text, string $hash_key, string $hash_iv ): ?array {
		if ( '' === $cipher_text || 16 !== strlen( $hash_key ) || 16 !== strlen( $hash_iv ) ) {
			return null;
		}

		$raw = base64_decode( $cipher_text, true );
		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$plain = openssl_decrypt( $raw, self::METHOD, $hash_key, OPENSSL_RAW_DATA, $hash_iv );
		if ( false === $plain ) {
			return null;
		}

		$decoded = json_decode( urldecode( $plain ), true );

		// 官方 SDK 對「解出來不是 JSON」丟 RtnException(111)；陣列以外的合法 JSON
		//（例如純字串）對 ECPG 的任何回應都沒有意義，同樣視為不可解。
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function assert_key_material( string $hash_key, string $hash_iv ): void {
		if ( 16 !== strlen( $hash_key ) || 16 !== strlen( $hash_iv ) ) {
			throw new \InvalidArgumentException( 'ECPG HashKey and HashIV must each be exactly 16 bytes.' );
		}
	}
}
