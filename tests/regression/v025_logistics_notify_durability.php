<?php
declare(strict_types=1);

/**
 * ECPay logistics callback durability regression.
 *
 * Executes the production projection method with a failed payment-detail CAS,
 * then drives the public notify() entry point in child processes. A durable Core
 * pipeline transition may be calculated, but its public hook must remain deferred
 * until every provider projection and replay commit succeeds.
 */

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_');
    function current_time(string $type): string { return '2026-08-27 12:00:00'; }

    /** @param mixed $value */
    function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
    /** @param mixed $value @return mixed */
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
    /** @param mixed $value */
    function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

    function v025_event(string $event): void
    {
        $path = (string) ($GLOBALS['v025_marker_path'] ?? '');
        if ('' !== $path) {
            file_put_contents($path, $event . '|', FILE_APPEND);
        }
    }

    function status_header(int $status): void { v025_event('status:' . $status); }

    final class WP_REST_Request
    {
        /** @param array<string,string> $params */
        public function __construct(private array $params) {}
        /** @return array<string,string> */
        public function get_body_params(): array { return $this->params; }
        /** @return array<string,string> */
        public function get_params(): array { return $this->params; }
    }

    final class V025Wpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var array<string,mixed> */
        private array $last_label_update = [];

        public function prepare(string $query, ...$args): string
        {
            unset($args);
            return $query;
        }

        /** @return int|string|null */
        public function get_var(string $query)
        {
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                return 'wp_ys_ec_shipping_label_attempts';
            }
            if (str_contains($query, 'SELECT order_id FROM')) {
                return 42;
            }
            return null;
        }

        public function get_row(string $query): ?object
        {
            if (str_contains($query, 'SELECT * FROM wp_ys_ec_shipping_labels')) {
                return (object) [
                    'id'                    => 9,
                    'order_id'              => 42,
                    'provider'              => 'ecpay',
                    'shipping_method'       => 'ys_ec_ecpay_ship_unimart_c2c',
                    'provider_trade_no'     => 'LOCAL-LGS-1',
                    'merchant_trade_no'     => 'LOCAL-ORDER-1',
                    'logistics_subtype'     => 'UNIMARTC2C',
                    'cvs_payment_no'        => '',
                    'validation_no'         => '',
                ];
            }
            if (str_contains($query, 'SELECT * FROM wp_ys_ec_orders')) {
                return (object) ['id' => 42];
            }
            if (str_contains($query, 'FROM wp_ys_ec_shipping_labels WHERE id')) {
                return (object) $this->last_label_update;
            }
            return null;
        }

        /** @param array<string,mixed> $data @param array<string,int> $where */
        public function update(string $table, array $data, array $where): int
        {
            unset($table, $where);
            $this->last_label_update = $data;
            return 1;
        }
    }

    $GLOBALS['wpdb'] = new V025Wpdb();
}

namespace YangSheep\Ecommerce\Security {
    final class YSReplayReservation
    {
        public function __construct(private string $scenario) {}

        public function is_acquired(): bool
        {
            return in_array($this->scenario, ['commit-fail', 'success'], true);
        }

        public function can_acknowledge(): bool { return 'completed' === $this->scenario; }
        public function get_token(): string { return 'local-replay-token'; }
    }

    final class YSWebhookGuard
    {
        public static function reserve(string $scope, string $signature): YSReplayReservation
        {
            unset($scope, $signature);
            $scenario = (string) ($GLOBALS['v025_scenario'] ?? 'replay-error');
            \v025_event('reserve:' . $scenario);
            return new YSReplayReservation($scenario);
        }

        public static function commit_replay(string $scope, string $signature, string $token, int $ttl): bool
        {
            unset($scope, $signature, $token);
            \v025_event('commit:' . $ttl);
            return 'commit-fail' !== ($GLOBALS['v025_scenario'] ?? '');
        }

        public static function release_replay(string $scope, string $signature, string $token): bool
        {
            unset($scope, $signature, $token);
            \v025_event('release');
            return true;
        }
    }
}

namespace YangSheep\Ecommerce\Models {
    final class YSOrder { public static function forget(int $id): void {} }
}

namespace YangSheep\Ecommerce\Services\Shipping {
    final class YSShippingDispatchAuthority
    {
        /** @return array{guard:bool,reason:string,result:mixed,serialization_release:string} */
        public static function with_order_serialization(int $order_id, callable $callback): array
        {
            unset($order_id);
            \v025_event('serialize');
            return [
                'guard'                 => true,
                'reason'                => 'ok',
                'result'                => $callback(),
                'serialization_release' => 'released',
            ];
        }

        public static function active_attempt(int $order_id, string $shipping_method): object
        {
            unset($order_id, $shipping_method);
            return (object) ['label_id' => 9, 'legacy' => false];
        }
    }

    final class YSShippingPipelineService
    {
        public static ?bool $publish_argument = null;
        public static int $publish_calls = 0;

