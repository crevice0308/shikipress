<?php
/** PHP development server only: serve real assets, route WordPress permalinks. */
if (PHP_SAPI !== 'cli-server') { throw new RuntimeException('Development server only.'); }
$root = realpath($_SERVER['DOCUMENT_ROOT']);
if (!$root || !is_file(dirname($root).'/.owned-disposable-ci')) { http_response_code(403); exit; }
$path = realpath($root.'/'.ltrim(rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/'));
if ($path && str_starts_with($path, $root.'/') && is_file($path)) { return false; }
require $root.'/index.php';
