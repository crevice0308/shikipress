# TOC fragment regression

Valid author-supplied HTML IDs may contain literal `%`, percent-like sequences,
Unicode, and fragment punctuation. Keep those IDs unchanged in the document,
percent-encode them in the generated TOC URL, and decode safely when moving focus.

Run from the repository root:

- `node tests/toc-fragments/run-unit.cjs`: seven cases against the complete actual
  frontend asset with a minimal synthetic DOM. It checks exact focus, malformed
  legacy fragments, no exceptions, and preservation of native fragment/history.
- `node tests/toc-fragments/run-php.mjs`: six cases on each supported PHP 8.2–8.5
  runtime, compiling the actual PHP asset and executing its actual TOC rendering
  statement with synthetic headings. Uses the existing pinned PHP-WASM packages
  installed under `tests/wordpress-layout`.

The PHP harness is deliberately **not** a full WordPress parser test. Additional
cases in `tests/frontend-integration.php` exercise the actual `shiki_toc()` and
WordPress HTML parser when the isolated WordPress integration suite is available.
These unit checks do not establish browser layout or complete WordPress behavior.

For before/after verification, `SHIKI_FRONTEND_JS` and `SHIKI_FRONTEND_PHP` may
point to separately extracted prior assets. The original code fails the malformed
fragment JS check and the percent-containing author-ID PHP check.
