<?php
/**
 * v019 — 每個被引用的類別名稱都必須解析得到
 *
 * `php -l` 只驗語法，**不驗符號**。少一行 `use` 的檔案 lint 完全乾淨，卻會在
 * 執行到那一行時 fatal。實際發生過：`EcpayLogisticsController` 用了
 * `OrderPaymentDetail` 與 `YSLogger` 卻沒有 import——物流 callback 一進來就
 * `Class not found`，而整條 lint 與所有既有回歸測試都是綠的（那條路徑沒有被
 * 任何測試執行到）。
 *
 * 本檔對 `src/` 每一個檔案做靜態符號解析：凡是以短名（非 `\` 開頭）引用的類別，
 * 必須來自下列其一
 *   - 該檔的 `use` 匯入
 *   - 與該檔同一個 namespace 且實際存在於本外掛（PSR-4 路徑推導）
 *   - PHP 內建／WordPress 全域類別（白名單）
 *
 * Run: php tests/regression/v019_symbol_resolution_contract.php
 */

declare(strict_types=1);

$root = str_replace('\\', '/', dirname(__DIR__, 2));

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        ++$pass;
        echo "  PASS  {$label}\n";
        return;
    }
    ++$fail;
    echo "  FAIL  {$label}\n";
};

/** PHP 內建與 WordPress 全域類別（以短名引用時合法，因為它們在全域 namespace）。 */
$GLOBAL_CLASSES = [
    'ZipArchive', 'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval',
    'Exception', 'Throwable', 'Error', 'TypeError', 'ValueError', 'JsonException',
    'RuntimeException', 'InvalidArgumentException', 'LogicException',
    'ArrayObject', 'Closure', 'Generator', 'Traversable', 'Iterator', 'IteratorAggregate',
    'RecursiveDirectoryIterator', 'RecursiveIteratorIterator', 'RecursiveCallbackFilterIterator',
    'FilesystemIterator', 'SplFileInfo', 'stdClass',
    'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty', 'ReflectionFunction',
    'WP_REST_Request', 'WP_REST_Response', 'WP_Error', 'WP_Query', 'WP_User', 'WP_Post',
    'WP_CLI', 'wpdb',
];

/** namespace → 目錄（PSR-4，與 composer/autoload 對齊）。 */
$PSR4 = [
    'YangSheep\\YSCartEcpay\\' => $root . '/src/',
];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ('php' === strtolower($file->getExtension())) {
        $files[] = str_replace('\\', '/', (string) $file->getPathname());
    }
}
sort($files);

$assert([] !== $files, '(a) 掃描到 src/ 底下的 PHP 檔（' . count($files) . ' 個）');

$problems = [];

foreach ($files as $path) {
    $src = str_replace("\r\n", "\n", (string) file_get_contents($path));

    // 檔案自身的 namespace
    $ns = '';
    if (preg_match('/^namespace\s+([^;]+);/m', $src, $m)) {
        $ns = trim($m[1]);
    }

    // use 匯入（含 alias）
    $imported = [];
    if (preg_match_all('/^use\s+([^;]+);/m', $src, $uses)) {
        foreach ($uses[1] as $use) {
            $use = trim($use);
            if (preg_match('/\s+as\s+(\w+)$/i', $use, $alias)) {
                $imported[$alias[1]] = true;
                continue;
            }
            $parts = explode('\\', $use);
            $imported[end($parts)] = true;
        }
    }

    // 該檔內宣告的類別／介面／trait／enum
    $declared = [];
    if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $src, $decls)) {
        foreach ($decls[1] as $d) {
            $declared[$d] = true;
        }
    }

    // 移除註解與字串，避免把文件裡提到的名字當成引用
    $code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
    $code = preg_replace('#(^|\s)//[^\n]*#', '', $code) ?? $code;
    $code = preg_replace("#'(?:\\\\.|[^'\\\\])*'#", "''", $code) ?? $code;
    $code = preg_replace('#"(?:\\\\.|[^"\\\\])*"#', '""', $code) ?? $code;

    // 短名引用：`Foo::`、`new Foo(`、`instanceof Foo`、`catch ( Foo `
    $referenced = [];
    if (preg_match_all('/(?<![\\\\\w$>])([A-Z]\w*)::/', $code, $r1)) {
        $referenced = array_merge($referenced, $r1[1]);
    }
    if (preg_match_all('/\bnew\s+([A-Z]\w*)\s*\(/', $code, $r2)) {
        $referenced = array_merge($referenced, $r2[1]);
    }
    if (preg_match_all('/\binstanceof\s+([A-Z]\w*)/', $code, $r3)) {
        $referenced = array_merge($referenced, $r3[1]);
    }
    if (preg_match_all('/\bcatch\s*\(\s*([A-Z]\w*)/', $code, $r4)) {
        $referenced = array_merge($referenced, $r4[1]);
    }

    foreach (array_unique($referenced) as $name) {
        if (in_array($name, ['self', 'static', 'parent'], true)) {
            continue;
        }
        if (isset($declared[$name]) || isset($imported[$name]) || in_array($name, $GLOBAL_CLASSES, true)) {
            continue;
        }

        // 同 namespace 內的類別：依 PSR-4 推導檔案是否存在
        $resolved = false;
        foreach ($PSR4 as $prefix => $dir) {
            if ('' !== $ns && str_starts_with($ns . '\\', $prefix)) {
                $relative = substr($ns . '\\' . $name, strlen($prefix));
                $candidate = $dir . str_replace('\\', '/', $relative) . '.php';
                if (is_file($candidate)) {
                    $resolved = true;
                    break;
                }
            }
        }
        if ($resolved) {
            continue;
        }

        $problems[] = sprintf('%s → %s', str_replace($root . '/', '', $path), $name);
    }
}

$assert(
    [] === $problems,
    '(b) 每個短名類別引用都解析得到（' . count($problems) . ' 個未解析'
        . ($problems ? '：' . implode('; ', array_slice($problems, 0, 6)) : '') . '）'
);

// (c) 自我驗證：把一個已知的 use 拿掉，本檢查必須抓得到——否則它只是擺設。
$probe_ns = 'YangSheep\\YSCartEcpay\\Api';
$probe_src = "<?php\nnamespace {$probe_ns};\nfinal class Probe {\n    public function go() { OrderPaymentDetail::mutate( 1, fn() => [] ); }\n}\n";
$probe_referenced = [];
preg_match_all('/(?<![\\\\\w$>])([A-Z]\w*)::/', $probe_src, $pm);
$probe_referenced = $pm[1];
$probe_unresolved = [];
foreach ($probe_referenced as $name) {
    $candidate = $root . '/src/Api/' . $name . '.php';
    if (!is_file($candidate)) {
        $probe_unresolved[] = $name;
    }
}
$assert(
    ['OrderPaymentDetail'] === $probe_unresolved,
    '(c) 自我驗證：缺 use 的引用確實會被判為未解析（否則本檢查毫無作用）'
);

echo "\nsymbol resolution contract: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
