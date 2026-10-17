#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/dev-common.sh"
python3 - "$SHIKI_DEV_STATE" "$SHIKI_PROJECT_ROOT" "$@" <<'PY'
import json, pathlib, sys, time, uuid
state, root = map(pathlib.Path, sys.argv[1:3])
if not (state/'runner.ready').exists():
    raise SystemExit('No runner. Start scripts/dev-runner.py in a persistent session first.')
argv = sys.argv[3:]
if not argv: raise SystemExit('Usage: dev-job.sh <command> [args]')
queue = state/'jobs'
token = uuid.uuid4().hex
temporary = queue/(token+'.tmp')
job = queue/(token+'.job.json')
result = queue/(token+'.result.json')
temporary.write_text(json.dumps({'argv':argv,'cwd':str(root),'timeout':900}))
temporary.rename(job)
for _ in range(10000):
    if result.exists():
        data=json.loads(result.read_text())
        print(data['output'], end='')
        result.unlink()
        raise SystemExit(data['returncode'])
    time.sleep(.1)
raise SystemExit('Job timeout; inspect private queue.')
PY
