<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');

    function sanitize_text_field(mixed $value): string {
        return trim((string)$value);
    }

    function sanitize_key(mixed $value): string {
        return strtolower((string)preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$value));
    }

    class WP_REST_Request {
        /** @param array<string,mixed> $query */
        public function __construct(private array $query) {}
        /** @return array<string,mixed> */
        public function get_query_params(): array { return $this->query; }
        /** @return array<string,mixed> */
        public function get_json_params(): array { return []; }
        /** @return array<string,mixed> */
        public function get_body_params(): array { return []; }
    }

    class WP_REST_Response {
        /** @var array<string,string> */
        public array $headers = [];
        /** @param array<string,mixed> $data */
        public function __construct(public array $data, private int $status) {}
        public function get_status(): int { return $this->status; }
        public function header(string $name, string $value): void { $this->headers[$name] = $value; }
    }
}

namespace YangSheep\Ecommerce\Api\Storefront {
    final class YSRequestParser {
        /** Mirrors Core 2.59.3: body only, deliberately no query fallback. */
        public static function params(\WP_REST_Request $request): array {
            $params = $request->get_json_params();
            return [] !== $params ? $params : $request->get_body_params();
        }
    }

    final class YSRestResponder {
        public static function error(string $code, string $message, int $status = 400): \WP_REST_Response {
            return new \WP_REST_Response(['success' => false, 'code' => $code], $status);
        }
        public static function success(string $code, string $message, array $data = []): \WP_REST_Response {
            return new \WP_REST_Response(['success' => true, 'code' => $code, 'data' => $data], 200);
        }
    }
}

namespace YangSheep\YSCartEcpay\Shipping\Ecpay {
    final class EcpayStoreSelector {
        public static string $scope = '';
        public static string $code = '';
        public static string $principal = '';

        public static function current_principal(string $scope): string {
            self::$scope = $scope;
            return 'principal:' . $scope;
        }

        public static function claim_result_code(string $code, string $principal): array {
            self::$code = $code;
            self::$principal = $principal;
            if ('AbCdEf0123456789AbCdEf0123456789' !== $code) {
                return ['error' => 'invalid', 'store' => []];
            }
            return ['error' => null, 'store' => ['store_id' => '001234']];
        }
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/src/Plugin.php';

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

    $code = 'AbCdEf0123456789AbCdEf0123456789';
    $request = new WP_REST_Request(['code' => $code, 'cart_scope' => 'headless_1']);
    $response = (new \YangSheep\YSCartEcpay\Plugin())->ecpay_store_result($request);

    $selector = \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector::class;
    $assert(200 === $response->get_status() && true === ($response->data['success'] ?? false), 'GET store-result accepts its documented query parameters');
    $assert($code === $selector::$code, 'one-time result code reaches the claim function without being dropped');
    $assert('headless_1' === $selector::$scope && 'principal:headless_1' === $selector::$principal, 'cart scope binds the same principal used by the claim');
    $assert('no-store, private' === ($response->headers['Cache-Control'] ?? ''), 'store result remains private and non-cacheable');

    // Reading the query bag directly means the handler now sees whatever shape the
    // caller sent. `?code[]=…` used to reach a `(string)` cast, which raises a PHP
    // warning and fabricates the literal identifier "Array"; `?cart_scope[]=…`
    // fabricated the scope "array" and therefore bound a different principal.
    // Non-scalar query parameters must be dropped, exactly like the signed callback
    // controllers already do.
    $warnings = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = $errstr;
        return true;
    }, E_ALL);
    $selector::$code = 'NOT-CALLED';
    $selector::$scope = 'NOT-CALLED';
    $selector::$principal = 'NOT-CALLED';
    $array_request = new WP_REST_Request(['code' => [$code], 'cart_scope' => ['headless_1']]);
    $array_response = (new \YangSheep\YSCartEcpay\Plugin())->ecpay_store_result($array_request);
    restore_error_handler();

    $assert([] === $warnings, 'array-shaped query parameters raise no PHP warning');
    $assert(
        400 === $array_response->get_status() && false === ($array_response->data['success'] ?? true),
        'array-shaped query parameters are rejected with HTTP 400'
    );
    $assert(
        '' === $selector::$code && $code !== $selector::$code,
        'array-shaped code is dropped instead of claiming a fabricated identifier'
    );
    $assert(
        'default' === $selector::$scope && 'principal:default' === $selector::$principal,
        'array-shaped cart scope cannot fabricate a different principal binding'
    );

    $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');
    $start = strpos($source, 'public function ecpay_store_result');
    $end = false === $start ? false : strpos($source, 'public function ecpay_reauthorize_saved_store', $start);
    $body = false !== $start && false !== $end ? substr($source, $start, $end - $start) : '';
    $assert(false !== strpos($body, 'get_query_params()') && false === strpos($body, 'YSRequestParser::params'), 'GET handler reads the query bag explicitly');

    echo "\nstore result query contract: {$passed} PASS / {$failed} FAIL\n";
    exit($failed > 0 ? 1 : 0);
}
