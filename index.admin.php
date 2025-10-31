<?php
require __DIR__ . '/vendor/autoload.php';

$f3 = \Base::instance();
$f3->config('config/globals.cfg');
$f3->config('config/routes.admin.ini');           // NEW: admin routes
session_set_cookie_params(['domain' => '.nominatepro.com', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
$f3->run();
