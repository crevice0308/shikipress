#!/usr/bin/env python3
"""Build/inspect a theme ZIP, optionally prove an installed copy matches it exactly."""
import argparse, hashlib, json, pathlib, re, stat, zipfile
p=argparse.ArgumentParser();p.add_argument('path');p.add_argument('value',nargs='?');p.add_argument('--build',action='store_true');p.add_argument('--installed');a=p.parse_args()
if a.build:
 root=pathlib.Path(a.path).resolve();archive=pathlib.Path(a.value)
 assert root.name=='shikipress' and (root/'style.css').is_file()
 with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
  for f in sorted(root.rglob('*')):
   assert not f.is_symlink(),f
   if f.is_file():z.write(f,'shikipress/'+str(f.relative_to(root)))
else:archive=pathlib.Path(a.path)
with zipfile.ZipFile(archive) as z:
 names=z.namelist();assert names and len(names)==len(set(names))
 for entry in z.infolist():
  parts=entry.filename.rstrip('/').split('/')
  assert parts[0]=='shikipress' and all(x not in ('','.','..') for x in parts)
  assert '\\' not in entry.filename and stat.S_IFMT(entry.external_attr>>16) in (0,stat.S_IFREG,stat.S_IFDIR)
  assert not any(x.startswith('.') for x in parts)
  assert not entry.filename.endswith(('.sql','.pem','.key','.log'))
 css=z.read('shikipress/style.css').decode();php=z.read('shikipress/functions.php').decode()
 version=re.search(r'^Version:\s*(\S+)',css,re.M)[1]
 assert re.fullmatch(r'\d+\.\d+\.\d+',version)
 assert re.search(r"define\(\s*['\"]SHIKI_VERSION['\"]\s*,\s*['\"]([^'\"]+)",php)[1]==version
 if not a.build and a.value:assert version==a.value
 files=[n for n in names if not n.endswith('/')]
 if a.installed:
  parent=pathlib.Path(a.installed);actual={str(f.relative_to(parent)) for f in (parent/'shikipress').rglob('*') if f.is_file() or f.is_symlink()}
  assert actual==set(files),{'unexpected':sorted(actual-set(files)),'missing':sorted(set(files)-actual)}
  for n in files:assert not (parent/n).is_symlink() and (parent/n).read_bytes()==z.read(n),n
print(json.dumps({'archive':str(archive),'version':version,'files':len(files),'sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),'pass':True}))
