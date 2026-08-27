<?php
declare(strict_types=1);

/**
 * ECPay response-integrity behavior regression.
 *
 * Executes the production response parser and payment client against synthetic,
 * locally signed fixtures. No merchant credential or network access is used.
 */

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('YS_CART_ECPAY_TESTING', true);

    final class WP_Error
    {
        public function __construct(private string $message) {}
        public function get_error_message(): string { return $this->message; }
    }

    /** @var list<array{code:int,body:string}|WP_Error> */
    $GLOBALS['v026_responses'] = [];

    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function wp_remote_post(string $url, array $args): array|WP_Error
    {
        $next = array_shift($GLOBALS['v026_responses']);
        return $next ?? new WP_Error('missing synthetic response');
    }
    function wp_remote_retrieve_response_code(array $response): int { return (int) $response['code']; }
    function wp_remote_retrieve_body(array $response): string { return (string) $response['body']; }
    function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
}

namespace YangSheep\YSCartEcpay\Support {
    final class Settings
    {
        /** @return array{merchant_id:string,hash_key:string,hash_iv:string,credit_check_code:string,test_mode:bool} */
        public static function payment_credentials(?array $pending = null): array
        {
            return [
                'merchant_id'       => 'LOCAL-MERCHANT',
                'hash_key'          => 'local-hash-key',
                'hash_iv'           => 'local-hash-iv',
                'credit_check_code' => '12345678',
                'test_mode'         => true,
            ];
        }

        public static function payment_query_endpoint(): string { return 'https://unit.invalid/query'; }
        public static function payment_credit_query_endpoint(): string { return 'https://unit.invalid/credit-query'; }
        public static function payment_do_action_endpoint(): string { return 'https://unit.invalid/do-action'; }
    }

    final class ProviderMaintenanceLock
    {
        public static function reader_lease(): object { return (object) ['token' => 'local-reader']; }
        public static function reader_fence(string $token): bool { return 'local-reader' === $token; }
    }
}

namespace {
    use YangSheep\YSCartEcpay\Payment\EcpayPaymentClient;
    use YangSheep\YSCartEcpay\Support\CheckMacValue;
    use YangSheep\YSCartEcpay\Support\HttpFormClient;

    $root = dirname(__DIR__, 2);
    require_once $root . '/src/Support/CheckMacValue.php';
    require_once $root . '/src/Support/HttpFormClient.php';
    require_once $root . '/src/Payment/EcpayPaymentClient.php';

    $passed = 0;
    $failed = 0;
    $assert = static function (bool $ok, string $label) use (&$passed, &$failed): void {
        if ($ok) {
            ++$passed;
            echo "PASS: {$label}\n";
            return;
        }
        ++$failed;
        echo "FAIL: {$label}\n";
    };

    $queue = static function (string $body, int $code = 200): void {
        $GLOBALS['v026_responses'][] = ['code' => $code, 'body' => $body];
    };

    /**
     * ECPay's verified encoded response service preserves literal plus signs
     * before decoding. RFC3986 keeps spaces distinct as %20 in this fixture.
     *
     * @param array<string,string> $fields
     */
    $encoded = static function (array $fields): string {
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        return str_replace('%2B', '+', $body);
    };

    /** @param array<string,string> $fields */
    $signed_query = static function (array $fields) use ($encoded): string {
        $fields['CheckMacValue'] = CheckMacValue::generate(
            $fields,
            'local-hash-key',
            'local-hash-iv',
            'sha256'
        );
        return $encoded($fields);
    };

    // Official SDK has two distinct response decoders. Plain EncodedStrResponse
    // follows application/x-www-form-urlencoded (`+` means space); only the
    // verified decoder preserves literal plus before CMV verification.
    $plain = HttpFormClient::parse_body('RtnCode=1&RtnMsg=A+B');
    $assert('A B' === ($plain['RtnMsg'] ?? null), 'plain encoded response decodes plus as space');

    $verified = HttpFormClient::parse_verified_body('RtnCode=1&RtnMsg=A+B');
    $assert('A+B' === ($verified['RtnMsg'] ?? null), 'verified encoded response preserves literal plus');

    $prefixed = HttpFormClient::parse_verified_body('1|RtnCode=1&RtnMsg=A+B');
    $assert('A+B' === ($prefixed['RtnMsg'] ?? null), 'status-prefixed verified response preserves literal plus');

    // QueryTradeInfo/V5 must verify the same decoded values ECPay signed.
    $query_fields = [
        'MerchantID'      => 'LOCAL-MERCHANT',
        'MerchantTradeNo' => 'ORDER-PLUS-1',
        'TradeStatus'     => '0',
        'CustomField1'    => 'A+B',
    ];
    $queue($signed_query($query_fields));
    $query = (new EcpayPaymentClient())->query_trade('ORDER-PLUS-1');
    $assert(
        true === ($query['success'] ?? false)
        && true === ($query['mac_verified'] ?? false)
        && 'A+B' === ($query['data']['CustomField1'] ?? null),
        'QueryTrade verifies signed response containing literal plus'
    );

