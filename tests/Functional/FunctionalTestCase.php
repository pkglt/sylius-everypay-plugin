<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Pkg\SyliusEveryPayPlugin\Functional\Support\EveryPayHttpMock;
use Tests\Pkg\SyliusEveryPayPlugin\Support\ShopFixtures;

abstract class FunctionalTestCase extends WebTestCase
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class, ?string $id = null): object
    {
        $service = static::getContainer()->get($id ?? $class);
        static::assertInstanceOf($class, $service);

        return $service;
    }

    protected function resourceFactory(string $serviceId): FactoryInterface
    {
        $factory = static::getContainer()->get($serviceId);
        \assert($factory instanceof FactoryInterface);

        return $factory;
    }

    protected function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class, 'doctrine.orm.default_entity_manager');
    }

    protected function everyPayHttpMock(): EveryPayHttpMock
    {
        return $this->service(EveryPayHttpMock::class);
    }

    protected function shopFixtures(): ShopFixtures
    {
        return $this->service(ShopFixtures::class);
    }

    protected function prepareDatabase(): void
    {
        $this->shopFixtures()->prepareDatabase();
    }

    protected function createShopEnvironment(): ChannelInterface
    {
        return $this->shopFixtures()->createShopEnvironment();
    }

    protected function createEveryPayPaymentMethod(ChannelInterface $channel, string $code = 'everypay'): PaymentMethodInterface
    {
        return $this->shopFixtures()->createEveryPayPaymentMethod($channel, $code);
    }

    /**
     * @param array<string, mixed> $paymentDetails
     */
    protected function createOrderWithPayment(
        ChannelInterface $channel,
        PaymentMethodInterface $method,
        array $paymentDetails = [],
        int $amount = 1000,
    ): PaymentInterface {
        return $this->shopFixtures()->createOrderWithPayment($channel, $method, $paymentDetails, $amount);
    }
}
