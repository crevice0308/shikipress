<?php if (!defined('ABSPATH')) { exit; } $shiki_context = shiki_frontend_context(); ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?> id="page-top">
<?php wp_body_open(); ?>
<a class="skip-link" href="#main-content">本文へ移動</a>
<header class="site-header" data-modal-inert>
    <div class="header-note"><span>A JOURNAL FOR EVERY SEASON</span><span><?php echo esc_html($shiki_context['label']); ?>のしおり <span aria-hidden="true">—</span> 暮らしに、季節の余白を。</span></div>
    <div class="header-main wrap">
        <div class="brand">
            <?php if (has_custom_logo()) { the_custom_logo(); } else { ?>
                <a class="brand-link" href="<?php echo esc_url(home_url('/')); ?>" rel="home"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span class="brand-name"><?php bloginfo('name'); ?><small>SHIKI NO SHIORI</small></span></a>
            <?php } ?>
        </div>
        <nav class="desktop-navigation" aria-label="メインナビゲーション">
            <?php if (has_nav_menu('primary')) { wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'menu_class' => 'primary-menu', 'depth' => 1]); } else { ?>
                <a href="<?php echo esc_url(home_url('/')); ?>" <?php if (is_front_page()) { echo 'aria-current="page"'; } ?>>ホーム</a>
                <a href="#nav-categories" data-panel="nav-categories" aria-controls="nav-categories" aria-expanded="false">四季の暮らし</a>
                <a href="#nav-pages" data-panel="nav-pages" aria-controls="nav-pages" aria-expanded="false">このサイトについて</a>
            <?php } ?>
        </nav>
        <div class="header-actions"><button class="icon-button theme-toggle" type="button" aria-label="ダークモードに切り替える" hidden><?php echo shiki_icon('sun'); ?></button><a class="icon-button" href="#nav-search" data-panel="nav-search" aria-controls="nav-search" aria-expanded="false" aria-label="検索を開く"><?php echo shiki_icon('search'); ?></a></div>
    </div>
</header>
