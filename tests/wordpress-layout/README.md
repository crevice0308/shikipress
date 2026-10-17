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
