<?php

// Pin Pest to this repository even when vendor belongs to a different checkout.
require __DIR__.'/bootstrap.php';
$suite = \Pest\TestSuite::getInstance(dirname(__DIR__), 'tests');
$suite->rootPath = (string) realpath(dirname(__DIR__));
$suite->testPath = 'tests';
$input = new \Symfony\Component\Console\Input\ArgvInput;
$output = new \Symfony\Component\Console\Output\ConsoleOutput;
$kernel = \Pest\Kernel::boot($suite, $input, $output);
$arguments = $_SERVER['argv'];
$result = $kernel->handle($arguments, $arguments);
$kernel->terminate();
exit($result);
