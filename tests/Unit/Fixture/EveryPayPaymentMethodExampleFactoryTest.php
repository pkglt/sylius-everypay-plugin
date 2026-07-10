<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Fixture;

use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Fixture\EveryPayPaymentMethodExampleFactory;
use Sylius\Bundle\CoreBundle\Fixture\Factory\ExampleFactoryInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface as PayumAwareGatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class EveryPayPaymentMethodExampleFactoryTest extends TestCase
{
    public function testForcesUsePayumOffForEveryPayMethods(): void
    {
        // Without this, fixture-created methods default use_payum to true and
        // checkout routes into Payum, where no everypay factory exists.
        $gatewayConfig = $this->createMock(PayumAwareGatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn(EveryPayGateway::FACTORY_NAME);
        $gatewayConfig->expects(self::once())->method('setUsePayum')->with(false);

        $factory = new EveryPayPaymentMethodExampleFactory($this->decoratedReturning($gatewayConfig));

        $factory->create(['code' => 'everypay']);
    }

    public function testLeavesOtherGatewaysUntouched(): void
    {
        $gatewayConfig = $this->createMock(PayumAwareGatewayConfigInterface::class);
        $gatewayConfig->method('getFactoryName')->willReturn('paypal');
        $gatewayConfig->expects(self::never())->method('setUsePayum');

        $factory = new EveryPayPaymentMethodExampleFactory($this->decoratedReturning($gatewayConfig));

        $factory->create();
    }

    /**
     * @return ExampleFactoryInterface<PaymentMethodInterface>
     */
    private function decoratedReturning(PayumAwareGatewayConfigInterface $gatewayConfig): ExampleFactoryInterface
    {
        $paymentMethod = $this->createStub(PaymentMethodInterface::class);
        $paymentMethod->method('getGatewayConfig')->willReturn($gatewayConfig);

        $decorated = $this->createStub(ExampleFactoryInterface::class);
        $decorated->method('create')->willReturn($paymentMethod);

        return $decorated;
    }
}
