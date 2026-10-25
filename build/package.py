#!/usr/bin/env python3
"""Assemble one uploadable WHMCS package with deterministic ZIP entries."""
from pathlib import Path
import hashlib, json, shutil, subprocess, zipfile

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'
VERSION = '1.0.0'
DATE = (2026, 10, 25, 0, 0, 0)
def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def files(path): return sorted(p for p in path.rglob('*') if p.is_file())

def assemble():
    target = DIST / 'whmcs-root'
    if target.exists(): shutil.rmtree(target)
    target.mkdir(parents=True)
    for folder in ('modules','includes'): shutil.copytree(ROOT / folder, target / folder, dirs_exist_ok=True)
    base = target / 'modules/gateways/bachs'
    library = base / 'lib'
    for name in ('README.md','CHANGELOG.md','CONTRIBUTING.md','SECURITY.md','LICENSE.txt'):
        shutil.copy2(ROOT / name, base / name)
    (base / 'docs').mkdir()
    for name in ('installation.md', 'development.md'):
        shutil.copy2(ROOT / 'docs' / name, base / 'docs' / name)
    components = {str(p.relative_to(target)): digest(p) for p in files(target) if p.suffix == '.php' and library not in p.parents and not str(p.relative_to(target)).startswith('modules/addons/')}
    manifest = dict(
        version=VERSION,
        source_commit=source_commit(), source_tree_sha256=source_digest(),
        runtime_syntax_floor='PHP 7.2', minimum_whmcs='8.0.0', target_whmcs='8.0+',
        components=components,
        classes={str(path.relative_to(library / 'src')): digest(path) for path in files(library / 'src')})
    (library / 'release.json').write_text(json.dumps(manifest, indent=2) + '\n')
    archive = DIST / f'bachs-whmcs-{VERSION}.zip'
    with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for path in files(target):
            info=zipfile.ZipInfo(str(path.relative_to(target)),DATE);info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o644<<16
            z.writestr(info,path.read_bytes())
    return archive

def source_commit():
    status = subprocess.run(['git', 'status', '--porcelain'], cwd=ROOT, capture_output=True, text=True)
    if status.returncode or status.stdout.strip():
        return None
    commit = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=ROOT, capture_output=True, text=True)
    return commit.stdout.strip() if commit.returncode == 0 else None

def source_digest():
    h=hashlib.sha256()
    for name in ('modules','includes'):
        for p in files(ROOT/name):h.update(str(p.relative_to(ROOT)).encode());h.update(p.read_bytes())
    return h.hexdigest()
def main():
    DIST.mkdir(exist_ok=True)
    paths=[assemble()]
    (DIST/'SHA256SUMS').write_text(''.join(f'{digest(p)}  {p.name}\n' for p in paths))
    # These files belonged to the earlier development package layout.
    for obsolete in ('release-manifest.json',):
        path = DIST / obsolete
        if path.exists():
            path.unlink()
    print('\n'.join(str(p.relative_to(ROOT)) for p in paths))
if __name__=='__main__':main()
