<?php
/**
 * YS CART Update 11 H0 source-custody owner.
 *
 * This file is intentionally byte-identical in Core, ECPay, and Shipping Date.
 * The caller supplies the repository alias, root, source authority, and this
 * copy's repository-relative owner path.
 */

if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "PHP 7.4 or newer is required.\n" );
    exit( 2 );
}

$options = getopt(
    '',
    array(
        'repo:',
        'alias:',
        'source:',
        'owner-path:',
        'cross::',
    )
);

$repo       = isset( $options['repo'] ) ? rtrim( (string) $options['repo'], '/\\' ) : '';
$alias      = isset( $options['alias'] ) ? (string) $options['alias'] : '';
$source     = isset( $options['source'] ) ? (string) $options['source'] : '';
$owner_path = isset( $options['owner-path'] ) ? (string) $options['owner-path'] : '';
$cross_path = isset( $options['cross'] ) ? (string) $options['cross'] : '';

$failures = array();
$passes   = 0;

function ys_h0_pass( $label ) {
    global $passes;
    ++$passes;
    echo "PASS: {$label}\n";
}

function ys_h0_fail( $label, $diagnostic = '' ) {
    global $failures;
    $failures[] = $label . ( '' !== $diagnostic ? ': ' . $diagnostic : '' );
    echo "FAIL: {$label}" . ( '' !== $diagnostic ? " ({$diagnostic})" : '' ) . "\n";
}

function ys_h0_check( $condition, $label, $diagnostic = '' ) {
    if ( $condition ) {
        ys_h0_pass( $label );
        return true;
    }
    ys_h0_fail( $label, $diagnostic );
    return false;
}

function ys_h0_is_safe_relative( $path ) {
    if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, '\\' ) || '/' === substr( $path, 0, 1 ) ) {
        return false;
    }
    foreach ( explode( '/', $path ) as $segment ) {
        if ( '' === $segment || '.' === $segment || '..' === $segment ) {
            return false;
        }
    }
    return true;
}

function ys_h0_command( $repo, array $args ) {
    $parts = array( 'git', '-C', $repo );
    foreach ( $args as $arg ) {
        $parts[] = $arg;
    }

    $process = proc_open(
        $parts,
        array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        ),
        $pipes
    );
    if ( ! is_resource( $process ) ) {
        return array( 'code' => 127, 'out' => '', 'err' => 'proc_open failed' );
    }

    fclose( $pipes[0] );
    $out = stream_get_contents( $pipes[1] );
    $err = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    $code = proc_close( $process );

    return array(
        'code' => (int) $code,
        'out'  => is_string( $out ) ? $out : '',
        'err'  => is_string( $err ) ? $err : '',
    );
}

function ys_h0_authority_bytes( $repo, $source, $path ) {
    if ( 'authoring' === $source ) {
        $absolute = $repo . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path );
        $bytes = is_file( $absolute ) ? file_get_contents( $absolute ) : false;
        return array(
            'ok'    => is_string( $bytes ),
            'bytes' => is_string( $bytes ) ? $bytes : '',
            'error' => is_string( $bytes ) ? '' : 'worktree-file-missing',
        );
    }

    $spec = 'index' === $source ? ':' . $path : 'HEAD:' . $path;
    $result = ys_h0_command( $repo, array( 'show', $spec ) );
    return array(
        'ok'    => 0 === $result['code'],
        'bytes' => $result['out'],
        'error' => 0 === $result['code'] ? '' : trim( $result['err'] . ' ' . $result['out'] ),
    );
}

function ys_h0_is_list( array $value ) {
    $expected = 0;
    foreach ( $value as $key => $_unused ) {
        if ( $key !== $expected ) {
            return false;
        }
        ++$expected;
    }
    return true;
}

function ys_h0_canonicalize( $value ) {
    if ( ! is_array( $value ) ) {
        return $value;
    }

    if ( ys_h0_is_list( $value ) ) {
        $result = array();
        foreach ( $value as $entry ) {
            $result[] = ys_h0_canonicalize( $entry );
        }
        return $result;
    }

    ksort( $value, SORT_STRING );
    foreach ( $value as $key => $entry ) {
        $value[ $key ] = ys_h0_canonicalize( $entry );
    }
    return $value;
}