        /** @return array<string,mixed> */
        public static function advance_from_carrier_status(
            int $order_id,
            string $status,
            string $reason = '',
            array $extra = [],
            bool $publish_hook = true
        ): array {
            self::$publish_argument = $publish_hook;
            $event = [
                'order_id' => $order_id,
                'from'     => 'label_created',
                'to'       => 'in_transit',
                'reason'   => $reason,
                'extra'    => $extra,
                'event_id' => (string) ($extra['event_id'] ?? ''),
            ];
            if ($publish_hook) {
                self::publish_advance_hook($event);
                return ['success' => true, 'persisted' => true, 'hook_published' => true];
            }
            return ['success' => true, 'persisted' => true, 'hook_event' => $event];
        }

        public static function publish_advance_hook(array $event): bool
        {
            unset($event);
            ++self::$publish_calls;
            \v025_event('hook');
            return true;
        }
    }
}

namespace YangSheep\Ecommerce\Utils {
    final class YSLogger
    {
        public static function error(string $channel, string $message, array $context = []): void {}
        public static function warning(string $channel, string $message, array $context = []): void {}
    }
}

namespace YangSheep\YSCartEcpay\Support {
    final class DetailWrite
    {
        public function __construct(private bool $persisted) {}
        public function is_persisted(): bool { return $this->persisted; }
        /** @return array<string,string> */
        public function to_log_context(): array { return ['state' => $this->persisted ? 'persisted' : 'failed']; }
    }

    final class OrderPaymentDetail
    {
        /** @var array<string,mixed> */
        public static array $projected = [];

        public static function mutate(int $order_id, callable $mutation): DetailWrite
        {
            unset($order_id);
            self::$projected = $mutation([]);
            return new DetailWrite(! empty($GLOBALS['v025_child']));
        }
    }

    final class ScalarColumnWriter
    {
        public static function write(int $order_id, array $columns): array { return ['state' => 'persisted']; }
        public static function is_persisted(array $result): bool { return true; }
    }

    final class ProviderMaintenanceLock
    {
        public static function reader_lease(): object { return (object) ['token' => 'local-reader']; }
        public static function reader_fence(string $token): bool { return 'local-reader' === $token; }
    }

    final class Settings
    {
        /** @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string} */
        private static function credentials(): array
        {
            return [
                'test_mode'   => true,
                'merchant_id' => 'LOCAL-MERCHANT',
                'hash_key'    => 'local-hash-key',
                'hash_iv'     => 'local-hash-iv',
            ];
        }

        /** @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string} */
        public static function logistics_credentials_for_method(string $method_id): array
        {
            unset($method_id);
            return self::credentials();
        }

        /** @return array{test_mode:bool,merchant_id:string,hash_key:string,hash_iv:string} */
        public static function logistics_credentials_for_subtype(string $subtype): array
        {
            unset($subtype);
            return self::credentials();
        }
    }
}

namespace {
    use YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService;
    use YangSheep\YSCartEcpay\Api\EcpayLogisticsController;
    use YangSheep\YSCartEcpay\Support\CheckMacValue;

    $root = dirname(__DIR__, 2);
    require_once $root . '/src/Support/CheckMacValue.php';
    require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
    require_once $root . '/src/Api/EcpayLogisticsController.php';

    if ('--notify-child' === ($argv[1] ?? '')) {
        $scenario = (string) ($argv[2] ?? '');
        $GLOBALS['v025_child'] = true;
        $GLOBALS['v025_scenario'] = $scenario;
        $GLOBALS['v025_marker_path'] = (string) ($argv[3] ?? '');

        // This value is intentionally already-unslashed, exactly as WP REST supplies
        // body params. Verification must preserve its whitespace, backslash and %xx.
        $params = [
            'MerchantID'          => 'LOCAL-MERCHANT',
            'AllPayLogisticsID'   => 'LOCAL-LGS-1',
            'MerchantTradeNo'     => 'LOCAL-ORDER-1',
            'LogisticsSubType'    => 'UNIMARTC2C',
            'LogisticsStatus'     => '300',
            'LogisticsStatusName' => '  Path C:\\Temp %2F  ',
        ];
        $params['CheckMacValue'] = CheckMacValue::generate(
            $params,
            'local-hash-key',
            'local-hash-iv',
            'md5'
        );

        (new EcpayLogisticsController())->notify(new WP_REST_Request($params));
        exit(97);
    }

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

    $controller = new EcpayLogisticsController();
    $method = new ReflectionMethod($controller, 'update_order_shipping');
    $order = (object) ['id' => 42];
    $label = (object) ['id' => 9, 'order_id' => 42, 'shipping_method' => 'ys_ec_ecpay_unimart_c2c'];
    $params = [
        'AllPayLogisticsID' => 'LOCAL-LGS-1',
        'MerchantTradeNo'   => 'LOCAL-ORDER-1',
        'LogisticsStatus'   => '300',
        'LogisticsStatusName' => '配送中',
    ];
    $hook_event = [];

