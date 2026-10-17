#!/usr/bin/env bash
# Upgrade between actual release ZIPs, only in the marked disposable verification site.
set -euo pipefail
source "$(dirname "$0")/dev-common.sh"
[[ "$SHIKI_DB_NAME" == shikipress_clean && -f "$SHIKI_DEV_STATE/.shikipress-owned-verification" ]] || exit 1
[[ $# -eq 2 ]] || { printf 'Usage: package-version-upgrade.sh PREVIOUS_THEME_ZIP RELEASE_THEME_ZIP\n' >&2; exit 1; }
previous_zip="$(realpath "$1")"
release_zip="$(realpath "$2")"
versions="$(python3 - "$previous_zip" "$release_zip" <<'PY'
import re,stat,sys,zipfile
versions=[]
for archive in sys.argv[1:]:
 with zipfile.ZipFile(archive) as z:
  names=z.namelist()
  assert names and len(names)==len(set(names)), 'ZIP contains duplicate entries'
  assert all(n.startswith('shikipress/') and '\\' not in n and not any(p in ('', '.', '..') for p in n.rstrip('/').split('/')) for n in names), 'Unsafe ZIP entry'
  assert all(stat.S_IFMT(i.external_attr >> 16) in (0,stat.S_IFREG,stat.S_IFDIR) for i in z.infolist()), 'ZIP contains links or special files'
  header=re.search(r'^Version:\s*(\S+)\s*$',z.read('shikipress/style.css').decode(),re.M)
  assert header and re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',header[1]), 'Invalid release version'
  bootstrap=re.search(r"define\(\s*['\"]SHIKI_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",z.read('shikipress/functions.php').decode())
  assert bootstrap and bootstrap[1]==header[1], 'Archive PHP/header mismatch'
  versions.append(header[1])
assert tuple(map(int,versions[0].split('.'))) < tuple(map(int,versions[1].split('.'))), 'Expected a previous-to-newer release upgrade'
print(' '.join(versions))
PY
)"
read -r previous_version release_version <<<"$versions"
export SHIKI_PACKAGE_EXPECTED_VERSION="$release_version" SHIKI_PACKAGE_PREVIOUS_VERSION="$previous_version" SHIKI_PROJECT_ROOT
shiki_wp eval 'if(!defined("SHIKI_TEST_ENV")||!SHIKI_TEST_ENV||DB_NAME!=="shikipress_clean"||get_stylesheet()!=="shikipress"||is_link(get_stylesheet_directory())){WP_CLI::error("Disposable clean site required");}'
shiki_wp theme activate twentytwentyfive
retired_theme="$(mktemp -d "$SHIKI_DEV_STATE/version-upgrade-XXXXXX")"
mv "$SHIKI_WP_ROOT/wp-content/themes/shikipress" "$retired_theme/release"
shiki_wp theme install "$previous_zip" --activate
SHIKI_PACKAGE_EXPECTED_VERSION="$previous_version" shiki_wp eval-file "$SHIKI_PROJECT_ROOT/tests/package-integration.php" legacy-release
shiki_wp eval 'if(SHIKI_VERSION!==getenv("SHIKI_PACKAGE_PREVIOUS_VERSION")||get_option("shikipress_version")!==getenv("SHIKI_PACKAGE_PREVIOUS_VERSION")){WP_CLI::error("Actual old release required");}update_option("shiki_package_upgrade_epoch",(int)get_option("shikipress_cache_epoch"),false);'
python3 - "$previous_zip" "$SHIKI_WP_ROOT/wp-content/themes" <<'PY'
import hashlib,pathlib,sys,zipfile
with zipfile.ZipFile(sys.argv[1]) as z:
 for name in z.namelist():
  if name.endswith('/'):continue
  installed=pathlib.Path(sys.argv[2])/name
  assert installed.is_file() and not installed.is_symlink() and installed.read_bytes()==z.read(name), f'Previous release file mismatch: {name}'
print('PASS actual previous release installed byte-for-byte from its ZIP')
PY
# Keep the active slug while exchanging the installed ZIP files. This exercises
# the init migration after updating an already-active theme, without relying on
# the filesystem's force-overwrite behavior. No production site uses this path.
mv "$SHIKI_WP_ROOT/wp-content/themes/shikipress" "$retired_theme/legacy"
python3 - "$release_zip" "$SHIKI_WP_ROOT/wp-content/themes" <<'PY'
import sys,zipfile
with zipfile.ZipFile(sys.argv[1]) as z:z.extractall(sys.argv[2])
PY
shiki_wp eval '$expected=getenv("SHIKI_PACKAGE_EXPECTED_VERSION");$r=["actual_php_release"=>SHIKI_VERSION===$expected,"actual_header"=>wp_get_theme()->get("Version")===$expected,"active_zip_version_migrated"=>get_option("shikipress_version")===$expected,"cache_epoch_advanced"=>(int)get_option("shikipress_cache_epoch")>(int)get_option("shiki_package_upgrade_epoch")];$report=["previous_version"=>getenv("SHIKI_PACKAGE_PREVIOUS_VERSION"),"release_version"=>$expected,"previous_cache_epoch"=>(int)get_option("shiki_package_upgrade_epoch"),"release_cache_epoch"=>(int)get_option("shikipress_cache_epoch"),"results"=>$r];file_put_contents(getenv("SHIKI_PROJECT_ROOT")."/tests/results/package-version-migration.json",wp_json_encode($report,JSON_PRETTY_PRINT));echo wp_json_encode($report);if(in_array(false,$r,true)){WP_CLI::error("Version upgrade mismatch");}'
python3 - "$previous_zip" "$release_zip" "$SHIKI_WP_ROOT/wp-content/themes" "$SHIKI_PROJECT_ROOT/tests/results/package-upgrade-file-integrity.json" <<'PY'
import hashlib,json,pathlib,re,sys,zipfile
previous,release,parent,destination=sys.argv[1:]
report={'previous_zip_sha256':hashlib.sha256(pathlib.Path(previous).read_bytes()).hexdigest(),'release_zip_sha256':hashlib.sha256(pathlib.Path(release).read_bytes()).hexdigest(),'mismatches':[]}
with zipfile.ZipFile(release) as z:
 files=[n for n in z.namelist() if not n.endswith('/')]
 expected=set(files)
 actual={str(p.relative_to(parent)) for p in (pathlib.Path(parent)/'shikipress').rglob('*') if p.is_file() or p.is_symlink()}
 report['files']=len(files)
 report['unexpected_files']=sorted(actual-expected)
 for name in files:
  installed=pathlib.Path(parent)/name
  if not installed.is_file() or installed.is_symlink() or installed.read_bytes()!=z.read(name):report['mismatches'].append(name)
report['pass']=not report['mismatches'] and not report['unexpected_files']
pathlib.Path(destination).write_text(json.dumps(report,indent=2))
assert report['pass'], 'Installed upgrade files differ from release ZIP'
print(f'PASS all {report["files"]} upgraded files match release ZIP with no leftovers')
PY
shiki_wp eval-file "$SHIKI_PROJECT_ROOT/tests/package-integration.php" version-upgrade
SHIKI_DEV_URL=http://127.0.0.1:8081 node "$SHIKI_PROJECT_ROOT/tests/package-browser.mjs"
printf 'PASS actual release upgrade %s -> %s, preserved settings/category IDs/20 article URLs and invalidated caches.\n' "$previous_version" "$release_version"
