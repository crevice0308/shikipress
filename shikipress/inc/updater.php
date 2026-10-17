<?php
/**
 * Native, opt-in-by-installation GitHub theme updates.
 *
 * Loaded by the active ShikiPress parent (including when a child theme is active).
 * Inactive themes cannot run their own updater. No token, telemetry, custom
 * installer, or changes to WordPress's automatic-update preferences are used.
 */
defined( 'ABSPATH' ) || exit;

final class ShikiPress_Updater {
    const URI = 'https://github.com/crevice0308/shikipress';
    const API = 'https://api.github.com/repos/crevice0308/shikipress/releases/latest';
    const CACHE = 'shikipress_github_release_v1';
    const MAX_PACKAGE = 10485760; // 10 MiB compressed; normal releases are much smaller.
    const MAX_EXPANDED = 31457280;

    /** Only the exact theme and Update URI may claim this provider. */
    public static function filter_update( $update, $theme_data, $theme_stylesheet, $locales ) {
        if ( 'shikipress' !== $theme_stylesheet || ! is_array( $theme_data ) ||
            self::URI !== ( $theme_data['UpdateURI'] ?? '' ) ) {
            return $update;
        }
        // Network-wide update transients can be consumed on a site where this
        // theme's verifier is not loaded. A theme-only updater cannot secure it.
        if ( is_multisite() || 'shikipress' !== get_template() ) {
            return false;
        }
        $release = self::release();
        $current = $theme_data['Version'] ?? '';
        if ( is_wp_error( $release ) || ! self::version( $current ) ||
            ! version_compare( $release['version'], $current, '>' ) ) {
            return false;
        }
        // Keep requirements visible in WordPress's native update UI. Both core
        // and pre_download() block installation on incompatible hosts.
        return array(
            'id'           => self::URI,
            'theme'        => 'shikipress',
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested'       => $release['tested'],
            'autoupdate'   => false,
        );
    }

    /** GitHub forbids framing; keep its release details a normal external link. */
    public static function prepare_themes( $themes ) {
        if ( ! is_array( $themes ) || is_multisite() || 'shikipress' !== get_template() ||
            ! class_exists( 'WP_HTML_Tag_Processor', false ) ||
            self::URI !== wp_get_theme( 'shikipress' )->get( 'UpdateURI' ) ) {
            return $themes;
        }
        foreach ( $themes as $key => $theme ) {
            if ( ! is_array( $theme ) || 'shikipress' !== ( $theme['id'] ?? null ) ||
                true !== ( $theme['hasUpdate'] ?? null ) || ! is_string( $theme['update'] ?? null ) ||
                strlen( $theme['update'] ) > 32768 ) {
                continue;
            }
            $html = new WP_HTML_Tag_Processor( $theme['update'] );
            while ( $html->next_tag( 'A' ) ) {
                $href = $html->get_attribute( 'href' );
                if ( ! is_string( $href ) || ! $html->has_class( 'thickbox' ) ||
                    ! $html->has_class( 'open-plugin-details-modal' ) ||
                    ! preg_match( '~^(' . preg_quote( self::URI, '~' ) . '/releases/tag/v([^/?#]+))(?:\?TB_iframe=true&width=1024&height=800)?$~D', $href, $match ) ||
                    ! self::version( $match[2] ) ) {
                    continue;
                }
                $html->set_attribute( 'href', $match[1] );
                $html->remove_class( 'thickbox' );
                $html->remove_class( 'open-plugin-details-modal' );
            }
            // The tag processor changes only selected attributes, preserving
            // the native Update now anchor, its nonce, and the surrounding UI.
            $themes[ $key ]['update'] = $html->get_updated_html();
        }
        return $themes;
    }

    /** Remove this provider's stale offer before its code becomes inactive. */
    public static function switched_theme( $new_name, $new_theme ) {
        if ( 'shikipress' === $new_theme->get_template() ) {
            return; // Switching between the parent and a child keeps the verifier loaded.
        }
        $updates = get_site_transient( 'update_themes' );
        if ( ! is_object( $updates ) ) {
            return;
        }
        $changed = false;
        foreach ( array( 'response', 'no_update' ) as $field ) {
            if ( isset( $updates->$field ) && is_array( $updates->$field ) && isset( $updates->{$field}['shikipress'] ) ) {
                unset( $updates->{$field}['shikipress'] );
                $changed = true;
            }
        }
        if ( $changed ) {
            set_site_transient( 'update_themes', $updates );
        }
    }

