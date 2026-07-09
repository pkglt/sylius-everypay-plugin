<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\ORM\EntityManagerInterface;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;
use Tests\Pkg\SyliusEveryPayPlugin\Behat\SharedStorage;
use Tests\Pkg\SyliusEveryPayPlugin\Functional\Support\EveryPayHttpMock;
use Tests\Pkg\SyliusEveryPayPlugin\Support\ShopFixtures;
use Webmozart\Assert\Assert;

/**
 * Drives the EveryPay payment lifecycle through the real shop HTTP routes of
 * the Sylius test application, with the EveryPay API scripted by the shared
 * HTTP mock. Redirects are followed manually so the hand-off to the external
 * hosted payment page stays observable.
 */
final class EveryPayShopContext implements Context
{
    private const PAYMENT_REFERENCE = 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd';

    private const PAYMENT_LINK = 'https://igw-demo.every-pay.com/lp/x419d7/3HeCGV01';

    private ?ChannelInterface $channel = null;

    private ?PaymentMethodInterface $paymentMethod = null;

    private ?PaymentInterface $payment = null;

    private ?string $externalRedirectUrl = null;

    /** @var list<string> */
    private array $visitedPaths = [];

    public function __construct(
        private readonly KernelBrowser $client,
        private readonly EveryPayHttpMock $everyPayApi,
        private readonly ShopFixtures $shopFixtures,
        private readonly EntityManagerInterface $entityManager,
        private readonly RouterInterface $router,
        private readonly SharedStorage $sharedStorage,
    ) {
    }

    #[BeforeScenario]
    public function prepareScenario(): void
    {
        // One container for the whole scenario: the scripted EveryPay mock and
        // the entities must survive across the requests a scenario performs.
        $this->client->disableReboot();
        $this->client->followRedirects(false);
        // test.client is a prototype service - every injection is a fresh
        // browser. Publish this one so all contexts drive the same browser
        // (and therefore the same un-rebooted container).
        $this->sharedStorage->set('client', $this->client);
        $this->shopFixtures->prepareDatabase();
    }

    #[Given('the store operates a channel with EveryPay payments')]
    public function theStoreOperatesAChannelWithEveryPayPayments(): void
    {
        $this->channel = $this->shopFixtures->createShopEnvironment();
        $this->paymentMethod = $this->shopFixtures->createEveryPayPaymentMethod($this->channel);
        $this->sharedStorage->set('channel', $this->channel);
        $this->sharedStorage->set('payment_method', $this->paymentMethod);
    }

    #[Given('there is an order awaiting payment')]
    public function thereIsAnOrderAwaitingPayment(): void
    {
        Assert::notNull($this->channel);
        Assert::notNull($this->paymentMethod);
        $this->payment = $this->shopFixtures->createOrderWithPayment($this->channel, $this->paymentMethod, amount: 2599);
        $this->sharedStorage->set('payment', $this->payment);
    }

    #[Given('EveryPay will accept the payment creation')]
    public function everyPayWillAcceptThePaymentCreation(): void
    {
        $this->everyPayApi->queueJson([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_link' => self::PAYMENT_LINK,
            'payment_state' => 'initial',
            'order_reference' => '000000001-1',
            'account_name' => 'EUR3D1',
            'currency' => 'EUR',
        ], 201);
    }

    #[Given('the EveryPay payment method shows the method buttons in the shop')]
    public function theEveryPayPaymentMethodShowsTheMethodButtonsInTheShop(): void
    {
        Assert::notNull($this->paymentMethod);
        $gatewayConfig = $this->paymentMethod->getGatewayConfig();
        Assert::notNull($gatewayConfig);
        $gatewayConfig->setConfig(array_merge($gatewayConfig->getConfig(), [
            EveryPayGateway::CONFIG_DISPLAY_MODE => EveryPayGateway::DISPLAY_MODE_METHOD_GRID,
        ]));
        $this->entityManager->flush();
    }

