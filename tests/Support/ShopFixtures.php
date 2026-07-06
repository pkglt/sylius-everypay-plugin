<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Support;

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
use Sylius\Component\Order\Model\AdjustmentInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

/**
 * Programmatic fixtures shared by the phpunit functional suite and the Behat
 * contexts — one place that knows how to stand up a channel, an EveryPay
 * payment method and an order awaiting payment inside the test application.
 */
final class ShopFixtures
{
    /** Schema is created once per PHP process; scenarios/tests get a purge. */
    private static bool $schemaCreated = false;

    /**
     * @param PaymentMethodFactoryInterface<PaymentMethodInterface> $paymentMethodFactory
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FactoryInterface $currencyFactory,
        private readonly FactoryInterface $localeFactory,
        private readonly FactoryInterface $channelFactory,
        private readonly PaymentMethodFactoryInterface $paymentMethodFactory,
        private readonly FactoryInterface $customerFactory,
        private readonly FactoryInterface $orderFactory,
        private readonly FactoryInterface $paymentFactory,
        private readonly FactoryInterface $adjustmentFactory,
    ) {
    }

    public function prepareDatabase(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        if (!self::$schemaCreated) {
            $schemaTool->dropDatabase();
            $schemaTool->createSchema($metadata);
            self::$schemaCreated = true;

            return;
        }

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach ($metadata as $classMetadata) {
            if ($classMetadata->isMappedSuperclass || $classMetadata->isEmbeddedClass) {
                continue;
            }
            $connection->executeStatement('DELETE FROM ' . $classMetadata->getTableName());
        }
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->entityManager->clear();
    }

    public function createShopEnvironment(): ChannelInterface
    {
        /** @var CurrencyInterface $currency */
        $currency = $this->currencyFactory->createNew();
        $currency->setCode('EUR');

        /** @var LocaleInterface $locale */
        $locale = $this->localeFactory->createNew();
        $locale->setCode('en_US');

        /** @var ChannelInterface $channel */
        $channel = $this->channelFactory->createNew();
        $channel->setCode('WEB');
        $channel->setName('Web');
        $channel->setHostname('localhost');
        $channel->setEnabled(true);
        $channel->setTaxCalculationStrategy('order_items_based');
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);

        $this->entityManager->persist($currency);
        $this->entityManager->persist($locale);
        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }

    public function createEveryPayPaymentMethod(ChannelInterface $channel, string $code = 'everypay'): PaymentMethodInterface
    {
        $method = $this->paymentMethodFactory->createWithGateway(EveryPayGateway::FACTORY_NAME);
        $method->setCode($code);
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('EveryPay');
        $method->setEnabled(true);
        $method->addChannel($channel);

        $gatewayConfig = $method->getGatewayConfig();
        if (null === $gatewayConfig) {
            throw new \LogicException('The payment method factory did not create a gateway config.');
        }
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

        $this->entityManager->persist($method);
        $this->entityManager->flush();

        return $method;
    }

    /**
     * @param array<string, mixed> $paymentDetails
     */
    public function createOrderWithPayment(
        ChannelInterface $channel,
        PaymentMethodInterface $method,
        array $paymentDetails = [],
        int $amount = 1000,
    ): PaymentInterface {
        /** @var CustomerInterface $customer */
        $customer = $this->customerFactory->createNew();
        $customer->setEmail('customer@example.com');

        /** @var OrderInterface $order */
        $order = $this->orderFactory->createNew();
        $order->setChannel($channel);
        $order->setLocaleCode('en_US');
        $order->setCurrencyCode('EUR');
        $order->setCustomer($customer);
        $order->setNumber('000000001');
        $order->setTokenValue('everypaytesttoken');
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutCompletedAt(new \DateTimeImmutable());

        // Give the itemless test order a real total: Sylius' payment
        // processing (e.g. creating a retry payment after a failure) is a
        // no-op on a zero-total order.
        /** @var AdjustmentInterface $adjustment */
        $adjustment = $this->adjustmentFactory->createNew();
        $adjustment->setType('test_total');
        $adjustment->setLabel('Test total');
        $adjustment->setAmount($amount);
        $adjustment->setNeutral(false);
        $order->addAdjustment($adjustment);

        /** @var PaymentInterface $payment */
        $payment = $this->paymentFactory->createNew();
        $payment->setMethod($method);
        $payment->setCurrencyCode('EUR');
        $payment->setAmount($amount);
        $payment->setState(BasePaymentInterface::STATE_NEW);
        $payment->setDetails($paymentDetails);
        $order->addPayment($payment);

        $this->entityManager->persist($customer);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $payment;
    }
}
