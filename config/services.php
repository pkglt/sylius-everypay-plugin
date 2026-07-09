<?php

declare(strict_types=1);

use Pkg\SyliusEveryPayPlugin\Provider\AfterPayUrlProviderInterface;
use Pkg\SyliusEveryPayPlugin\Provider\PayloadAfterPayUrlProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    // All cross-cutting wiring lives in attributes on the classes themselves
    // (#[AsMessageHandler], #[AsEventListener], #[AsGatewayConfigurationType],
    // #[AsNotifyPaymentProvider], #[AsDecorator], #[AutoconfigureTag],
    // #[Autowire]) - the prototype below only registers them as autowired,
    // autoconfigured services. Excluded: the bundle/DI plumbing, plain value
    // objects, the command DTOs (messages rather than services) and the
    // shop-bundle integration, which the extension loads conditionally.
    $services->load('Pkg\\SyliusEveryPayPlugin\\', __DIR__ . '/../src/')
        ->exclude([
            __DIR__ . '/../src/PkgSyliusEveryPayPlugin.php',
            __DIR__ . '/../src/DependencyInjection/',
            __DIR__ . '/../src/EveryPayGateway.php',
            __DIR__ . '/../src/Command/',
            __DIR__ . '/../src/Client/EveryPayApiException.php',
            __DIR__ . '/../src/Client/EveryPayCredentials.php',
            __DIR__ . '/../src/Provider/SyliusShopAfterPayUrlProvider.php',
            __DIR__ . '/../src/Validator/Constraints/ValidEveryPayCredentials.php',
        ]);

    // Headless default - config/services/integrations/sylius_shop.php
    // re-aliases this to the shop-aware provider when SyliusShopBundle exists.
    $services->alias(AfterPayUrlProviderInterface::class, PayloadAfterPayUrlProvider::class);
};
