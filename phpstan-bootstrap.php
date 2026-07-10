<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

// Load environment for the dev container
(new Dotenv())->bootEnv(__DIR__ . '/.env');

// Boot a minimal kernel to get the EntityManager
$kernel = new Kernel('dev', false);
$kernel->boot();

return $kernel->getContainer()->get('doctrine.orm.entity_manager');
