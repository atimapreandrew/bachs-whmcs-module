#!/usr/bin/env python3
"""Check local documentation links and scan public source for private material."""

from pathlib import Path
import re
import sys
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[1]
PUBLIC_FOLDERS = ('src', 'modules', 'includes', 'resources', 'tests', 'build', 'docs', '.github')


def main():
    errors = []
    documents = sorted(ROOT.glob('*.md')) + sorted((ROOT / 'docs').glob('*.md'))
    for path in documents:
        for link in re.findall(r'\[[^\]]*\]\(([^)]+)\)', path.read_text()):
            link = link.strip('<>')
            parsed = urlsplit(link)
            if parsed.scheme or not parsed.path:
                continue
            target = (path.parent / unquote(parsed.path)).resolve()
            if not target.is_relative_to(ROOT) or not target.exists():
                errors.append(f'{path.relative_to(ROOT)}: missing or outside-repository link: {link}')

    paths = [path for path in ROOT.iterdir() if path.is_file()]
    paths += [path for folder in PUBLIC_FOLDERS for path in (ROOT / folder).rglob('*') if path.is_file() and '__pycache__' not in path.parts]
    for path in paths:
        if path.suffix in ('.bundle', '.zip', '.pyc'):
            continue
        body = path.read_bytes()
        if re.search(rb'sk_(?:live|sandbox)_[A-Za-z0-9_-]{16,}|whsec_[A-Za-z0-9_-]{16,}|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----', body):
            errors.append(f'{path.relative_to(ROOT)}: possible secret; inspect privately')
        if path.name.startswith('.env') or path.suffix in ('.sqlite', '.db', '.pem', '.key'):
            errors.append(f'{path.relative_to(ROOT)}: private file in public source')

    if errors:
        print('\n'.join(errors))
        return 1
    print(f'{len(documents)} documentation files checked; local links and public-source secret scan passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
