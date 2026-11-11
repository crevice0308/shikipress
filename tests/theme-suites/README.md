# Theme integration suites in CI

`run-ci.sh` installs a new disposable WordPress with a uniquely named local MySQL database, installs the built theme ZIP, seeds the development fixtures (`scripts/fixtures.php 20`) and runs the theme's real-WordPress suites:

`core`, `frontend`, `seo`, `ads`, `public-scope`, `automation`, `tools`, `lifecycle`, `media`, `regression` (`tests/*-integration.php`).

The workflow job `theme-suites` in `.github/workflows/compatibility.yml` runs it on the declared minimum (WordPress 6.6 / PHP 8.2) and on the current WordPress release with the newest tested PHP. It uses the same loopback origin and reading settings as `scripts/setup-dev.sh`.

## Render sweep

After the suites, `render-setup.php` seeds a representative scenario (menus, comments, a featured image, classic and paginated articles, every ad position in local test mode, the optional view counter, a seasonal event, SEO fields) and `check-render.py` requests each URL from PHP's development server on the loopback origin:

- every template: home, paged home, all-stories route, block and classic articles, protected and excluded content, pages, reader sitemap, category/tag/author/date archives, search (including an escaped markup query), 404, attachment and embed;
- feeds, core XML sitemaps, `robots.txt` and the public REST lists;
- as a signed-in administrator: all ShikiPress settings tabs, the post/page/category editors, and the nonce-protected season preview.

Each theme page must return the expected status with one `<title>`, one `<h1>`, the theme header/footer/main, unique `id` values, resolving in-page links, `aria-*` references and label targets, balanced element nesting (no end tag without its start tag, nothing left open when its container closes; end tags HTML lets authors omit are tolerated), and no trace of the hidden fixtures (drafts, private, scheduled, protected or excluded content) outside their own page. Scenario-specific checks cover the table of contents, ad slots, breadcrumbs, noindex rules and escaping. Each result line prints a short outline of the page (title, headings, canonical, robots, description, card and ad counts). This reads server output only; it runs no JavaScript.

For the password-protected article and page, the sweep also submits the page's own form as a reader would, with the fixtures' password: a wrong password must keep the body hidden, the right one must show it, and the page stays `noindex` without ads either way.

Safety:

- Refuses to run unless `CI=true`, `RUNNER_TEMP` is set and the state directory does not exist yet. It never reuses a site.
- External HTTP is blocked (`WP_HTTP_BLOCK_EXTERNAL` plus a must-use guard). Only the synthetic responses the suites register themselves are returned; mail is never sent.
- The suites create and remove their own posts, terms, users and options. No retention comparison follows, so this job is separate from the content-retention job.
- The administrator session used by the render sweep lasts one hour, exists only on the disposable site and its cookie file is deleted before the reports are uploaded.
- The run fails on any PHP fatal error, or any warning or deprecation raised from the theme directory, including during the render sweep.

A green run covers only the versions printed in that run and the fixtures the suites create. It is not a browser test and does not contact real advertising, consent, translation or update services.
