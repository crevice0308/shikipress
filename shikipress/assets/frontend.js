/* Functional preferences and progressively enhanced navigation; no external requests. */
(() => {
    'use strict';
    const root = document.documentElement;
    root.classList.add('js');
    const panels = Array.from(document.querySelectorAll('.nav-panel'));
    const triggers = Array.from(document.querySelectorAll('[data-panel]'));
    const backdrop = document.querySelector('.panel-backdrop');
    const status = document.getElementById('shiki-status');
    let active = null;
    let returnTo = null;
    const inertStates = new Map();
    const focusable = (panel) => Array.from(panel.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary,[tabindex]:not([tabindex="-1"])')).filter((el) => !el.hidden && !el.closest('[hidden]') && el.getClientRects().length);
    const announce = (message) => { if (status) status.textContent = message; };
    const closePanel = (restore = true) => {
        if (!active) return;
        const previous = active;
        active.style.removeProperty('bottom');
        active.style.removeProperty('max-height');
        active.hidden = true;
        active.classList.remove('is-open');
        active.removeAttribute('role');
        active.removeAttribute('aria-modal');
        triggers.forEach((el) => el.setAttribute('aria-expanded', 'false'));
        if (backdrop) backdrop.hidden = true;
        document.body.classList.remove('modal-open');
        inertStates.forEach((value, el) => { el.inert = value; });
        inertStates.clear();
        active = null;
        announce(`${previous.querySelector('h2')?.textContent || 'パネル'}を閉じました`);
        if (restore && returnTo?.isConnected) returnTo.focus({ preventScroll: true });
        if (window.visualViewport) document.documentElement.style.removeProperty('--keyboard-offset');
    };
    const openPanel = (panel, trigger) => {
        if (!panel) return;
        if (active === panel) { closePanel(); return; }
        if (active) closePanel(false);
        returnTo = trigger || document.activeElement;
        active = panel;
        panel.hidden = false;
        panel.classList.add('is-open');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        if (backdrop) backdrop.hidden = false;
        triggers.forEach((el) => el.setAttribute('aria-expanded', String(el.dataset.panel === panel.id)));
        document.querySelectorAll('[data-modal-inert]').forEach((el) => { inertStates.set(el, el.inert); el.inert = true; });
        document.body.classList.add('modal-open');
        const input = panel.querySelector('input[type="search"]');
        // Focusing the heading first avoids forcing a mobile software keyboard open.
        (input && window.matchMedia('(min-width: 768px)').matches ? input : panel).focus({ preventScroll: true });
        announce(`${panel.querySelector('h2')?.textContent || 'パネル'}を開きました`);
    };
    panels.forEach((panel) => { panel.hidden = true; const close = panel.querySelector('.panel-close'); if (close) { close.hidden = false; close.addEventListener('click', () => closePanel()); } });
    triggers.forEach((trigger) => trigger.addEventListener('click', (event) => { const panel = document.getElementById(trigger.dataset.panel); if (!panel) return; event.preventDefault(); openPanel(panel, trigger); }));
    backdrop?.addEventListener('click', () => closePanel());
    document.addEventListener('keydown', (event) => {
        if (!active) return;
        if (event.key === 'Escape') { event.preventDefault(); closePanel(); }
        if (event.key === 'Tab') {
            const items = focusable(active);
            if (!items.length) { event.preventDefault(); active.focus(); return; }
            const first = items[0], last = items[items.length - 1];
            if (event.shiftKey && (document.activeElement === first || document.activeElement === active)) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || document.activeElement === active)) { event.preventDefault(); first.focus(); }
        }
    });
    document.addEventListener('focusin', (event) => { if (active && !active.contains(event.target)) active.focus({ preventScroll: true }); });
    panels.forEach((panel) => panel.addEventListener('click', (event) => { const link = event.target.closest('a[href]'); if (link && !link.dataset.panel) closePanel(false); }));
    // Keep the search form in the visual viewport when mobile keyboards resize it.
    const fitPanel = () => {
        if (!active || !window.visualViewport || window.innerWidth >= 768) return;
        const keyboard = Math.max(0, window.innerHeight - window.visualViewport.height - window.visualViewport.offsetTop);
        if (keyboard > 120) { active.style.bottom = `${keyboard + 8}px`; active.style.maxHeight = `${Math.max(120, window.visualViewport.height - 24)}px`; }
        else { active.style.removeProperty('bottom'); active.style.removeProperty('max-height'); }
    };
    window.visualViewport?.addEventListener('resize', fitPanel);
    window.visualViewport?.addEventListener('scroll', fitPanel);
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)');
    const themeButtons = Array.from(document.querySelectorAll('.theme-toggle'));
    const currentTheme = () => root.dataset.theme || (systemDark.matches ? 'dark' : 'light');
    const updateThemeLabels = () => themeButtons.forEach((button) => { const dark = currentTheme() === 'dark'; button.setAttribute('aria-label', dark ? 'ライトモードに切り替える' : 'ダークモードに切り替える'); button.setAttribute('aria-pressed', String(dark)); button.title = '表示を切り替える。Shiftキーを押しながら操作すると端末設定に戻ります。'; });
    themeButtons.forEach((button) => { button.hidden = false; button.addEventListener('click', (event) => {
        if (event.shiftKey) { delete root.dataset.theme; try { localStorage.removeItem('shiki-theme'); } catch (error) { /* Private browsing can disable storage. */ } announce('表示を端末設定に合わせました'); }
        else { const next = currentTheme() === 'dark' ? 'light' : 'dark'; root.dataset.theme = next; try { localStorage.setItem('shiki-theme', next); } catch (error) { /* The preference still applies to this page. */ } announce(next === 'dark' ? 'ダークモードに切り替えました' : 'ライトモードに切り替えました'); }
        updateThemeLabels();
    }); });
    systemDark.addEventListener?.('change', updateThemeLabels);
    updateThemeLabels();
    document.querySelectorAll('.reading-toggle').forEach((button) => { button.hidden = false; button.addEventListener('click', () => { const enabled = !document.body.classList.contains('reading-mode'); document.body.classList.toggle('reading-mode', enabled); button.setAttribute('aria-pressed', String(enabled)); button.textContent = enabled ? '通常表示' : '読書表示'; announce(enabled ? '読書表示に切り替えました' : '通常表示に切り替えました'); }); });
    document.querySelectorAll('.entry-content table').forEach((table) => { if (table.closest('.wp-block-table,.table-scroll')) return; const wrap = document.createElement('div'); wrap.className = 'table-scroll'; wrap.tabIndex = 0; wrap.setAttribute('role', 'region'); wrap.setAttribute('aria-label', '横にスクロールできる表'); table.parentNode.insertBefore(wrap, table); wrap.appendChild(table); });
    document.querySelectorAll('.entry-content .wp-block-table').forEach((table) => { table.tabIndex = 0; table.setAttribute('role', 'region'); table.setAttribute('aria-label', '横にスクロールできる表'); });
    // Deep links keep normal browser history and work without JavaScript.
    document.querySelectorAll('.article-toc a[href^="#"]').forEach((link) => link.addEventListener('click', () => { const target = document.getElementById(decodeURIComponent(link.hash.slice(1))); if (target) { target.tabIndex = -1; target.focus({ preventScroll: true }); } }));
})();
