<?php

// Shared dependencies are optional; application and test classes always come
// from this checkout, never from the checkout owning the vendor directory.
$root = dirname(__DIR__);
$autoload = getenv('INVENTORY_TEST_VENDOR_AUTOLOAD') ?: $root.'/vendor/autoload.php';
$loader = require $autoload;
$prefixes = ['App\\' => 'app/', 'Tests\\' => 'tests/', 'Database\\Factories\\' => 'database/factories/', 'Database\\Seeders\\' => 'database/seeders/'];
foreach ($prefixes as $prefix => $directory) {
    $loader->setPsr4($prefix, $root.'/'.$directory);
}
$map = [];
foreach ($loader->getClassMap() as $class => $path) {
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $local = $root.'/'.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($local)) $map[$class] = $local;
        }
    }
}
$loader->addClassMap($map);
putenv('APP_BASE_PATH='.$root);
$_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = $root;

return $autoload;
