<?php
/** ShikiPress Ultimate bootstrap. Modified 2026-10-19 for 1.1.2. */
defined( 'ABSPATH' ) || exit;
define( 'SHIKI_VERSION', '1.1.2' );
define( 'SHIKI_DIR', get_template_directory() );
define( 'SHIKI_URL', get_template_directory_uri() );
require_once SHIKI_DIR . '/inc/core.php';
foreach ( array( 'updater', 'automation', 'ads', 'seo', 'tools', 'compat', 'frontend', 'captcha' ) as $shiki_module ) {
    if ( is_readable( SHIKI_DIR . '/inc/' . $shiki_module . '.php' ) ) {
        require_once SHIKI_DIR . '/inc/' . $shiki_module . '.php';
    }
}
