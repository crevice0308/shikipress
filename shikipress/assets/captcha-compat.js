/* Native Contact Form 7 Turnstile lifecycle adapter. Provider verification remains server side. */
(() => {
    'use strict';

    const pending = new WeakMap();
    let sequence = 0;
    const formFor = event => event.target instanceof Element
        ? (event.target.matches('form.wpcf7-form') ? event.target : event.target.closest('.wpcf7')?.querySelector('form.wpcf7-form'))
        : null;
    const responseFor = widget => widget.querySelector('[name="_wpcf7_turnstile_response"]');

    function widgetSelector(widget) {
        if (!widget.id) {
            let candidate;
            do { sequence += 1; candidate = 'shiki-cf7-turnstile-' + sequence; }
            while (document.getElementById(candidate));
            widget.id = candidate;
        }
        return '#' + CSS.escape(widget.id);
    }

    document.addEventListener('wpcf7beforesubmit', event => {
        const form = formFor(event);
        if (!form) { return; }
        const snapshots = [];
        form.querySelectorAll('.wpcf7-turnstile.cf-turnstile').forEach(widget => {
            const value = responseFor(widget)?.value;
            if (typeof value === 'string' && value !== '') { snapshots.push({ widget, value }); }
        });
        pending.set(form, snapshots);
    });

    document.addEventListener('wpcf7submit', event => {
        const form = formFor(event);
        if (!form) { return; }
        const snapshots = pending.get(form) || [];
        pending.delete(form);
        // CF7 6.2.2 resets only the first implicit widget. Run after its handler,
        // refreshing a submitted native widget only when nobody already reset it.
        queueMicrotask(() => {
            if (typeof window.turnstile?.reset !== 'function') { return; }
            snapshots.forEach(({ widget, value }) => {
                if (!widget.isConnected || responseFor(widget)?.value !== value) { return; }
                try { window.turnstile.reset(widgetSelector(widget)); }
                catch (_) { /* A provider error must not interrupt CF7's response UI. */ }
            });
        });
    });
})();
