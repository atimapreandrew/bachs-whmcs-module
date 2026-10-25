#!/usr/bin/env python3
"""Lint runtime code and enforce targeted source/artifact safety checks without dependencies."""
from pathlib import Path
import re, subprocess, sys
ROOT=Path(__file__).resolve().parents[1]
paths=sorted(p for folder in ('modules','includes') for p in (ROOT/folder).rglob('*.php'))
errors=[]
for p in paths:
    r=subprocess.run(['php','-l',str(p)],capture_output=True,text=True)
    if r.returncode:errors.append(r.stdout+r.stderr)
    s=p.read_text()
    for pattern in (r'\b(eval|unserialize|shell_exec|passthru|proc_open|popen)\s*\(',r'CURLOPT_SSL_VERIFYPEER\s*=>\s*false',r'CURLOPT_FOLLOWLOCATION\s*=>\s*true',r'(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+tbl(?:invoices|accounts|hosting)\b'):
        if re.search(pattern,s,re.I):errors.append(f'{p.relative_to(ROOT)}: prohibited runtime pattern {pattern}')
    if re.search(r'\b(?:TODO|FIXME|IMPLEMENT_ME)\b',s):errors.append(f'{p}: unfinished runtime marker')
for path in ('bachs.js','admin.js'):
    r=subprocess.run(['node','--check',str(ROOT/'modules/gateways/bachs/assets'/path)],capture_output=True,text=True)
    if r.returncode:errors.append(r.stderr)
if errors:print('\n'.join(errors));sys.exit(1)
print(f'{len(paths)} runtime PHP files linted; JavaScript syntax and targeted security checks passed.')
