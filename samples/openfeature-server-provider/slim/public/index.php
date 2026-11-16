<?php

declare(strict_types=1);

use App\ConfigDirectorSettings;
use App\SampleApplication;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenvFileValues = Dotenv::createArrayBacked(dirname(__DIR__))->safeLoad();

SampleApplication::create(ConfigDirectorSettings::fromEnvironment(getenv() + $dotenvFileValues))->run();
