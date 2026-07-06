<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Verifies admin-entered gateway credentials against the EveryPay API when
 * the payment method is saved. Only definitive rejections (401/403, unknown
 * processing account) fail validation — network problems or EveryPay outages
 * never block saving.
 */
final class ValidEveryPayCredentials extends Constraint
{
    public string $rejectedMessage = 'pkg_everypay.credentials.rejected';

    public string $accountNotFoundMessage = 'pkg_everypay.credentials.account_not_found';
}
