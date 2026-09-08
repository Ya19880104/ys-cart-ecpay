<?php
/** Binary-safe committed source for the release builder and package contract. */
declare(strict_types=1);

function ys_cart_ecpay_release_git(string $root, array $args): string
{
    $stderr = tmpfile();
    if (false === $stderr) {
        throw new RuntimeException('Unable to allocate git stderr stream.');
    }
    $process = proc_open(array_merge(['git', '-C', $root], $args), [1 => ['pipe', 'w'], 2 => $stderr], $pipes);
    if (!is_resource($process)) {
        fclose($stderr);
        throw new RuntimeException('Unable to start git.');
    }
    $bytes = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $rc = proc_close($process);
    fclose($stderr);
    if (0 !== $rc || false === $bytes) {
        throw new RuntimeException('Unable to read committed release source.');
    }
    return $bytes;
}

/** Eligible paths and their exact HEAD bytes; filesystem-only files never enter the package. */
function ys_cart_ecpay_release_head(string $root): array
{
    $head = trim(ys_cart_ecpay_release_git($root, ['rev-parse', '--verify', 'HEAD']));
    $files = $dirs = $links = $bytes = [];
    foreach (explode("\0", ys_cart_ecpay_release_git($root, ['ls-tree', '-rz', $head])) as $record) {
        if ('' === $record) {
            continue;
        }
        [$metadata, $path] = explode("\t", $record, 2);
        if (null !== ys_cart_ecpay_release_exclusion_reason($path)) {
            continue;
        }
        [$mode, $type, $oid] = explode(' ', $metadata);
        if ('blob' !== $type || !in_array($mode, ['100644', '100755'], true)) {
            $links[] = $path;
            continue;
        }
        $files[] = $path;
        $bytes[$path] = ys_cart_ecpay_release_git($root, ['cat-file', 'blob', $oid]);
        for ($parent = dirname($path); '.' !== $parent; $parent = dirname($parent)) {
            $dirs[$parent] = true;
        }
    }
    $dirs = array_keys($dirs);
    sort($files, SORT_STRING);
    sort($dirs, SORT_STRING);
    sort($links, SORT_STRING);
    return compact('head', 'files', 'dirs', 'links', 'bytes');
}
