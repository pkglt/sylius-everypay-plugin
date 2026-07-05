<?php

declare(strict_types=1);

use Pkg\SyliusEveryPayPlugin\Provider\AfterPayUrlProviderInterface;
use Pkg\SyliusEveryPayPlugin\Provider\PayloadAfterPayUrlProvider;
use Pkg\SyliusEveryPayPlugin\Provider\SyliusShopAfterPayUrlProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Loaded by the plugin extension only when SyliusShopBundle is registered:
// the shop's after-pay route becomes the fallback return URL for payment
// requests without an explicit payload URL.
return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(SyliusShopAfterPayUrlProvider::class)
        ->args([
            service(PayloadAfterPayUrlProvider::class),
            service('sylius_shop.provider.order_pay.after_pay_url'),
        ]);

    $services->alias(AfterPayUrlProviderInterface::class, SyliusShopAfterPayUrlProvider::class);
};
