<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\CommandProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Command\CaptureEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\Command\NotifyEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\Command\RefundEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\Command\StatusEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\CommandProvider\EveryPayCommandProvider;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class EveryPayCommandProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string<PaymentRequestHashAwareInterface>}>
     */
    public static function actionProvider(): iterable
    {
        yield 'capture' => [PaymentRequestInterface::ACTION_CAPTURE, CaptureEveryPayPayment::class];
        yield 'status' => [PaymentRequestInterface::ACTION_STATUS, StatusEveryPayPayment::class];
        yield 'notify' => [PaymentRequestInterface::ACTION_NOTIFY, NotifyEveryPayPayment::class];
        yield 'refund' => [PaymentRequestInterface::ACTION_REFUND, RefundEveryPayPayment::class];
    }

    /**
     * @param class-string<PaymentRequestHashAwareInterface> $commandClass
     */
    #[DataProvider('actionProvider')]
    public function testSupportedActionsProduceHashCarryingCommands(string $action, string $commandClass): void
    {
        $paymentRequest = $this->paymentRequest($action);
        $provider = new EveryPayCommandProvider();

        self::assertTrue($provider->supports($paymentRequest));

        $command = $provider->provide($paymentRequest);

        self::assertInstanceOf($commandClass, $command);
        self::assertSame('pr-hash', $command->getHash());
    }

    public function testUnsupportedActionsAreRejected(): void
    {
        $provider = new EveryPayCommandProvider();

        self::assertFalse($provider->supports($this->paymentRequest(PaymentRequestInterface::ACTION_AUTHORIZE)));
        self::assertFalse($provider->supports($this->paymentRequest(PaymentRequestInterface::ACTION_SYNC)));
    }

    private function paymentRequest(string $action): PaymentRequestInterface
    {
        $paymentRequest = $this->createStub(PaymentRequestInterface::class);
        $paymentRequest->method('getAction')->willReturn($action);
        $paymentRequest->method('getId')->willReturn('pr-hash');

        return $paymentRequest;
    }
}
