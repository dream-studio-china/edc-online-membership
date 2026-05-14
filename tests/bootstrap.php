<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Ensure tests run with APP_ENV=test when an external runner (IDE) doesn't set it.
$_SERVER['APP_ENV'] ??= 'test';
$_ENV['APP_ENV'] ??= 'test';

if (method_exists(Dotenv::class, 'bootEnv')) {
    // Load .env and the env-specific file (.env.test) when APP_ENV=test.
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Provide a safe fallback for DATABASE_URL when env files are not loaded (IDE runners).
// Prefer an sqlite file inside var/test.db to avoid requiring external DB in tests.
$_SERVER['DATABASE_URL'] ??= "sqlite:///%kernel.project_dir%/var/test.db";
$_ENV['DATABASE_URL'] ??= "sqlite:///%kernel.project_dir%/var/test.db";

// Make Symfony KernelTestCase/WebTestCase robust in CLI/IDE test runners.
$_SERVER['KERNEL_CLASS'] ??= 'App\\Kernel';
$_ENV['KERNEL_CLASS'] ??= 'App\\Kernel';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
