<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
session_start();

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once 'AliyunTrafficCheck.php';
require_once 'src/HttpRouter.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$app = new AliyunTrafficCheck();
$router = new HttpRouter($app, __DIR__);
$router->dispatch($_GET['action'] ?? 'view');
