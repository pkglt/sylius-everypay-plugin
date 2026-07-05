<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Processor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pkg\SyliusEveryPayPlugin\Processor\EveryPayStateMapper;
use Sylius\Component\Payment\Model\PaymentInterface;

final class EveryPayStateMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function stateProvider(): iterable
    {
        yield 'initial is a no-op (payment must stay new/re-payable)' => ['initial', null];
        yield 'waiting_for_3ds is pending' => ['waiting_for_3ds', PaymentInterface::STATE_PROCESSING];
        yield 'waiting_for_sca is pending' => ['waiting_for_sca', PaymentInterface::STATE_PROCESSING];
        yield 'sent_for_processing is pending' => ['sent_for_processing', PaymentInterface::STATE_PROCESSING];
        yield 'settled completes' => ['settled', PaymentInterface::STATE_COMPLETED];
        yield 'authorised authorizes' => ['authorised', PaymentInterface::STATE_AUTHORIZED];
        yield 'failed fails' => ['failed', PaymentInterface::STATE_FAILED];
        yield 'abandoned fails' => ['abandoned', PaymentInterface::STATE_FAILED];
        yield 'voided cancels' => ['voided', PaymentInterface::STATE_CANCELLED];
        yield 'refunded refunds' => ['refunded', PaymentInterface::STATE_REFUNDED];
        yield 'charged_back is manual' => ['charged_back', null];
        yield 'unknown state is ignored' => ['some_future_state', null];
    }

    #[DataProvider('stateProvider')]
    public function testMapsEveryPayStatesToSyliusStates(string $everyPayState, ?string $expected): void
    {
        self::assertSame($expected, (new EveryPayStateMapper())->toSyliusState($everyPayState));
    }
}
