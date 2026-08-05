<?php

declare(strict_types=1);

use Pkg\SyliusEveryPayPlugin\Notification\ChargedBackPaymentNotificationProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Loaded by the plugin extension only when SyliusAdminBundle is registered:
// charged-back EveryPay payments surface as admin navbar notifications.
return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(ChargedBackPaymentNotificationProvider::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('translator'),
            param('sylius.model.payment.class'),
        ])
        ->tag('sylius_admin.notification');
};
