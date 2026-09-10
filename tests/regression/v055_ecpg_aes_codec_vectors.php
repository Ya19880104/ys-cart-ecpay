<?php
/**
 * v055 — 站內付 2.0 AES 編解碼對官方測試向量（v0.5.0）
 *
 * 期望值**不是**由受測程式產生：三組向量取自綠界官方 skill `guides/14-aes-encryption.md`
 * （來源 developers.ecpay.com.tw/9103.md，金鑰為公開測試特店 2000132 的 HashKey／HashIV）。
 *
 * 一個已知差異要講清楚：skill 的向量 1／2 以「不跳脫斜線」的 JSON 產生（`"/1234567"`），
 * 而綠界**官方 PHP SDK** `AesService::encrypt()` 用預設 `json_encode()`（會寫成 `\/`）。
 * 本 codec 刻意與官方 SDK 逐字一致，因此對含斜線的向量，密文只有**前三個 AES 區塊**
 * （URL encode 後斜線出現之前的 48 bytes）與 skill 向量相同——CBC 模式下這正好證明
 * 金鑰、IV、模式與 padding 全對；斜線之後的區塊不同是預期的。解密端兩種寫法都解得開，
 * 向量 1／2 的密文因此仍拿來驗解密。向量 3 不含斜線，必須**逐位元**相同。
 *
 * 不依賴 WordPress：只需 define ABSPATH（production 檔案第一行是 `defined('ABSPATH') || exit`，
 * 不先定義會靜默 exit 0＝空綠）。
 *
 * Run: php tests/regression/v055_ecpg_aes_codec_vectors.php
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
$root = str_replace( '\\', '/', dirname( __DIR__, 2 ) );
require_once $root . '/src/Ecpg/EcpgAesCodec.php';

use YangSheep\YSCartEcpay\Ecpg\EcpgAesCodec;

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

// 公開測試特店 2000132（官方文件 8981／35542 公開，非機密）。
$key = 'ejCk326UnaZWKisg';
$iv  = 'q9jcZX8Ib9LM8wYk';

/** 與受測程式無關的獨立解密：raw openssl → urldecode → json_decode。 */
$independent_decrypt = static function ( string $b64 ) use ( $key, $iv ): array {
	$plain = openssl_decrypt( base64_decode( $b64, true ), 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv );
	return [ 'plain' => (string) $plain, 'json' => json_decode( urldecode( (string) $plain ), true ) ];
};

echo "## v055 ECPG AES codec vs official vectors\n";

// ── 向量 3：特殊字元（不含斜線）→ 逐位元相同 ───────────────────────────────
$v3_plain    = [ 'Name' => "test!*'()~value" ];
$v3_expected = 'uvI4yrErM37XNQkXGAgRgBuDOiJoVs72Xn/rum9Ejl1DSna4HyLSoY7764PmhTR7JXb9jJWLSjCGcZEDeFiABg==';
$v3_cipher   = EcpgAesCodec::encrypt( $v3_plain, $key, $iv );
$assert( $v3_cipher === $v3_expected, 'V3 encrypt equals the official ciphertext byte-for-byte' );
$assert( EcpgAesCodec::decrypt( $v3_expected, $key, $iv ) === $v3_plain, 'V3 official ciphertext decrypts to the official plaintext' );
$v3_raw = $independent_decrypt( $v3_cipher );
$assert(
	$v3_raw['plain'] === '%7B%22Name%22%3A%22test%21%2A%27%28%29%7Evalue%22%7D',
	'V3 intermediate URL encoding is PHP urlencode (! * \' ( ) ~ all percent-encoded, ~ as %7E)'
);

