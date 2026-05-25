<?php
declare(strict_types=1);

$slug    = 'ys-cart-ecpay';
$root    = dirname(__DIR__);
$main    = $root . DIRECTORY_SEPARATOR . 'ys-cart-ecpay.php';
$source  = (string) file_get_contents($main);
$version = preg_match('/^ \* Version:\s*([^\r\n]+)/m', $source, $matches) ? trim($matches[1]) : '0.0.0';
$outDir  = $root . DIRECTORY_SEPARATOR . 'artifacts';
$zipPath = $outDir . DIRECTORY_SEPARATOR . $slug . '-' . $version . '.zip';

if (!extension_loaded('zip')) {
    fwrite(STDERR, "Zip extension is required.\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "Unable to create artifacts directory.\n");
    exit(1);
}

if (is_file($zipPath)) {
    unlink($zipPath);
}

$excludeDirs = [
    '.git',
    '.github',
    '.idea',
    '.vscode',
    'artifacts',
    'bin',
    'node_modules',
    'tests',
    'tmp',
];

$excludeFiles = [
    '.gitignore',
    '.env',
    '.env.example',
    'composer.json',
    'composer.lock',
    'CHANGELOG.md',
    'phpunit.xml',
];

$zip = new ZipArchive();
if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    fwrite(STDERR, "Unable to open zip: {$zipPath}\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    $path = $file->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $parts = explode('/', $relative);

    if (array_intersect($parts, $excludeDirs)) {
        continue;
    }

    if ('docs/superpowers' === $relative || str_starts_with($relative, 'docs/superpowers/')) {
        continue;
    }

    if (str_ends_with($relative, '.log') || str_ends_with($relative, '.tmp')) {
        continue;
    }

    if (str_starts_with(basename($relative), '.env')) {
        continue;
    }

    if (in_array(basename($relative), $excludeFiles, true)) {
        continue;
    }

    if ($file->isDir()) {
        $zip->addEmptyDir($slug . '/' . $relative);
        continue;
    }

    $zip->addFile($path, $slug . '/' . $relative);
}

$zip->close();

echo $zipPath . PHP_EOL;
