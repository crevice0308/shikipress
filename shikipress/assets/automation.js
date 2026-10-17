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
  document.querySelectorAll('.shiki-regenerate').forEach(button => button.addEventListener('click', async () => {
    button.disabled = true;
    try { show(await send('shiki_slug_suggest', button.dataset.post)); }
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