function ys_h0_decode_manifest( $bytes, $label ) {
    if ( ! is_string( $bytes ) || '' === $bytes ) {
        ys_h0_fail( $label, 'empty' );
        return null;
    }
    if ( 0 === strncmp( $bytes, "\xEF\xBB\xBF", 3 ) ) {
        ys_h0_fail( $label, 'utf8-bom' );
        return null;
    }
    if ( 1 !== preg_match( '//u', $bytes ) ) {
        ys_h0_fail( $label, 'invalid-utf8' );
        return null;
    }

    $decoded = json_decode( $bytes, true );
    if ( ! is_array( $decoded ) ) {
        ys_h0_fail( $label, 'invalid-json-' . json_last_error_msg() );
        return null;
    }
    return $decoded;
}

function ys_h0_manifest_hash( array $manifest ) {
    if ( ! isset( $manifest['integrity'] ) || ! is_array( $manifest['integrity'] ) ) {
        return null;
    }
    $manifest['integrity']['manifest_sha256'] = '';
    $canonical = json_encode(
        ys_h0_canonicalize( $manifest ),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    return is_string( $canonical ) ? hash( 'sha256', $canonical . "\n" ) : null;
}

function ys_h0_validate_self_hash( array $manifest, $label ) {
    $stored = isset( $manifest['integrity']['manifest_sha256'] )
        ? strtolower( (string) $manifest['integrity']['manifest_sha256'] )
        : '';
    $actual = ys_h0_manifest_hash( $manifest );
    return ys_h0_check(
        is_string( $actual ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $stored ) && hash_equals( $stored, $actual ),
        $label . ' self-hash is valid',
        'stored=' . $stored . ' actual=' . ( is_string( $actual ) ? $actual : 'invalid' )
    );
}

function ys_h0_normalized_sha( $bytes ) {
    return hash( 'sha256', str_replace( array( "\r\n", "\r" ), "\n", $bytes ) );
}

function ys_h0_load_json_file( $path ) {
    $bytes = is_file( $path ) ? file_get_contents( $path ) : false;
    return is_string( $bytes ) ? json_decode( $bytes, true ) : null;
}

function ys_h0_selector_matches( array $selector, $path ) {
    if ( ! isset( $selector['kind'], $selector['value'] ) ) {
        return false;
    }
    if ( 'exact' === $selector['kind'] ) {
        return $path === $selector['value'];
    }
    if ( 'prefix' === $selector['kind'] ) {
        return 0 === strpos( $path, $selector['value'] );
    }
    return false;
}

function ys_h0_runtime_projection( $profile_root ) {
    if ( ! is_dir( $profile_root ) ) {
        return array( 'ok' => false, 'error' => 'profile-root-missing' );
    }

    $allowed = '/^(?:ys-plugin-hub-client\.php|index\.php|src\/.+\.php|templates\/.+\.php|assets\/.+\.(?:css|js))$/D';
    $known_excluded = '/^(?:composer\.json|composer\.lock|tests\/|docs\/)/D';
    $rows = array();
    $seen = array();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $profile_root, FilesystemIterator::SKIP_DOTS )
    );
    foreach ( $iterator as $file ) {
        if ( ! $file->isFile() ) {
            continue;
        }
        if ( $file->isLink() ) {
            return array( 'ok' => false, 'error' => 'profile-link' );
        }

        $full = str_replace( '\\', '/', $file->getPathname() );
        $base = rtrim( str_replace( '\\', '/', realpath( $profile_root ) ), '/' ) . '/';
        if ( 0 !== strpos( $full, $base ) ) {
            return array( 'ok' => false, 'error' => 'profile-path-escape' );
        }
        $relative = substr( $full, strlen( $base ) );
        $folded = strtolower( $relative );
        if ( isset( $seen[ $folded ] ) && $seen[ $folded ] !== $relative ) {
            return array( 'ok' => false, 'error' => 'profile-case-collision' );
        }
        $seen[ $folded ] = $relative;

        if ( 1 !== preg_match( $allowed, $relative ) ) {
            if ( 1 !== preg_match( $known_excluded, $relative ) ) {
                return array( 'ok' => false, 'error' => 'UNKNOWN_PROFILE_PATH:' . $relative );
            }
            continue;
        }

        $bytes = file_get_contents( $file->getPathname() );
        if ( ! is_string( $bytes ) || 0 === strncmp( $bytes, "\xEF\xBB\xBF", 3 ) || 1 !== preg_match( '//u', $bytes ) ) {
            return array( 'ok' => false, 'error' => 'profile-text-invalid:' . $relative );
        }
        $normalized = str_replace( array( "\r\n", "\r" ), "\n", $bytes );
        $rows[] = $relative . "\t" . strlen( $normalized ) . "\t" . hash( 'sha256', $normalized );
    }

    sort( $rows, SORT_STRING );
    if ( array() === $rows ) {
        return array( 'ok' => false, 'error' => 'profile-empty' );
    }

    return array(
        'ok'    => true,
        'count' => count( $rows ),
        'sha'   => hash( 'sha256', implode( "\n", $rows ) . "\n" ),
    );
}

