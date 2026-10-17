<?php
/** Development-only front controller for the loopback PHP server. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($_SERVER['DOCUMENT_ROOT'] . rawurldecode($path));
$root = realpath($_SERVER['DOCUMENT_ROOT']);
$theme = realpath(dirname(__DIR__) . '/shikipress');
$local_file = $file && (str_starts_with($file, $root . DIRECTORY_SEPARATOR) || ($theme && str_starts_with($path, '/wp-content/themes/shikipress/') && str_starts_with($file, $theme . DIRECTORY_SEPARATOR)));
if ($path !== '/' && $local_file && is_file($file)) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
