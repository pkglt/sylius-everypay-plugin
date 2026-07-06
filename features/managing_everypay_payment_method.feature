Feature: Managing the EveryPay payment method
    In order to accept EveryPay payments
    As an Administrator
    I want to configure the gateway credentials safely

    Background:
        Given the store operates a channel with EveryPay payments
        And I am logged in as an administrator

    Scenario: Seeing the credential fields when creating an EveryPay method
        When I open the EveryPay payment method creation form
        Then I should see the EveryPay credential fields

    Scenario: Keeping the stored API secret when leaving the field blank
        When I edit the EveryPay payment method leaving the API secret blank
        Then the previously stored API secret is kept