    if ($method->getNumberOfParameters() >= 5) {
        $args = [$order, $params, $label, 'ecpay-event-local-1', &$hook_event];
        $persisted = $method->invokeArgs($controller, $args);
    } else {
        $persisted = $method->invoke($controller, $order, $params, $label);
    }

    $assert(false === $persisted, 'failed provider projection is reported as retryable');
    $assert(false === YSShippingPipelineService::$publish_argument, 'pipeline hook publication is explicitly deferred');
    $assert(0 === YSShippingPipelineService::$publish_calls, 'failed payment-detail projection publishes zero hooks');
    $assert([] === $hook_event, 'failed projection does not return a hook for publication');

    $source = (string) file_get_contents($root . '/src/Api/EcpayLogisticsController.php');
    $reserve = strpos($source, "YSWebhookGuard::reserve( 'ecpay_logistics_notify'");
    $serialize = strpos($source, '$this->with_notify_serialization(');
    $commit = strpos($source, 'YSWebhookGuard::commit_replay(');
    $publish = strpos($source, 'YSShippingPipelineService::publish_advance_hook(');
    $release = strpos($source, "YSWebhookGuard::release_replay( 'ecpay_logistics_notify'");

    $assert(false !== $reserve && false !== $serialize && $reserve < $serialize, 'typed replay reservation precedes order serialization');
    $assert(false !== $commit && false !== $publish && $commit < $publish, 'replay completion is durable before hook publication');
    $assert(false !== $release, 'retryable exits release the replay reservation');
    $assert(
        str_contains($source, 'private const LOGISTICS_NOTIFY_REPLAY_TTL = 345600;')
        && str_contains($source, 'self::LOGISTICS_NOTIFY_REPLAY_TTL')
        && ! str_contains($source, "\$replay_token, 600"),
        'completed replay authority covers the official three-day ECPay retry window plus one-day buffer'
    );
    $assert(
        str_contains($source, "[ 'event_id' => \$event_id ]")
        && preg_match('/advance_from_carrier_status\([\s\S]*?\[ \'event_id\' => \$event_id \][\s\S]*?false\s*\)/', $source) === 1,
        'stable event id is passed to Core with eager publication disabled'
    );

    /** @return array{stdout:string,stderr:string,exit:int,event:string} */
    $run_notify = static function (string $scenario): array {
        $marker = tempnam(sys_get_temp_dir(), 'ys-ecpay-logistics-notify-');
        if (false === $marker) {
            throw new RuntimeException('Unable to allocate callback marker.');
        }
        file_put_contents($marker, '');

        $process = proc_open(
            [PHP_BINARY, __FILE__, '--notify-child', $scenario, $marker],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (! is_resource($process)) {
            @unlink($marker);
            throw new RuntimeException('Unable to start callback child process.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $event = (string) file_get_contents($marker);
        @unlink($marker);

        return compact('stdout', 'stderr', 'exit', 'event');
    };

    $completed = $run_notify('completed');
    $in_progress = $run_notify('in-progress');
    $replay_error = $run_notify('replay-error');
    $commit_fail = $run_notify('commit-fail');
    $success = $run_notify('success');

    $assert(
        '1|OK' === $completed['stdout'] && '' === $completed['stderr'] && 0 === $completed['exit'],
        'completed duplicate returns HTTP-success byte-exact ACK'
    );
    $assert(
        'reserve:completed|status:200|' === $completed['event'],
        'completed duplicate performs zero serialization, commit, release, or hook publication'
    );
    $assert(
        '0|Replay unavailable' === $in_progress['stdout']
        && '' === $in_progress['stderr']
        && 0 === $in_progress['exit']
        && 'reserve:in-progress|status:503|' === $in_progress['event'],
        'in-progress replay returns HTTP 503 non-ACK with zero commit, release, or hook'
    );
    $assert(
        '0|Replay unavailable' === $replay_error['stdout']
        && '' === $replay_error['stderr']
        && 0 === $replay_error['exit']
        && 'reserve:replay-error|status:503|' === $replay_error['event'],
        'replay-store error returns HTTP 503 non-ACK with zero commit, release, or hook'
    );
    $assert(
        '0|Replay commit failed' === $commit_fail['stdout']
        && '' === $commit_fail['stderr']
        && 0 === $commit_fail['exit'],
        'commit failure returns HTTP 503 byte-exact non-ACK'
    );
    $assert(
        'reserve:commit-fail|serialize|commit:345600|release|status:503|' === $commit_fail['event'],
        'commit failure uses TTL 345600, releases exactly once, and publishes zero hooks'
    );
    $assert(
        '1|OK' === $success['stdout'] && '' === $success['stderr'] && 0 === $success['exit'],
        'signed raw-byte callback success returns HTTP-success byte-exact ACK'
    );
    $assert(
        'reserve:success|serialize|commit:345600|hook|status:200|' === $success['event'],
        'success commits TTL 345600 before exactly one hook and performs zero releases'
    );

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed > 0 ? 1 : 0);
}
