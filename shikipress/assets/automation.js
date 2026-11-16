/* Editor-only explicit title actions. No network on page load. */
(() => {
  'use strict';
  const status = document.getElementById('shiki-automation-status');
  const candidate = document.getElementById('shiki-slug-candidate');
  if (!status || !candidate || !window.shikiAutomation) return;
  const send = async (action, postId, extra = {}) => {
    const body = new URLSearchParams({action, post_id: postId, nonce: shikiAutomation.nonce, ...extra});
    const response = await fetch(shikiAutomation.ajax, {method: 'POST', credentials: 'same-origin', body});
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || '処理できませんでした。');
    return json.data;
  };
  const show = (data) => {
    status.textContent = data.message || data.source || '';
    if (data.slug) candidate.value = data.slug;
  };
  // "Apply on save" choices apply once. Checking a box again issues a new token for the next save, and the
  // block editor (which keeps this form after sending it with a save) clears the boxes once they were sent.
  const token = document.getElementById('shiki-apply-token');
  const applyBoxes = () => Array.from(document.querySelectorAll('input[name="shiki_apply_classification"], input[name="shiki_apply_slug"]'));
  const renew = () => { if (token && window.crypto?.getRandomValues) token.value = Array.from(window.crypto.getRandomValues(new Uint8Array(10)), (byte) => byte.toString(16).padStart(2, '0')).join(''); };
  applyBoxes().forEach((box) => box.addEventListener('change', () => { if (box.checked) renew(); }));
  if (window.wp?.data?.subscribe && window.wp.data.select) {
    let sending = false;
    window.wp.data.subscribe(() => {
      const now = Boolean(window.wp.data.select('core/edit-post')?.isSavingMetaBoxes?.());
      if (sending && !now) applyBoxes().forEach((box) => { box.checked = false; });
      sending = now;
    });
  }
  // Suggestions follow the title being edited, not only the saved one.
  const currentTitle = () => {
    const field = document.getElementById('title');
    if (field) return field.value;
    try { return window.wp?.data?.select('core/editor')?.getEditedPostAttribute('title'); } catch (_) { return undefined; }
  };
  document.querySelectorAll('.shiki-regenerate').forEach(button => button.addEventListener('click', async () => {
    button.disabled = true;
    const title = currentTitle();
    try { show(await send('shiki_slug_suggest', button.dataset.post, typeof title === 'string' ? {title} : {})); }
    catch (error) { status.textContent = error.message; }
    finally { button.disabled = false; }
  }));
  document.querySelectorAll('.shiki-translate').forEach(button => button.addEventListener('click', async () => {
    if (!document.getElementById('shiki-deepl-consent')?.checked) { status.textContent = '保存済みタイトルの送信承認を選択してください。'; return; }
    button.disabled = true;
    try {
      show(await send('shiki_translate_title', button.dataset.post, {consent: '1'}));
      let polls = 0;
      const poll = async () => {
        try {
          const result = await send('shiki_translation_status', button.dataset.post); show(result);
          if (['done', 'error', 'none'].includes(result.state) || ++polls >= 30) { button.disabled = false; return; }
          window.setTimeout(poll, 5000);
        } catch (error) { status.textContent = error.message; button.disabled = false; }
      };
      window.setTimeout(poll, 5000);
    } catch (error) { status.textContent = error.message; button.disabled = false; }
  }));
})();
