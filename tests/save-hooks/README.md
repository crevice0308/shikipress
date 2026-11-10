# Isolated save-hook tests

```sh
php tests/save-hooks/unit.php
```

Runs the actual `inc/core.php`, `inc/automation.php` and `inc/seo.php` with synthetic WordPress stubs. It checks that:

- `save_post` fired with fewer or loosely typed arguments (as some plugins do) never raises an error in a theme handler, and an ID of `0` never resolves to the global post;
- only core's explicit `$update === false` marks a new theme draft;
- revision and autosave saves do not advance the public cache epoch, while saving the post itself still does.

No WordPress installation, database or HTTP request is used. The real-WordPress counterparts are in `tests/automation-integration.php`, `tests/core-integration.php` and `tests/lifecycle-integration.php` (see `tests/theme-suites/`).
