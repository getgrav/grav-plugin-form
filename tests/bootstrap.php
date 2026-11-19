<?php

// Runs against a Grav checkout for its vendor libraries (Twig, PHPUnit):
// GRAV_ROOT=/path/to/grav vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/unit
$root = getenv('GRAV_ROOT') ?: dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/classes/TwigExtension.php';
