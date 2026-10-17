> 出典: 1.0.2 納品資料。記載された過去の試験結果は本リポジトリでの再実行結果ではありません。現在の検証範囲は README.md と docs/verification.md を参照してください。

# Frontend implementation

- Architecture: classic hybrid templates with theme.json and ordinary PHP, CSS and deferred JavaScript. No build tool or Node process required in production. WP block editor typography/palette follow theme.json and the shared stylesheet.
- Original presentation: warm paper, clear Japanese hierarchy, serif headings, system sans body, four season palettes, original botanical still-life illustrations.
- Files: all theme templates; inc/frontend.php; theme.json; assets/style.css, frontend.js and season-*.svg.
- Mobile: persistent four text-labelled bottom links (home, categories, pages, search); other links progressively enhance real fragment links into modal panels. Without JS, the same content and search form remain reachable at the document end. With JS, focus enters a labelled dialog; background surfaces become inert; Tab cycles; Escape, close button and backdrop close; original focus returns. Mobile keyboard uses visualViewport when available. Safe-area padding is included.
- Reading: H2/H3 TOC with details disclosure, stable generated IDs, preservation of existing unique quoted IDs, duplicate author IDs replaced, anchor focus, scroll margins, explicit published/modified dates, optional reading layout (ad elements retained), related posts. Native blocks, captions, quotes, lists, code, tables and comments styled. Native table wrappers remain horizontally contained without JS; JS adds a labelled keyboard-scrollable region.
- Visibility: cards and automatic page lists filter publish/password/exclude status; category counts use the public-post query, not raw WordPress term counts; custom menu page/post objects also filtered. Root query protection remains responsible for search/archive query counts.
- Homepage: 0 posts preparation with no article grid; 1–5 show actual latest posts; 6+ seasonal picks and latest exclude one another. Popular section opt-in uses actual selection helper and excludes prior cards. Empty seasonal groups hidden. No fake article/card filling.
- Design admin: season auto/fixed/timezone fields (sanitizer owned by automation module), hero copy and TOC/popular toggles; custom logo via core Customizer. Authenticated nonce-protected per-season preview links; events managed by automation tab.
- Functional localStorage value: only explicit light/dark preference, no identity. Initial follows OS; click toggles; Shift-click returns to OS. Private storage errors are contained. Reduced-motion respected. No external fonts, imagery, code or telemetry.

## Artwork provenance and license

All four SVG files in `shikipress/assets/season-*.svg` were drawn for ShikiPress from original geometric shapes and paths during this implementation. No stock photos, external images, copied shapes or third-party assets are incorporated. The illustrations are editable vector artwork, not generated screenshots. They are distributed under GPL-3.0-or-later with the theme. They are illustrative seasonal fallbacks, not documentary photographs.

## Validation status

Code and real-browser verification are recorded in the root test report after integration. Screenshots must be captured from the real WordPress implementation; no mockups or SVG renderings are claimed as browser evidence. Physical iPhone/Android testing and site-specific advertisement delivery require their own environment and are not claimed here.

## Executed verification

- Isolated real WordPress integration: `SHIKI_DEV_STATE=/workspace/scratch/28f95e205fde/dev-state bash scripts/dev-job.sh bash scripts/wp.sh eval-file tests/frontend-integration.php`. The dedicated test checks unique/quoted/unquoted/empty/data-id anchors, links resolving exactly once, visibility of native/custom relative menus, bounded category cache, real article pagination including static frontpages, design export sections and many-page disclosures. Final execution: 24 assertions passed. Machine-readable results: `tests/results/frontend-integration.json`.
- Visual inspection of actual Noto CJK rendered WordPress screenshots: autumn 390px and 1440px, dark autumn mobile, article mobile and admin dashboard 390px. No clipping or document overflow observed. Fixed mobile navigation appears at the first viewport's lower edge in full-page screenshots by browser design.
- The WordPress theme preview `shikipress/screenshot.png` is a real 1200×900 Chromium viewport capture. All original SVGs render correctly as header and missing-thumbnail fallbacks. Their use is decorative illustration, not a claim of article photography.
- Comment fields have explicit Japanese labels even under an English WordPress installation; theme comment avatars are disabled to avoid automatic Gravatar requests. Core threaded reply JavaScript is loaded only when applicable.
- Computed palette contrast: `tests/contrast.mjs` measured real Chromium `getComputedStyle` after CSS transitions settled, for four seasons × light/dark × body/muted/content links/text links/primary buttons/accent copy. **48/48 passed**, minimum normal-text criterion 4.5:1; no external requests. Evidence: `tests/results/contrast.json`. This targeted palette check is not a claim of a complete WCAG conformance audit.
