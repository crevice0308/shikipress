# Optional WordPress layout integration

This is a disposable WordPress Playground/WASM + SQLite integration, not a native PHP/MySQL production test. It starts WordPress7.1.3 with PHP8.3, copies the selected theme to an isolated test folder, creates five local posts/one page and synthetic thumbnail metadata, then runs Chromium against home/article/page/search/404. It does not touch an existing site, modify repository theme files, use credentials, or request ad/CMP providers. The local web server and browser start in the same process tree and the server is closed at the end.

Prerequisites: Node24.18+ and npm11.16+ (the pinned Playground engine requirements), network for the official WordPress/PHP runtime downloads, and a Playwright-compatible OS. From this directory run:

```sh
npm ci --ignore-scripts
npx playwright install --with-deps chromium
npm test
```

THEME_DIR may select another theme. SHIKI_BROWSER may select an installed official headless-shell. Use writable HOME/TMPDIR when running in a restricted container. Loopback port9415 must be available. Runtime version metadata, assertions and screenshots are saved under results/ and screenshots/. Synthetic image fixtures and attachment metadata test markup/srcset behavior; they do not validate native GD/Imagick thumbnail generation.

No blanket guarantee of future WordPress/PHP/plugin changes or live provider compatibility is implied. For production, also validate the real PHP/MySQL stack, actual plugin versions, image processing, backups/restore and provider settings.

## Development dependency security regression

Run `npm run test:dependencies` after `npm ci --ignore-scripts`. This uses actual Express/qs query parsing, fast-uri and body-parser with synthetic streams (11 assertions); no server is exposed. It is a parser smoke test, **not** proof of full Playground/WordPress compatibility.

The narrow `express` → `qs: 6.16.2` override replaces Express 4.22.2's vulnerable `~6.15.3` constraint with the patched minor. Remove the override when the upstream constraint permits a non-vulnerable version. The lock also updates body-parser 1.20.8 → 1.20.9 and fast-uri 3.1.8 → 3.1.9, preserving official npm registry URLs and integrity hashes. These are development-only dependencies and are not included in the theme ZIP.

Relevant advisories: [body-parser](https://github.com/advisories/GHSA-27r3-jxmh-p828), [fast-uri](https://github.com/advisories/GHSA-w74x-v6f9-j3xg), [qs](https://github.com/advisories/GHSA-6pq8-jhm2-2vcw). Do not run the test server against untrusted traffic. `npm audit` is a point-in-time dependency check, not a source security guarantee.
