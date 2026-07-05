<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface as PayumAwareGatewayConfigInterface;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Pkg\SyliusEveryPayPlugin\Functional\Support\EveryPayHttpMock;

abstract class FunctionalTestCase extends WebTestCase
{
    private static bool $schemaCreated = false;

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

    /**
     * Creates the schema once per process and guarantees an empty database for
     * every test (SQLite file lives in the kernel cache directory).
     */
    protected function prepareDatabase(): void
    {
        $entityManager = $this->entityManager();
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        if (!self::$schemaCreated) {
            $schemaTool->dropDatabase();
            $schemaTool->createSchema($metadata);
            self::$schemaCreated = true;

            return;
        }

        $connection = $entityManager->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $classMetadata) {
            if ($classMetadata->isMappedSuperclass || $classMetadata->isEmbeddedClass) {
                continue;
            }
            $connection->executeStatement('DELETE FROM ' . $classMetadata->getTableName());
        }
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $entityManager->clear();
    }

    protected function createShopEnvironment(): ChannelInterface
    {
        /** @var CurrencyInterface $currency */
        $currency = $this->resourceFactory('sylius.factory.currency')->createNew();
        $currency->setCode('EUR');

        /** @var LocaleInterface $locale */
        $locale = $this->resourceFactory('sylius.factory.locale')->createNew();
        $locale->setCode('en_US');

        /** @var ChannelInterface $channel */
        $channel = $this->resourceFactory('sylius.factory.channel')->createNew();
        $channel->setCode('WEB');
        $channel->setName('Web');
        $channel->setHostname('localhost');
        $channel->setEnabled(true);
        $channel->setTaxCalculationStrategy('order_items_based');
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);

        $entityManager = $this->entityManager();
        $entityManager->persist($currency);
        $entityManager->persist($locale);
        $entityManager->persist($channel);
        $entityManager->flush();

        return $channel;
    }

    protected function createEveryPayPaymentMethod(ChannelInterface $channel, string $code = 'everypay'): PaymentMethodInterface
    {
        $methodFactory = static::getContainer()->get('sylius.factory.payment_method');
        \assert($methodFactory instanceof PaymentMethodFactoryInterface);
        /** @var PaymentMethodInterface $method */
        $method = $methodFactory->createWithGateway(EveryPayGateway::FACTORY_NAME);
        $method->setCode($code);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('EveryPay');
        $method->setEnabled(true);
        $method->addChannel($channel);

        $gatewayConfig = $method->getGatewayConfig();
        static::assertNotNull($gatewayConfig);
        $gatewayConfig->setGatewayName($code);
        $gatewayConfig->setConfig([
            EveryPayGateway::CONFIG_API_USERNAME => 'abcd1234abcd1234',
            EveryPayGateway::CONFIG_API_SECRET => 'test-secret',
            EveryPayGateway::CONFIG_ACCOUNT_NAME => 'EUR3D1',
            EveryPayGateway::CONFIG_ENVIRONMENT => EveryPayGateway::ENVIRONMENT_DEMO,
        ]);
        if ($gatewayConfig instanceof PayumAwareGatewayConfigInterface) {
            $gatewayConfig->setUsePayum(false);
        }

        $entityManager = $this->entityManager();
        $entityManager->persist($method);
        $entityManager->flush();

        return $method;
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
        /** @var CustomerInterface $customer */
        $customer = $this->resourceFactory('sylius.factory.customer')->createNew();
        $customer->setEmail('customer@example.com');

        /** @var OrderInterface $order */
        $order = $this->resourceFactory('sylius.factory.order')->createNew();
        $order->setChannel($channel);
        $order->setLocaleCode('en_US');
        $order->setCurrencyCode('EUR');
        $order->setCustomer($customer);
        $order->setNumber('000000001');
        $order->setTokenValue('everypaytesttoken');
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutCompletedAt(new \DateTimeImmutable());

        /** @var PaymentInterface $payment */
        $payment = $this->resourceFactory('sylius.factory.payment')->createNew();
        $payment->setMethod($method);
        $payment->setCurrencyCode('EUR');
        $payment->setAmount($amount);
        $payment->setState(BasePaymentInterface::STATE_NEW);
        $payment->setDetails($paymentDetails);
        $order->addPayment($payment);

        $entityManager = $this->entityManager();
        $entityManager->persist($customer);
        $entityManager->persist($order);
        $entityManager->flush();

        return $payment;
    }
}
