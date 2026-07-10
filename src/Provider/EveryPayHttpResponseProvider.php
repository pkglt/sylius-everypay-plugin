<?php

declare(strict_types=1);

namespace Pkg\SyliusEveryPayPlugin\Provider;

use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * After the capture handler created the EveryPay payment, sends the customer
 * to the hosted payment page - or, when the gateway is configured with the
 * method grid, renders the payment method buttons (per-method direct links)
 * inside the shop first. When it does not support the request (capture
 * failed, or the payment already reached a final state), Sylius falls back
 * to /after-pay/{hash} -> status check -> thank-you/retry.
 */
#[AutoconfigureTag('sylius.payment_request.provider.http_response', ['gateway_factory' => EveryPayGateway::FACTORY_NAME])]
final readonly class EveryPayHttpResponseProvider implements HttpResponseProviderInterface
{
    private const REDIRECTABLE_PAYMENT_STATES = [
        PaymentInterface::STATE_NEW,
        PaymentInterface::STATE_PROCESSING,
    ];

    public function __construct(
        private Environment $twig,
    ) {
    }

    public function supports(
        RequestConfiguration $requestConfiguration,
        PaymentRequestInterface $paymentRequest,
    ): bool {
        if (PaymentRequestInterface::ACTION_CAPTURE !== $paymentRequest->getAction()) {
            return false;
        }

        if (PaymentRequestInterface::STATE_PROCESSING !== $paymentRequest->getState()) {
            return false;
        }

        // Do not send a customer whose payment already settled/failed back
        // to the hosted page on a /pay/{hash} reload.
        if (!in_array($paymentRequest->getPayment()->getState(), self::REDIRECTABLE_PAYMENT_STATES, true)) {
            return false;
        }

        $responseData = $paymentRequest->getResponseData();

        return is_string($responseData['payment_link'] ?? null) && '' !== $responseData['payment_link'];
    }

    public function getResponse(
        RequestConfiguration $requestConfiguration,
        PaymentRequestInterface $paymentRequest,
    ): Response {
        $responseData = $paymentRequest->getResponseData();
        /** @var string $paymentLink */
        $paymentLink = $responseData['payment_link'];

        $methodOptions = EveryPayGateway::paymentMethodOptionsFrom($responseData['payment_methods'] ?? null);
        if ([] !== $methodOptions && EveryPayGateway::DISPLAY_MODE_METHOD_GRID === $this->displayModeFor($paymentRequest)) {
            $payment = EveryPayGateway::corePaymentFrom($paymentRequest);
            $preferredCountry = $payment->getOrder()?->getBillingAddress()?->getCountryCode();

            return new Response($this->twig->render('@PkgSyliusEveryPayPlugin/shop/method_grid.html.twig', [
                'method_groups' => EveryPayGateway::groupPaymentMethodOptions($methodOptions, $preferredCountry),
                'payment' => $payment,
                'payment_link' => $paymentLink,
                'payment_request' => $paymentRequest,
            ]));
        }

        return new RedirectResponse($paymentLink, Response::HTTP_SEE_OTHER);
    }

    private function displayModeFor(PaymentRequestInterface $paymentRequest): string
    {
        $gatewayConfig = $paymentRequest->getMethod()->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return EveryPayGateway::DISPLAY_MODE_REDIRECT;
        }

        return EveryPayGateway::displayModeFrom($gatewayConfig->getConfig());
    }
}
