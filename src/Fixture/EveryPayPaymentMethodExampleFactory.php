<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Fixture;

use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Bundle\CoreBundle\Fixture\Factory\ExampleFactoryInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface as PayumAwareGatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Decorates the payment method fixture factory to force use_payum=false on
 * EveryPay methods: the Sylius 2.2 fixture tree has no usePayum node and the
 * inner factory defaults it to true, which would route checkout through
 * Payum where no everypay factory exists. This mirrors what
 * PayumGatewayConfigTypeExtension does automatically on the admin form.
 *
 * @implements ExampleFactoryInterface<PaymentMethodInterface>
 */
#[AsDecorator(decorates: 'sylius.fixture.example_factory.payment_method', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class EveryPayPaymentMethodExampleFactory implements ExampleFactoryInterface
{
    /**
     * @param ExampleFactoryInterface<PaymentMethodInterface> $decorated
     */
    public function __construct(
        #[AutowireDecorated]
        private readonly ExampleFactoryInterface $decorated,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function create(array $options = []): PaymentMethodInterface
    {
        /** @var PaymentMethodInterface $paymentMethod */
        $paymentMethod = $this->decorated->create($options);

        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (
            $gatewayConfig instanceof PayumAwareGatewayConfigInterface &&
            EveryPayGateway::FACTORY_NAME === $gatewayConfig->getFactoryName()
        ) {
            $gatewayConfig->setUsePayum(false);
        }

        return $paymentMethod;
    }
}
