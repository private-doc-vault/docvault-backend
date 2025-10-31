<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Fix: Ensure APP_ENV='test' is preserved in both $_SERVER and $_ENV
// PHPUnit sets $_SERVER['APP_ENV'] = 'test' via phpunit.dist.xml
// but Dotenv->bootEnv() will override $_ENV['APP_ENV'] from .env file
// Symfony Kernel uses getenv() which reads from $_ENV, so we need to set it explicitly
if (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'test') {
    $_ENV['APP_ENV'] = 'test';
    putenv('APP_ENV=test'); // Also set in the environment for getenv()
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Ensure test environment is still set after bootEnv (in case it was overridden)
if (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'test') {
    $_ENV['APP_ENV'] = 'test';
    putenv('APP_ENV=test');
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
