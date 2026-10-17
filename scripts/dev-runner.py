#!/usr/bin/env python3
"""Optional Work Mode runner: keep services and tests in one loopback namespace.

This is a development-only process. The job queue is private (0700) and local.
Normal Linux users can run setup/start/wp scripts directly without this runner.
"""
import json
import os
from pathlib import Path
import subprocess
import sys
import time

root = Path(__file__).resolve().parent.parent
state = Path(os.environ.get("SHIKI_DEV_STATE", str(Path.home() / ".local/state/shikipress-dev"))).resolve()
state.mkdir(mode=0o700, parents=True, exist_ok=True)
queue = state / "jobs"
queue.mkdir(mode=0o700, exist_ok=True)
env = dict(os.environ, SHIKI_DEV_STATE=str(state))
for command in ("setup-dev.sh", "start-dev.sh"):
    result = subprocess.run(["bash", str(root / "scripts" / command)], cwd=root, env=env)
    if result.returncode:
        sys.exit(result.returncode)
(state / "runner.ready").write_text(str(os.getpid()))
print("Runner ready; submit scripts/dev-job.sh <command> [args]", flush=True)
processed = set()
try:
    while not (state / "runner.stop").exists():
        for path in sorted(queue.glob("*.job.json")):
            if path.name in processed:
                path.unlink(missing_ok=True)
                continue
            processed.add(path.name)
            request = json.loads(path.read_text())
            running = path.with_suffix(".running")
            path.rename(running)
            try:
                result = subprocess.run(request["argv"], cwd=request.get("cwd", str(root)), env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=request.get("timeout", 900))
                data = {"returncode": result.returncode, "output": result.stdout}
            except Exception as exc:
                data = {"returncode": 1, "output": repr(exc)}
            destination = queue / path.name.replace(".job.json", ".result.json")
            destination.write_text(json.dumps(data, ensure_ascii=False))
            running.unlink()
        time.sleep(0.1)
finally:
    subprocess.run(["bash", str(root / "scripts/stop-dev.sh")], cwd=root, env=env)
    (state / "runner.ready").unlink(missing_ok=True)
