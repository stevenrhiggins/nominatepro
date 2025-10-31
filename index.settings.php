<?php
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$f3 = \Base::instance();

// ---- F3 template root + temp ----
$f3->set('UI', '/ui/views');           // F3 will resolve templates relative to this


$f3->set('UI', '/home/nominat2/public_html/ui/views/');

try {
    $dsn = "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4";
    $db = new \DB\SQL(
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

$f3->set('DB', $db);

$f3->config('config/globals.cfg');                 // your existing base config(s)
$f3->config('config/routes.settings.ini');        // NEW: settings-only routes
// (Optional) shared session cookie across subdomains
session_set_cookie_params(['domain' => '.nominatepro.com', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
$f3->run();
