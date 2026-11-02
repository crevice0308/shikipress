# Isolated tools security-boundary tests

Run native PHP directly, or use the existing pinned WordPress Playground development dependencies:

```sh
php tests/security-tools/unit.php
npm ci --ignore-scripts --prefix tests/wordpress-layout
node tests/security-tools/run-unit.mjs
```

The WASM runner executes the actual `inc/tools.php` on PHP 8.2–8.5. Each version checks 63 cases: operation-specific nonce and administrator permission, import envelope/type/size/secret/executable rejection, explicit code privilege, disabled execution after import, and restricted IP/URL targets.

WordPress functions are synthetic stubs, including a small settings schema. No HTTP request, database, real credential, or live site is used. These tests do **not** establish full Settings API enforcement, DNS/cURL pinning, actual WordPress integration, MySQL retention, or browser behavior. See `tests/tools-integration.php` for the separate real WordPress suite.