// ── 向量 1／2：含斜線 → 前三區塊相同、解密一致、差異只在斜線跳脫 ────────────
// 第三個元素是 skill 記載的 Step-2（URL encode 後）明文：用它算出「斜線出現前有幾個完整
// AES 區塊」——V1 的斜線在第 4 區塊（前 3 塊相同），V2 的斜線在第 2 區塊（只有第 1 塊相同）。
$vectors = [
	'V1' => [ [ 'MerchantID' => '2000132', 'BarCode' => '/1234567' ], 'XeEOdHpTRvxKEqs/JD9RSd16s7VtpyWVCN6AV44pKTW3DVa6yI7vKmjBRp2eulDhXoru/qBqFDBH3fEqlkMn3bbJfJBfGAq+v+SvttutYnc=', '%7B%22MerchantID%22%3A%222000132%22%2C%22BarCode%22%3A%22%2F1234567%22%7D' ],
	'V2' => [ [ 'BarCode' => '/1234567', 'MerchantID' => '2000132' ], 'r0JSyF9wVmywUav725b3rdJs3xp/ekrC/7PGb18zhKyXkPsamV9l4rPnBkaaraPcHtMSwrmSPP3wuS7b8g/aAKGs0iGiknpgpbdXKXvFrYM=', '%7B%22BarCode%22%3A%22%2F1234567%22%2C%22MerchantID%22%3A%222000132%22%7D' ],
];
foreach ( $vectors as $name => [ $plain, $expected, $official_step2 ] ) {
	$assert( EcpgAesCodec::decrypt( $expected, $key, $iv ) === $plain, "{$name} official ciphertext decrypts to the official plaintext (key order preserved)" );
	$ours = EcpgAesCodec::encrypt( $plain, $key, $iv );
	$raw  = $independent_decrypt( $ours );
	// 兩份明文相同的前綴長度 → 完整區塊數 → 可逐位元比對的 Base64 長度（3 bytes = 4 chars）。
	$common_prefix = strspn( $raw['plain'] ^ substr( $official_step2, 0, strlen( $raw['plain'] ) ), "\0" );
	$whole_blocks  = intdiv( $common_prefix, 16 );
	$b64_chars     = intdiv( $whole_blocks * 16, 3 ) * 4;
	$assert( $whole_blocks >= 1 && substr( $ours, 0, $b64_chars ) === substr( $expected, 0, $b64_chars ), "{$name} the {$whole_blocks} AES block(s) before the slash match the official ciphertext (key/IV/mode/padding proven)" );
	$assert( $raw['json'] === $plain, "{$name} our ciphertext decrypts (independently) to the same plaintext" );
	$assert( str_contains( $raw['plain'], '%5C%2F' ), "{$name} our intermediate escapes the slash like the official PHP SDK (json_encode default)" );
}

// ── 中文與巢狀：官方 SDK 的 json_encode 預設會寫成 \uXXXX，必須能 roundtrip ─
$nested = [ 'OrderInfo' => [ 'ItemName' => '泡麵#清潔用品', 'TotalAmount' => 500 ], 'ConsumerInfo' => [ 'Name' => '測試' ] ];
$cipher = EcpgAesCodec::encrypt( $nested, $key, $iv );
$assert( EcpgAesCodec::decrypt( $cipher, $key, $iv ) === $nested, 'nested Unicode payload round-trips with types intact' );
$assert( $independent_decrypt( $cipher )['json'] === $nested, 'nested Unicode payload decrypts independently' );

// ── 失敗一律 null／例外，不得回空陣列 ──────────────────────────────────────
$assert( null === EcpgAesCodec::decrypt( $v3_expected, 'pwFHCqoQZGmho4w6', $iv ), 'wrong key → null' );
$assert( null === EcpgAesCodec::decrypt( $v3_expected, $key, 'EkRm7iFT261dpevs' ), 'wrong IV → null (padding/JSON fails)' );
$assert( null === EcpgAesCodec::decrypt( 'not base64!!', $key, $iv ), 'non-Base64 input → null' );
$assert( null === EcpgAesCodec::decrypt( '', $key, $iv ), 'empty input → null' );
$tampered = $v3_expected;
$tampered[10] = 'A' === $tampered[10] ? 'B' : 'A';
$assert( null === EcpgAesCodec::decrypt( $tampered, $key, $iv ), 'tampered ciphertext → null' );
$assert( null === EcpgAesCodec::decrypt( $v3_expected, 'short', $iv ), 'short key → null' );
$non_json = base64_encode( openssl_encrypt( urlencode( 'just a string' ), 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv ) );
$assert( null === EcpgAesCodec::decrypt( $non_json, $key, $iv ), 'valid cipher of non-JSON plaintext → null (SDK RtnException 111 analogue)' );
$scalar_json = base64_encode( openssl_encrypt( urlencode( '"scalar"' ), 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv ) );
$assert( null === EcpgAesCodec::decrypt( $scalar_json, $key, $iv ), 'valid cipher of scalar JSON → null (only objects/arrays are ECPG payloads)' );

$threw = false;
try {
	EcpgAesCodec::encrypt( [ 'a' => 1 ], 'short', $iv );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
$assert( $threw, 'encrypt with a non-16-byte key throws InvalidArgumentException' );
$threw = false;
try {
	EcpgAesCodec::encrypt( [ 'bad' => "\xB1\x31" ], $key, $iv );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
$assert( $threw, 'encrypt with non-UTF-8 payload throws (json_encode failure is not swallowed)' );

echo "\nv055: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 ? 1 : 0 );
