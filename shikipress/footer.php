<?php if (!defined('ABSPATH')) { exit; }
$shiki_context = shiki_frontend_context();
$shiki_footer_has_posts = shiki_public_posts(['posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true])->have_posts();
$shiki_tabs = array_merge(['home' => 'ホーム', 'categories' => 'カテゴリー', 'pages' => '固定ページ', 'search' => '検索'], (array) shiki_option('tabs', []));
?>
<?php if ($shiki_footer_has_posts && function_exists('shiki_ad')) { ?><div class="footer-ad wrap" data-modal-inert><?php shiki_ad('footer'); ?></div><?php } ?>
<footer class="site-footer" data-modal-inert>
    <div class="footer-main wrap"><div class="footer-intro"><a class="footer-brand" href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a><p>めぐる季節に、暮らしのしおりを。<br>心地よい毎日を、一つずつ。</p><a class="text-link" href="<?php echo esc_url(home_url('/')); ?>">トップページへ <?php echo shiki_icon('arrow'); ?></a></div><div class="footer-pages"><p class="eyebrow">INFORMATION</p><?php
        $shiki_roles = (array) shiki_option('page_roles', []);
        $shiki_public = array_map(function ($page) { return $page->ID; }, shiki_public_pages());
        $shiki_role_ids = array_values(array_unique(array_filter(array_map('absint', $shiki_roles))));
        if ($shiki_role_ids) {
            echo '<ul class="footer-role-links">';
            foreach ($shiki_role_ids as $page_id) { if (in_array($page_id, $shiki_public, true)) { echo '<li><a href="' . esc_url(get_permalink($page_id)) . '">' . esc_html(get_the_title($page_id)) . '</a></li>'; } }
            echo '</ul>';
        }
        if (has_nav_menu('footer')) { wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'menu_class' => 'footer-menu', 'depth' => 1]); }
        ?><a class="text-link" href="#nav-pages" data-panel="nav-pages" aria-controls="nav-pages" aria-expanded="false">固定ページ一覧 <?php echo shiki_icon('arrow'); ?></a></div></div>
    <div class="footer-bottom wrap"><small>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php bloginfo('name'); ?></small><span class="footer-motto">四季を、ていねいに。</span><a href="#page-top" class="back-top">ページ上部へ <?php echo shiki_icon('up'); ?></a></div>
</footer>
<div class="navigation-panels wrap">
    <section class="nav-panel" id="nav-categories" tabindex="-1" aria-labelledby="categories-title"><div class="panel-heading"><div><p class="eyebrow">EXPLORE THE SEASONS</p><h2 id="categories-title">カテゴリーから探す</h2></div><button class="icon-button panel-close" type="button" aria-label="カテゴリー一覧を閉じる" hidden><?php echo shiki_icon('close'); ?></button></div><?php shiki_season_links('compact'); ?><ul class="category-list"><?php $shiki_categories = shiki_visible_categories(); foreach ($shiki_categories as $row) { $url = get_term_link($row['term']); if (!is_wp_error($url)) { ?><li><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($row['term']->name); ?><span><?php echo esc_html($row['count']); ?><?php echo shiki_icon('arrow'); ?></span></a></li><?php } } ?></ul><?php if (!$shiki_categories) { ?><p class="muted">記事の公開後、カテゴリーをこちらにご案内します。</p><?php } ?></section>
    <section class="nav-panel" id="nav-pages" tabindex="-1" aria-labelledby="pages-title"><div class="panel-heading"><div><p class="eyebrow">INFORMATION</p><h2 id="pages-title">サイト案内・固定ページ</h2></div><button class="icon-button panel-close" type="button" aria-label="固定ページ一覧を閉じる" hidden><?php echo shiki_icon('close'); ?></button></div><?php shiki_page_links(); ?></section>
    <section class="nav-panel" id="nav-search" tabindex="-1" aria-labelledby="search-title"><div class="panel-heading"><div><p class="eyebrow">FIND YOUR LITTLE INSPIRATION</p><h2 id="search-title">暮らしのヒントを探す</h2></div><button class="icon-button panel-close" type="button" aria-label="検索を閉じる" hidden><?php echo shiki_icon('close'); ?></button></div><p>気になる季節や、暮らしのキーワードを入力してください。</p><?php get_search_form(); ?></section>
</div>
<div class="panel-backdrop" hidden></div>
<nav class="mobile-tabs" aria-label="スマホ下部ナビゲーション" data-modal-inert>
    <a href="<?php echo esc_url(home_url('/')); ?>" <?php if (is_front_page()) { echo 'aria-current="page"'; } ?>><?php echo shiki_icon('home'); ?><span><?php echo esc_html($shiki_tabs['home']); ?></span></a>
    <a href="#nav-categories" data-panel="nav-categories" aria-controls="nav-categories" aria-expanded="false"><?php echo shiki_icon('grid'); ?><span><?php echo esc_html($shiki_tabs['categories']); ?></span></a>
    <a href="#nav-pages" data-panel="nav-pages" aria-controls="nav-pages" aria-expanded="false"><?php echo shiki_icon('pages'); ?><span><?php echo esc_html($shiki_tabs['pages']); ?></span></a>
    <a href="#nav-search" data-panel="nav-search" aria-controls="nav-search" aria-expanded="false"><?php echo shiki_icon('search'); ?><span><?php echo esc_html($shiki_tabs['search']); ?></span></a>
</nav>
<div class="screen-reader-text" role="status" aria-live="polite" id="shiki-status"></div>
<?php wp_footer(); ?>
</body>
</html>
