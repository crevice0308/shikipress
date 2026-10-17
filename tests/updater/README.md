# Native theme updater regression

Run these only in disposable test environments. The public theme updater is single-site only, requires the canonical `shikipress` parent folder, and is loaded by that active theme or an active child.

## Focused checks

```sh
python3 tests/updater/fixtures.py /tmp/shiki-updater-fixtures
php tests/updater/unit.php /tmp/shiki-updater-fixtures
python3 tests/updater/publisher-tests.py
```

`unit.php` uses explicit WordPress/HTTP stubs and byte-level ZIP fixtures. It exercises release identity, stable versions, metadata/asset integrity, cache/failure behavior, redirect restrictions, package paths/headers/expansion, hook isolation, and inactive/multisite restrictions. These focused tests are not a substitute for native WordPress installation tests. `run-unit.mjs` is an optional local PHP-WASM runner using the existing `tests/wordpress-layout` runtime dependencies.

`publisher-tests.py` extracts the bounded inline publisher for isolated tests with fake API functions. Its output may say “Published and verified” for synthetic test objects; these tests make no GitHub writes and are not evidence of an actual release.

## Actual WordPress/MySQL lifecycle

After `tests/upgrade/run-ci.sh assert-php` and before the official plugin smoke phase:

```sh
bash tests/updater/run-ci.sh
```

The runner requires the owned disposable retention site, local generated MySQL database, and CI temporary directory. It installs only its own safety MU guard, blocks external HTTP and mail, and mocks the fixed official GitHub release URLs with local synthetic metadata/ZIPs. No future-version fixture is published to GitHub.

The real 1.0.4 Git commit is packaged and replaced by current source through standard WordPress ZIP installation, modeling the initial bootstrap. The new provider then offers a synthetic newer version through `wp_update_themes`, and actual `Theme_Upgrader` single/bulk operations install it. Fresh bootstraps check the result; standard ZIP installation restores the exact current source afterward. The existing retention assertions remain unchanged. Child-theme files and unrelated update settings must remain intact. Rejected packages must leave the installed files unchanged.

The safety MU guard is removed only after a successful run and an exact ownership/hash check, so the following plugin phase can fetch official packages. Failures keep the guard in place. Evidence remains under the disposable retention reports/updater directory and is uploaded by CI. This fixture is not intended for production, remote databases, or existing user content.
