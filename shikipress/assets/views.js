/* Optional first-party aggregate event. No identifiers, cookies, or local storage. */
(() => {
  'use strict';
  const config = window.shikiViews;
  if (!config) return;
  let elapsed = false, sent = false, tcData = null, subscribed = false;
  const allowed = () => {
    if (!config.consentRequired) return true;
    if (!config.cmpId || !tcData || tcData.cmpStatus !== 'loaded' || Number(tcData.cmpId) !== Number(config.cmpId)) return false;
    if (tcData.gdprApplies === false) return true;
    if (tcData.gdprApplies !== true || !['tcloaded', 'useractioncomplete'].includes(tcData.eventStatus) || !tcData.tcString) return false;
    return [8, 9].every(id => tcData.publisher?.consents?.[id] === true);
  };
  const send = () => {
    if (sent || !elapsed || document.visibilityState !== 'visible' || !allowed()) return;
    sent = true;
    fetch(config.endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({post_id: config.postId, consent: config.consentRequired ? 'tcf-confirmed' : 'not-required', cmp_id: config.cmpId || 0}), keepalive: true}).catch(() => {});
  };
  window.setTimeout(() => { elapsed = true; send(); }, 5000);
  document.addEventListener('visibilitychange', send);
  if (!config.consentRequired) return;
  const subscribe = () => {
    if (subscribed || typeof window.__tcfapi !== 'function') return;
    subscribed = true;
    try { window.__tcfapi('addEventListener', 2, (data, success) => { tcData = success ? data : null; send(); }); }
    catch (error) { tcData = null; subscribed = false; }
  };
  subscribe();
  let attempts = 0;
  const timer = window.setInterval(() => { subscribe(); if (subscribed || ++attempts >= 60) window.clearInterval(timer); }, 500);
})();
