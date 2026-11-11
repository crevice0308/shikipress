# Isolated save-hook tests

```sh
php tests/save-hooks/unit.php
```

Runs the actual `inc/core.php`, `inc/automation.php` and `inc/seo.php` with synthetic WordPress stubs. It checks that:

- `save_post` fired with fewer or loosely typed arguments (as some plugins do) never raises an error in a theme handler, and an ID of `0` never resolves to the global post;
- only core's explicit `$update === false` marks a new theme draft;
- only a change a visitor can reach advances the public cache epoch: saving a published post or its terms and public metadata, a post entering or leaving publication, and deleting a published post or an attachment;
- saves of drafts, pending, scheduled and private posts (including the block editor's autosave of the author's own draft and the auto-draft of the new-post screen), revisions and oEmbed cache entries do not, and neither do their terms or metadata;
- a call that cannot name its post keeps the full invalidation.

No WordPress installation, database or HTTP request is used. The real-WordPress counterparts are in `tests/automation-integration.php`, `tests/core-integration.php`, `tests/lifecycle-integration.php` and `tests/regression-integration.php` (see `tests/theme-suites/`).
