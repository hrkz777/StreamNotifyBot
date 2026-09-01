<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ((bool) $_SERVER['APP_DEBUG']) {
    umask(0000);
}
