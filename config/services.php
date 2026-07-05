<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    // All cross-cutting wiring lives in attributes on the classes themselves
    // (#[AsMessageHandler], #[AsEventListener], #[AsGatewayConfigurationType],
    // #[AsNotifyPaymentProvider], #[AsDecorator], #[AutoconfigureTag],
    // #[Autowire]) — the prototype below only registers them as autowired,
    // autoconfigured services. Excluded: the bundle/DI plumbing, plain value
    // objects and the command DTOs, which are messages rather than services.
    $services->load('Pkg\\SyliusEveryPayPlugin\\', __DIR__ . '/../src/')
        ->exclude([
            __DIR__ . '/../src/PkgSyliusEveryPayPlugin.php',
            __DIR__ . '/../src/DependencyInjection/',
            __DIR__ . '/../src/EveryPayGateway.php',
            __DIR__ . '/../src/Command/',
            __DIR__ . '/../src/Client/EveryPayApiException.php',
            __DIR__ . '/../src/Client/EveryPayCredentials.php',
        ]);
};
