<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * On PHP >= 8.4 with symfony/var-exporter 8 the LazyGhost proxy path is gone,
 * so Doctrine ORM 3.x must use native lazy objects. On PHP 8.2/8.3 (where
 * var-exporter 7 still provides LazyGhost) the option itself is unavailable —
 * hence the runtime switch instead of plain YAML.
 */
return static function (ContainerConfigurator $configurator): void {
    if (\PHP_VERSION_ID >= 80400) {
        $configurator->extension('doctrine', [
            'orm' => [
                'enable_native_lazy_objects' => true,
            ],
        ]);
    }
};
