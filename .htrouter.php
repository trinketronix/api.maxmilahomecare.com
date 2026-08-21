<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($uri !== '/' && is_file(__DIR__ . $uri)) { return false; } // same rule as Apache: RewriteCond %{REQUEST_FILENAME} !-f
$_GET['_url'] = $_SERVER['REQUEST_URI'];
require_once __DIR__ . '/index.php';