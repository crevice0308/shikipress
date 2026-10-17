#!/usr/bin/env python3
"""Disposable byte-level ZIP fixtures; never execute archive contents."""
import pathlib, struct, sys, zipfile
out = pathlib.Path(sys.argv[1]); out.mkdir(parents=True, exist_ok=True)
style = '''/*
Theme Name: ShikiPress Ultimate
Version: 2.0.0
Requires at least: 6.6
Requires PHP: 8.2
Text Domain: shikipress
Update URI: https://github.com/crevice0308/shikipress
*/
'''
files = {'style.css': style, 'functions.php': "<?php\ndefine( 'SHIKI_VERSION', '2.0.0' );\n", 'index.php': '<?php // fixture', 'theme.json':'{}', 'inc/core.php':'<?php // fixture', 'inc/updater.php':'<?php // fixture'}
def create(name, changes=None, extra=None, method=zipfile.ZIP_DEFLATED):
    content = files.copy(); content.update(changes or {})
    with zipfile.ZipFile(out / (name+'.zip'), 'w', compression=method) as z:
        for key, value in content.items():
            if value is not None: z.writestr('shikipress/'+key, value)
        for key, value in (extra or {}).items(): z.writestr(key, value)
create('valid'); create('valid-stored', method=zipfile.ZIP_STORED)
create('traversal', extra={'shikipress/../escape.php':'x'})
create('absolute', extra={'/shikipress/escape.php':'x'})
create('backslash', extra={'shikipress/inc\\escape.php':'x'})
create('extra-root', extra={'other/escape.php':'x'})
create('hidden-path', extra={'shikipress/.hidden/file.php':'x'})
create('trailing-dot', extra={'shikipress/example.':'x'})
create('device-name', extra={'shikipress/NUL.txt':'x'})
create('case-collision', extra={'shikipress/STYLE.CSS':'x'})
create('missing-core', changes={'inc/core.php': None})
create('case-core', changes={'inc/core.php': None, 'Inc/Core.php':'<?php // fixture'})
create('bad-version', changes={'style.css':style.replace('2.0.0','2.0.1')})
create('bad-update-uri', changes={'style.css':style.replace('crevice0308','other')})
create('bad-requires', changes={'style.css':style.replace('6.6','6.7')})
create('bad-php', changes={'style.css':style.replace('8.2','8.3')})
create('child-template', changes={'style.css':style.replace('*/','Template: another\n*/')})
create('wrong-constant', changes={'functions.php':"<?php\ndefine('SHIKI_VERSION','1.0.0');"})
create('file-as-parent', extra={'shikipress/inc':'x'})
create('huge-entry', extra={'shikipress/big.txt': b'x' * (10485760+1)})
create('too-many', extra={f'shikipress/files/{i}.txt':'x' for i in range(1501)})
create('symlink')
with zipfile.ZipFile(out/'symlink.zip','a') as z:
    entry=zipfile.ZipInfo('shikipress/link'); entry.create_system=3; entry.external_attr=0o120777 << 16
    z.writestr(entry,'../../other')
create('special-file')
with zipfile.ZipFile(out/'special-file.zip','a') as z:
    entry=zipfile.ZipInfo('shikipress/pipe'); entry.create_system=3; entry.external_attr=0o010644 << 16
    z.writestr(entry,'')
create('local-name-mismatch')
b=bytearray((out/'local-name-mismatch.zip').read_bytes()); b[30]=ord('X'); (out/'local-name-mismatch.zip').write_bytes(b)
create('hidden-expansion', extra={'shikipress/bomb.txt':b'x'*1000000})
b=bytearray((out/'hidden-expansion.zip').read_bytes()); name=b'shikipress/bomb.txt'; pos=b.index(name)-30
struct.pack_into('<I',b,pos+22,1)
pos=b.index(name,b.index(b'PK\x01\x02'))-46; struct.pack_into('<I',b,pos+24,1)
(out/'hidden-expansion.zip').write_bytes(b)
create('corrupt-crc'); b=bytearray((out/'corrupt-crc.zip').read_bytes()); pos=b.index(b'PK\x01\x02'); struct.pack_into('<I',b,14,1);struct.pack_into('<I',b,pos+16,1);(out/'corrupt-crc.zip').write_bytes(b)
create('duplicate')
with zipfile.ZipFile(out/'duplicate.zip','a') as z: z.writestr('shikipress/style.css',style)
(out/'not-zip.zip').write_bytes(b'bad archive')
def change_flags(name, flags, method=zipfile.ZIP_DEFLATED):
    create(name, method=method)
    b=bytearray((out/(name+'.zip')).read_bytes()); pos=0
    while (pos:=b.find(b'PK\x01\x02', pos)) != -1:
        local=struct.unpack_from('<I',b,pos+42)[0]
        struct.pack_into('<H',b,pos+8,flags);struct.pack_into('<H',b,local+6,flags);pos+=46
    (out/(name+'.zip')).write_bytes(b)
change_flags('valid-deflate-fast',4)
change_flags('valid-deflate-max',2)
change_flags('invalid-store-option',2,zipfile.ZIP_STORED)
change_flags('encrypted',1)
change_flags('patched-data',32)
change_flags('strong-encryption',64)
for name, field in [('unicode-extra',0x7075),('zip64-extra',1)]:
    create(name)
    with zipfile.ZipFile(out/(name+'.zip'),'a') as z:
        entry=zipfile.ZipInfo('shikipress/extra.txt');entry.extra=struct.pack('<HH',field,1)+b'\0';z.writestr(entry,'x')
print(f'Created {len(list(out.glob("*.zip")))} ZIP fixtures')
