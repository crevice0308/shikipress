#!/usr/bin/env python3
"""Bounded real-process WordPress link-lock regression. No external requests."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--wp-path', required=True)
parser.add_argument('--reports', required=True)
parser.add_argument('--fixture', default=str(Path(__file__).with_name('integration.php')))
parser.add_argument('--native-driver', help='Optional evidence-only bootstrap instead of installed WP-CLI.')
parser.add_argument('--php', help='Exact native PHP binary for --native-driver.')
parser.add_argument('--opcache-cli', action='store_true')
args = parser.parse_args()
state = Path(os.environ.get('SHIKI_CI_STATE', '')).resolve()
wp_path = Path(args.wp_path).resolve()
reports = Path(args.reports).resolve()
fixture = Path(args.fixture).resolve()
if os.environ.get('SHIKI_LINK_LOCK_FIXTURE') != '1':
    parser.error('SHIKI_LINK_LOCK_FIXTURE=1 is required.')
if (not (state / '.owned-disposable-ci').is_file()
        or (state / '.owned-disposable-ci').read_text().strip() != 'Disposable ShikiPress regression database; never production'
        or wp_path != state / 'wordpress' or reports != state / 'reports/link-lock'
        or not fixture.is_file() or reports.exists()):
    parser.error('Requires an owned disposable site and a new report directory.')
if bool(args.native_driver) != bool(args.php):
    parser.error('--native-driver and --php must be supplied together.')
if args.native_driver:
    driver = Path(args.native_driver).resolve()
    php = Path(args.php).resolve()
    if not driver.is_file() or not php.is_file():
        parser.error('Exact native binary and driver must exist.')
    prefix = [str(php), '-n', '-d', 'memory_limit=256M', '-d', 'max_execution_time=30',
              '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
              '-d', 'opcache.enable_cli=' + ('1' if args.opcache_cli else '0'),
              '-d', 'disable_functions=mail,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,proc_open,popen',
              str(driver), str(fixture)]
else:
    prefix = ['wp', '--path=' + str(wp_path), '--allow-root', '--context=cli', 'eval-file', str(fixture)]
reports.mkdir(parents=True)
observations = []
errors = []

def command(phase, scenario, directory):
    return prefix + [phase, scenario, str(directory)]

def save_output(phase, scenario, stdout, stderr, returncode):
    stem = reports / (scenario + '-' + phase)
    stem.with_suffix('.json').write_text(stdout)
    stem.with_suffix('.stderr').write_text(stderr)
    if returncode:
        raise RuntimeError(f'{scenario}/{phase} exited {returncode}: {stderr[-1000:]} {stdout[-1000:]}')
    try:
        value = json.loads(stdout)
    except ValueError as error:
        raise RuntimeError(f'{scenario}/{phase} did not produce one JSON report') from error
    observations.append(value)
    return value

def run(phase, scenario, directory):
    result = subprocess.run(command(phase, scenario, directory), text=True, capture_output=True, timeout=35)
    return save_output(phase, scenario, result.stdout, result.stderr, result.returncode)

def await_marker(path, process):
    deadline = time.monotonic() + 25
    while not path.is_file():
        if process.poll() is not None:
            stdout, stderr = process.communicate()
            raise RuntimeError(f'Child exited before {path.name}: {stderr[-1000:]} {stdout[-1000:]}')
        if time.monotonic() > deadline:
            raise RuntimeError('Timed out waiting for ' + path.name)
        time.sleep(0.05)

for scenario in ('lifecycle', 'stale-reclaim', 'owner-release', 'absent-acquire'):
    directory = reports / scenario
    directory.mkdir()
    (directory / '.fixture-run').write_text('ShikiPress link-lock integration fixture\n')
    a = b = None
    try:
        if scenario == 'lifecycle':
            run('lifecycle', scenario, directory)
        else:
            run('seed', scenario, directory)
            a = subprocess.Popen(command('a', scenario, directory), text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            await_marker(directory / 'a-read.json', a)
            b = subprocess.Popen(command('b', scenario, directory), text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            await_marker(directory / 'b-acquired.json', b)
            if b.poll() is not None:
                raise RuntimeError('B must remain live while A resumes.')
            (directory / 'resume-a').write_text('B has acquired the actual fresh lock and is still alive.\n')
            stdout, stderr = a.communicate(timeout=25)
            save_output('a', scenario, stdout, stderr, a.returncode)
            if b.poll() is not None:
                raise RuntimeError('B exited before A finished.')
            (directory / 'finish-b').write_text('A completed; inspect the committed option and release only your own token.\n')
            stdout, stderr = b.communicate(timeout=25)
            save_output('b', scenario, stdout, stderr, b.returncode)
            run('verify', scenario, directory)
    except Exception as error:
        errors.append(f'{scenario}: {error}')
    finally:
        for process in (a, b):
            if process is not None and process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
        try:
            run('cleanup', scenario, directory)
        except Exception as error:
            errors.append(f'{scenario} cleanup: {error}')
summary = {
    'scenarios': 4,
    'assertions': sum(item.get('assertions', 0) for item in observations),
    'pass': not errors and all(item.get('pass') is True for item in observations),
    'errors': errors,
    'failed_checks': [{'scenario': item['scenario'], 'phase': item['phase'], 'check': key}
                      for item in observations for key, value in item.get('checks', {}).items() if not value],
    'runtime': [{key: item[key] for key in ('php', 'wordpress', 'theme', 'opcache_enabled')} for item in observations[:1]],
    'all_processes_opcache_enabled': bool(observations) and all(item.get('opcache_enabled') is True for item in observations),
    'scope': 'Actual WordPress option operations with two simultaneously live independent processes. Hooks pause only; no fake DB writes or external HTTP. Native-driver runs are distinct from full WP-CLI/MySQL CI.'
}
(reports / 'summary.json').write_text(json.dumps(summary, indent=2) + '\n')
print(json.dumps(summary, indent=2))
sys.exit(0 if summary['pass'] else 1)
