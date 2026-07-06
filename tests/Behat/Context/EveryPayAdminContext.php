<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\ORM\EntityManagerInterface;
use Pkg\SyliusEveryPayPlugin\EveryPayGateway;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;
use Tests\Pkg\SyliusEveryPayPlugin\Behat\SharedStorage;
use Tests\Pkg\SyliusEveryPayPlugin\Support\ShopFixtures;
use Webmozart\Assert\Assert;

/**
 * Admin credential management for the EveryPay gateway, driven through the
 * real admin pages: the twig-hook-rendered credential fields and the
 * "leave blank to keep the stored secret" behaviour of the password field.
 */
final class EveryPayAdminContext implements Context
{
    public function __construct(
        private readonly ShopFixtures $shopFixtures,
        private readonly SharedStorage $sharedStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly RouterInterface $router,
    ) {
    }

    #[Given('I am logged in as an administrator')]
    public function iAmLoggedInAsAnAdministrator(): void
    {
        $this->client()->loginUser($this->shopFixtures->createAdminUser(), 'admin');
    }

    #[When('I open the EveryPay payment method creation form')]
    public function iOpenTheEveryPayPaymentMethodCreationForm(): void
    {
        $this->client()->request('GET', $this->router->generate('sylius_admin_payment_method_create', ['factory' => 'everypay']));
    }

    #[Then('I should see the EveryPay credential fields')]
    public function iShouldSeeTheEveryPayCredentialFields(): void
    {
        Assert::true($this->client()->getResponse()->isSuccessful());
        $content = (string) $this->client()->getResponse()->getContent();

        foreach (['api_username', 'api_secret', 'account_name', 'environment'] as $field) {
            Assert::contains($content, sprintf('[gatewayConfig][config][%s]', $field));
        }
    }

    #[When('I edit the EveryPay payment method leaving the API secret blank')]
    public function iEditTheEveryPayPaymentMethodLeavingTheApiSecretBlank(): void
    {
        $method = $this->sharedStorage->get('payment_method', PaymentMethodInterface::class);

        $crawler = $this->client()->request('GET', $this->router->generate('sylius_admin_payment_method_update', ['id' => $method->getId()]));
        Assert::true($this->client()->getResponse()->isSuccessful());

        $form = $crawler->selectButton('Update')->form();
        // A password widget never re-renders its value — the browser submits
        // an empty string exactly like an untouched admin form does.
        $form['sylius_admin_payment_method[gatewayConfig][config][api_secret]'] = '';
        $form['sylius_admin_payment_method[gatewayConfig][config][api_username]'] = 'updated1234abcd0';

        $this->client()->submit($form);
    }

    #[Then('the previously stored API secret is kept')]
    public function thePreviouslyStoredApiSecretIsKept(): void
    {
        $method = $this->sharedStorage->get('payment_method', PaymentMethodInterface::class);
        $id = $method->getId();

        $this->entityManager->clear();
        $method = $this->entityManager->find($method::class, $id);
        Assert::isInstanceOf($method, PaymentMethodInterface::class);

        $gatewayConfig = $method->getGatewayConfig();
        Assert::notNull($gatewayConfig);
        $config = $gatewayConfig->getConfig();

        Assert::same($config[EveryPayGateway::CONFIG_API_SECRET] ?? null, 'test-secret');
        Assert::same($config[EveryPayGateway::CONFIG_API_USERNAME] ?? null, 'updated1234abcd0');
    }

    private function client(): KernelBrowser
    {
        return $this->sharedStorage->get('client', KernelBrowser::class);
    }
}
