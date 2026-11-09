<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

(new \vielhuber\healthspace\Healthspace(rootDir: __DIR__))->run();
