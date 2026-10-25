#!/usr/bin/env python3
"""Check the upload ZIP, file hashes, bootstrap, and private-file exclusions."""

from pathlib import Path
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

from package import DIST, VERSION, digest


def run_php(code):
    result = subprocess.run(['php', '-r', code], capture_output=True, text=True)
    return result.returncode, result.stdout, result.stderr


def check_loader(path, label, errors):
    code = (
        "define('WHMCS', true); require " + repr(str(path))
        + "; echo Bachs\\Whmcs\\Domain\\Money::add('0.10', '0.20');"
    )
    status, output, error = run_php(code)
    if status or output != '0.30':
        errors.append(label + ' failed: ' + output + error)


def main():
    errors = []
    archive_name = f'bachs-whmcs-{VERSION}.zip'
    archive_path = DIST / archive_name
    library_path = 'modules/gateways/bachs/lib/'
    required = (
        'modules/gateways/bachs.php',
        'modules/gateways/bachs_card.php',
        'modules/gateways/bachs/card.php',
        'modules/gateways/callback/bachs.php',
        'modules/addons/bachs_admin/bachs_admin.php',
        'modules/addons/bachs_admin/templates/client.tpl',
        'includes/hooks/bachs.php',
    )

    with zipfile.ZipFile(archive_path) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)):
            errors.append('Duplicate ZIP entries')
        for name in names:
            if name.startswith('/') or '..' in Path(name).parts:
                errors.append('Unsafe archive path: ' + name)
            if re.search(r'(^|/)(tests|node_modules|vendor|\.git|\.env|build|\.validation)(/|$)', name):
                errors.append('Development or private file shipped: ' + name)
            if re.search(rb'sk_(?:live|sandbox)_[A-Za-z0-9_-]{16,}|whsec_[A-Za-z0-9_-]{16,}', archive.read(name)):
                errors.append('Possible credential in: ' + name)

        for removed in ('Connect', 'Finance', 'Disputes', 'Subscriptions'):
            if library_path + 'src/Application/' + removed + '.php' in names:
                errors.append('Removed merchant service shipped: ' + removed)
        for name in required:
            if name not in names:
                errors.append('Missing required file: ' + name)

        manifest = json.loads(archive.read(library_path + 'release.json'))
        if manifest.get('version') != VERSION or manifest.get('target_whmcs') != '7.0–9.1':
            errors.append('Incorrect package version or target metadata')
        for name, expected in manifest['components'].items():
            if hashlib.sha256(archive.read(name)).hexdigest() != expected:
                errors.append('Component hash mismatch: ' + name)
        for name, expected in manifest['classes'].items():
            if hashlib.sha256(archive.read(library_path + 'src/' + name)).hexdigest() != expected:
                errors.append('Class hash mismatch: ' + name)

    base = DIST / 'whmcs-root/modules/gateways/bachs'
    check_loader(base / 'lib/autoload.php', 'Packaged autoload', errors)
    check_loader(base / 'bootstrap.php', 'Packaged bootstrap', errors)

    # Simulate an incomplete upload without touching the real package.
    with tempfile.TemporaryDirectory(prefix='bachs-package-check-') as temporary:
        copied = Path(temporary) / 'whmcs-root'
        shutil.copytree(DIST / 'whmcs-root', copied)
        gateway = copied / 'modules/gateways/bachs.php'
        gateway.write_text(gateway.read_text() + '\n// simulated partial upload\n')
        bootstrap = copied / 'modules/gateways/bachs/bootstrap.php'
        code = (
            "define('WHMCS', true); try { require " + repr(str(bootstrap))
            + "; exit(2); } catch (RuntimeException $e) { echo 'blocked'; }"
        )
        status, output, error = run_php(code)
        if status or output != 'blocked':
            errors.append('Partial upload was not blocked: ' + error)

    if sorted(path.name for path in DIST.glob('*.zip')) != [archive_name]:
        errors.append('Unexpected extra ZIP files')
    expected_checksums = f'{digest(archive_path)}  {archive_name}\n'
    if (DIST / 'SHA256SUMS').read_text() != expected_checksums:
        errors.append('ZIP checksum mismatch')
    if any('/docs/' in name and not name.endswith(('installation.md', 'development.md')) for name in names):
        errors.append('Old development paperwork shipped')
    if library_path + 'src/Infrastructure/Whmcs/Acceptance.php' in names:
        errors.append('Removed private acceptance-file gate shipped')

    if errors:
        print('\n'.join(errors))
        return 1
    print(f'Package verified: {len(names)} entries, file hashes, bootstrap, private-file exclusions, and partial-upload guard.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
