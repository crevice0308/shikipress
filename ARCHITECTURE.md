# ShikiPress module contract
Root: /workspace/scratch/28f95e205fde/shikipress-project
Theme root shikipress/. Classic hybrid theme with theme.json, Gutenberg styles.
No external runtime dependencies. PHP >=8.2, WordPress >=6.6 (target actual downloaded stable).
Prefix shiki_. Version SHIKI_VERSION=1.0.1.
Core functions in inc/core.php, loaded first:
- shiki_defaults(): apply_filters('shiki_defaults', [flat key=>value]).
- shiki_option($key,$default=null): merged defaults + get_option('shikipress_settings',[]).
- shiki_settings_form_start($section), shiki_settings_form_end(): options.php Settings API form. Inputs shikipress_settings[key], hidden shikipress_section. Root sanitation merges current settings. Each module filters shiki_sanitize_settings($out,$input,$section) returning merged output; MUST only modify own section. No secrets in settings! Keys in wp-config constant SHIKIPRESS_DEEPL_KEY etc only.
- shiki_public_posts($args=[]): WP_Query default publish, has_password false; exclude meta _shiki_exclude=1 (public listings), no filters suppression.
- shiki_public_pages(): array published, unprotected, nonexcluded WP_Post, obey settings page_exclude IDs, page_priority IDs, menu_order.
- shiki_invalidate_cache(): increment option shikipress_cache_epoch; action shiki_cache_invalidated.
Admin root menu shikipress, tab slugs dashboard/design/automation/navigation/ads/seo/diagnostics/backup. Root calls do_action('shiki_admin_tab_'.$tab). Each module callback renders complete forms using helpers or own admin-post forms, never nested forms.
Core settings keys: season_mode=auto, season_fixed=autumn, timezone=Asia/Tokyo; page_exclude=[], page_priority=[], page_roles=[]; tabs=[home=>ホーム,categories=>カテゴリー,pages=>固定ページ,search=>検索]. Modules add own defaults via filter.
Module responsibilities:
- inc/automation.php: shiki_season(?DateTimeImmutable $date=null): spring/summer/autumn/winter; shiki_season_context(): [season,label,event]; shiki_season_terms(): season=>term ID; shiki_select_posts($context,$exclude=[],$limit=3,$post_id=0): WP_Post[] where contexts featured/related/popular; admin automation. Seasonal design admin root consumes module helper if needed. Module own cron and classifier/slugs/editor boxes.
- inc/ads.php assets/ads.js + ads CSS if needed: shiki_ad($position) ECHO eligible slots; shiki_ad_html($position) RETURN html; own admin ads. Integrate content via filter. Ad block. Defaults safely test/off, no external requests during tests.
- inc/seo.php: head, robots, XML exclusion, breadcrumbs shiki_breadcrumbs() echo, shiki_html_sitemap() shortcode. Admin SEO.
- inc/tools.php: diagnostics admin, link scanner, settings export/import/preview/apply/restore admin backup, protections. Owned tests/tools-* if desired.
- inc/compat.php + assets/plugin-compat.css: optional CF7/TablePress styles, Yoast stricter robots/XML policy and independent page routing, WP Super Cache/Yoast invalidation. All APIs are conditional; no runtime plugin requirement.
- Frontend: templates/assets/style.css/assets/frontend.js/inc/frontend.php/theme.json/style.css and SVG artwork. Helpers shiki_card($post), shiki_render_cards($posts), shiki_toc($content) defined frontend. Use function_exists guards if module not ready. Root includes inc/frontend.php last. admin root sets custom-logo support and register menus primary/footer.
- Environment: scripts/setup-dev.sh/start-dev.sh/stop-dev.sh, test fixture scripts, tests browser/integration.
All admin actions capability+nonce. Only published unprotected content in listings/selection/search/maps. No automatic public modifications.
Write focused module test scripts and notes under docs/<module>-implementation.md. Record unimplemented precisely, do not label placeholders as done. Preserve public function signatures.
