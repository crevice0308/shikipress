# Offline layout regression

This suite reads the theme's actual CSS/JavaScript and supplies representative, disposable HTML. It does not install WordPress, visit production, load live ad/CMP providers, click ads, or use credentials. All browser network requests are intercepted; the one expected failing fixture loader uses the reserved `.invalid` domain.

Run from the repository root:

```sh
npm ci --ignore-scripts
npx playwright install --with-deps chromium
npm run test:layout
```

`THEME_DIR` may select another theme directory. Normal runs use the pinned Playwright browser. `SHIKI_BROWSER` can select an already installed headless-shell executable in restricted environments; that override also enables single-process flags. `RUN_LABEL` names generated output. Results and screenshots are local/CI artifacts and are excluded from source control.

The suite checks 320–1920px viewport widths, light/dark rendering, long site names, content overflow, search dialog/inert/focus behavior, readable body text, mobile controls, dummy ready/delay/no-fill/failure states, responsive synthetic tags for six generic service adapters, blocked loaders, no-consent behavior, declared fixed-width gating, and cached synchronous AdSense no-fill reporting. AdSense is represented by an inert script element and a local callback; its provider is never requested.

These are bounded regressions. They do not guarantee all future WordPress, PHP, plugins, provider code, or real-device Safari behavior. Run them after updates alongside WordPress integration/retention tests. Fixed-width tags require the correct minimum width setting; unknown fixed-width tags are not automatically inferred. Reserve the actual expected maximum ad height: a 600px creative in a 250px reservation still moves later content by350px. No provider iframe is clipped, scaled, relocated, or removed by these guards.
