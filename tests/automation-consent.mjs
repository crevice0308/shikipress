import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const script = fs.readFileSync(new URL('../shikipress/assets/views.js', import.meta.url), 'utf8');
const cases = [];
const valid = {cmpId: 42, cmpStatus: 'loaded', gdprApplies: true, eventStatus: 'useractioncomplete', tcString: 'SYNTHETIC_TEST_ONLY', publisher: {consents: {8: true, 9: true}}};
function run(label, data, expected, overrides = {}) {
  const calls = [], timers = []; let listener;
  const context = {
    window: {shikiViews: {endpoint: '/mock-view', postId: 123, consentRequired: true, cmpId: 42, ...overrides}, setTimeout: cb => timers.push(cb), setInterval: () => 1, clearInterval: () => {}},
    document: {visibilityState: 'visible', addEventListener: () => {}},
    fetch: (endpoint, options) => { calls.push({endpoint, options}); return Promise.resolve({ok: true}); }
  };
  if (data !== undefined) context.window.__tcfapi = (command, version, callback) => { assert.equal(command, 'addEventListener'); assert.equal(version, 2); listener = callback; if (data) callback(data, true); };
  vm.runInNewContext(script, context);
  timers.forEach(cb => cb());
  assert.equal(calls.length, expected, label);
  if (calls.length) {
    const payload = JSON.parse(calls[0].options.body);
    assert.equal(payload.post_id, 123);
    assert.equal(Object.keys(payload).sort().join(','), 'cmp_id,consent,post_id');
  }
  cases.push({test: label, passed: true});
  return {calls, listener};
}
run('CMP missing blocks measurement', undefined, 0);
run('CMP denies publisher purpose blocks measurement', {...valid, publisher: {consents: {8: false, 9: true}}}, 0);
run('CMP missing one purpose blocks measurement', {...valid, publisher: {consents: {8: true}}}, 0);
run('CMP ID mismatch blocks measurement', {...valid, cmpId: 77}, 0);
run('CMP loading blocks measurement', {...valid, cmpStatus: 'loading'}, 0);
run('GDPR applicability unknown blocks measurement', {...valid, gdprApplies: undefined}, 0);
run('Missing TC string blocks measurement', {...valid, tcString: ''}, 0);
run('Confirmed publisher purposes permits one event', valid, 1);
run('Confirmed non-GDPR CMP permits one event', {...valid, gdprApplies: false}, 1);
run('Explicit acknowledged no-consent mode permits one event', undefined, 1, {consentRequired: false});
const later = run('Pending CMP starts with no event', null, 0);
later.listener(valid, true); later.listener(valid, true);
assert.equal(later.calls.length, 1, 'later valid consent sends once');
cases.push({test: 'later valid consent sends once', passed: true});
console.log(JSON.stringify(cases, null, 2));
console.log(`Success: ${cases.length} measurement consent script checks passed. Synthetic CMP only.`);
