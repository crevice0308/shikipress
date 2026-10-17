<?php if (!defined('ABSPATH')) { exit; } get_header(); ?>
<main id="main-content" class="wrap not-found" tabindex="-1" data-modal-inert><p class="eyebrow">PAGE NOT FOUND</p><div class="error-number" aria-hidden="true">4<span><?php echo shiki_icon('leaf'); ?></span>4</div><h1>そのしおりは、見つかりませんでした。</h1><p>ページが移動したか、URLが変わったようです。<br>トップページや検索から、お探しのしおりへどうぞ。</p><a class="button button-primary" href="<?php echo esc_url(home_url('/')); ?>">トップページへ <?php echo shiki_icon('arrow'); ?></a><div class="not-found-search"><?php get_search_form(); ?></div></main>
<?php get_footer(); ?>
