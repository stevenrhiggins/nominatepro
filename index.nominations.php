<?php

use DB\SQL;

require __DIR__ . '/vendor/autoload.php';


$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$f3 = \Base::instance();

$f3->set('UI', '/home/nominat2/public_html/ui/views/');

try {
    $dsn = "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4";
    $db = new SQL(
        $dsn,
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD'],
        [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]
    );
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$f3->set('server', 'https://nominations.nominatepro.com');

$f3->set('DB', $db);

$f3->set('MAILGUN_API_KEY', $_ENV['MAILGUN_API_KEY']);
$f3->set('MAILGUN_API_KEY', $_ENV['MAILGUN_DOMAIN']);

$f3 = \Base::instance();

$f3->config('config/globals.cfg');
$f3->config('config/routes.nominations.ini');     // NEW: nominations routes


//$f3->route(
//    'GET /nomination/@award=[a-f0-9]{40}/verify-email',
//    'App\Controllers\NominationsController->verifyEmail'
//);

session_set_cookie_params(['domain'=>'.nominatepro.com','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);

$f3->run();
