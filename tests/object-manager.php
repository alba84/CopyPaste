<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$environment = $_SERVER['APP_ENV'] ?? 'dev';
$debug = $_SERVER['APP_DEBUG'] ?? true;

if (!is_string($environment)) {
    $environment = 'dev';
}
if (!is_bool($debug)) {
    $debug = true;
}

$kernel = new Kernel($environment, $debug);
$kernel->boot();

$registry = $kernel->getContainer()->get('doctrine');
if (!$registry instanceof ManagerRegistry) {
    throw new RuntimeException('Doctrine registry is not available.');
}

$entityManager = $registry->getManager();
if (!$entityManager instanceof EntityManagerInterface) {
    throw new RuntimeException('Entity manager is not available.');
}

return $entityManager;
