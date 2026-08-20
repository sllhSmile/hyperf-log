<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$autoloadCandidates = [
    $packageRoot . '/vendor/autoload.php',
    getcwd() . '/vendor/autoload.php',
    dirname($packageRoot, 2) . '/vendor/autoload.php',
];

$autoload = null;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    throw new RuntimeException('Composer autoload.php not found for package tests.');
}

require $autoload;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Sllhsmile\\HyperfLog\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
