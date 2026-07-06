Feature: Refunding EveryPay payments
    In order to reimburse customers safely
    As an Administrator
    I want refunds to reach EveryPay exactly once — no matter where they start

    Background:
        Given the store operates a channel with EveryPay payments
        And there is a completed EveryPay payment

    Scenario: Refunding a payment from the admin panel
        Given EveryPay will confirm the refund
        When the administrator refunds the payment
        Then a single refund request was sent to EveryPay
        And the payment is refunded

    Scenario: A refund made in the EveryPay portal is never refunded twice
        Given EveryPay reports the payment as refunded
        When EveryPay delivers the refunded callback
        Then the callback is acknowledged
        And the payment is refunded
        And no refund request was sent to EveryPay

    Scenario: A failed refund at EveryPay leaves the payment untouched
        Given EveryPay will fail the refund
        When the administrator tries to refund the payment
        Then the refund is rejected
        And the payment remains completed in the database
