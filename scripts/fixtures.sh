#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/dev-common.sh"
shiki_wp eval-file "$SHIKI_PROJECT_ROOT/scripts/fixtures.php" "${1:-20}"
