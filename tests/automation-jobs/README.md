# Translation job ownership regression

These fixtures never contact DeepL and use inert synthetic credentials and titles.

## Isolated PHP cases

```sh
for scenario in $(php tests/automation-jobs/unit.php --list); do
  php tests/automation-jobs/unit.php "$scenario"
done
```

The same 27 cases run in the PHP 8.2–8.5 syntax matrix. They model atomic metadata comparisons and request-local cache interleavings, including normal success/error/backoff, stale success/error/retry results, same-title replacement tokens, replacement before claim, bounded lock deferrals, recovery, a lock another worker creates between this worker's read and its own insert (`absent-lock-race`), duplicate workers, exception cleanup, and stale status polling. The stub database accepts only the theme's prepared insert-only lock statement; `add_option()` is modelled with core's check-then-UPSERT behaviour, which the theme must not use for locks. Each case starts a fresh PHP request so the existing per-request HTTP budget is exercised normally.

Optional local WASM runner, using the existing `tests/wordpress-layout` dependencies:

```sh
node tests/automation-jobs/run-unit.mjs
# An optional source path demonstrates the regression against the old automation.php.
node tests/automation-jobs/run-unit.mjs /tmp/original-automation.php
```

## Native WordPress metadata and Cron

`bash tests/automation-jobs/run-ci.sh` runs after the updater fixture against the existing owned disposable MySQL retention site. It refuses other databases/sites, performs 13 separately bootstrapped cases, and rechecks the original content/media/settings retention snapshot afterward. No real credential may be configured.

`integration.php` verifies actual `update_post_meta(..., $previous)` behavior, cache invalidation, and core single-event scheduling. The job lock is created by an insert-only query that fires no option hook, so the `replace-before-claim` case places the replacement at the worker's claim (the start of its metadata update) and checks that the worker holds its lock at that moment. HTTP is mocked and mail blocked; the synthetic post, jobs, lock, and temporary settings are cleaned up. Status cases replace only the fixture's database rows directly to model another request while deliberately retaining stale request-local post/meta caches. The fixture is also executable in a marked local WordPress Playground for SQLite-backed checks; SQLite results do not substitute for the CI MySQL run.