    #[Given('EveryPay will accept the payment creation with a list of bank methods')]
    public function everyPayWillAcceptThePaymentCreationWithAListOfBankMethods(): void
    {
        $this->everyPayApi->queueJson([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_link' => self::PAYMENT_LINK,
            'payment_state' => 'initial',
            'payment_methods' => [
                [
                    'source' => 'swedbank_ob_lt',
                    'display_name' => 'Swedbank',
                    'country_code' => 'LT',
                    'payment_link' => self::PAYMENT_LINK . '?method=swedbank_ob_lt',
                    'logo_url' => 'https://igw-demo.every-pay.com/assets/swedbank.svg',
                ],
                [
                    'source' => 'seb_ob_lt',
                    'display_name' => 'SEB',
                    'country_code' => 'LT',
                    'payment_link' => self::PAYMENT_LINK . '?method=seb_ob_lt',
                    'logo_url' => 'https://igw-demo.every-pay.com/assets/seb.svg',
                ],
                [
                    'source' => 'seb_ob_ee',
                    'display_name' => 'AS SEB Pank',
                    'country_code' => 'EE',
                    'payment_link' => self::PAYMENT_LINK . '?method=seb_ob_ee',
                    'logo_url' => 'https://igw-demo.every-pay.com/assets/seb.svg',
                ],
                [
                    'source' => 'card',
                    'display_name' => 'VISA/MasterCard',
                    'country_code' => null,
                    'payment_link' => self::PAYMENT_LINK . '?method=card',
                    'logo_url' => 'https://igw-demo.every-pay.com/assets/card.svg',
                ],
            ],
        ], 201);
    }

    #[Then('the customer sees the bank buttons instead of being redirected')]
    public function theCustomerSeesTheBankButtonsInsteadOfBeingRedirected(): void
    {
        Assert::null($this->externalRedirectUrl, 'Expected the in-shop method grid, got an external redirect.');
        Assert::true($this->client->getResponse()->isSuccessful());

        $content = (string) $this->client->getResponse()->getContent();
        Assert::contains($content, 'data-test-everypay-method-grid');
        Assert::contains($content, 'Swedbank');
        Assert::contains($content, self::PAYMENT_LINK . '?method=swedbank_ob_lt');
        Assert::contains($content, 'data-test-everypay-hosted-page-link');
    }

