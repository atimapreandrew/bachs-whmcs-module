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
    shutil.copytree(ROOT / 'src', library / 'src')
    shutil.copytree(ROOT / 'resources/assets', base / 'assets')
    shutil.copytree(ROOT / 'resources/templates', target / 'modules/addons/bachs_admin/templates', dirs_exist_ok=True)
    (library / 'autoload.php').write_text("""<?php
if (!defined('WHMCS')) { http_response_code(403); exit('Access denied'); }
spl_autoload_register(function ($class) {
    $prefix = 'Bachs\\\\Whmcs\\\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = str_replace('\\\\', '/', substr($class, strlen($prefix))) . '.php';
        $file = __DIR__ . '/src/' . $relative;
        if (is_file($file)) {
            static $manifest;
            if (!$manifest) { $manifest = json_decode(file_get_contents(__DIR__ . '/release.json'), true); }
            if (!isset($manifest['classes'][$relative]) || !hash_equals($manifest['classes'][$relative], hash_file('sha256', $file))) { throw new RuntimeException('Bachs component integrity mismatch'); }
            require_once $file;
        }
    }
});
""")
    deny='<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n'
    (library / '.htaccess').write_text(deny)
    (base / 'bin/.htaccess').write_text(deny)
    for folder in ('lib','bin'): (base / folder / 'index.php').write_text("<?php\nhttp_response_code(403); exit('Access denied');\n")
    for name in ('README.md','CHANGELOG.md','CONTRIBUTING.md','SECURITY.md','LICENSE.txt','THIRD_PARTY_NOTICES.md'):
        shutil.copy2(ROOT / name, base / name)
    shutil.copytree(ROOT / 'docs', base / 'docs')
    components = {str(p.relative_to(target)): digest(p) for p in files(target) if p.suffix == '.php' and library not in p.parents}
    manifest = dict(version=VERSION, profile='universal', release_status='release_candidate_not_production_verified',
                    latest_whmcs_reference='9.1.0', source_commit=source_commit(),
                    source_tree_sha256=source_digest(), build_date='2026-10-25',
                    target_whmcs='7.0–9.1',
                    runtime_syntax_floor='PHP 5.6', licensed_whmcs_tests='not_run', provider_sandbox_tests='not_run',
                    components=components, classes={str(p.relative_to(library / 'src')):digest(p) for p in files(library / 'src')},
                    dependencies=[], required_extensions=['curl','openssl','json','pdo_mysql'])
    (library / 'release.json').write_text(json.dumps(manifest,indent=2)+'\n')
    sbom={'bomFormat':'CycloneDX','specVersion':'1.5','version':1,'metadata':{'component':{'type':'application','name':'bachs-whmcs','version':VERSION}},'components':[]}
    (base / 'sbom.json').write_text(json.dumps(sbom,indent=2)+'\n')
    archive = DIST / f'bachs-whmcs-{VERSION}.zip'
    with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for path in files(target):
            info=zipfile.ZipInfo(str(path.relative_to(target)),DATE);info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o644<<16
            z.writestr(info,path.read_bytes())
    return archive

def source_commit():
    p=subprocess.run(['git','rev-parse','HEAD'],cwd=ROOT,capture_output=True,text=True)
    return p.stdout.strip() if p.returncode==0 else None
def source_digest():
    h=hashlib.sha256()
    for name in ('src','modules','includes','resources'):
        for p in files(ROOT/name):h.update(str(p.relative_to(ROOT)).encode());h.update(p.read_bytes())
    return h.hexdigest()
def main():
    DIST.mkdir(exist_ok=True)
    paths=[assemble()]
    (DIST/'SHA256SUMS').write_text(''.join(f'{digest(p)}  {p.name}\n' for p in paths))
    (DIST/'release-manifest.json').write_text(json.dumps({'version':VERSION,'source_commit':source_commit(),'source_tree_sha256':source_digest(),'release_status':'release_candidate_not_production_verified','packages':[{ 'file':p.name,'sha256':digest(p),'bytes':p.stat().st_size} for p in paths]},indent=2)+'\n')
    print('\n'.join(str(p.relative_to(ROOT)) for p in paths))
if __name__=='__main__':main()