if ( '' === $repo || ! is_dir( $repo ) || ! in_array( $alias, array( 'core', 'ecpay', 'shipping' ), true ) ||
    ! in_array( $source, array( 'authoring', 'index', 'head' ), true ) || ! ys_h0_is_safe_relative( $owner_path ) ) {
    fwrite(
        STDERR,
        "Usage: php owner.php --repo=<root> --alias=core|ecpay|shipping --source=authoring|index|head --owner-path=<path> [--cross=<bindings.json>]\n"
    );
    exit( 2 );
}

echo "H0 custody refuses an ignored owner path\n";
echo "CANDIDATE_AUTHORITY=" . strtoupper( 'authoring' === $source ? 'WORKTREE' : $source ) . "\n";
echo "COMMITTED=" . ( 'head' === $source ? 'YES' : 'NO' ) . "\n";

$inside = ys_h0_command( $repo, array( 'rev-parse', '--is-inside-work-tree' ) );
ys_h0_check( 0 === $inside['code'] && 'true' === trim( $inside['out'] ), 'repository is a Git worktree' );

$head = ys_h0_command( $repo, array( 'rev-parse', 'HEAD' ) );
$head_sha = trim( $head['out'] );
ys_h0_check( 0 === $head['code'] && 1 === preg_match( '/^[a-f0-9]{40}$/D', $head_sha ), 'HEAD commit is readable', $head_sha );

$manifest_path = 'tests/fixtures/h0/path-owner-manifest.json';
$manifest_blob = ys_h0_authority_bytes( $repo, $source, $manifest_path );
$manifest = $manifest_blob['ok'] ? ys_h0_decode_manifest( $manifest_blob['bytes'], 'path-owner manifest' ) : null;
ys_h0_check( $manifest_blob['ok'], 'path-owner manifest exists in selected authority', $manifest_blob['error'] );

if ( is_array( $manifest ) ) {
    ys_h0_validate_self_hash( $manifest, 'path-owner manifest' );
    ys_h0_check( isset( $manifest['schema'] ) && 'ys-h0-path-owner/v1' === $manifest['schema'], 'path-owner schema is exact' );
    ys_h0_check(
        isset( $manifest['repositories'][ $alias ]['local_owner'] ) &&
        $manifest['repositories'][ $alias ]['local_owner'] === $owner_path,
        'owner path agrees with repository alias'
    );

    $rules_ok = isset( $manifest['rules'], $manifest['path_sets'], $manifest['gates'] ) &&
        is_array( $manifest['rules'] ) && is_array( $manifest['path_sets'] ) && is_array( $manifest['gates'] );
    if ( $rules_ok ) {
        foreach ( $manifest['rules'] as $rule ) {
            if ( ! isset( $rule['path_set'], $rule['required_focused'], $rule['required_final'] ) ||
                ! isset( $manifest['path_sets'][ $rule['path_set'] ] ) ||
                ! is_array( $rule['required_focused'] ) || array() === $rule['required_focused'] ||
                ! is_array( $rule['required_final'] ) || array() === $rule['required_final'] ) {
                $rules_ok = false;
                break;
            }
            foreach ( array_merge( $rule['required_focused'], $rule['required_final'] ) as $gate ) {
                if ( ! isset( $manifest['gates'][ $gate ] ) ) {
                    $rules_ok = false;
                    break 2;
                }
            }
        }
    }
    ys_h0_check( $rules_ok, 'every owner rule maps to existing focused and final gates' );

    $owner_covered = false;
    if ( isset( $manifest['path_sets']['h0_evidence'][ $alias ] ) ) {
        foreach ( $manifest['path_sets']['h0_evidence'][ $alias ] as $selector ) {
            if ( is_array( $selector ) && ys_h0_selector_matches( $selector, $owner_path ) ) {
                $owner_covered = true;
                break;
            }
        }
    }
    ys_h0_check( $owner_covered, 'owner manifest covers its executable owner path' );
}

