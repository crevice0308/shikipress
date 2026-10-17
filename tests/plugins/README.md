# Official-plugin smoke checks

This phase belongs immediately after `tests/upgrade/run-ci.sh assert-php` in the existing content-retention job. It uses that exact native PHP process family, local MySQL database, installed theme, WordPress version, media files, and disposable state. It does not create or accept a production target.

## Pinned official packages

`plugins.tsv` is the version/main-file manifest:

- Site Kit by Google 1.189.0
- Contact Form 7 6.2.2
- Yoast SEO 28.7
- WP Super Cache 3.1.4
- TablePress 3.4.1
- Wordfence 9.0.2
- UpdraftPlus 1.26.11

WP-CLI installs versioned packages from WordPress.org without activating them and verifies each against WordPress.org's version-specific checksums with `--strict`. It also asserts each installed plugin header version during every phase. A missing package, checksum mismatch, or unavailable checksum fails instead of silently substituting another release. WP-CLI 2.8+ is required for the checksum `--version` option: https://developer.wordpress.org/cli/commands/plugin/verify-checksums/ .

## Run

The existing workflow provisions MySQL, WP-CLI, PHP/extensions, and the disposable upgrade fixtures. After its PHP-upgrade assertion:

```sh
bash tests/plugins/run-ci.sh
```

The runner requires `CI=true`, `RUNNER_TEMP`, the original owned-state marker text, a state directory below `RUNNER_TEMP`, DB host `127.0.0.1`, a generated `shikipress_ci_<digits>_<digits>` database, native `mysqli`, ShikiPress active, and the fixture URL `http://127.0.0.1:9450`. A reused plugin-report directory is refused. Restart the whole disposable job to rerun.

## What is asserted

1. Each plugin activates alone, completes a fresh bootstrap, retains representative settings and synthetic data after deactivation, and reactivates. The exact active-plugin list is checked in every phase.
2. The seven plugins activate together as one non-competing stack: one SEO, cache, form, table, Google-integration, security, and backup plugin. Final deactivation leaves no active plugins.
3. The original upgrade regression rechecks its articles, postmeta, taxonomy/relationships, permalinks, comments, menus, widgets, media checksums, custom CSS, and theme/core settings after every individual activation/deactivation and the combined/final phases. These original fixtures and expected snapshots are not rewritten.
4. Selected real plugin settings survive cycles: Site Kit's admin-bar toggle; CF7 form content/properties; Yoast's post title/description templates; Super Cache's configured lifetime; TablePress custom CSS/table content; Wordfence's keep-tables setting; and UpdraftPlus's retained-backup count. These are representative fields, not an exhaustive settings audit.
5. Actual HTTP requests to the native PHP development server assert 200 responses, ShikiPress header/footer, exactly one call to `wp_head`, `wp_body_open`, and `wp_footer`, one title/canonical/Open Graph URL, and no duplicated description metadata. Every redirect is restricted to the exact loopback origin.
6. CF7 produces its real form/email input from a stored form shortcode, and TablePress produces real table markup containing both synthetic rows. Combined mode renders both again.
7. Yoast takes metadata ownership. The native HTTP checks exercise a real reader-sitemap second page and tracking-query variant, checking one canonical, one `og:url`, and one WebPage schema URL/ID that agree. Ordinary article URLs ignore the sitemap-only query. Enough separate posts are seeded for a real second slice at the existing page-size setting; the retained theme settings remain unchanged.
8. Native PHP, WordPress, theme, MySQL, and all plugin header versions are written into phase JSON. The runner fails for PHP fatals/parse errors or ShikiPress warnings/deprecations in the debug log; other plugin diagnostics are retained for review.

## Safety and scope

Packages and checksums are fetched before the safety MU plugin is installed, with normal plugins/themes skipped. After that, the MU plugin short-circuits every WordPress HTTP API request, including loopbacks and update calls, and intercepts `wp_mail` before any transport. Every assertion phase probes both filters. Cron and automatic updates remain disabled. The MU stays installed even if a phase fails. Nothing signs into Google, accepts OAuth grants, enables live ads, delivers mail, starts a security scan, runs a backup/restore, or configures remote storage. Super Cache is active with caching disabled; its real configuration lifetime is exercised, not cache effectiveness.

The MU blocks WordPress API transports; it is not an operating-system firewall or a sandbox for arbitrary malicious PHP/native socket code. Only the pinned, checksum-verified official plugin packages run. The HTML client fetches local server HTML and follows only loopback redirects; it does not load external browser resources or execute JavaScript.

This is a scoped activation/retention/server-render smoke test. It does not establish Google account/service integration, CF7 AJAX/validation/delivery, TablePress client-side sorting/filtering/layout, production caching/CDN behavior, firewall/security effectiveness, backup/restore integrity, remote uploads, or compatibility with every plugin setting/extension. Existing browser/manual coverage must be reported separately.

## Evidence

Versioned phase reports, checksum logs, server/debug logs, HTTP assertion JSON, and rendered synthetic HTML are saved below `$RUNNER_TEMP/shikipress-retention-ci/reports/plugins`. The workflow uploads that folder even on failure. No credentials, real user content, or OAuth tokens are used in these fixtures.
