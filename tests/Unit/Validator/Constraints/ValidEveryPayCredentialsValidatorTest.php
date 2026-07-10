<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Unit\Validator\Constraints;

use Pkg\SyliusEveryPayPlugin\Client\EveryPayApiClient;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Pkg\SyliusEveryPayPlugin\Validator\Constraints\ValidEveryPayCredentials;
use Pkg\SyliusEveryPayPlugin\Validator\Constraints\ValidEveryPayCredentialsValidator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<ValidEveryPayCredentialsValidator>
 */
final class ValidEveryPayCredentialsValidatorTest extends ConstraintValidatorTestCase
{
    private MockResponse $apiResponse;

    protected function setUp(): void
    {
        $this->apiResponse = new MockResponse('{}');

        parent::setUp();
    }

    protected function createValidator(): ValidEveryPayCredentialsValidator
    {
        return new ValidEveryPayCredentialsValidator(
            new EveryPayApiClient(new MockHttpClient(fn (): MockResponse => $this->apiResponse)),
        );
    }

    public function testRejectedCredentialsAddAViolationOnTheSecret(): void
    {
        $this->apiResponse = new MockResponse('{"error":{"message":"Authentication failed"}}', ['http_code' => 401]);

        $this->validator->validate($this->config(), new ValidEveryPayCredentials());

        $this->buildViolation('pkg_everypay.credentials.rejected')
            ->atPath('property.path[api_secret]')
            ->assertRaised();
    }

    public function testForbiddenCredentialsAddAViolationOnTheSecret(): void
    {
        $this->apiResponse = new MockResponse('{"error":{"message":"Forbidden"}}', ['http_code' => 403]);

        $this->validator->validate($this->config(), new ValidEveryPayCredentials());

        $this->buildViolation('pkg_everypay.credentials.rejected')
            ->atPath('property.path[api_secret]')
            ->assertRaised();
    }

    public function testUnknownProcessingAccountAddsAViolationOnTheAccountName(): void
    {
        $this->apiResponse = new MockResponse('{"error":{"message":"not found"}}', ['http_code' => 404]);

        $this->validator->validate($this->config(), new ValidEveryPayCredentials());

        $this->buildViolation('pkg_everypay.credentials.account_not_found')
            ->atPath('property.path[account_name]')
            ->assertRaised();
    }

    public function testServerErrorsNeverBlockSaving(): void
    {
        $this->apiResponse = new MockResponse('oops', ['http_code' => 500]);

        $this->validator->validate($this->config(), new ValidEveryPayCredentials());

        $this->assertNoViolation();
    }

    public function testTransportFailuresNeverBlockSaving(): void
    {
        $this->apiResponse = new MockResponse('', ['error' => 'Connection timed out']);

        $this->validator->validate($this->config(), new ValidEveryPayCredentials());

        $this->assertNoViolation();
    }

    public function testIncompleteConfigurationIsLeftToNotBlank(): void
    {
        $this->validator->validate($this->config(secret: ''), new ValidEveryPayCredentials());

        $this->assertNoViolation();
    }

    public function testValidCredentialsPass(): void
    {
        $this->apiResponse = new MockResponse('{"processing_account":{"name":"EUR3D1"}}');

        $this->validator->validate($this->config(), new ValidEveryPayCredentials());

        $this->assertNoViolation();
    }

    /**
     * @return array<string, string>
     */
    private function config(string $secret = 'test-secret'): array
    {
        return [
            EveryPayGateway::CONFIG_API_USERNAME => 'abcd1234abcd1234',
            EveryPayGateway::CONFIG_API_SECRET => $secret,
            EveryPayGateway::CONFIG_ACCOUNT_NAME => 'EUR3D1',
            EveryPayGateway::CONFIG_ENVIRONMENT => EveryPayGateway::ENVIRONMENT_DEMO,
        ];
    }
}
