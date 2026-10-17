#!/usr/bin/env python3
"""Build a deterministic installable theme ZIP, excluding development material.
Modified 2026-10-17 for the public source distribution (GPL-3.0-or-later).
"""
from pathlib import Path
import hashlib
import json
import re
import sys
import zipfile

root = Path(__file__).resolve().parent.parent
theme = root / 'shikipress'
out = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else root / 'dist'
version = re.search(r'^Version:\s*(\S+)', (theme / 'style.css').read_text(), re.M).group(1)
if not re.fullmatch(r'\d+\.\d+\.\d+', version):
    raise SystemExit('Invalid theme version')
if f"define( 'SHIKI_VERSION', '{version}' );" not in (theme / 'functions.php').read_text():
    raise SystemExit('Theme header/bootstrap versions disagree')
allowed = {'.php', '.css', '.js', '.json', '.svg', '.png'}
entries = []
for file in sorted(theme.rglob('*')):
    relative = file.relative_to(root)
    if file.is_symlink():
        raise SystemExit(f'Symlink refused: {relative}')
    if not file.is_file():
        continue
    if any(part.startswith('.') for part in relative.parts) or (file.suffix not in allowed and file.name != 'LICENSE'):
        raise SystemExit(f'Unexpected theme file: {relative}')
    if file.suffix in {'.php', '.js', '.css', '.json'} and re.search(r"define\s*\(\s*['\"]SHIKIPRESS_DEEPL_KEY['\"]\s*,", file.read_text()):
        raise SystemExit('Embedded key definition refused')
    entries.append((file, relative.as_posix()))
for required in ['style.css', 'index.php', 'functions.php', 'theme.json', 'LICENSE']:
    if not (theme / required).is_file():
        raise SystemExit(f'Missing {required}')
out.mkdir(parents=True, exist_ok=True)
target = out / f'shikipress-{version}.zip'
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for file, name in entries:
        info = zipfile.ZipInfo(name, date_time=(2026, 10, 17, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, file.read_bytes())
with zipfile.ZipFile(target) as archive:
    if archive.testzip():
        raise SystemExit('ZIP CRC failure')
checksum = hashlib.sha256(target.read_bytes()).hexdigest()
(out / 'SHA256SUMS.txt').write_text(f'{checksum}  {target.name}\n')
print(json.dumps({'file': str(target), 'files': len(entries), 'sha256': checksum}, indent=2))
