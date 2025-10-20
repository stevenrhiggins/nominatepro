<?php
declare(strict_types=1);

$DEBUG = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $DEBUG ? '1' : '0');
error_reporting($DEBUG ? E_ALL : 0);

/* Locate project root even if docroot is nested */
$CANDIDATES = [__DIR__, dirname(__DIR__), dirname(dirname(__DIR__))];
$ROOT = null;
foreach ($CANDIDATES as $c) {
    if (is_file($c . '/vendor/autoload.php')) { $ROOT = $c; break; }
}
if (!$ROOT) {
    http_response_code(500);
    exit("Composer autoloader not found");
}

/* Composer + Fat-Free */
require_once $ROOT . '/vendor/autoload.php';
if (is_file($ROOT.'/vendor/bcosca/fatfree-core/base.php')) {
    require_once $ROOT.'/vendor/bcosca/fatfree-core/base.php';
} else {
    require_once $ROOT.'/vendor/bcosca/fatfree/lib/base.php';
}

/* Fallback autoloader only if Composer isn't mapping App\ */
$psr4 = @include $ROOT.'/vendor/composer/autoload_psr4.php';
if (!is_array($psr4) || !isset($psr4['App\\'])) {
    spl_autoload_register(function ($class) use ($ROOT) {
        if (strncmp($class, 'App\\', 4) === 0) {
            $relative = substr($class, 4);
            $path = $ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) require $path;
        }
    });
}

/* Boot F3 */
// public/index.php (bootstrap)
$f3 = Base::instance();

$f3->config('config/global.ini');

$f3->set('DEBUG', $DEBUG ? 3 : 0);

/* Non-namespaced helpers (e.g., renderHtml, Flash shim) */
$f3->set('AUTOLOAD', $ROOT.'/app/;'.$ROOT.'/app/Support/');

/* Config */
$f3->config($ROOT.'/config/globals.cfg');

/* DB (utf8mb4, strict errors) */
try {
    foreach (['DB_NAME','DB_USERNAME','DB_PASSWORD'] as $k) {
        if (!$f3->exists($k)) throw new RuntimeException("Missing {$k} in globals.cfg");
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $f3->get('DB_HOST') ?: 'localhost',
        $f3->get('DB_PORT') ?: '3306',
        $f3->get('DB_NAME')
    );
    $f3->set('DB', new \DB\SQL($dsn, $f3->get('DB_USERNAME'), $f3->get('DB_PASSWORD'), [
        \PDO::ATTR_ERRMODE           => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES  => false,
        \PDO::ATTR_STRINGIFY_FETCHES => false,
    ]));
} catch (\Throwable $e) {
    if ($DEBUG) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "DB init failed: ", $e->getMessage();
    } else {
        error_log('DB init failed: '.$e->getMessage());
        http_response_code(500);
        echo 'Internal Server Error';
    }
    exit;
}

/* Cache: memcache with safe folder fallback */
try { $f3->set('CACHE', 'memcache=localhost'); }
catch (\Throwable $e) {
    $dir = $ROOT.'/tmp/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $f3->set('CACHE', 'folder='.$dir.'/');
}

/* Timezone */
date_default_timezone_set('America/New_York');

/* Optional: also load your existing route files if present */
foreach ([
             '/config/auth_routes.ini',
             '/config/reset_password_routes.ini',
             '/config/registration_routes.ini',
             '/config/nomination_routes.ini',
             '/config/organization_routes.ini',
             '/config/tickets_routes.ini',
             '/config/xhttp_routes.ini',
             '/config/settings_routes.ini',
             '/config/administration_routes.ini',
             '/config/judging_routes.ini',
         ] as $rel) {
    $file = $ROOT.$rel;
    if (is_file($file)) $f3->config($file);
}

/* Dev error page */
if ($DEBUG) {
    $f3->set('ONERROR', function($f3){
        header('Content-Type: text/plain; charset=UTF-8');
        echo $f3->get('ERROR.text'), "\n\n", $f3->get('ERROR.trace');
    });
}

//Mailgun
$f3->set('MAILGUN_API_KEY', "key-d32113992557e3cb44d698211fbd4646");
$f3->set('MAILGUN_DOMAIN', "mail.nominatepro.com");

// One session name across the app (optional but recommended)
session_name('np_session');

// Must be set BEFORE the session is opened (i.e., before any SESSION.*)
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',                 // <-- critical: make cookie visible to /app, /login, etc.
    'domain'   => 'new.nominatepro.com', // or '.nominatepro.com' if you’ll span subdomains later
    'secure'   => true,                // you're on HTTPS
    'httponly' => true,
    'samesite' => 'Lax',
]);

/* Dispatch */
$f3->run();
