#!/usr/bin/env python3
"""Offline tests of the exact, data-only publisher embedded in release.yml.

No GitHub requests, repository writes, uploaded assets, or release mutations occur.
"""
import copy
import hashlib
import io
import json
from pathlib import Path
import subprocess
import textwrap
import tempfile
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / '.github/workflows/release.yml'
TEXT = WORKFLOW.read_text()
CODE = textwrap.dedent(TEXT.split("python3 - <<'PYTHON'\n", 1)[1].rsplit('          PYTHON', 1)[0])
PUBLISHER = {'__name__': 'offline_release_publisher'}
exec(compile(CODE, str(WORKFLOW), 'exec'), PUBLISHER)


def fixture(version='1.1.1', header=None):
    if header is None:
        header = (f'/*\nTheme Name: ShikiPress Ultimate\nVersion: {version}\n'
                  'Requires at least: 6.6\nRequires PHP: 8.2\nText Domain: shikipress\n*/\n')
    return {
        'shikipress/style.css': header.encode(),
        'shikipress/functions.php': f"<?php\ndefine( 'SHIKI_VERSION', '{version}' );\n".encode(),
        'shikipress/index.php': b'<?php // fixture\n',
        'shikipress/theme.json': b'{"version":3}\n',
        'shikipress/LICENSE': b'GPL-3.0-or-later\n',
    }


