<?php
error_reporting(E_ALL); ini_set('display_errors','1');

require __DIR__.'/vendor/autoload.php';

$map = @include __DIR__.'/vendor/composer/autoload_psr4.php';
echo "Has App\\ mapping? ", (isset($map['App\\']) ? 'YES' : 'NO'), PHP_EOL;
if (isset($map['App\\'])) { print_r($map['App\\']); }

$file = __DIR__.'/app/Controllers/Auth/LoginController.php';
echo "File present? ", (is_file($file) ? 'YES' : 'NO'), " -> $file", PHP_EOL;

echo "Class exists? ", (class_exists('app\\Controllers\\LoginController') ? 'YES' : 'NO'), PHP_EOL;
