<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\VarExporter\ProxyHelper;

/*
 * With symfony/var-exporter 8 the LazyGhost proxy generator is gone, so
 * Doctrine ORM refuses to build proxies unless native lazy objects (PHP >= 8.4)
 * are enabled. Mirror ORM's own detection instead of a plain YAML flag: on
 * older stacks (var-exporter 6/7 - e.g. a --prefer-lowest build) the option may
 * not even exist in the doctrine-bundle configuration, and LazyGhost works.
 */
return static function (ContainerConfigurator $configurator): void {
    if (\PHP_VERSION_ID >= 80400 && !method_exists(ProxyHelper::class, 'generateLazyGhost')) {
        $configurator->extension('doctrine', [
            'orm' => [
                'enable_native_lazy_objects' => true,
            ],
        ]);
    }
};