    $credit_response = static function (array $overrides = []): string {
        $base = [
            'RtnMsg'   => '',
            'RtnValue' => [
                'TradeID'    => 'GWSR-100',
                'amount'     => 100,
                'status'     => '已關帳',
                'close_data' => [],
            ],
        ];
        foreach ($overrides as $key => $value) {
            if (str_starts_with((string) $key, 'RtnValue.')) {
                $base['RtnValue'][substr((string) $key, 9)] = $value;
            } else {
                $base[$key] = $value;
            }
        }
        return (string) json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };

    $client = new EcpayPaymentClient();
    $queue($credit_response());
    $credit = $client->query_credit_close_status('GWSR-100', 100);
    $assert(true === ($credit['success'] ?? false) && 'closed' === ($credit['state'] ?? ''), 'CreditDetail accepts exact successful identity');

    $credit_cases = [
        'non-empty RtnMsg' => ['RtnMsg' => 'provider warning'],
        'mismatched TradeID' => ['RtnValue.TradeID' => 'GWSR-OTHER'],
        'missing TradeID' => ['RtnValue.TradeID' => ''],
        'mismatched amount' => ['RtnValue.amount' => 99],
        'non-canonical amount' => ['RtnValue.amount' => '100.0'],
        'padded TradeID' => ['RtnValue.TradeID' => ' GWSR-100'],
        'padded amount' => ['RtnValue.amount' => '100 '],
        'whitespace-only RtnMsg' => ['RtnMsg' => ' '],
    ];
    foreach ($credit_cases as $label => $overrides) {
        $queue($credit_response($overrides));
        $result = $client->query_credit_close_status('GWSR-100', 100);
        $assert(
            false === ($result['success'] ?? true) && 'unknown' === ($result['state'] ?? ''),
            "CreditDetail fails closed on {$label}"
        );
    }

    $do_action_body = static function (array $overrides = []) use ($encoded): string {
        return $encoded(array_merge([
            'MerchantID'      => 'LOCAL-MERCHANT',
            'MerchantTradeNo' => 'ORDER-REFUND-1',
            'TradeNo'         => 'TRADE-REFUND-1',
            'RtnCode'         => '1',
            'RtnMsg'          => '',
        ], $overrides));
    };

    $queue($do_action_body());
    $action = $client->do_action_refund('ORDER-REFUND-1', 'TRADE-REFUND-1', 100.0, 'R');
    $assert(true === ($action['success'] ?? false) && false === ($action['indeterminate'] ?? true), 'DoAction accepts exact echoed identity');

    $identity_cases = [
        'mismatched MerchantID' => ['MerchantID' => 'OTHER-MERCHANT'],
        'mismatched MerchantTradeNo' => ['MerchantTradeNo' => 'ORDER-OTHER'],
        'mismatched TradeNo' => ['TradeNo' => 'TRADE-OTHER'],
        'missing TradeNo' => ['TradeNo' => ''],
        'padded MerchantID' => ['MerchantID' => ' LOCAL-MERCHANT'],
        'padded MerchantTradeNo' => ['MerchantTradeNo' => 'ORDER-REFUND-1 '],
        'padded TradeNo' => ['TradeNo' => "TRADE-REFUND-1\n"],
    ];
    foreach ($identity_cases as $label => $overrides) {
        $queue($do_action_body($overrides));
        $result = $client->do_action_refund('ORDER-REFUND-1', 'TRADE-REFUND-1', 100.0, 'R');
        $assert(
            false === ($result['success'] ?? true) && true === ($result['indeterminate'] ?? false),
            "DoAction treats {$label} as indeterminate"
        );
    }

    $queue($do_action_body(['RtnCode' => '0', 'RtnMsg' => 'provider+rejected']));
    $rejected = $client->do_action_refund('ORDER-REFUND-1', 'TRADE-REFUND-1', 100.0, 'R');
    $assert(
        false === ($rejected['success'] ?? true)
        && false === ($rejected['indeterminate'] ?? true)
        && 'provider rejected' === ($rejected['message'] ?? ''),
        'DoAction uses the official plain encoded decoder and keeps exact-identity rejection retryable'
    );

    $queue($do_action_body(['TradeNo' => 'TRADE-OTHER', 'RtnCode' => '0', 'RtnMsg' => 'rejected']));
    $wrong_rejection = $client->do_action_refund('ORDER-REFUND-1', 'TRADE-REFUND-1', 100.0, 'R');
    $assert(
        false === ($wrong_rejection['success'] ?? true) && true === ($wrong_rejection['indeterminate'] ?? false),
        'DoAction wrong-identity rejection is not treated as proof this request failed'
    );

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed > 0 ? 1 : 0);
}
