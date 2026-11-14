<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRules(['@PER-CS' => true])
    ->setFinder(
        Finder::create()
            ->in([
                __DIR__ . '/app',
                __DIR__ . '/bootstrap',
                __DIR__ . '/config',
                __DIR__ . '/public',
                __DIR__ . '/routes',
                __DIR__ . '/tests',
            ])
            ->exclude('cache')
            ->append([__FILE__, __DIR__ . '/artisan']),
    );
