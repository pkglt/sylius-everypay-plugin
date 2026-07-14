Feature: Paying with EveryPay
    In order to pay for my order with a card or my bank
    As a Customer
    I want to be sent to the EveryPay hosted payment page and have my payment settled reliably

    Background:
        Given the store operates a channel with EveryPay payments
        And there is an order awaiting payment

    Scenario: Being redirected to the EveryPay hosted payment page
        Given EveryPay will accept the payment creation
        When the customer proceeds to pay
        Then the customer is redirected to the EveryPay payment page
        And the EveryPay payment reference is stored on the payment

    Scenario: Completing the payment after returning from EveryPay
        Given EveryPay will accept the payment creation
        And the customer proceeds to pay
        And EveryPay reports the payment as settled
        When the customer returns from the EveryPay payment page
        Then the payment is completed
        And the customer lands on the thank you page

    Scenario: The server callback settles the payment when the customer never returns
        Given EveryPay will accept the payment creation
        And the customer proceeds to pay
        And EveryPay reports the payment as settled
        When EveryPay delivers the payment callback
        Then the callback is acknowledged
        And the payment is completed

    Scenario: Recovering when EveryPay rejects the payment creation
        Given EveryPay will reject the payment creation
        When the customer proceeds to pay
        Then the payment is failed
        And the customer can retry with a fresh payment

    Scenario: Reloading the payment page does not create a second EveryPay payment
        Given EveryPay will accept the payment creation
        And the customer proceeds to pay
        When the customer proceeds to pay again
        Then the customer is redirected to the EveryPay payment page
        And only one payment creation request was sent to EveryPay

    Scenario: Repeated callbacks are handled idempotently
        Given EveryPay will accept the payment creation
        And the customer proceeds to pay
        And EveryPay reports the payment as settled
        And EveryPay delivers the payment callback
        And EveryPay reports the payment as settled
        When EveryPay delivers the payment callback
        Then the callback is acknowledged
        And the payment is completed

    Scenario: Choosing the bank directly in the shop
        Given the EveryPay payment method shows the method buttons in the shop
        And EveryPay will accept the payment creation with a list of bank methods
        When the customer proceeds to pay
        Then the customer sees the bank buttons instead of being redirected
        And the payment page reads as part of the checkout
        And the bank buttons are grouped by country, the customer's country first

    Scenario: Paying inside the shop with the embedded checkout
        Given the EveryPay payment method uses the embedded checkout
        And EveryPay will accept the payment creation with a mobile access token
        When the customer proceeds to pay
        Then the customer sees the embedded checkout instead of being redirected
        And the payment page reads as part of the checkout

    Scenario: The embedded checkout falls back to the hosted page without a token
        Given the EveryPay payment method uses the embedded checkout
        And EveryPay will accept the payment creation
        When the customer proceeds to pay
        Then the customer is redirected to the EveryPay payment page