def reference_zip(files):
    output = io.BytesIO()
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, data in sorted(files.items()):
            info = zipfile.ZipInfo(name, date_time=(2026, 10, 17, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data)
    return output.getvalue()


def specification(files, version='1.1.1', updater=True):
    spec = {'version': version, 'commit': None, 'prerelease': False,
            'sha256': hashlib.sha256(reference_zip(files)).hexdigest(), 'files': len(files)}
    if updater:
        spec['update'] = {'requires': '6.6', 'requires_php': '8.2', 'tested': '7.1.3'}
    return spec


def release_state(spec, source, assets, draft=False):
    return {'id': 100, 'tag_name': f'v{spec["version"]}', 'target_commitish': source,
            'body': PUBLISHER['marker'](spec, source), 'draft': draft,
            'prerelease': spec['prerelease'], 'immutable': False,
            'html_url': f'https://github.com/crevice0308/shikipress/releases/tag/v{spec["version"]}',
            'assets': [{'id': number, 'name': name, 'state': 'uploaded', 'size': len(data)}
                       for number, (name, data) in enumerate(assets.items(), 1)]}


class PublisherTests(unittest.TestCase):
    def setUp(self):
        self.files = fixture()
        self.spec = specification(self.files)
        self.assets = PUBLISHER['package'](self.files, self.spec)
        self.source = 'a' * 40

    def test_embedded_python_and_permissions(self):
        compile(CODE, str(WORKFLOW), 'exec')
        subprocess.run(['bash', '-n'], input=("set -euo pipefail\npython3 - <<'PYTHON'\n"
                        + CODE + 'PYTHON\n').encode(), check=True)
        self.assertIn('permissions: {}', TEXT)
        self.assertIn('    permissions:\n      contents: write\n      actions: read\n', TEXT)
        self.assertEqual(PUBLISHER['WORKFLOWS'], {
            'compatibility.yml': 'WordPress and PHP compatibility',
            'browser-regression.yml': 'Offline browser regression',
        })
        self.assertIn("current_spec(files)", CODE)
        self.assertNotIn("for spec in RELEASES", CODE)

    def test_metadata_comes_from_the_exact_installable_zip(self):
        data = self.assets['shikipress-1.1.1.zip']
        metadata = json.loads(self.assets['shikipress-update.json'])
        self.assertEqual(metadata, {
            'schema': 1, 'theme': 'shikipress', 'version': '1.1.1',
            'requires': '6.6', 'requires_php': '8.2', 'tested': '7.1.3',
            'package': 'https://github.com/crevice0308/shikipress/releases/download/v1.1.1/shikipress-1.1.1.zip',
            'sha256': hashlib.sha256(data).hexdigest(), 'size': len(data),
        })
        self.assertEqual(data, reference_zip(self.files))
        self.assertEqual(self.assets['SHA256SUMS.txt'],
                         f'{metadata["sha256"]}  shikipress-1.1.1.zip\n'.encode())
        self.assertEqual(self.assets, PUBLISHER['package'](self.files, self.spec))
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            self.assertEqual(archive.read('shikipress/style.css'), self.files['shikipress/style.css'])
        self.assertNotIn('shikipress/shikipress-update.json', self.files)

    def test_reviewed_configuration_and_historical_pins(self):
        specs = PUBLISHER['RELEASES']
        self.assertEqual([(s['version'], s['commit'], s['sha256'], s['files'], s['prerelease'])
                          for s in specs[:3]], [
            ('1.0.2', '9544985a466e3d8b3d8e5953d261a6aadf038696',
             '0fc2e436a7907ba482d59e3934b60f0cc354667286b1089a3d2dc26d11717704', 44, True),
            ('1.0.3', 'a671266dc0e16b71daaf88e7f32e833842da27fb',
             'e83da81eb6330a27be5ffce9c9809d19afc455b5745152c77965ca017b0d23b7', 44, False),
            ('1.0.4', '39cefcf2adf81110e6f0a068ee75bec36f48cdf3',
             'fbe53ab06290577c72afc17b9dc3da76fb2fe9cb693e35ba88238f78e88d1386', 44, False),
        ])
        self.assertTrue(all('update' not in spec for spec in specs[:3]))
        self.assertEqual(specs[3], {
            'version': '1.1.0', 'commit': 'd35fc242b585251e698ee4097a9d0303e2f42b13', 'prerelease': False,
            'sha256': 'a300419c39f6bf132a3b2bb6b74b6b57f7577668586bc62eb2a9c5a38b7584b9', 'files': 45,
            'update': {'requires': '6.6', 'requires_php': '8.2', 'tested': '7.1.3'},
        })
        self.assertEqual(specs[-1]['version'], '1.1.1')
        self.assertEqual(specs[-1]['commit'], '5391872439380cd8e9c73bfd60d9f015fd303436')
        self.assertFalse(specs[-1]['prerelease'])
        self.assertEqual(specs[-1]['update'], {'requires': '6.6', 'requires_php': '8.2', 'tested': '7.1.3'})
        self.assertIn("target_wp: '7.1.3'", (ROOT / '.github/workflows/compatibility.yml').read_text())

    def test_malformed_or_conflicting_headers_fail_closed(self):
        header = self.files['shikipress/style.css'].decode()
        changes = [
            ('Version: 1.1.1', 'Version: 1.1.1-rc.1'),
            ('Version: 1.1.1', 'Version: 1.1'),
            ('Version: 1.1.1', 'Version: 01.1.1'),
            ('Version: 1.1.1', 'Version: 1.1.1 trailing'),
            ('Version: 1.1.1', 'Version: 1.2.0'),
            ('Version: 1.1.1', 'Version: 1.1.1\nVersion: 9.9.9'),
            ('Version: 1.1.1', ''),
            ('Requires at least: 6.6', 'Requires at least: 6.7'),
            ('Requires at least: 6.6', 'Requires at least: 6.6\nRequires at least: 6.6'),
            ('Requires at least: 6.6', 'Requires at least: 6.6-rc1'),
            ('Requires PHP: 8.2', ''),
            ('Requires PHP: 8.2', 'Requires PHP: 8.3'),
            ('Requires PHP: 8.2', 'Requires PHP: 8.2 arbitrary'),
            ('/*', '/*\n' + 'x' * 8192),
            ('/*', '/*\n' + '日' * 2800),
            ('/*', ''),
        ]
        for old, new in changes:
            with self.subTest(replacement=new[:60]):
                files = fixture(header=header.replace(old, new))
                spec = specification(files)
                with self.assertRaises(RuntimeError):
                    PUBLISHER['update_metadata'](reference_zip(files), spec)
                with self.assertRaises(RuntimeError):
                    PUBLISHER['package'](files, spec)

    def test_prerelease_metadata_refused(self):
        spec = dict(self.spec, prerelease=True)
        with self.assertRaisesRegex(RuntimeError, 'only for stable'):
            PUBLISHER['package'](self.files, spec)

    def test_unreviewed_tested_target_refused(self):
        spec = copy.deepcopy(self.spec)
        spec['update']['tested'] = 'latest'
        with self.assertRaisesRegex(RuntimeError, 'reviewed WordPress target'):
            PUBLISHER['package'](self.files, spec)

    def test_zip_checksum_and_pending_pins_fail_closed(self):
        with self.assertRaisesRegex(RuntimeError, 'Updater ZIP checksum mismatch'):
            PUBLISHER['update_metadata'](self.assets['shikipress-1.1.1.zip'] + b'changed', self.spec)
        with self.assertRaisesRegex(RuntimeError, 'Pinned package checksum mismatch'):
            PUBLISHER['package'](self.files, dict(self.spec, sha256='0' * 64))
        with self.assertRaisesRegex(RuntimeError, 'Reviewed package pins are incomplete'):
            PUBLISHER['package'](self.files, dict(self.spec, sha256='PENDING_REVIEWED_PACKAGE_SHA256', files=None))

    def test_historical_releases_keep_exactly_two_assets(self):
        for version in ('1.0.2', '1.0.3', '1.0.4'):
            with self.subTest(version=version):
                files = fixture(version)
                spec = specification(files, version, updater=False)
                assets = PUBLISHER['package'](files, spec)
                self.assertEqual(set(assets), {f'shikipress-{version}.zip', 'SHA256SUMS.txt'})
                release = release_state(spec, self.source, assets)
                api = mock.Mock(side_effect=lambda path, **kwargs:
                                assets[next(item['name'] for item in release['assets']
                                            if path == f'releases/assets/{item["id"]}')])
                with mock.patch.dict(PUBLISHER, api=api):
                    PUBLISHER['verify_release'](release, spec, self.source, assets, True)
                    api.reset_mock()
                    PUBLISHER['publish']({'spec': spec, 'source': self.source,
                                          'tag': f'v{version}', 'release': release, 'assets': assets},
                                         self.source, [])
                    api.assert_not_called()
                release['assets'].append({'id': 999, 'name': 'shikipress-update.json',
                                          'state': 'uploaded', 'size': 1})
                with self.assertRaisesRegex(RuntimeError, 'Unexpected existing release assets'):
                    PUBLISHER['verify_assets'](release, assets, True)

    def test_historical_updater_release_keeps_three_assets_without_mutation(self):
        files = fixture('1.1.0')
        spec = specification(files, '1.1.0')
        spec['commit'] = PUBLISHER['RELEASES'][3]['commit']
        assets = PUBLISHER['package'](files, spec)
        self.assertEqual(set(assets), {'shikipress-1.1.0.zip', 'SHA256SUMS.txt', 'shikipress-update.json'})
        self.assertEqual(json.loads(assets['shikipress-update.json'])['version'], '1.1.0')
        release = release_state(spec, spec['commit'], assets)
        original = copy.deepcopy(release)
        api = mock.Mock(side_effect=lambda path, **kwargs:
                        assets[next(item['name'] for item in release['assets']
                                    if path == f'releases/assets/{item["id"]}')])
        with mock.patch.dict(PUBLISHER, api=api):
            PUBLISHER['verify_release'](release, spec, spec['commit'], assets, True)
            api.reset_mock()
            PUBLISHER['publish']({'spec': spec, 'source': spec['commit'], 'tag': 'v1.1.0',
                                  'release': release, 'assets': assets}, self.source, [])
            api.assert_not_called()
        self.assertEqual(release, original)

    def test_prepare_existing_historical_source_stays_pinned(self):
        for configured in PUBLISHER['RELEASES'][:-1]:
            with self.subTest(version=configured['version']):
                spec = dict(configured)
                assets = {'historical.zip': b'original zip', 'SHA256SUMS.txt': b'original checksum'}
                release = release_state(spec, spec['commit'], assets)
                ancestor = mock.Mock()
                theme_at = mock.Mock(return_value={'historical': b'original'})
                package = mock.Mock(return_value=assets)
                with mock.patch.dict(PUBLISHER, find_release=lambda tag: release,
                                     tag_commit=lambda tag: spec['commit'], ancestor=ancestor,
                                     theme_at=theme_at, package=package,
                                     verify_release=mock.Mock()):
                    prepared = PUBLISHER['prepare'](spec, self.source)
                self.assertEqual(prepared['source'], spec['commit'])
                self.assertIs(prepared['release'], release)
                theme_at.assert_called_once_with(spec['commit'])
                ancestor.assert_called_once_with(spec['commit'], self.source)

    def test_published_new_release_reuses_tagged_source(self):
        release = release_state(self.spec, 'b' * 40, self.assets)
        theme_at = mock.Mock(return_value=self.files)
        with mock.patch.dict(PUBLISHER, find_release=lambda tag: release, tag_commit=lambda tag: 'b' * 40,
                             ancestor=mock.Mock(), theme_at=theme_at, verify_release=mock.Mock()):
            prepared = PUBLISHER['prepare'](self.spec, self.source)
        self.assertEqual(prepared['source'], 'b' * 40)
        theme_at.assert_called_once_with('b' * 40)

    def run_draft_publication(self, corrupted=None, initial_names=()):
        initial = {name: self.assets[name] for name in initial_names}
        release = release_state(self.spec, self.source, initial, draft=True)
        remote = dict(initial)
        seen_verified = set()
        writes = []
        uploads = []
        evidence = [{'id': 1, 'attempt': 1, 'url': 'https://github.com/verified-ci'}]

        def api(path, method='GET', data=None, binary=False, **kwargs):
            if path.startswith('releases/assets/'):
                self.assertEqual(method, 'GET')
                self.assertTrue(binary)
                asset_id = int(path.rsplit('/', 1)[1])
                name = next(item['name'] for item in release['assets'] if item['id'] == asset_id)
                seen_verified.add(name)
                return remote[name]
            self.assertEqual(path, 'releases/100')
            if method == 'PATCH':
                self.assertEqual(set(remote), set(self.assets))
                self.assertEqual(seen_verified, set(self.assets))
                self.assertEqual(remote, self.assets)
                self.assertEqual(data, {'draft': False, 'prerelease': False, 'make_latest': 'true'})
                writes.append(copy.deepcopy(data))
                release['draft'] = False
            else:
                self.assertEqual(method, 'GET')
            return copy.deepcopy(release)

        def upload(command, check, timeout):
            self.assertEqual(command[:4], ['gh', 'release', 'upload', 'v1.1.1'])
            self.assertEqual(command[5:], ['--repo', 'crevice0308/shikipress'])
            name = Path(command[4]).name
            self.assertNotIn(name, remote)
            raw = Path(command[4]).read_bytes()
            remote[name] = (b'X' + raw[1:]) if name == corrupted else raw
            release['assets'].append({'id': len(release['assets']) + 1, 'name': name,
                                      'state': 'uploaded', 'size': len(remote[name])})
            uploads.append(name)

        item = {'spec': self.spec, 'source': self.source, 'tag': 'v1.1.1',
                'release': copy.deepcopy(release), 'assets': self.assets}
        with mock.patch.dict(PUBLISHER, api=api, current_main=mock.Mock(),
                             ci_gate=lambda sha: evidence, stable_gate=mock.Mock(), find_release=lambda tag: copy.deepcopy(release),
                             tag_commit=lambda tag: self.source), mock.patch.object(PUBLISHER['subprocess'], 'run', upload):
            if corrupted:
                with self.assertRaisesRegex(RuntimeError, 'Existing release asset differs'):
                    PUBLISHER['publish'](item, self.source, evidence)
            else:
                PUBLISHER['publish'](item, self.source, evidence)
        return writes, uploads, release

    def test_publish_after_all_three_assets_are_byte_verified(self):
        writes, uploads, release = self.run_draft_publication()
        self.assertEqual(set(uploads), set(self.assets))
        self.assertEqual(len(writes), 1)
        self.assertFalse(release['draft'])

    def test_resume_only_missing_assets_without_overwriting(self):
        writes, uploads, release = self.run_draft_publication(initial_names=('shikipress-1.1.1.zip',))
        self.assertEqual(set(uploads), {'SHA256SUMS.txt', 'shikipress-update.json'})
        self.assertEqual(len(writes), 1)

    def test_corrupt_uploaded_asset_cannot_publish(self):
        for name in self.assets:
            with self.subTest(asset=name):
                writes, uploads, release = self.run_draft_publication(corrupted=name)
                self.assertEqual(writes, [])
                self.assertTrue(release['draft'])

    def test_missing_new_metadata_cannot_be_complete(self):
        release = release_state(self.spec, self.source,
                                {k: v for k, v in self.assets.items() if k != 'shikipress-update.json'})
        with self.assertRaisesRegex(RuntimeError, 'missing expected assets'):
            PUBLISHER['verify_assets'](release, self.assets, True)

    def test_any_new_stable_version_is_derived_without_allowlist_edits(self):
        for version in ('1.1.2', '1.2.0', '2.0.0'):
            with self.subTest(version=version):
                files = fixture(version)
                spec = PUBLISHER['current_spec'](files)
                self.assertEqual(spec, specification(files, version))
                assets = PUBLISHER['package'](files, spec)
                self.assertEqual(json.loads(assets['shikipress-update.json'])['version'], version)
        files = {f'shikipress/{p.relative_to(ROOT / "shikipress").as_posix()}': p.read_bytes()
                 for p in (ROOT / 'shikipress').rglob('*') if p.is_file()}
        spec = PUBLISHER['current_spec'](files)
        self.assertEqual(spec['version'], PUBLISHER['re'].search(rb'^Version:[ \t]*(\S+)', files['shikipress/style.css'], PUBLISHER['re'].M)[1].decode())
        self.assertEqual(spec['sha256'], hashlib.sha256(reference_zip(files)).hexdigest())

    def test_automatic_release_rejects_nonstable_and_changed_requirements(self):
        for version in ('1.2.0-rc.1', '1.2', '01.2.0', '1.2.0+build'):
            with self.subTest(version=version), self.assertRaises(RuntimeError):
                PUBLISHER['current_spec'](fixture(version))
        files = fixture('1.1.2')
        files['shikipress/style.css'] = files['shikipress/style.css'].replace(b'Requires PHP: 8.2', b'Requires PHP: 8.3')
        with self.assertRaisesRegex(RuntimeError, 'release-policy review'):
            PUBLISHER['current_spec'](files)

    def test_stable_gate_blocks_downgrades_and_invalid_latest(self):
        for tag in ('v1.1.0', 'v1.1.1'):
            with mock.patch.dict(PUBLISHER, api=lambda path: {'tag_name':tag, 'draft':False, 'prerelease':False}):
                self.assertEqual(PUBLISHER['stable_gate'](self.spec), tag)
        for release in ({'tag_name':'v1.1.2'}, {'tag_name':'v1.1.1-rc.1'},
                        {'tag_name':'v1.1.1', 'draft':True}, {'tag_name':'v1.1.1', 'prerelease':True}):
            with self.subTest(release=release), mock.patch.dict(PUBLISHER, api=lambda path: release), self.assertRaises(RuntimeError):
                PUBLISHER['stable_gate'](self.spec)

    def test_same_version_changed_bytes_cannot_republish(self):
        original = release_state(self.spec, 'b' * 40, self.assets)
        changed = dict(self.files, **{'shikipress/index.php': b'<?php // changed theme bytes'})
        spec = PUBLISHER['current_spec'](changed)
        with mock.patch.dict(PUBLISHER, find_release=lambda tag: original, tag_commit=lambda tag: 'b' * 40,
                             ancestor=mock.Mock(), theme_at=lambda sha: self.files):
            with self.assertRaisesRegex(RuntimeError, 'Pinned package checksum mismatch'):
                PUBLISHER['prepare'](spec, self.source)

    def test_documentation_only_same_version_is_read_only(self):
        original = release_state(self.spec, 'b' * 40, self.assets)
        reads = mock.Mock(side_effect=lambda path, **kwargs:
                          self.assets[next(a['name'] for a in original['assets'] if path == f'releases/assets/{a["id"]}')])
        with mock.patch.dict(PUBLISHER, find_release=lambda tag: original, tag_commit=lambda tag: 'b' * 40,
                             ancestor=mock.Mock(), theme_at=lambda sha: self.files, api=reads):
            prepared = PUBLISHER['prepare'](PUBLISHER['current_spec'](self.files), self.source)
            reads.reset_mock()
            PUBLISHER['publish'](prepared, self.source, [])
            reads.assert_not_called()
        self.assertEqual(original['assets'], release_state(self.spec, 'b' * 40, self.assets)['assets'])

    def test_main_publishes_only_current_version_after_exact_ci(self):
        files = fixture('1.1.2')
        spec = PUBLISHER['current_spec'](files)
        evidence = [{'id':1, 'attempt':2, 'url':'https://github.com/ci'}]
        prepared = {'spec':spec}
        publish = mock.Mock()
        prepare = mock.Mock(return_value=prepared)
        with tempfile.NamedTemporaryFile(mode='w') as event:
            event.write('{}'); event.flush()
            env = {'GITHUB_REPOSITORY':'crevice0308/shikipress', 'GITHUB_REF':'refs/heads/main',
                   'GITHUB_SHA':self.source, 'GITHUB_EVENT_PATH':event.name, 'GITHUB_EVENT_NAME':'workflow_dispatch'}
            with mock.patch.dict(PUBLISHER['os'].environ, env), mock.patch.dict(PUBLISHER,
                    current_main=mock.Mock(), ci_gate=lambda sha:evidence, theme_at=lambda sha:files,
                    stable_gate=mock.Mock(), prepare=prepare, publish=publish,
                    api=lambda path: {'tag_name':'v1.1.2', 'draft':False, 'prerelease':False}):
                PUBLISHER['main']()
        prepare.assert_called_once_with(spec, self.source)
        publish.assert_called_once_with(prepared, self.source, evidence)

    def test_stale_event_and_unfinished_ci_cannot_prepare_or_publish(self):
        for kind in ('stale', 'unfinished'):
            payload = {'workflow_run': {'head_sha':'b' * 40}} if kind == 'stale' else {}
            with tempfile.NamedTemporaryFile(mode='w') as event:
                json.dump(payload, event); event.flush()
                env = {'GITHUB_REPOSITORY':'crevice0308/shikipress', 'GITHUB_REF':'refs/heads/main',
                       'GITHUB_SHA':self.source, 'GITHUB_EVENT_PATH':event.name,
                       'GITHUB_EVENT_NAME':'workflow_run' if kind == 'stale' else 'workflow_dispatch'}
                prepare, publish, theme_at = mock.Mock(), mock.Mock(), mock.Mock()
                with mock.patch.dict(PUBLISHER['os'].environ, env), mock.patch.dict(PUBLISHER,
                        current_main=mock.Mock(), ci_gate=lambda sha:None, theme_at=theme_at,
                        prepare=prepare, publish=publish):
                    PUBLISHER['main']()
                for call in (prepare, publish, theme_at): call.assert_not_called()

    def test_ci_requires_exact_main_push_and_current_successful_run(self):
        valid = {'head_sha': self.source, 'head_branch': 'main', 'event': 'push',
                 'head_repository': {'full_name': 'crevice0308/shikipress'},
                 'repository': {'full_name': 'crevice0308/shikipress'}}
        self.assertTrue(PUBLISHER['trusted_run'](valid, self.source))
        for changes in ({'head_sha': 'b' * 40}, {'head_branch': 'topic'}, {'event': 'pull_request'},
                        {'head_repository': {'full_name': 'untrusted/fork'}},
                        {'repository': {'full_name': 'untrusted/fork'}}):
            self.assertFalse(PUBLISHER['trusted_run']({**valid, **changes}, self.source))
        old = {**valid, 'id': 1, 'run_number': 1}
        new = {**valid, 'id': 2, 'run_number': 2, 'path': '.github/workflows/compatibility.yml',
               'name': 'WordPress and PHP compatibility', 'status': 'completed', 'conclusion': 'failure'}
        with mock.patch.dict(PUBLISHER, api=mock.Mock(side_effect=[
                {'total_count': 2, 'workflow_runs': [old, new]}, new])):
            self.assertIsNone(PUBLISHER['ci_gate'](self.source))


if __name__ == '__main__':
    unittest.main(verbosity=2)
