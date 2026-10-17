#!/usr/bin/env bash
# Ubuntu/Debian: sudo apt-get install php-cli php-mysql php-curl php-xml php-mbstring php-gd php-zip mariadb-server mariadb-client curl unzip
set -euo pipefail
source "$(dirname "$0")/dev-common.sh"
for executable in php mariadbd mariadb mariadb-install-db curl; do
    command -v "$executable" >/dev/null || { printf 'Missing dependency: %s\n' "$executable" >&2; exit 1; }
done
mkdir -p "$SHIKI_DEV_STATE/downloads"
if [[ ! -f "$SHIKI_DEV_STATE/wp-cli.phar" ]]; then
    curl -fsSL --retry 2 --max-time 120 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$SHIKI_DEV_STATE/wp-cli.phar"
fi
if [[ ! -f "$SHIKI_WP_ROOT/wp-load.php" ]]; then
    # Pin defaults to the version actually used for the release tests. Override explicitly for later validation.
    SHIKI_WP_VERSION="${SHIKI_WP_VERSION:-7.1.3}"
    curl -fsSL --retry 2 --max-time 120 "https://wordpress.org/wordpress-$SHIKI_WP_VERSION.tar.gz" -o "$SHIKI_DEV_STATE/downloads/wordpress.tar.gz"
    tar -xzf "$SHIKI_DEV_STATE/downloads/wordpress.tar.gz" -C "$SHIKI_DEV_STATE"
fi
shiki_start_database
if [[ ! -f "$SHIKI_DEV_STATE/credentials.json" ]]; then
    php -r '$p=["admin_user"=>"shiki_admin","admin_password"=>bin2hex(random_bytes(20)),"database_password"=>bin2hex(random_bytes(20))];file_put_contents($argv[1],json_encode($p));' "$SHIKI_DEV_STATE/credentials.json"
    chmod 600 "$SHIKI_DEV_STATE/credentials.json"
fi
[[ "$SHIKI_DB_NAME" =~ ^[a-zA-Z0-9_]+$ ]] || { printf 'Invalid development database name.\n' >&2; exit 1; }
shiki_db -e "CREATE DATABASE IF NOT EXISTS \`$SHIKI_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if [[ ! -f "$SHIKI_WP_ROOT/wp-config.php" ]]; then
    # Values are read privately by PHP; they do not enter shell history or logs.
    php -r '$p=json_decode(file_get_contents($argv[1]),true);$c=file_get_contents($argv[2]."/wp-config-sample.php");$c=str_replace(["database_name_here","username_here","password_here","localhost"],[$argv[5],"root","","127.0.0.1:".$argv[3]],$c);$c=str_replace("define( '\''WP_DEBUG'\'', false );","define( '\''WP_DEBUG'\'', true );\ndefine( '\''WP_DEBUG_DISPLAY'\'', false );\ndefine( '\''WP_DEBUG_LOG'\'', ".var_export($argv[4]."/php-errors.log",true)." );\ndefine( '\''SHIKI_TEST_ENV'\'', true );\ndefine( '\''WP_ENVIRONMENT_TYPE'\'', '\''local'\'' );\ndefine( '\''DISABLE_WP_CRON'\'', true );",$c);$c=preg_replace_callback("/put your unique phrase here/",fn()=>bin2hex(random_bytes(32)),$c);file_put_contents($argv[2]."/wp-config.php",$c);' "$SHIKI_DEV_STATE/credentials.json" "$SHIKI_WP_ROOT" "$SHIKI_DB_PORT" "$SHIKI_DEV_STATE" "$SHIKI_DB_NAME"
fi
if ! shiki_wp core is-installed >/dev/null 2>&1; then
    # This temporary private installer avoids passing the password as a command argument.
    SHIKI_INSTALL_STATE="$SHIKI_DEV_STATE" SHIKI_INSTALL_URL="$SHIKI_DEV_URL" php -r '$_SERVER["HTTP_HOST"]=parse_url(getenv("SHIKI_INSTALL_URL"),PHP_URL_HOST).":".parse_url(getenv("SHIKI_INSTALL_URL"),PHP_URL_PORT);$_SERVER["SERVER_NAME"]=parse_url(getenv("SHIKI_INSTALL_URL"),PHP_URL_HOST);$_SERVER["REQUEST_URI"]="/";define("WP_INSTALLING",true);require getenv("SHIKI_INSTALL_STATE")."/wordpress/wp-load.php";require_once ABSPATH."wp-admin/includes/upgrade.php";$p=json_decode(file_get_contents(getenv("SHIKI_INSTALL_STATE")."/credentials.json"),true);add_filter("pre_wp_mail","__return_false");wp_install("季節のしおり",$p["admin_user"],"admin@example.invalid",false,"",$p["admin_password"]);wp_delete_post(1,true);wp_delete_post(2,true);update_option("home",getenv("SHIKI_INSTALL_URL"));update_option("siteurl",getenv("SHIKI_INSTALL_URL"));'
fi
shiki_wp option update blogdescription '四季の暮らしを、心地よく。'
shiki_wp option update timezone_string Asia/Tokyo
shiki_wp option update blog_public 0
shiki_wp rewrite structure '/%postname%/' --hard
if [[ "${SHIKI_SKIP_THEME:-0}" != 1 ]]; then
mkdir -p "$SHIKI_WP_ROOT/wp-content/themes"
if [[ -L "$SHIKI_WP_ROOT/wp-content/themes/shikipress" ]]; then
    ln -sfn "$SHIKI_PROJECT_ROOT/shikipress" "$SHIKI_WP_ROOT/wp-content/themes/shikipress"
elif [[ ! -e "$SHIKI_WP_ROOT/wp-content/themes/shikipress" ]]; then
    ln -s "$SHIKI_PROJECT_ROOT/shikipress" "$SHIKI_WP_ROOT/wp-content/themes/shikipress"
fi
if [[ -f "$SHIKI_PROJECT_ROOT/shikipress/style.css" && -f "$SHIKI_PROJECT_ROOT/shikipress/index.php" ]]; then shiki_wp theme activate shikipress; fi
fi
shiki_wp plugin deactivate --all
printf 'Development WordPress ready. URL: %s\nPrivate credentials: %s/credentials.json\n' "$SHIKI_DEV_URL" "$SHIKI_DEV_STATE"
php -v | head -1
shiki_wp core version
mariadbd --version
