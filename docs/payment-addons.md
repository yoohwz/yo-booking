# Payment add-on contract

Online gateways are add-ons. Core owns appointment totals, immutable currency snapshots, the payment ledger, transaction idempotency, and refund limits.

1. Implement `PaymentProviderInterface` and `OnlinePaymentGatewayInterface`.
2. Implement `PaymentProviderCapabilitiesInterface` when the gateway restricts currencies, countries, or transaction amounts.
3. Register the provider with `yo_booking_payment_providers`.
4. Return an HTTPS `checkout_url` from `create_checkout()`.
5. Verify webhook signatures in the add-on, normalize the event, and pass a stable idempotency key to `PaymentManager::record_transaction()`.
6. Never update appointment payment totals directly; core recalculates them from successful ledger rows under an appointment-level lock.

Appointments are single-currency snapshots. Reports separate totals by currency. Fixed deposits use the default company currency, so multi-currency catalogs should use percentage deposits.
