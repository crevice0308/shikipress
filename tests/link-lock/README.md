# Link-lock ownership regression

`unit.php` provides isolated PHP cases. `run-ci.sh` uses the existing owned disposable MySQL retention site, following the same guards as the translation integration tests. It runs actual WordPress option operations in separate, simultaneously live processes, then rechecks the original retention snapshot. Python uses only the standard library and invokes commands without a shell.

The integration suite covers:

- Ordinary acquire, contention, foreign release, owner release, expired-lock reclaim, and successor release.
- Stale reclaim: A reads a genuinely expired option and pauses. B reclaims it and remains alive with a fresh lock. A resumes and must not delete B's lock or become a second owner.
- Absent acquisition: A pauses immediately before its actual option INSERT SQL. B inserts and holds a fresh lock. A resumes its unchanged SQL and must not overwrite B or become a second owner. This catches WordPress add_option UPSERT behavior as well as testing insert-only acquisition.
- Stale owner release: A reads its own expired option and pauses. B reclaims it and remains alive. A resumes release and must leave B's lock intact.

A WordPress read filter, or the query filter immediately before INSERT, only pauses A at a valid scheduling point. The query filter returns SQL unchanged. It never supplies invented option data or mutates the database. B uses the actual theme function; A resumes in its original process with its original request-local cache. A final process records ordering, ownership, and cleanup. Pauses are bounded to 20 seconds, subprocess waits are bounded, and cleanup can release only tokens created by this fixture. Network and mail are blocked inside every fixture phase, and the test refuses an unmarked or non-local site. It does not execute external link requests, induce OOM, or pressure system resources.

## CI

Run after `tests/upgrade/run-ci.sh` created the owned disposable site:

```sh
bash tests/link-lock/run-ci.sh
```

`RUNNER_TEMP`, `CI=true`, and the optional `SHIKI_CI_STATE` follow the retention fixture contract. Reports and barrier observations are saved under the owned state's `reports/link-lock`. The runner refuses an existing report directory.

## Optional native CLI / SQLite evidence

`run-integration.py` normally invokes installed WP-CLI. For a separately prepared, explicitly marked local site, `--native-driver /absolute/bootstrap.php --php /absolute/php` instead invokes that exact binary. The bootstrap must load that site's WordPress, set `$args` to the fixture's phase/scenario/report-directory arguments, and include the supplied fixture. `--opcache-cli` requests CLI OPcache when available. Every fixture phase reports its measured `opcache_enabled` status; the summary records whether OPcache was enabled in all fixture processes. These runs must be labeled native CLI / SQLite evidence, not MySQL or full WP-CLI evidence.

The original vulnerable source is a negative control: stale reclaim, stale release, and absent acquisition preservation checks must fail, even though ordinary lifecycle checks pass. Do not change the expected checks to make old code pass. No theme version is hard-coded into the assertions.