    #[Then('the payment page reads as part of the checkout')]
    public function thePaymentPageReadsAsPartOfTheCheckout(): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        // Order context: number and the amount due.
        Assert::contains($content, '000000001');
        Assert::contains($content, 'data-test-everypay-amount-due');
        Assert::contains($content, '25.99');
        Assert::contains($content, 'data-test-everypay-back-to-order');
    }

    #[Then('the bank buttons are grouped by country, the customer\'s country first')]
    public function theBankButtonsAreGroupedByCountry(): void
    {
        $content = (string) $this->client->getResponse()->getContent();

        $lithuania = strpos($content, 'data-test-everypay-method-group="LT"');
        $international = strpos($content, 'data-test-everypay-method-group="international"');
        $estonia = strpos($content, 'data-test-everypay-method-group="EE"');
        Assert::notFalse($lithuania);
        Assert::notFalse($international);
        Assert::notFalse($estonia);
        // Billing country (LT) first, then international, then the rest.
        Assert::true($lithuania < $international && $international < $estonia, 'Group order is wrong.');

        // Country names come from the locale-aware Sylius filter.
        Assert::contains($content, 'Lithuania');
        Assert::contains($content, 'Estonia');
    }

    #[Given('EveryPay will reject the payment creation')]
    public function everyPayWillRejectThePaymentCreation(): void
    {
        $this->everyPayApi->queueJson([
            'error' => ['code' => 4001, 'message' => 'Authentication failed'],
        ], 401);
    }

    #[Given('EveryPay reports the payment as settled')]
    public function everyPayReportsThePaymentAsSettled(): void
    {
        $this->everyPayApi->queueJson([
            'payment_reference' => self::PAYMENT_REFERENCE,
            'payment_state' => 'settled',
            'payment_method' => 'card',
            'standing_amount' => 25.99,
        ]);
    }

    #[When('the customer proceeds to pay')]
    public function theCustomerProceedsToPay(): void
    {
        $payUrl = $this->router->generate('sylius_shop_order_pay', [
            'tokenValue' => 'everypaytesttoken',
            '_locale' => 'en_US',
        ]);

        $this->browseFrom($payUrl);
    }

    #[When('the customer proceeds to pay again')]
    public function theCustomerProceedsToPayAgain(): void
    {
        $this->theCustomerProceedsToPay();
    }

    #[Then('only one payment creation request was sent to EveryPay')]
    public function onlyOnePaymentCreationRequestWasSentToEveryPay(): void
    {
        $oneoffRequests = array_filter(
            $this->everyPayApi->recordedRequests(),
            static fn (array $request): bool => str_contains($request['url'], '/v4/payments/oneoff'),
        );

        Assert::count($oneoffRequests, 1);
    }

    #[Then('the customer is redirected to the EveryPay payment page')]
    public function theCustomerIsRedirectedToTheEveryPayPaymentPage(): void
    {
        Assert::same($this->externalRedirectUrl, self::PAYMENT_LINK);
    }

    #[Then('the EveryPay payment reference is stored on the payment')]
    public function theEveryPayPaymentReferenceIsStoredOnThePayment(): void
    {
        Assert::same(EveryPayGateway::paymentReferenceFrom($this->payment()->getDetails()), self::PAYMENT_REFERENCE);
    }

    #[When('the customer returns from the EveryPay payment page')]
    public function theCustomerReturnsFromTheEveryPayPaymentPage(): void
    {
        $this->browseFrom($this->customerReturnPath());
    }

    #[When('EveryPay delivers the payment callback')]
    public function everyPayDeliversThePaymentCallback(): void
    {
        $this->client->request(
            'POST',
            sprintf('/payment-methods/everypay?payment_reference=%s&event_name=status_updated', self::PAYMENT_REFERENCE),
        );
    }

    #[Then('the callback is acknowledged')]
    public function theCallbackIsAcknowledged(): void
    {
        $statusCode = $this->client->getResponse()->getStatusCode();
        Assert::range($statusCode, 200, 299);
    }

    #[Then('the payment is completed')]
    public function thePaymentIsCompleted(): void
    {
        Assert::same($this->payment()->getState(), BasePaymentInterface::STATE_COMPLETED);
    }

    #[Then('the payment is failed')]
    public function thePaymentIsFailed(): void
    {
        Assert::same($this->payment()->getState(), BasePaymentInterface::STATE_FAILED);
    }

    #[Then('the customer lands on the thank you page')]
    public function theCustomerLandsOnTheThankYouPage(): void
    {
        $lastPath = $this->visitedPaths[count($this->visitedPaths) - 1] ?? '';
        Assert::contains($lastPath, 'thank-you');
    }

    #[Then('the customer can retry with a fresh payment')]
    public function theCustomerCanRetryWithAFreshPayment(): void
    {
        $order = $this->payment()->getOrder();
        Assert::notNull($order);

        $states = [];
        foreach ($order->getPayments() as $payment) {
            $states[] = (string) $payment->getState();
        }

        Assert::inArray(BasePaymentInterface::STATE_NEW, $states, sprintf(
            'Expected a fresh new payment next to the failed one, got payment states: %s.',
            implode(', ', $states),
        ));
    }

    /**
     * Requests a path and follows internal redirects manually, stopping at an
     * external Location (the hosted payment page) or a non-redirect response.
     */
    private function browseFrom(string $path): void
    {
        $this->externalRedirectUrl = null;
        $this->visitedPaths = [];

        for ($hop = 0; $hop < 6; ++$hop) {
            $this->visitedPaths[] = $path;
            $this->client->request('GET', $path);

            $location = (string) $this->client->getResponse()->headers->get('Location', '');
            if ('' === $location || !$this->client->getResponse()->isRedirection()) {
                return;
            }

            $host = (string) parse_url($location, \PHP_URL_HOST);
            if ('' !== $host && 'localhost' !== $host) {
                $this->externalRedirectUrl = $location;

                return;
            }

            $path = (string) (parse_url($location, \PHP_URL_PATH) ?: $location);
            if (null !== ($query = parse_url($location, \PHP_URL_QUERY))) {
                $path .= '?' . $query;
            }
        }

        throw new \RuntimeException(sprintf('Too many redirects, visited: %s.', implode(' -> ', $this->visitedPaths)));
    }

    /**
     * The return URL EveryPay would send the customer back to - taken from the
     * customer_url of the recorded oneoff request, exactly like the real flow.
     */
    private function customerReturnPath(): string
    {
        foreach ($this->everyPayApi->recordedRequests() as $request) {
            if (!str_contains($request['url'], '/v4/payments/oneoff') || null === $request['body']) {
                continue;
            }
            /** @var array{customer_url?: string} $body */
            $body = json_decode($request['body'], true, 512, \JSON_THROW_ON_ERROR);
            $customerUrl = $body['customer_url'] ?? null;
            Assert::string($customerUrl);

            $path = (string) parse_url($customerUrl, \PHP_URL_PATH);
            Assert::notEmpty($path);

            return $path;
        }

        throw new \RuntimeException('No oneoff request was recorded - did the customer proceed to pay?');
    }

    private function payment(): PaymentInterface
    {
        Assert::notNull($this->payment);

        // Request handling may clear the entity manager and detach our handle.
        if ($this->entityManager->contains($this->payment)) {
            $this->entityManager->refresh($this->payment);

            return $this->payment;
        }

        $payment = $this->entityManager->find($this->payment::class, $this->payment->getId());
        Assert::isInstanceOf($payment, PaymentInterface::class);
        $this->payment = $payment;

        return $payment;
    }
}
