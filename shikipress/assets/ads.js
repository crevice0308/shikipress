(function () {
  'use strict';
  const cfg = window.shikiAdsConfig || {live: false};
  const loaders = new Map();
  let tcData = null;
  let listenerInstalled = false;
  let listenerTimer = null;
  let productionStarted = false;
  let reloadRequested = false;
  const consentedSlots = new Set();
  const elements = Array.from(document.querySelectorAll('[data-shiki-ad]'));
  const decode = function (value) {
    const bytes = Uint8Array.from(atob(value), function (c) { return c.charCodeAt(0); });
    return new TextDecoder().decode(bytes);
  };
  const setState = function (node, state, text) {
    node.dataset.state = state;
    const status = node.querySelector('.shiki-ad__status');
    if (status && text) status.textContent = text;
    node.dispatchEvent(new CustomEvent('shiki:ad-state', {detail: {state: state}}));
  };
  const payloads = new Map();
  elements.forEach(function (node) {
    try { payloads.set(node, JSON.parse(decode(node.dataset.shikiAd))); }
    catch (err) { setState(node, 'failure', '広告枠の設定を確認してください'); }
  });
  const deviceAllowed = function (payload) {
    const mobile = window.matchMedia('(max-width: 767px)').matches;
    return !payload.device || payload.device === 'all' || (payload.device === 'mobile' ? mobile : !mobile);
  };
  // Check only the official TCF API response. LocalStorage and cookies are not consent substitutes.
  const consentAllowed = function (payload) {
    if (!cfg.live || !cfg.cmpId || !tcData || tcData.cmpStatus !== 'loaded' || Number(tcData.cmpId) !== Number(cfg.cmpId)) return false;
    if (tcData.gdprApplies === false) return true;
    if (tcData.gdprApplies !== true || !['tcloaded', 'useractioncomplete'].includes(tcData.eventStatus) || !tcData.tcString) return false;
    const purposes = payload.purposes || [1, 3, 4];
    const vendors = payload.vendors || [];
    if (!vendors.length || !purposes.length) return false;
    if (tcData.publisher && tcData.publisher.restrictions && Object.keys(tcData.publisher.restrictions).length) return false;
    return purposes.every(function (id) { return tcData.purpose && tcData.purpose.consents && tcData.purpose.consents[id] === true; }) &&
      vendors.every(function (id) { return tcData.vendor && tcData.vendor.consents && tcData.vendor.consents[id] === true; });
  };
  const loadScript = function (source, target, shared) {
    const src = source.getAttribute('src');
    let absolute = '';
    if (src) {
      try { absolute = new URL(src, window.location.href).href; } catch (err) { return Promise.reject(new Error('script-url')); }
      if (!absolute.startsWith('https://')) return Promise.reject(new Error('script-https'));
      if (shared && loaders.has(absolute)) { target.remove(); return loaders.get(absolute); }
      const existing = Array.from(document.scripts).find(function (script) { return script !== source && script.src === absolute; });
      if (shared && existing) { target.remove(); return Promise.resolve(); }
    }
    const promise = new Promise(function (resolve, reject) {
      const script = document.createElement('script');
      Array.from(source.attributes).forEach(function (attr) { if (attr.name !== 'src') script.setAttribute(attr.name, attr.value); });
      let timeout = null;
      if (src) {
        script.async = false;
        script.src = absolute;
        script.addEventListener('load', function () { clearTimeout(timeout); script.dataset.shikiLoaded = 'true'; resolve(); }, {once: true});
        script.addEventListener('error', function () { clearTimeout(timeout); reject(new Error('script-load')); }, {once: true});
        timeout = window.setTimeout(function () { reject(new Error('script-timeout')); }, 15000);
      } else script.textContent = source.textContent;
      target.replaceWith(script);
      if (!src) resolve();
    });
    if (shared && src) loaders.set(absolute, promise);
    return promise;
  };
  const mountCode = async function (container, html, sharedLoaders) {
    if (/document\s*\.\s*(write|writeln)\s*\(/i.test(html)) throw new Error('document-write-not-supported');
    const template = document.createElement('template');
    template.innerHTML = html;
    const scripts = Array.from(template.content.querySelectorAll('script')).map(function (script) {
      const marker = document.createComment('shiki-approved-script');
      script.replaceWith(marker);
      return {script: script, marker: marker};
    });
    container.replaceChildren(template.content);
    for (const item of scripts) {
      const source = item.script.getAttribute('src');
      const absolute = source ? new URL(source, window.location.href).href : '';
      await loadScript(item.script, item.marker, (sharedLoaders || []).includes(absolute));
    }
  };
  const adsenseLoader = function (publisher) {
    if (!/^ca-pub-[0-9]{16}$/.test(publisher || '')) return Promise.reject(new Error('publisher'));
    const src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + publisher;
    if (loaders.has(src)) return loaders.get(src);
    const source = document.createElement('script'); source.src = src; source.setAttribute('crossorigin', 'anonymous');
    const marker = document.createComment('shiki-adsense-loader'); document.head.appendChild(marker);
    return loadScript(source, marker, true);
  };
  const mountSlot = async function (node, payload) {
    if (node.dataset.started === 'true') return;
    if (!deviceAllowed(payload)) { setState(node, 'device-excluded'); return; }
    // Check a declared fixed creative width before any provider code or request.
    // Never clip, resize, move, or remove a provider iframe after it has started.
    const minWidth = Math.max(0, Number(payload.minWidth) || 0);
    const availableWidth = node.querySelector('.shiki-ad__body').getBoundingClientRect().width;
    if (minWidth > 0 && availableWidth + 0.5 < minWidth) {
      setState(node, 'size-excluded', '広告枠の幅が不足しているため配信を保留しています');
      return;
    }
    if (payload.mode !== 'production' || !cfg.live) {
      node.dataset.started = 'true';
      const mode = payload.dummy || 'ready';
      if (mode === 'delay') {
        setState(node, 'delay', 'TEST 広告枠・2秒の遅延を再現');
        window.setTimeout(function () { setState(node, 'test', 'TEST 広告枠・遅延完了（外部通信なし）'); }, 2000);
      } else if (mode === 'no-fill') setState(node, 'no-fill', 'TEST 未配信（確保領域を維持）');
      else if (mode === 'failure') setState(node, 'failure', 'TEST 読み込み失敗（外部通信なし）');
      else setState(node, 'test', 'TEST 広告枠（外部通信なし）');
      return;
    }
    if (!consentAllowed(payload)) { setState(node, 'consent-wait', '広告は同意状態の確認待ちです'); return; }
    node.dataset.started = 'true';
    productionStarted = true; consentedSlots.add(payload);
    const body = node.querySelector('.shiki-ad__body');
    setState(node, 'loading', '広告を読み込み中');
    try {
      if (payload.service === 'adsense') {
        if (!/^[0-9]{1,20}$/.test(payload.adSlot || '')) throw new Error('ad-slot');
        const ad = document.createElement('ins'); ad.className = 'adsbygoogle'; ad.style.display = 'block';
        ad.dataset.adClient = payload.publisher; ad.dataset.adSlot = payload.adSlot;
        ad.dataset.adFormat = 'auto'; ad.dataset.fullWidthResponsive = 'true';
        body.replaceChildren(ad);
        await adsenseLoader(payload.publisher);
        // Observe before push and inspect immediately: a cached loader may finish synchronously.
        // No-fill detection reports state only; never hides or moves a provider iframe.
        const updateFillState = function () {
          if (ad.dataset.adStatus === 'unfilled') setState(node, 'no-fill');
          else if (ad.dataset.adStatus === 'filled') setState(node, 'ready');
        };
        new MutationObserver(updateFillState).observe(ad, {attributes: true, attributeFilter: ['data-ad-status']});
        (window.adsbygoogle = window.adsbygoogle || []).push({});
        updateFillState();
      } else await mountCode(body, payload.code || '', payload.sharedLoaders || []);
      if (node.dataset.state === 'loading') setState(node, 'ready');
    } catch (err) {
      setState(node, 'failure', '広告を読み込めませんでした');
      node.dispatchEvent(new CustomEvent('shiki:ad-error', {detail: {reason: String(err.message || 'unknown')}}));
    }
  };
  let autoStarted = false;
  const update = function () {
    payloads.forEach(function (payload, node) { mountSlot(node, payload); });
    const autoPayload = {vendors: [755], purposes: [1, 3, 4]};
    if (cfg.auto && !autoStarted && consentAllowed(autoPayload)) {
      autoStarted = true; productionStarted = true; consentedSlots.add(autoPayload);
      adsenseLoader(cfg.publisher).catch(function () { document.dispatchEvent(new CustomEvent('shiki:ad-error', {detail: {reason: 'auto-loader'}})); });
    }
  };
  const subscribe = function () {
    if (listenerInstalled || typeof window.__tcfapi !== 'function') return;
    listenerInstalled = true;
    try {
      window.__tcfapi('addEventListener', 2, function (data, success) {
        tcData = success ? data : null;
        if (productionStarted && Array.from(consentedSlots).some(function (payload) { return !consentAllowed(payload); }) && !reloadRequested) {
          reloadRequested = true;
          // CMP must persist revocation. Reload removes the already-loaded provider runtime.
          window.location.reload(); return;
        }
        update();
      });
    } catch (err) { tcData = null; listenerInstalled = false; }
  };
  update();
  window.addEventListener('resize', function () { update(); }, {passive: true});
  if (cfg.live) {
    window.googlefc = window.googlefc || {};
    window.googlefc.callbackQueue = window.googlefc.callbackQueue || [];
    window.googlefc.callbackQueue.push({'CONSENT_API_READY': subscribe});
    subscribe();
    let attempts = 0;
    listenerTimer = window.setInterval(function () { subscribe(); attempts++; if (listenerInstalled || attempts >= 60) window.clearInterval(listenerTimer); }, 500);
    // Only the explicitly approved CMP code is allowed before consent. No ad code is loaded here.
    if (cfg.cmpCode) {
      const holder = document.createElement('div'); holder.className = 'shiki-cmp-integration'; document.body.appendChild(holder);
      try { mountCode(holder, decode(cfg.cmpCode), []).then(subscribe).catch(function () { tcData = null; }); }
      catch (err) { tcData = null; }
    }
  }
})();
