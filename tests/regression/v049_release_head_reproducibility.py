"""Run the actual builder in retained LF/CRLF checkouts; no network or product writes."""
from pathlib import Path
import hashlib, json, os, shutil, subprocess, sys, tempfile, zipfile

repo = Path(__file__).resolve().parents[2]
php = sys.argv[1]
arena = Path(tempfile.mkdtemp(prefix='ys-ecpay-head-build-'))
rows = []

def run(args, cwd, check=True):
    result = subprocess.run(args, cwd=cwd, capture_output=True, timeout=120)
    if check and result.returncode:
        raise RuntimeError(result.stderr.decode(errors='replace'))
    return result

def verify(ok, label):
    rows.append({'case': label, 'pass': bool(ok)})
    print(('PASS ' if ok else 'FAIL ') + label, flush=True)

archives = []
for eol in ('lf', 'crlf'):
    target = arena / eol
    run(['git', '-c', 'core.autocrlf=' + ('true' if eol == 'crlf' else 'false'),
         'clone', '--no-hardlinks', '--quiet', str(repo), str(target)], arena)
    run(['git', 'config', 'core.autocrlf', 'true' if eol == 'crlf' else 'false'], target)
    # Test pending builder changes as a coherent fixture commit, never alter the source repo.
    for rel in ('bin/build-release.php', 'bin/release-policy.php', 'bin/release-source.php',
                'tests/regression/v004_release_package_contract.php'):
        if (repo / rel).exists():
            (target / rel).write_bytes((repo / rel).read_bytes().replace(b'\r\n', b'\n').replace(
                b'\n', b'\r\n' if eol == 'crlf' else b'\n'))
            run(['git', 'add', '--', rel], target)
    run(['git', '-c', 'user.name=YS local fixture', '-c', 'user.email=fixture@example.invalid',
         'commit', '--allow-empty', '-qm', 'Exercise current release builder'], target)
    # Existing Windows checkouts can still contain CRLF after an eol=lf attributes change.
    # Git normalizes those text bytes and correctly reports them clean.
    if eol == 'crlf':
        for rel in run(['git', 'ls-files', '-z'], target).stdout.decode().split('\0'):
            path = target / rel
            if rel and path.is_file():
                data = path.read_bytes()
                if b'\0' not in data:
                    path.write_bytes(data.replace(b'\r\n', b'\n').replace(b'\n', b'\r\n'))
        run(['git', 'add', '-u'], target)
        verify(not run(['git', 'diff', '--cached', '--name-only'], target).stdout.strip(), 'crlf: normalization preserves HEAD')
    verify((b'\r\n' in (target / 'ys-cart-ecpay.php').read_bytes()) == (eol == 'crlf'), eol + ': actual checkout EOL')
    verify(not run(['git', 'status', '--porcelain', '--untracked-files=no'], target).stdout.strip(), eol + ': tracked clean')
    (target / 'local-only-note.txt').write_text('Untracked file must never ship.\n')
    build = run([php, 'bin/build-release.php'], target, False)
    verify(build.returncode == 0, eol + ': build succeeds')
    if build.returncode:
        print(build.stderr.decode(errors='replace'))
        continue
    archive = Path(build.stdout.decode().strip())
    original = archive.read_bytes()
    archives.append(original)
    with zipfile.ZipFile(archive) as package:
        verify('ys-cart-ecpay/local-only-note.txt' not in package.namelist(), eol + ': untracked excluded')
        files = [n for n in package.namelist() if not n.endswith('/')]
        verify(all(package.read(n) == run(['git', 'cat-file', 'blob', 'HEAD:' + n[len('ys-cart-ecpay/'):]], target, False).stdout
                   for n in files), eol + ': every shipped file equals HEAD blob')
    run([php, 'bin/build-release.php'], target)
    verify(archive.read_bytes() == original, eol + ': repeat identical')
    contract = run([php, 'tests/regression/v004_release_package_contract.php'], target, False)
    (arena / (eol + '-v004.stdout')).write_bytes(contract.stdout)
    (arena / (eol + '-v004.stderr')).write_bytes(contract.stderr)
    verify(contract.returncode == 0 and not contract.stderr, eol + ': complete v004 package contract')
    with (target / 'README.md').open('ab') as handle:
        handle.write(b'\nfixture tracked edit\n')
    dirty = run([php, 'bin/build-release.php'], target, False)
    verify(dirty.returncode != 0 and archive.read_bytes() == original, eol + ': dirty rejected and prior artifact retained')
    run(['git', 'add', '--', 'README.md'], target)
    staged = run([php, 'bin/build-release.php'], target, False)
    verify(staged.returncode != 0 and archive.read_bytes() == original, eol + ': staged rejected and prior artifact retained')
verify(len(archives) == 2 and archives[0] == archives[1], 'LF and CRLF checkouts produce identical archive')
(arena / 'receipt.json').write_text(json.dumps({'rows': rows, 'hashes': [hashlib.sha256(b).hexdigest() for b in archives]}, indent=2))
print(json.dumps({'arena': str(arena), 'pass': sum(r['pass'] for r in rows), 'fail': sum(not r['pass'] for r in rows)}))
sys.exit(any(not r['pass'] for r in rows))
