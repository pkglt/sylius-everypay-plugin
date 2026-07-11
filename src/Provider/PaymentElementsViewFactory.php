<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Provider;

use Pkg\SyliusEveryPayPlugin\Client\EveryPayCredentials;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Shapes the template context of the embedded checkout page (the Payment
 * Elements display mode): the SDK bundle URL, the secureElements() options
 * and the confirm() payment intent. Returns null when anything the SDK
 * requires is missing, so the response provider falls back to the hosted
 * page redirect - the same safety net the method grid has.
 */
final readonly class PaymentElementsViewFactory
{
    /**
     * @return array<string, mixed>|null
     */
    public function viewFrom(PaymentRequestInterface $paymentRequest): ?array
    {
        $responseData = $paymentRequest->getResponseData();

        $paymentLink = $responseData['payment_link'] ?? null;
        $paymentReference = $responseData['payment_reference'] ?? null;
        $elements = $responseData['payment_elements'] ?? null;
        if (
            !is_array($elements) ||
            !is_string($paymentLink) || '' === $paymentLink ||
            !is_string($paymentReference) || '' === $paymentReference
        ) {
            return null;
        }

        // The SDK hard-requires every confirm() field - render nothing
        // rather than an element that throws on submit.
        $mobileAccessToken = $elements['mobile_access_token'] ?? null;
        $customerUrl = $elements['customer_url'] ?? null;
        $orderReference = $elements['order_reference'] ?? null;
        if (
            !is_string($mobileAccessToken) || '' === $mobileAccessToken ||
            !is_string($customerUrl) || '' === $customerUrl ||
            !is_string($orderReference) || '' === $orderReference
        ) {
            return null;
        }

        $gatewayConfig = $paymentRequest->getMethod()->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return null;
        }
        $config = $gatewayConfig->getConfig();
        $credentials = EveryPayCredentials::fromConfig($config);
        if ('' === $credentials->apiUsername || '' === $credentials->accountName) {
            return null;
        }

        $payment = EveryPayGateway::corePaymentFrom($paymentRequest);
        $environment = EveryPayGateway::environmentFrom($config);

        $amount = $elements['amount'] ?? null;
        $locale = $elements['locale'] ?? null;
        $email = $elements['email'] ?? null;
        $preferredCountry = $elements['preferred_country'] ?? null;

        return [
            'payment' => $payment,
            'payment_request' => $paymentRequest,
            'payment_link' => $paymentLink,
            'sdk_url' => EveryPayGateway::ELEMENTS_SDK_URLS[$environment],
            // Serialized into the page as the JSON the checkout script reads.
            'elements' => [
                'setup' => [
                    'account_name' => $credentials->accountName,
                    'api_username' => $credentials->apiUsername,
                    'environment' => EveryPayGateway::ELEMENTS_SDK_ENVIRONMENTS[$environment],
                    'amount' => is_numeric($amount) ? (float) $amount : EveryPayGateway::amountToDecimal((int) $payment->getAmount()),
                    'locale' => is_string($locale) && '' !== $locale ? $locale : 'en',
                    'email' => is_string($email) && '' !== $email ? $email : null,
                    'preferred_country' => is_string($preferredCountry) && '' !== $preferredCountry ? $preferredCountry : null,
                ],
                'payment_intent' => [
                    'accountName' => $credentials->accountName,
                    'apiUsername' => $credentials->apiUsername,
                    'bearerToken' => $mobileAccessToken,
                    'orderReference' => $orderReference,
                    'paymentLink' => $paymentLink,
                    'returnURL' => $customerUrl,
                    'paymentReference' => $paymentReference,
                ],
            ],
        ];
    }
}
