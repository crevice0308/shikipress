# Theme integration suites in CI

`run-ci.sh` installs a new disposable WordPress with a uniquely named local MySQL database, installs the built theme ZIP, seeds the development fixtures (`scripts/fixtures.php 20`) and runs the theme's real-WordPress suites:

`core`, `frontend`, `seo`, `ads`, `public-scope`, `automation`, `tools`, `lifecycle`, `media` (`tests/*-integration.php`).

The workflow job `theme-suites` in `.github/workflows/compatibility.yml` runs it on the declared minimum (WordPress 6.6 / PHP 8.2) and on the current WordPress release with the newest tested PHP. It uses the same loopback origin and reading settings as `scripts/setup-dev.sh`.

Safety:

- Refuses to run unless `CI=true`, `RUNNER_TEMP` is set and the state directory does not exist yet. It never reuses a site.
- External HTTP is blocked (`WP_HTTP_BLOCK_EXTERNAL` plus a must-use guard). Only the synthetic responses the suites register themselves are returned; mail is never sent.
- The suites create and remove their own posts, terms, users and options. No retention comparison follows, so this job is separate from the content-retention job.
- The run fails on any PHP fatal error, or any warning or deprecation raised from the theme directory.

A green run covers only the versions printed in that run and the fixtures the suites create. It is not a browser test and does not contact real advertising, consent, translation or update services.
