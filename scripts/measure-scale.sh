#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/dev-common.sh"
trap 'bash "$SHIKI_PROJECT_ROOT/scripts/fixtures.sh" 20' EXIT
for fixture_count in 0 1 5 20 100 500; do
    bash "$SHIKI_PROJECT_ROOT/scripts/fixtures.sh" "$fixture_count"
    shiki_wp eval-file "$SHIKI_PROJECT_ROOT/tests/scale-performance.php" "$fixture_count"
done
