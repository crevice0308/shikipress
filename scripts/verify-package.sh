#!/usr/bin/env bash
# Run inside the same service namespace as dev-runner. Uses only its dedicated clean DB.
set -euo pipefail
source "$(dirname "$0")/dev-common.sh"
source_version="$(sed -nE 's/^Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p' "$SHIKI_PROJECT_ROOT/shikipress/style.css")"
[[ "$source_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { printf 'Invalid source release version.\n' >&2; exit 1; }
package_path="${1:-$SHIKI_PROJECT_ROOT/dist/shikipress-$source_version.zip}"
package_path="$(realpath "$package_path")"
release_version="$(python3 - "$package_path" <<'PY'
import re, stat, sys, zipfile
with zipfile.ZipFile(sys.argv[1]) as z:
 names=z.namelist()
 assert names and len(names)==len(set(names)), 'ZIP must contain unique files'
 assert all(n.startswith('shikipress/') and '\\' not in n and not any(p in ('', '.', '..') for p in n.rstrip('/').split('/')) for n in names), 'ZIP must have exactly one safe shikipress root'
 assert all(stat.S_IFMT(i.external_attr >> 16) in (0,stat.S_IFREG,stat.S_IFDIR) for i in z.infolist()), 'ZIP must not contain links or special files'
 assert all('/.env' not in n and not n.endswith(('.sql','.pem','.key')) for n in names), 'Forbidden private artifact'
 assert 'shikipress/style.css' in names and 'shikipress/index.php' in names and 'shikipress/functions.php' in names
 version=re.search(r'^Version:\s*(\S+)\s*$',z.read('shikipress/style.css').decode(),re.M)
 assert version and re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',version[1]), 'Invalid archive release version'
 bootstrap=re.search(r"define\(\s*['\"]SHIKI_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",z.read('shikipress/functions.php').decode())
 assert bootstrap and bootstrap[1]==version[1], 'Archive header and PHP release versions differ'
 print(version[1])
PY
)"
export SHIKI_PACKAGE_EXPECTED_VERSION="$release_version" SHIKI_PROJECT_ROOT
printf 'PASS installable ZIP root, safe entries and matching release %s.\n' "$release_version"
primary_state="$SHIKI_DEV_STATE"
clean_state="${SHIKI_CLEAN_STATE:-$(dirname "$SHIKI_DEV_STATE")/clean-state}"
mkdir -p "$clean_state/downloads"
if [[ ! -f "$clean_state/.shikipress-owned-verification" ]]; then
    if shiki_db -N -e 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME="shikipress_clean"' | rg -q .; then printf 'Unowned shikipress_clean DB exists; refusing to reset.\n' >&2; exit 1; fi
    printf 'Dedicated ShikiPress disposable package verification\n' >"$clean_state/.shikipress-owned-verification"
fi
# Reset only this explicitly owned, fixed-name throwaway database.
shiki_db -e 'DROP DATABASE IF EXISTS shikipress_clean; CREATE DATABASE shikipress_clean CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
if [[ ! -f "$clean_state/wp-cli.phar" ]]; then cp "$primary_state/wp-cli.phar" "$clean_state/wp-cli.phar"; fi
if [[ ! -f "$clean_state/wordpress/wp-load.php" ]]; then tar -xzf "$primary_state/downloads/wordpress.tar.gz" -C "$clean_state"; fi
if [[ -L "$clean_state/wordpress/wp-content/themes/shikipress" ]]; then printf 'Clean theme path must not be a symlink.\n' >&2; exit 1; fi
# Each complete verification starts from actual fresh ZIP files even on
# filesystems where an upgrader's force-overwrite does not replace every file.
if [[ -d "$clean_state/wordpress/wp-content/themes/shikipress" ]]; then
    retired_theme="$(mktemp -d "$clean_state/previous-verification-XXXXXX")"
    mv "$clean_state/wordpress/wp-content/themes/shikipress" "$retired_theme/shikipress"
fi
rm -f "$clean_state/wordpress/wp-config.php"
export SHIKI_DEV_STATE="$clean_state" SHIKI_DB_NAME=shikipress_clean SHIKI_DEV_PORT=8081 SHIKI_SKIP_THEME=1
source "$SHIKI_PROJECT_ROOT/scripts/dev-common.sh"
bash "$SHIKI_PROJECT_ROOT/scripts/setup-dev.sh"
shiki_wp theme install "$package_path" --force --activate
python3 - "$package_path" "$SHIKI_WP_ROOT/wp-content/themes" "$SHIKI_PROJECT_ROOT/tests/results/package-fresh-file-integrity.json" <<'PY'
import hashlib,json,pathlib,sys,zipfile
archive,parent,destination=sys.argv[1:]
report={'zip_sha256':hashlib.sha256(pathlib.Path(archive).read_bytes()).hexdigest(),'mismatches':[]}
with zipfile.ZipFile(archive) as z:
 files=[n for n in z.namelist() if not n.endswith('/')]
 actual={str(p.relative_to(parent)) for p in (pathlib.Path(parent)/'shikipress').rglob('*') if p.is_file() or p.is_symlink()}
 report['files']=len(files)
 report['unexpected_files']=sorted(actual-set(files))
 for name in files:
  installed=pathlib.Path(parent)/name
  if not installed.is_file() or installed.is_symlink() or installed.read_bytes()!=z.read(name):report['mismatches'].append(name)
report['pass']=not report['mismatches'] and not report['unexpected_files']
pathlib.Path(destination).write_text(json.dumps(report,indent=2))
assert report['pass'], 'Fresh installed files differ from release ZIP'
print(f'PASS all {report["files"]} fresh installed files match release ZIP with no leftovers')
PY
bash "$SHIKI_PROJECT_ROOT/scripts/start-dev.sh"
shiki_wp eval-file "$SHIKI_PROJECT_ROOT/tests/package-integration.php" baseline
shiki_wp eval 'shiki_setup_categories();'
bash "$SHIKI_PROJECT_ROOT/scripts/fixtures.sh" 20
shiki_wp eval-file "$SHIKI_PROJECT_ROOT/tests/package-integration.php" snapshot
shiki_wp theme activate twentytwentyfive
shiki_wp theme activate shikipress
shiki_wp eval-file "$SHIKI_PROJECT_ROOT/tests/package-integration.php" reactivation
shiki_wp theme install "$package_path" --force --activate
shiki_wp eval-file "$SHIKI_PROJECT_ROOT/tests/package-integration.php" update
SHIKI_DEV_URL=http://127.0.0.1:8081 node "$SHIKI_PROJECT_ROOT/tests/package-browser.mjs"
SHIKI_CLEAN_STATE="$clean_state" bash "$SHIKI_PROJECT_ROOT/scripts/package-rollback.sh" "$package_path"
if [[ -n "${2:-}" ]]; then
    export SHIKI_PROJECT_ROOT
    bash "$SHIKI_PROJECT_ROOT/scripts/package-version-upgrade.sh" "$2" "$package_path"
fi
printf 'PASS clean delivery ZIP installed, reactivated, overwritten from ZIP, and rendered.\n'
