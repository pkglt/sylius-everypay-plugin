<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Processor;

use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Maps EveryPay `payment_state` values to target Sylius payment states
 * (graph `sylius_payment`). See the state table in
 * docs/everypay-api.md.
 *
 * `charged_back` is intentionally unmapped — chargebacks are handled manually
 * from the EveryPay merchant portal.
 *
 * `initial` is also unmapped: the Sylius payment must stay `new` while the
 * customer has not committed to anything on the hosted page, because the
 * shop's pay flow (PaymentToPayResolver) only re-enters for `new` payments.
 */
final class EveryPayStateMapper
{
    public const STATE_SETTLED = 'settled';

    private const MAP = [
        'waiting_for_3ds_response' => PaymentInterface::STATE_PROCESSING,
        'waiting_for_sca' => PaymentInterface::STATE_PROCESSING,
        'sent_for_processing' => PaymentInterface::STATE_PROCESSING,
        '3ds_confirmed' => PaymentInterface::STATE_PROCESSING,
        self::STATE_SETTLED => PaymentInterface::STATE_COMPLETED,
        'authorised' => PaymentInterface::STATE_AUTHORIZED,
        'failed' => PaymentInterface::STATE_FAILED,
        'abandoned' => PaymentInterface::STATE_FAILED,
        'voided' => PaymentInterface::STATE_CANCELLED,
        'refunded' => PaymentInterface::STATE_REFUNDED,
    ];

    public function toSyliusState(string $everyPayState): ?string
    {
        return self::MAP[$everyPayState] ?? null;
    }
}
