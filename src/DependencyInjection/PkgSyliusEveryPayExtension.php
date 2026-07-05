<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class PkgSyliusEveryPayExtension extends Extension
{
    /** @param array<array-key, mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.php');

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');
        if (isset($bundles['SyliusShopBundle'])) {
            $loader->load('services/integrations/sylius_shop.php');
        }
    }
}
