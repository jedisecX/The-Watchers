#!/usr/bin/env bash
# Creates a disposable WordPress + SQLite install with RSS Media Hub active, for PHPUnit and E2E.
# Usage: tests/bin/install-wp-tests.sh <target-dir> [wp-version] [host:port]
# Requires: git, php (pdo_sqlite, dom, curl, sodium, gd, fileinfo).
# The site uses SQLite, so no MySQL server is needed. NEVER point tests at a real site.
set -euo pipefail
TARGET="${1:?target dir}"
WP_VERSION="${2:-6.8.3}"
HOST="${3:-localhost:8089}"
PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

mkdir -p "$TARGET"
cd "$TARGET"
[ -d wp-src ] || git clone -q --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git wp-src
[ -d sqlite-src ] || git clone -q --depth 1 https://github.com/WordPress/sqlite-database-integration.git sqlite-src

rm -rf site && cp -r wp-src site && rm -rf site/.git
SQ=site/wp-content/plugins/sqlite-database-integration
cp -rL sqlite-src/packages/plugin-sqlite-database-integration "$SQ"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$(pwd)/$SQ#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$SQ/db.copy" > site/wp-content/db.php
ln -sfn "$PLUGIN_DIR" site/wp-content/plugins/rss-media-hub

cat > site/wp-config.php <<CFG
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'wp.sqlite' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) { define( \$k, 'test-only-' . \$k ); }
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG

cat > site/router.php <<'ROUTER'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = __DIR__ . $path;
if ( '/' !== $path && is_file( $file ) ) { return false; }
if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) ) { require rtrim( $file, '/' ) . '/index.php'; return; }
require __DIR__ . '/index.php';
ROUTER

php -r '
define( "WP_INSTALLING", true );
$_SERVER["HTTP_HOST"] = $argv[2]; $_SERVER["REQUEST_URI"] = "/";
require $argv[1] . "/site/wp-load.php";
require_once ABSPATH . "wp-admin/includes/upgrade.php";
wp_install( "Media Hub Test", "admin", "admin@example.com", true, "", "admin-pass-123" );
update_option( "siteurl", "http://" . $argv[2] ); update_option( "home", "http://" . $argv[2] );
update_option( "permalink_structure", "/%postname%/" );
' "$(pwd)" "$HOST"
php -r '
$_SERVER["HTTP_HOST"] = $argv[2]; $_SERVER["REQUEST_URI"] = "/";
require $argv[1] . "/site/wp-load.php";
require_once ABSPATH . "wp-admin/includes/plugin.php";
$r = activate_plugin( "rss-media-hub/rss-media-hub.php" );
if ( is_wp_error( $r ) ) { fwrite( STDERR, $r->get_error_message() . "\n" ); exit( 1 ); }
' "$(pwd)" "$HOST"
cp site/wp-content/database/wp.sqlite pristine.sqlite
echo "Ready: RMH_TEST_WP_PATH=$(pwd)/site  (admin / admin-pass-123, serve with: php -S $HOST -t site site/router.php)"
