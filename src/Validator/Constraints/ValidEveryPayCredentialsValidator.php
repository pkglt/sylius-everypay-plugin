<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Validator\Constraints;

use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiException;
use Pkg\SyliusEveryPayPlugin\Client\EveryPayCredentials;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidEveryPayCredentialsValidator extends ConstraintValidator
{
    /** Keep the admin form responsive even when EveryPay is unreachable. */
    private const CHECK_TIMEOUT = 5.0;

    public function __construct(
        private readonly EveryPayApiClient $apiClient,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidEveryPayCredentials) {
            throw new UnexpectedTypeException($constraint, ValidEveryPayCredentials::class);
        }

        if (!is_array($value)) {
            return;
        }

        $credentials = EveryPayCredentials::fromConfig($value);
        if ('' === $credentials->apiUsername || '' === $credentials->apiSecret || '' === $credentials->accountName) {
            return; // NotBlank on the individual fields reports these.
        }

        try {
            $this->apiClient->getProcessingAccount($credentials, self::CHECK_TIMEOUT);
        } catch (EveryPayApiException $exception) {
            if (401 === $exception->statusCode || 403 === $exception->statusCode) {
                $this->context->buildViolation($constraint->rejectedMessage)
                    ->atPath('[' . EveryPayGateway::CONFIG_API_SECRET . ']')
                    ->addViolation();

                return;
            }

            if (404 === $exception->statusCode) {
                $this->context->buildViolation($constraint->accountNotFoundMessage)
                    ->atPath('[' . EveryPayGateway::CONFIG_ACCOUNT_NAME . ']')
                    ->addViolation();

                return;
            }

            // Transport failures and server errors never block saving -
            // admin-entered data is trusted when it cannot be verified.
        }
    }
}