$required = array(
    '.gitattributes',
    $owner_path,
    $manifest_path,
);
if ( 'core' === $alias ) {
    $required[] = 'tests/fixtures/h0/package-2.0.5-manifest.json';
    $required[] = 'tests/fixtures/h0/legacy-profile-inventory.json';
}
if ( 'ecpay' === $alias ) {
    $required[] = '.gitignore';
}
if ( 'shipping' === $alias ) {
    $required[] = 'tests/suite_manifest.php';
    $required[] = 'tests/regression/v100_runner_completeness_contract.php';
}
$required = array_values( array_unique( $required ) );

foreach ( $required as $path ) {
    $authority = ys_h0_authority_bytes( $repo, $source, $path );
    ys_h0_check( $authority['ok'], $path . ' exists in selected authority', $authority['error'] );
    if ( ! $authority['ok'] ) {
        continue;
    }

    $stage = ys_h0_command( $repo, array( 'ls-files', '--stage', '--', $path ) );
    $stage_lines = array_values( array_filter( preg_split( '/\r?\n/', trim( $stage['out'] ) ) ) );
    $stage_zero = 1 === count( $stage_lines ) && 1 === preg_match( '/^[0-7]{6} [a-f0-9]{40,64} 0\t/', $stage_lines[0] );
    ys_h0_check( 0 === $stage['code'] && $stage_zero, $path . ' is tracked at index stage 0' );

    $ignored = ys_h0_command( $repo, array( 'check-ignore', '--no-index', '--', $path ) );
    ys_h0_check( 1 === $ignored['code'], $path . ' is not ignored', trim( $ignored['out'] . ' ' . $ignored['err'] ) );

    $absolute = $repo . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path );
    $worktree = is_file( $absolute ) ? file_get_contents( $absolute ) : false;
    ys_h0_check( is_string( $worktree ), $path . ' exists in worktree' );
    if ( is_string( $worktree ) ) {
        ys_h0_check(
            hash_equals( ys_h0_normalized_sha( $authority['bytes'] ), ys_h0_normalized_sha( $worktree ) ),
            $path . ' normalized worktree bytes equal selected authority'
        );
        ys_h0_check( 0 !== strncmp( $worktree, "\xEF\xBB\xBF", 3 ), $path . ' has no UTF-8 BOM' );
    }

    if ( 'authoring' !== $source ) {
        $unstaged = ys_h0_command( $repo, array( 'diff', '--name-only', '--', $path ) );
        ys_h0_check( 0 === $unstaged['code'] && '' === trim( $unstaged['out'] ), $path . ' has no unstaged drift' );
    }
    if ( 'head' === $source ) {
        $staged = ys_h0_command( $repo, array( 'diff', '--cached', '--name-only', 'HEAD', '--', $path ) );
        ys_h0_check( 0 === $staged['code'] && '' === trim( $staged['out'] ), $path . ' has no staged drift from HEAD' );
    }
}

$attribute_paths = array(
    'vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php',
    $owner_path,
    $manifest_path,
);
foreach ( $attribute_paths as $path ) {
    $args = array( 'check-attr' );
    if ( 'index' === $source ) {
        $args[] = '--cached';
    } elseif ( 'head' === $source ) {
        $args[] = '--source=HEAD';
    }
    $args = array_merge( $args, array( 'text', 'eol', '--', $path ) );
    $attr = ys_h0_command( $repo, $args );
    $attr_ok = 0 === $attr['code'] &&
        1 === preg_match( '/: text: (?:set|auto)\r?\n.*: eol: lf(?:\r?\n|$)/s', $attr['out'] );
    ys_h0_check( $attr_ok, $path . ' is covered by committed/index LF attributes', trim( $attr['out'] . ' ' . $attr['err'] ) );
}

