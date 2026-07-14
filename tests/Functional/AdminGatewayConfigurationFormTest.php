<?php

declare(strict_types=1);

namespace Tests\Pkg\SyliusEveryPayPlugin\Functional;

use Symfony\Component\Routing\RouterInterface;

/**
 * Renders the real admin payment method form for the everypay factory. This is
 * the regression trap the wiring cannot catch statically: without the
 * gateway_configuration.everypay twig hook the credential fields silently
 * disappear from the page.
 */
final class AdminGatewayConfigurationFormTest extends FunctionalTestCase
{
    public function testGatewayCredentialFieldsRenderOnTheCreateForm(): void
    {
        $client = static::createClient();
        $this->prepareDatabase();
        $this->createShopEnvironment();
        $admin = $this->shopFixtures()->createAdminUser();

        $client->loginUser($admin, 'admin');

        $router = $this->service(RouterInterface::class, 'router');
        $url = $router->generate('sylius_admin_payment_method_create', ['factory' => 'everypay']);

        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        foreach (['api_username', 'api_secret', 'account_name', 'environment', 'display_mode'] as $field) {
            self::assertStringContainsString(
                sprintf('[gatewayConfig][config][%s]', $field),
                $content,
                sprintf('The "%s" gateway field is missing - is the gateway_configuration.everypay twig hook loaded?', $field),
            );
        }

        // All three checkout appearances are offered.
        foreach (['redirect', 'method_grid', 'payment_elements'] as $displayMode) {
            self::assertStringContainsString(sprintf('value="%s"', $displayMode), $content);
        }

        // Labels prove the plugin translations are wired into the form.
        self::assertStringContainsString('Processing account', $content);
        self::assertStringContainsString('leave empty to keep the currently stored secret', $content);

        // Browsers must neither save/autofill the credential pair (an
        // autofilled secret would silently overwrite the stored one) nor
        // offer to GENERATE a password - the secret is issued by EveryPay.
        self::assertStringContainsString('autocomplete="one-time-code"', $content);
        self::assertStringContainsString('autocomplete="off"', $content);
    }
}