    /** Stable SemVer only, with canonical decimal components. */
    private static function version( $value ) {
        return is_string( $value ) && strlen( $value ) <= 32 &&
            1 === preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $value );
    }

    private static function platform_version( $value ) {
        return is_string( $value ) && strlen( $value ) <= 32 &&
            1 === preg_match( '/^[1-9][0-9]*\.(0|[1-9][0-9]*)(?:\.(0|[1-9][0-9]*))?$/D', $value );
    }

    private static function error( $code = 'invalid_release' ) {
        return new WP_Error( 'shikipress_' . $code,
            __( 'ShikiPress の更新を安全に確認できないため停止しました。時間をおいて再確認するか、公式 GitHub リリースを確認してください。', 'shikipress' ) );
    }

    /** Check normalized cached data as well as newly received metadata. */
    private static function valid_data( $data ) {
        if ( ! is_array( $data ) || 1 !== ( $data['schema'] ?? null ) ||
            'shikipress' !== ( $data['theme'] ?? null ) || ! self::version( $data['version'] ?? null ) ) {
            return false;
        }
        foreach ( array( 'requires', 'requires_php', 'tested' ) as $field ) {
            if ( ! self::platform_version( $data[ $field ] ?? null ) ) {
                return false;
            }
        }
        $base = self::URI . '/releases/';
        return version_compare( $data['requires'], '6.6', '>=' ) &&
            version_compare( $data['requires_php'], '8.2', '>=' ) &&
            version_compare( $data['tested'], $data['requires'], '>=' ) &&
            $base . 'tag/v' . $data['version'] === ( $data['url'] ?? '' ) &&
            $base . 'download/v' . $data['version'] . '/shikipress-' . $data['version'] . '.zip' === ( $data['package'] ?? '' ) &&
            is_string( $data['sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) &&
            is_int( $data['size'] ?? null ) && $data['size'] > 0 && $data['size'] <= self::MAX_PACKAGE;
    }

    /** Never send the site's URL, locale, email, credentials, or cookies. */
    private static function request( $url, $limit, $asset = false, $filename = null ) {
        $deadline = microtime( true ) + ( $filename ? 60 : 5 );
        $original = $url;
        for ( $hop = 0; $hop <= 3; ++$hop ) {
            $parts = wp_parse_url( $url );
            if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ||
                isset( $parts['user'] ) ||
                isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
                return self::error( 'unsafe_url' );
            }
            if ( 0 === $hop ) {
                if ( ( ! $asset && self::API !== $url ) || ( $asset &&
                    1 !== preg_match( '~^https://github\.com/crevice0308/shikipress/releases/download/v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)/(?:shikipress-update\.json|shikipress-[0-9]+\.[0-9]+\.[0-9]+\.zip)$~D', $url ) ) ) {
                    return self::error( 'unsafe_url' );
                }
            } elseif ( ! $asset || ! in_array( $parts['host'] ?? '',
                array( 'release-assets.githubusercontent.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com' ), true ) ) {
                return self::error( 'unsafe_redirect' );
            }
            $remaining = $deadline - microtime( true );
            if ( $remaining <= 0 ) {
                return self::error( 'timeout' );
            }
            $args = array(
                'timeout'             => $remaining,
                'redirection'         => 0, // Validate every redirect before following it.
                'sslverify'           => true,
                'reject_unsafe_urls'  => true,
                'limit_response_size' => $limit + 1,
                'user-agent'          => 'ShikiPress-Updater/1',
                'cookies'             => array(),
                'headers'             => array( 'Accept' => $asset ? 'application/octet-stream' : 'application/vnd.github+json' ),
            );
            if ( ! $asset ) {
                $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
            }
            if ( $filename ) {
                $args['stream'] = true;
                $args['filename'] = $filename;
            }
            $response = wp_safe_remote_get( $url, $args );
            if ( is_wp_error( $response ) ) {
                return self::error( 'http_failed' );
            }
            $status = wp_remote_retrieve_response_code( $response );
            if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
                $url = wp_remote_retrieve_header( $response, 'location' );
                if ( ! is_string( $url ) || '' === $url || $url === $original ) {
                    return self::error( 'unsafe_redirect' );
                }
                continue;
            }
            if ( 200 !== $status ) {
                return self::error( 'http_status' );
            }
            if ( $filename ) {
                clearstatcache( true, $filename );
                $length = filesize( $filename );
                return false !== $length && $length <= $limit ? $response : self::error( 'oversize' );
            }
            $body = wp_remote_retrieve_body( $response );
            return is_string( $body ) && strlen( $body ) <= $limit ? $body : self::error( 'oversize' );
        }
        return self::error( 'redirect_limit' );
    }

    /** Fresh GitHub data is mandatory again at installation time. */
    public static function release( $refresh = false ) {
        if ( ! $refresh ) {
            $cached = get_site_transient( self::CACHE );
            if ( is_array( $cached ) && true === ( $cached['error'] ?? false ) ) {
                return self::error();
            }
            if ( is_array( $cached ) && self::valid_data( $cached['data'] ?? null ) &&
                is_string( $cached['mac'] ?? null ) && hash_equals(
                    hash_hmac( 'sha256', wp_json_encode( $cached['data'] ), wp_salt( 'auth' ) ), $cached['mac'] ) ) {
                return $cached['data'];
            }
        }
        $data = self::fetch_release();
        if ( is_wp_error( $data ) ) {
            set_site_transient( self::CACHE, array( 'error' => true ), 15 * MINUTE_IN_SECONDS );
        } else {
            set_site_transient( self::CACHE, array(
                'data' => $data,
                'mac' => hash_hmac( 'sha256', wp_json_encode( $data ), wp_salt( 'auth' ) ),
            ), 6 * HOUR_IN_SECONDS );
        }
        return $data;
    }

    private static function fetch_release() {
        $body = self::request( self::API, 524288 );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $release = json_decode( $body, true, 32 );
        if ( ! is_array( $release ) || false !== ( $release['draft'] ?? null ) ||
            false !== ( $release['prerelease'] ?? null ) || ! is_string( $release['tag_name'] ?? null ) ||
            'v' !== substr( $release['tag_name'], 0, 1 ) || ! self::version( substr( $release['tag_name'], 1 ) ) ) {
            return self::error();
        }
        $version = substr( $release['tag_name'], 1 );
        if ( self::URI . '/releases/tag/v' . $version !== ( $release['html_url'] ?? '' ) ||
            ! is_array( $release['assets'] ?? null ) || count( $release['assets'] ) > 100 ) {
            return self::error();
        }
        $wanted = array( 'shikipress-update.json' => null, 'shikipress-' . $version . '.zip' => null );
        foreach ( $release['assets'] as $asset ) {
            if ( ! is_array( $asset ) || ! is_string( $asset['name'] ?? null ) || ! array_key_exists( $asset['name'], $wanted ) ) {
                continue;
            }
            $name = $asset['name'];
            if ( null !== $wanted[ $name ] || 'uploaded' !== ( $asset['state'] ?? '' ) ||
                self::URI . '/releases/download/v' . $version . '/' . $name !== ( $asset['browser_download_url'] ?? '' ) ||
                ! is_int( $asset['size'] ?? null ) || $asset['size'] <= 0 || $asset['size'] > self::MAX_PACKAGE ||
                ! is_string( $asset['digest'] ?? null ) || 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/D', $asset['digest'] ) ) {
                return self::error();
            }
            $wanted[ $name ] = $asset;
        }
        $manifest = $wanted['shikipress-update.json'];
        $zip = $wanted['shikipress-' . $version . '.zip'];
        if ( ! $manifest || ! $zip || $manifest['size'] > 16384 ) {
            return self::error();
        }
        $body = self::request( $manifest['browser_download_url'], 16384, true );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        if ( strlen( $body ) !== $manifest['size'] || ! hash_equals( substr( $manifest['digest'], 7 ), hash( 'sha256', $body ) ) ) {
            return self::error( 'metadata_integrity' );
        }
        $data = json_decode( $body, true, 16 );
        if ( ! is_array( $data ) ) {
            return self::error();
        }
        $data['url'] = $release['html_url'];
        if ( ! self::valid_data( $data ) || $version !== $data['version'] || $zip['size'] !== $data['size'] ||
            ! hash_equals( substr( $zip['digest'], 7 ), $data['sha256'] ) || $zip['browser_download_url'] !== $data['package'] ) {
            return self::error();
        }
        // Do not retain arbitrary fields supplied by the remote JSON.
        return array_intersect_key( $data, array_flip( array(
            'schema', 'theme', 'version', 'requires', 'requires_php', 'tested', 'package', 'sha256', 'size', 'url',
        ) ) );
    }

    /** Protect single, bulk and automatic updates without taking over ZIP imports. */
    public static function pre_download( $reply, $package, $upgrader, $hook_extra ) {
        if ( ! ( $upgrader instanceof Theme_Upgrader ) || ! is_array( $hook_extra ) ||
            'shikipress' !== ( $hook_extra['theme'] ?? '' ) ||
            ( isset( $hook_extra['type'] ) && 'theme' !== $hook_extra['type'] ) ||
            ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) ) {
            return $reply;
        }
        if ( is_multisite() ) {
            return new WP_Error( 'shikipress_multisite',
                __( 'マルチサイトでは GitHub の公式 ZIP を使い、ネットワーク管理画面から手動で ShikiPress を更新してください。', 'shikipress' ) );
        }
        // Theme_Upgrader::bulk_upgrade supplies only 'theme' per item. Manual
        // imports use action=install and no target theme, and stay untouched.
        if ( is_wp_error( $reply ) ) {
            return $reply;
        }
        $installed = wp_get_theme( 'shikipress' );
        if ( self::URI !== $installed->get( 'UpdateURI' ) || ! self::version( $installed->get( 'Version' ) ) ) {
            return self::error( 'theme_identity' );
        }
        $data = self::release( true ); // Never trust update_themes or our persistent cache to authorize a ZIP.
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( ! is_string( $package ) || $data['package'] !== $package ||
            ! version_compare( $data['version'], $installed->get( 'Version' ), '>' ) ) {
            return self::error( 'package_mismatch' );
        }
        if ( ! is_wp_version_compatible( $data['requires'] ) || ! is_php_version_compatible( $data['requires_php'] ) ) {
            return new WP_Error( 'shikipress_incompatible', sprintf(
                /* translators: 1: required WordPress version, 2: required PHP version. */
                __( 'この ShikiPress 更新には WordPress %1$s 以上、PHP %2$s 以上が必要です。先に動作環境を更新してください。', 'shikipress' ),
                $data['requires'], $data['requires_php'] ) );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = wp_tempnam( 'shikipress-update.zip' );
        if ( ! $file ) {
            return self::error( 'temporary_file' );
        }
        $response = self::request( $package, $data['size'], true, $file );
        clearstatcache( true, $file );
        if ( is_wp_error( $response ) || $data['size'] !== filesize( $file ) ||
            ! hash_equals( $data['sha256'], (string) hash_file( 'sha256', $file ) ) ) {
            wp_delete_file( $file );
            return is_wp_error( $response ) ? $response : self::error( 'package_integrity' );
        }
        $valid = self::validate_archive( $file, $data );
        if ( is_wp_error( $valid ) ) {
            wp_delete_file( $file );
            return $valid;
        }
        // Core owns extraction, rollback, destination replacement and cleanup.
        return $file;
    }

    /** Reject ambiguous ZIP extra fields (ZIP64 and alternate Unicode paths). */
    private static function zip_extra_safe( $extra ) {
        while ( '' !== $extra ) {
            if ( strlen( $extra ) < 4 ) {
                return false;
            }
            $field = unpack( 'vid/vlength', substr( $extra, 0, 4 ) );
            if ( in_array( $field['id'], array( 0x0001, 0x7075 ), true ) || strlen( $extra ) < 4 + $field['length'] ) {
                return false;
            }
            $extra = substr( $extra, 4 + $field['length'] );
        }
        return true;
    }

    /**
     * Inspect central AND local records before any extraction. This also checks
     * Unix symlink attributes when PHP's ZipArchive extension is unavailable.
     * ZIP64, encrypted archives and non-store/deflate methods are unnecessary
     * for the small release packages and deliberately fail closed.
     */
    public static function validate_archive( $file, $data ) {
        $zip = file_get_contents( $file, false, null, 0, self::MAX_PACKAGE + 1 );
        if ( ! is_string( $zip ) || strlen( $zip ) < 22 || strlen( $zip ) > self::MAX_PACKAGE ) {
            return self::error( 'archive' );
        }
        $end = strrpos( $zip, "PK\x05\x06" );
        if ( false === $end || $end + 22 > strlen( $zip ) ) {
            return self::error( 'archive' );
        }
        $e = unpack( 'vdisk/vstart/vdisk_count/vcount/Vsize/Voffset/vcomment', substr( $zip, $end + 4, 18 ) );
        if ( 0 !== $e['disk'] || 0 !== $e['start'] || $e['disk_count'] !== $e['count'] ||
            $e['count'] < 1 || $e['count'] > 1500 || $e['offset'] + $e['size'] !== $end ||
            $end + 22 + $e['comment'] !== strlen( $zip ) ) {
            return self::error( 'archive' );
        }
        $position = $e['offset'];
        $entries = array();
        $paths = array();
        $expanded = 0;
        for ( $i = 0; $i < $e['count']; ++$i ) {
            if ( $position + 46 > $end || "PK\x01\x02" !== substr( $zip, $position, 4 ) ) {
                return self::error( 'archive' );
            }
            $h = unpack( 'vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr( $zip, $position + 4, 42 ) );
            $length = 46 + $h['name'] + $h['extra'] + $h['comment'];
            $name = substr( $zip, $position + 46, $h['name'] );
            $mode = ( $h['external'] >> 16 ) & 0170000;
            $directory = str_ends_with( $name, '/' );
            if ( $position + $length > $end || $h['name'] < 1 || $h['name'] > 240 ||
                0 !== $h['disk'] || $h['needed'] > 20 ||
                0 !== ( $h['flags'] & ~( 8 === $h['method'] ? 0x080e : 0x0808 ) ) ||
                ! in_array( $h['method'], array( 0, 8 ), true ) ||
                ! in_array( $mode, array( 0, 0100000, 0040000 ), true ) ||
                ( 0040000 === $mode && ! $directory ) || ( 0100000 === $mode && $directory ) ||
                ( ( $h['external'] & 0x10 ) && ! $directory ) ||
                1 !== preg_match( '~^shikipress/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]*$~D', $name ) ||
                preg_match( '~(?:^|/)(?:\.[^/]*|[^/]*\.|(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.[^/]*)?)(?:/|$)~i', $name ) ||
                ! self::zip_extra_safe( substr( $zip, $position + 46 + $h['name'], $h['extra'] ) ) ) {
                return self::error( 'archive_path' );
            }
            $key = strtolower( rtrim( $name, '/' ) );
            if ( isset( $paths[ $key ] ) || $h['size'] > self::MAX_PACKAGE || ( $directory && 0 !== $h['size'] ) ) {
                return self::error( 'archive_path' );
            }
            $expanded += $h['size'];
            if ( $expanded > self::MAX_EXPANDED ) {
                return self::error( 'archive_size' );
            }
            $paths[ $key ] = array( 'filename' => rtrim( $name, '/' ), 'directory' => $directory, 'size' => $h['size'] );
            $h['filename'] = $name;
            $entries[] = $h;
            $position += $length;
        }
        if ( $position !== $end ) {
            return self::error( 'archive' );
        }
        foreach ( $paths as $name => $info ) {
            $parent = dirname( $name );
            while ( '.' !== $parent ) {
                if ( isset( $paths[ $parent ] ) && ! $paths[ $parent ]['directory'] ) {
                    return self::error( 'archive_path' );
                }
                $parent = dirname( $parent );
            }
        }
        usort( $entries, static function ( $a, $b ) { return $a['offset'] <=> $b['offset']; } );
        $position = 0;
        foreach ( $entries as $h ) {
            if ( $h['offset'] !== $position || $position + 30 > $e['offset'] || "PK\x03\x04" !== substr( $zip, $position, 4 ) ) {
                return self::error( 'archive_local' );
            }
            $local = unpack( 'vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr( $zip, $position + 4, 26 ) );
            $header_end = $position + 30 + $local['name'] + $local['extra'];
            if ( $header_end + $h['compressed'] > $e['offset'] ||
                $local['needed'] !== $h['needed'] || $local['flags'] !== $h['flags'] || $local['method'] !== $h['method'] ||
                $local['name'] !== $h['name'] || $h['filename'] !== substr( $zip, $position + 30, $local['name'] ) ||
                ! self::zip_extra_safe( substr( $zip, $position + 30 + $local['name'], $local['extra'] ) ) ) {
                return self::error( 'archive_local' );
            }
            foreach ( array( 'crc', 'compressed', 'size' ) as $field ) {
                if ( $local[ $field ] !== $h[ $field ] && ( ! ( $h['flags'] & 8 ) || 0 !== $local[ $field ] ) ) {
                    return self::error( 'archive_local' );
                }
            }
            // Do not trust claimed uncompressed sizes. PclZip's eventual
            // extraction is safe only after every actual stream is bounded and
            // its length/CRC agrees with both records. No archive PHP is run.
            $payload = substr( $zip, $header_end, $h['compressed'] );
            if ( 8 === $h['method'] ) {
                if ( ! function_exists( 'gzinflate' ) ) {
                    return self::error( 'archive_compression' );
                }
                $payload = @gzinflate( $payload, $h['size'] + 1 );
            }
            if ( ! is_string( $payload ) || strlen( $payload ) !== $h['size'] ||
                hash( 'crc32b', $payload ) !== bin2hex( pack( 'N', $h['crc'] ) ) ) {
                return self::error( 'archive_integrity' );
            }
            unset( $payload );
            $position = $header_end + $h['compressed'];
            if ( $h['flags'] & 8 ) {
                if ( "PK\x07\x08" === substr( $zip, $position, 4 ) ) {
                    $position += 4;
                }
                if ( $position + 12 > $e['offset'] ) {
                    return self::error( 'archive_local' );
                }
                $descriptor = unpack( 'Vcrc/Vcompressed/Vsize', substr( $zip, $position, 12 ) );
                if ( $descriptor['crc'] !== $h['crc'] || $descriptor['compressed'] !== $h['compressed'] || $descriptor['size'] !== $h['size'] ) {
                    return self::error( 'archive_local' );
                }
                $position += 12;
            }
        }
        if ( $position !== $e['offset'] ) {
            return self::error( 'archive_local' );
        }
        foreach ( array( 'style.css', 'functions.php', 'index.php', 'theme.json', 'inc/core.php', 'inc/updater.php' ) as $required ) {
            $path = 'shikipress/' . $required;
            if ( ! isset( $paths[ $path ] ) || $paths[ $path ]['filename'] !== $path || $paths[ $path ]['directory'] || $paths[ $path ]['size'] < 1 ) {
                return self::error( 'archive_missing' );
            }
        }
        if ( $paths['shikipress/style.css']['size'] > 65536 || $paths['shikipress/functions.php']['size'] > 65536 ) {
            return self::error( 'archive_headers' );
        }
        unset( $zip );
        $texts = array();
        if ( class_exists( 'ZipArchive', false ) && apply_filters( 'unzip_file_use_ziparchive', true ) ) {
            $archive = new ZipArchive();
            if ( true === $archive->open( $file, ZipArchive::CHECKCONS ) ) {
                foreach ( array( 'style.css', 'functions.php' ) as $name ) {
                    $texts[ $name ] = $archive->getFromName( 'shikipress/' . $name, 8192 );
                }
                $archive->close();
            }
        } else {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            $archive = new PclZip( $file );
            foreach ( array( 'style.css', 'functions.php' ) as $name ) {
                $selected = $archive->extract( PCLZIP_OPT_BY_NAME, 'shikipress/' . $name, PCLZIP_OPT_EXTRACT_AS_STRING );
                if ( is_array( $selected ) && 1 === count( $selected ) &&
                    'shikipress/' . $name === $selected[0]['filename'] && isset( $selected[0]['content'] ) ) {
                    $texts[ $name ] = substr( $selected[0]['content'], 0, 8192 );
                }
            }
        }
        if ( ! is_string( $texts['style.css'] ?? null ) || ! is_string( $texts['functions.php'] ?? null ) ) {
            return self::error( 'archive_headers' );
        }
        $style = $texts['style.css'];
        $style = str_replace( "\r", "\n", $style );
        foreach ( array( 'Theme Name' => 'ShikiPress Ultimate', 'Version' => $data['version'],
            'Update URI' => self::URI, 'Requires at least' => $data['requires'],
            'Requires PHP' => $data['requires_php'], 'Text Domain' => 'shikipress' ) as $header => $expected ) {
            if ( ! preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $style, $match ) ||
                $expected !== trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) ) ) {
                return self::error( 'archive_headers' );
            }
        }
        if ( preg_match( '/^[ \t\/*#@]*Template:\s*\S/mi', $style ) ||
            1 !== preg_match_all( '/^[ \t]*define\(\s*[\'"]SHIKI_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/m', $texts['functions.php'], $versions ) ||
            $data['version'] !== $versions[1][0] ) {
            return self::error( 'archive_headers' );
        }
        return true;
    }
}

add_filter( 'update_themes_github.com', array( 'ShikiPress_Updater', 'filter_update' ), 10, 4 );
add_filter( 'upgrader_pre_download', array( 'ShikiPress_Updater', 'pre_download' ), 10, 4 );
add_action( 'switch_theme', array( 'ShikiPress_Updater', 'switched_theme' ), 10, 2 );
add_filter( 'wp_prepare_themes_for_js', array( 'ShikiPress_Updater', 'prepare_themes' ) );
