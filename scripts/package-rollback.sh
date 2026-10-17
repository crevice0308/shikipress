#!/usr/bin/env bash
# Rehearse actual file rollback only in the disposable clean verification installation.
set -euo pipefail
source "$(dirname "$0")/dev-common.sh"
source_version="$(sed -nE 's/^Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$/\1/p' "$SHIKI_PROJECT_ROOT/shikipress/style.css")"
[[ "$source_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || exit 1
package_path="$(realpath "${1:-$SHIKI_PROJECT_ROOT/dist/shikipress-$source_version.zip}")"
release_version="$(python3 - "$package_path" <<'PY'
import re, stat, sys, zipfile
with zipfile.ZipFile(sys.argv[1]) as z:
 names=z.namelist()
 assert names and len(names)==len(set(names))
 assert all(n.startswith('shikipress/') and '\\' not in n and not any(p in ('', '.', '..') for p in n.rstrip('/').split('/')) for n in names)
 assert all(stat.S_IFMT(i.external_attr >> 16) in (0,stat.S_IFREG,stat.S_IFDIR) for i in z.infolist())
 header=re.search(r'^Version:\s*(\S+)\s*$',z.read('shikipress/style.css').decode(),re.M)
 assert header and re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',header[1])
 bootstrap=re.search(r"define\(\s*['\"]SHIKI_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",z.read('shikipress/functions.php').decode())
 assert bootstrap and bootstrap[1]==header[1]
 print(header[1])
PY
)"
export SHIKI_PACKAGE_EXPECTED_VERSION="$release_version" SHIKI_PACKAGE_SIMULATED_VERSION="$release_version-test-simulated"
clean_state="${SHIKI_CLEAN_STATE:-$(dirname "$SHIKI_DEV_STATE")/clean-state}"
[[ -f "$clean_state/.shikipress-owned-verification" ]] || { printf 'Missing dedicated clean verification marker.\n' >&2; exit 1; }
export SHIKI_DEV_STATE="$clean_state" SHIKI_DEV_PORT=8081 SHIKI_DB_NAME=shikipress_clean
source "$SHIKI_PROJECT_ROOT/scripts/dev-common.sh"
shiki_wp eval 'if (!defined("SHIKI_TEST_ENV") || !SHIKI_TEST_ENV || DB_NAME !== "shikipress_clean" || get_stylesheet() !== "shikipress" || is_link(get_stylesheet_directory()) || SHIKI_VERSION !== getenv("SHIKI_PACKAGE_EXPECTED_VERSION")) { WP_CLI::error("Not the disposable clean installed release."); } $p=get_stylesheet_directory()."/style.css"; $s=file_get_contents($p); $s=preg_replace("/^Version:\\s*.+$/m", "Version: ".getenv("SHIKI_PACKAGE_SIMULATED_VERSION"), $s, 1); file_put_contents($p,$s."\n/* SHIKI_SIMULATED_DEFECT: disposable rollback rehearsal only. */\n"); wp_clean_themes_cache();'
shiki_wp eval-file "$SHIKI_PROJECT_ROOT/tests/package-integration.php" simulated-update
# Preserve the defective files outside the web root, then perform a fresh ZIP install.
# This avoids depending on an upgrader claiming success while a filesystem keeps old files.
shiki_wp theme activate twentytwentyfive
rollback_directory="$(mktemp -d "$clean_state/rollback-XXXXXX")"
mv "$SHIKI_WP_ROOT/wp-content/themes/shikipress" "$rollback_directory/shikipress"
[[ ! -e "$SHIKI_WP_ROOT/wp-content/themes/shikipress" ]] || { printf 'Theme was not moved out of the install destination.\n' >&2; exit 1; }
shiki_wp theme install "$package_path" --activate
shiki_wp eval-file "$SHIKI_PROJECT_ROOT/tests/package-integration.php" rollback
python3 - "$package_path" "$SHIKI_WP_ROOT/wp-content/themes" "$SHIKI_PROJECT_ROOT/tests/results/package-file-integrity.json" <<'PY'
import hashlib, json, pathlib, sys, zipfile
archive, parent, destination = sys.argv[1:]
mismatches=[]; count=0
with zipfile.ZipFile(archive) as z:
 for name in z.namelist():
  if name.endswith('/'): continue
  count += 1
  file=pathlib.Path(parent)/name
  if not file.is_file() or hashlib.sha256(file.read_bytes()).digest()!=hashlib.sha256(z.read(name)).digest(): mismatches.append(name)
report={'zip_sha256':hashlib.sha256(pathlib.Path(archive).read_bytes()).hexdigest(),'files':count,'mismatches':mismatches,'pass':not mismatches}
pathlib.Path(destination).write_text(json.dumps(report,indent=2))
if mismatches: raise SystemExit('Installed package file bytes differ from delivery ZIP')
print(f'PASS all {count} installed files match delivery ZIP byte-for-byte')
PY
SHIKI_DEV_URL=http://127.0.0.1:8081 node "$SHIKI_PROJECT_ROOT/tests/package-browser.mjs"
printf 'PASS actual file rollback rehearsal from simulated defective header to delivered release ZIP. No prior released version was claimed.\n'
