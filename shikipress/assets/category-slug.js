(() => {
  'use strict';
  const generate = document.getElementById('shiki-category-generate');
  const adopt = document.getElementById('shiki-category-adopt');
  const candidate = document.getElementById('shiki-new-category-candidate');
  const status = document.getElementById('shiki-category-status');
  if (!generate || !adopt || !candidate || !status || !window.shikiCategory) return;
  generate.addEventListener('click', async () => {
    generate.disabled = true;
    try {
      const response = await fetch(shikiCategory.ajax, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({action: 'shiki_category_slug', nonce: shikiCategory.nonce, title: document.getElementById('tag-name')?.value || ''})});
      const json = await response.json();
      if (!json.success) throw new Error(json.data?.message || '候補を取得できませんでした。');
      candidate.value = json.data.slug || ''; status.textContent = json.data.source;
    } catch (error) { status.textContent = error.message; }
    finally { generate.disabled = false; }
  });
  adopt.addEventListener('click', () => {
    const clean = candidate.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 70).replace(/-+$/g, '');
    if (!clean) { status.textContent = '意味を確認した英語候補を入力してください。'; return; }
    const slug = document.getElementById('tag-slug');
    if (slug) { slug.value = clean; slug.dispatchEvent(new Event('change', {bubbles: true})); status.textContent = '標準スラッグ欄へ入力しました。「新規カテゴリーを追加」で確定してください。'; }
  });
})();
