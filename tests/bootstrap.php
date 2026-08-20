<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$autoload = $packageRoot . '/vendor/autoload.php';
if (! is_file($autoload)) {
    $autoload = dirname($packageRoot, 2) . '/vendor/autoload.php';
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
