/** Actual frontend asset with a minimal synthetic DOM, not a browser/layout test. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(process.env.SHIKI_FRONTEND_JS || path.resolve(__dirname, '../../shikipress/assets/frontend.js'), 'utf8');
const cases = [
  ['ordinary', '#ordinary'],
  ['100%', '#100%25'],
  ['section%20literal', '#section%2520literal'],
  ['日本語の見出し', '#%E6%97%A5%E6%9C%AC%E8%AA%9E%E3%81%AE%E8%A6%8B%E5%87%BA%E3%81%97'],
  ['a#b?c', '#a%23b%3Fc'],
  ['100%', '#100%'], // Old cached markup with a bare percent sign.
  ['bad%E0%A4', '#bad%E0%A4'], // Malformed UTF-8 escape must not throw.
];
for (const [id, hash] of cases) {
  let click, focused = false;
  const target = {tabIndex: null, focus(options) { focused = true; assert.equal(options.preventScroll, true); }};
  const link = {hash, addEventListener(name, handler) { if (name === 'click') click = handler; }};
  const document = {
    documentElement: {classList: {add() {}}, dataset: {}},
    querySelectorAll(selector) { return selector === '.article-toc a[href^="#"]' ? [link] : []; },
    querySelector() { return null; },
    getElementById(key) { return key === id ? target : null; },
    addEventListener() {},
  };
  const window = {matchMedia() { return {matches: false, addEventListener() {}}; }};
  vm.runInNewContext(source, {document, window});
  assert.equal(typeof click, 'function');
  assert.doesNotThrow(() => click());
  assert.equal(focused, true, `${hash} must focus exact ID ${id}`);
  assert.equal(target.tabIndex, -1);
  assert.equal(link.hash, hash, 'Handler must preserve native fragment/history');
}
console.log(JSON.stringify({status: 'PASS', cases: cases.length, method: 'Actual frontend.js with synthetic DOM; no browser or WordPress runtime'}));
