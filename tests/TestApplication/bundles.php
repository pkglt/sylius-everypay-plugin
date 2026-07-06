<?php

declare(strict_types=1);

$bundles = [
    Pkg\SyliusEveryPayPlugin\PkgSyliusEveryPayPlugin::class => ['all' => true],
];

// Registered only when the Behat stack is installed (require-dev): the
// SymfonyExtension drives scenarios through this bundle's driver kernel.
if (class_exists(FriendsOfBehat\SymfonyExtension\Bundle\FriendsOfBehatSymfonyExtensionBundle::class)) {
    $bundles[FriendsOfBehat\SymfonyExtension\Bundle\FriendsOfBehatSymfonyExtensionBundle::class] = ['test' => true];
}

return $bundles;
