# Disposable upgrade and retention tests

These tests are for a throwaway WordPress installation only. They do not connect to a production site or use real articles, email addresses, media, credentials, or advertising accounts.

## GitHub Actions integration

The workflow is `.github/workflows/compatibility.yml`; its portable test scripts are in this directory.

`baseline-commit.txt` records the full, verified Git commit SHA containing the untouched supplied 1.0.2 theme as the immutable baseline. That commit must contain `shikipress/style.css` and `shikipress/functions.php`. The workflow fetches full history, builds the old package with `git archive`, and verifies both version markers are 1.0.2. Do not replace the baseline with an invented version-header edit.

The workflow has only `contents: read`. It runs on main pushes, pull requests, and manual dispatch. It does not publish releases, upload artifacts, open pull requests, contact users, or schedule recurring jobs. Execution logs contain only generated synthetic fixtures and runtime versions. GitHub-hosted runners are required; do not run this on a machine hosting a real WordPress site.

## Checks

- Native `php -l` on PHP 8.2, 8.3, 8.4, and 8.5.
- Theme ZIP entry safety, matching PHP/header versions, and exact byte-for-byte installed-file comparison.
- MySQL 8.0-backed WordPress installation at the declared minimum version, 6.6.
- Actual theme deactivation/reactivation.
- Same-version reinstall through `wp theme install ... --force --activate`.
- Genuine previous Git baseline to current source release upgrade through the same standard WordPress upgrader. A failure does not silently fall back to filesystem extraction.
- WordPress 6.6 to pinned 7.1.3 and separately the current `latest` selector, followed by `wp core update-db` and official checksums.
- PHP 8.2 to 8.5 and 8.3 to 8.4 while retaining exactly the same database and uploads directory.
- Twenty published article records; draft/private/scheduled/trashed/protected content; revisions; page, attachment, navigation item and custom CSS records; metadata; category and tag identities; relationships; URLs; media bytes and metadata; comments; theme options; logo, menus, active widgets, sticky status and permalink settings.

Private permalinks are compared as the same administrator in every phase. WordPress's timestamped sidebar backup and the inactive-widget holding area are excluded from strict equality; actual sidebar placements and widget content are still required to match. Core's lazy custom-CSS lookup is populated before the baseline. This avoids reporting normal core bookkeeping as data loss.

## Interpreting results

A green test establishes only the versions, fixtures and operations printed in that run. It is not a guarantee for future WordPress/PHP versions, third-party plugins, production hosting, arbitrary article markup, or external ad scripts. Keep tested versions current and perform a staging check and restorable database/uploads backup before production upgrades.

The separate local Playground test uses official WordPress PHP-WASM packages and SQLite. Its results must be labeled separately from native PHP/MySQL CI.