$running_bytes = file_get_contents( __FILE__ );
$owner_authority = ys_h0_authority_bytes( $repo, $source, $owner_path );
ys_h0_check(
    is_string( $running_bytes ) && $owner_authority['ok'] && hash_equals( hash( 'sha256', $owner_authority['bytes'] ), hash( 'sha256', $running_bytes ) ),
    'executing owner bytes equal selected authority owner blob'
);

$tracked_tests = ys_h0_command( $repo, array( 'ls-files', '-z', '--', 'tests/regression/*.php' ) );
$tracked_test_set = array();
foreach ( explode( "\0", $tracked_tests['out'] ) as $path ) {
    if ( '' !== $path ) {
        $tracked_test_set[ str_replace( '\\', '/', $path ) ] = true;
    }
}
$disk_test_paths = array();
foreach ( glob( $repo . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'regression' . DIRECTORY_SEPARATOR . '*.php' ) as $path ) {
    $disk_test_paths[] = 'tests/regression/' . basename( $path );
}
$untracked_tests = array();
foreach ( $disk_test_paths as $path ) {
    if ( ! isset( $tracked_test_set[ $path ] ) ) {
        $untracked_tests[] = $path;
    }
}
ys_h0_check(
    0 === $tracked_tests['code'] && array() === $untracked_tests,
    'every on-disk regression PHP owner is tracked',
    implode( ',', $untracked_tests )
);

if ( 'ecpay' === $alias ) {
    $ignore_blob = ys_h0_authority_bytes( $repo, $source, '.gitignore' );
    $bad_rules = $ignore_blob['ok'] && 1 === preg_match( '/^\/tests\/?\s*$/m', $ignore_blob['bytes'] );
    ys_h0_check( $ignore_blob['ok'] && ! $bad_rules, 'ECPay no longer ignores the tests root' );
}

if ( 'core' === $alias ) {
    foreach ( array(
        'tests/fixtures/h0/package-2.0.5-manifest.json' => 'package fixture manifest',
        'tests/fixtures/h0/legacy-profile-inventory.json' => 'legacy profile inventory',
    ) as $path => $label ) {
        $blob = ys_h0_authority_bytes( $repo, $source, $path );
        $decoded = $blob['ok'] ? ys_h0_decode_manifest( $blob['bytes'], $label ) : null;
        if ( is_array( $decoded ) ) {
            ys_h0_validate_self_hash( $decoded, $label );
        }
    }

    $package_blob = ys_h0_authority_bytes( $repo, $source, 'tests/fixtures/h0/package-2.0.5-manifest.json' );
    $package = $package_blob['ok'] ? json_decode( $package_blob['bytes'], true ) : null;
    ys_h0_check(
        is_array( $package ) && 23 === $package['file_count'] && 23 === count( $package['files'] ) &&
        isset( $package['exclusions'] ) && array() === $package['exclusions'],
        'package 2.0.5 fixture owns all 23 files with zero exclusions'
    );

    $inventory_blob = ys_h0_authority_bytes( $repo, $source, 'tests/fixtures/h0/legacy-profile-inventory.json' );
    $inventory = $inventory_blob['ok'] ? json_decode( $inventory_blob['bytes'], true ) : null;
    $inventory_ok = is_array( $inventory ) &&
        24 === $inventory['expected_workspace_loader_count'] &&
        5 === $inventory['distinct_workspace_bootstrap_count'] &&
        28 === $inventory['profile_count'] &&
        7 === $inventory['distinct_profile_count'];
    ys_h0_check( $inventory_ok, 'legacy inventory freezes 24 workspace loaders, 5 bootstraps, and 7 runtime profiles' );

    if ( is_array( $inventory ) ) {
        $by_id = array();
        $unclassified = array();
        foreach ( $inventory['profiles'] as $profile ) {
            $by_id[ $profile['id'] ] = $profile;
            if ( ! in_array( $profile['classification'], array( 'supported_lazy', 'deterministic_unready', 'scope_excluded' ), true ) ) {
                $unclassified[] = $profile['id'];
            }
        }
        ys_h0_check( array() === $unclassified, 'legacy inventory has no unclassified profile', implode( ',', $unclassified ) );
        ys_h0_check(
            isset( $by_id['fixture-package-2.0.5'] ) &&
            'supported_lazy' === $by_id['fixture-package-2.0.5']['classification'],
            'package 2.0.5 exact profile is supported_lazy'
        );
        ys_h0_check(
            isset( $by_id['workspace-ys-translatepress-addons'] ) &&
            'scope_excluded' === $by_id['workspace-ys-translatepress-addons']['classification'],
            'TranslatePress remains inventory-only scope_excluded'
        );
        foreach ( array(
            'candidate-core-bbc2e6e' => 'bbc2e6e0480c2061c3db34101273cb2f74f55dd6',
            'candidate-ecpay-c5492ee' => 'c5492eebc247982fabbc3b635952dba9da5f05bb',
            'candidate-shipping-d703ca97' => 'd703ca97ab88697e582311bc7c5482e6cf8a16c8',
        ) as $id => $commit ) {
            ys_h0_check(
                isset( $by_id[ $id ]['source_commit'] ) && $commit === $by_id[ $id ]['source_commit'] &&
                'supported_lazy' === $by_id[ $id ]['classification'],
                $id . ' source anchor and classification are frozen'
            );
        }
    }
}

