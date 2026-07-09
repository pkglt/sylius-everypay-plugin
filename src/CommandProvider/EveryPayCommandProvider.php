<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\CommandProvider;

use Pkg\SyliusEveryPayPlugin\Command\CaptureEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\Command\NotifyEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\Command\RefundEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\Command\StatusEveryPayPayment;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Maps EveryPay PaymentRequest actions to command-bus commands - a single
 * provider switching on action instead of the Sylius per-action composite,
 * so there is exactly one wiring point (the attribute tag below).
 */
#[AutoconfigureTag('sylius.payment_request.command_provider', ['gateway_factory' => EveryPayGateway::FACTORY_NAME])]
final class EveryPayCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return in_array($paymentRequest->getAction(), [
            PaymentRequestInterface::ACTION_CAPTURE,
            PaymentRequestInterface::ACTION_STATUS,
            PaymentRequestInterface::ACTION_NOTIFY,
            PaymentRequestInterface::ACTION_REFUND,
        ], true);
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return match ($paymentRequest->getAction()) {
            PaymentRequestInterface::ACTION_CAPTURE => new CaptureEveryPayPayment($paymentRequest->getId()),
            PaymentRequestInterface::ACTION_STATUS => new StatusEveryPayPayment($paymentRequest->getId()),
            PaymentRequestInterface::ACTION_NOTIFY => new NotifyEveryPayPayment($paymentRequest->getId()),
            PaymentRequestInterface::ACTION_REFUND => new RefundEveryPayPayment($paymentRequest->getId()),
            default => throw new \LogicException(sprintf('Unsupported EveryPay payment request action "%s".', (string) $paymentRequest->getAction())),
        };
    }
}