if ( '' !== $cross_path ) {
    $bindings = ys_h0_load_json_file( $cross_path );
    ys_h0_check( is_array( $bindings ), 'cross-repository binding JSON is readable' );

    if ( is_array( $bindings ) ) {
        $reference_manifest = null;
        $reference_owner = null;
        foreach ( array( 'core', 'ecpay', 'shipping' ) as $bound_alias ) {
            $bound_root = isset( $bindings['repositories'][ $bound_alias ]['root'] )
                ? rtrim( $bindings['repositories'][ $bound_alias ]['root'], '/\\' )
                : '';
            $bound_owner = isset( $bindings['repositories'][ $bound_alias ]['owner_path'] )
                ? $bindings['repositories'][ $bound_alias ]['owner_path']
                : '';
            $manifest_result = ys_h0_authority_bytes( $bound_root, $source, $manifest_path );
            $owner_result = ys_h0_authority_bytes( $bound_root, $source, $bound_owner );
            ys_h0_check( $manifest_result['ok'] && $owner_result['ok'], $bound_alias . ' cross authority blobs are readable' );
            if ( ! $manifest_result['ok'] || ! $owner_result['ok'] ) {
                continue;
            }
            if ( null === $reference_manifest ) {
                $reference_manifest = $manifest_result['bytes'];
                $reference_owner = $owner_result['bytes'];
            } else {
                ys_h0_check( hash_equals( $reference_manifest, $manifest_result['bytes'] ), $bound_alias . ' path-owner manifest is byte-identical' );
                ys_h0_check( hash_equals( $reference_owner, $owner_result['bytes'] ), $bound_alias . ' custody owner executable is byte-identical' );
            }
        }

        $core_package = ys_h0_authority_bytes(
            rtrim( $bindings['repositories']['core']['root'], '/\\' ),
            $source,
            'tests/fixtures/h0/package-2.0.5-manifest.json'
        );
        $package_manifest = $core_package['ok'] ? json_decode( $core_package['bytes'], true ) : null;
        $package_root = isset( $bindings['package_fixture_root'] )
            ? rtrim( $bindings['package_fixture_root'], '/\\' )
            : '';
        $package_ok = is_array( $package_manifest ) && is_dir( $package_root );
        $disk_paths = array();
        if ( $package_ok ) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $package_root, FilesystemIterator::SKIP_DOTS )
            );
            $expected = array();
            foreach ( $package_manifest['files'] as $entry ) {
                $expected[ $entry['path'] ] = $entry;
            }
            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() || $file->isLink() ) {
                    $package_ok = false;
                    continue;
                }
                $base = rtrim( str_replace( '\\', '/', realpath( $package_root ) ), '/' ) . '/';
                $full = str_replace( '\\', '/', $file->getPathname() );
                $relative = substr( $full, strlen( $base ) );
                $disk_paths[] = $relative;
                if ( ! isset( $expected[ $relative ] ) ) {
                    $package_ok = false;
                    continue;
                }
                $bytes = file_get_contents( $file->getPathname() );
                if ( ! is_string( $bytes ) ||
                    ! hash_equals( $expected[ $relative ]['raw_sha256'], hash( 'sha256', $bytes ) ) ||
                    ! hash_equals( $expected[ $relative ]['normalized_lf_sha256'], ys_h0_normalized_sha( $bytes ) ) ) {
                    $package_ok = false;
                }
            }
            sort( $disk_paths, SORT_STRING );
            $expected_paths = array_keys( $expected );
            sort( $expected_paths, SORT_STRING );
            $package_ok = $package_ok && $disk_paths === $expected_paths;
        }
        ys_h0_check( $package_ok, 'external package 2.0.5 fixture matches all 23 checked-in entries' );

        $core_inventory = ys_h0_authority_bytes(
            rtrim( $bindings['repositories']['core']['root'], '/\\' ),
            $source,
            'tests/fixtures/h0/legacy-profile-inventory.json'
        );
        $inventory = $core_inventory['ok'] ? json_decode( $core_inventory['bytes'], true ) : null;
        $profile_bindings = isset( $bindings['profile_roots'] ) && is_array( $bindings['profile_roots'] )
            ? $bindings['profile_roots']
            : array();
        $profiles_ok = is_array( $inventory );
        if ( $profiles_ok ) {
            $by_id = array();
            foreach ( $inventory['profiles'] as $profile ) {
                $by_id[ $profile['id'] ] = $profile;
            }
            foreach ( $profile_bindings as $id => $root ) {
                $projection = ys_h0_runtime_projection( $root );
                if ( ! isset( $by_id[ $id ] ) || ! $projection['ok'] ||
                    $projection['count'] !== $by_id[ $id ]['runtime_file_count'] ||
                    ! hash_equals( $by_id[ $id ]['normalized_profile_sha256'], $projection['sha'] ) ) {
                    $profiles_ok = false;
                    ys_h0_fail( $id . ' live/frozen projection matches inventory', isset( $projection['error'] ) ? $projection['error'] : 'hash-or-count' );
                } else {
                    ys_h0_pass( $id . ' live/frozen projection matches inventory' );
                }
            }
        }
        ys_h0_check( $profiles_ok, 'all named profile bindings reproduce the checked-in inventory' );

        $workspace_root = isset( $bindings['workspace_plugins_root'] )
            ? rtrim( $bindings['workspace_plugins_root'], '/\\' )
            : '';
        $loaders = array();
        foreach ( glob( $workspace_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR ) as $plugin_root ) {
            $loader = $plugin_root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'yangsheep' .
                DIRECTORY_SEPARATOR . 'ys-plugin-hub-client' . DIRECTORY_SEPARATOR . 'ys-plugin-hub-client.php';
            if ( is_file( $loader ) ) {
                $loaders[] = $loader;
            }
        }
        $loader_hashes = array();
        foreach ( $loaders as $loader ) {
            $bytes = file_get_contents( $loader );
            if ( is_string( $bytes ) ) {
                $loader_hashes[] = ys_h0_normalized_sha( $bytes );
            }
        }
        ys_h0_check( 24 === count( $loaders ), 'workspace immediate-child scan still finds exactly 24 Hub loaders' );
        ys_h0_check( 5 === count( array_unique( $loader_hashes ) ), 'workspace immediate-child scan still finds exactly 5 normalized loader hashes' );
    }
}

echo "SUMMARY: {$passes} PASS / " . count( $failures ) . " FAIL\n";

if ( 'authoring' === $source ) {
    echo "CUSTODY_PREFLIGHT=UNFROZEN\n";
    exit( 2 );
}

if ( $failures ) {
    echo "CUSTODY_" . strtoupper( $source ) . "_PREFLIGHT=FAIL\n";
    exit( 1 );
}

echo "CUSTODY_" . strtoupper( $source ) . "_PREFLIGHT=PASS\n";
exit( 0 );
