# Payments

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Gateway-agnostic payment flow. **The frontend never decides that a payment succeeded.**

1. `GET /payment-methods?currency=&country=` — which gateways to offer (Stripe, PayPal, Paystack, Flutterwave, Monnify), with display metadata only.
2. `POST /payments/initialize` — Laravel creates the payment with the gateway using its **secret key** and returns `authorization_url` + `reference`. The frontend redirects only if the host is on its allow-list (`checkout.stripe.com`, `*.paypal.com`, `checkout.paystack.com`, `checkout.flutterwave.com`, `*.monnify.com`).
3. The gateway redirects the buyer to `return_url` (`/payment/callback?reference=…`). Query parameters such as `status=success` are **ignored**.
4. The frontend polls `GET /payments/{reference}` (backoff: 1.5 s → 8 s, ~60 s budget). Laravel verifies with the gateway API and/or the signed webhook, then returns `status` (`pending | processing | successful | failed | cancelled`). On `successful` the order moves to `paid` and escrow to `held`.

Webhooks (`POST /webhooks/{gateway}`) are gateway → Laravel only, must verify signatures, must be idempotent on `reference`, and are not called by the frontend. Card numbers/CVV never touch Pilotwardrobe servers or the SPA: the gateway's hosted page collects them.

## Samples

### Available payment methods

`GET /payment-methods?currency=NGN&country=NG` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "code": "paystack",
      "name": "Paystack",
      "description": "Card, bank transfer, USSD",
      "flow": "redirect",
      "enabled": true,
      "unavailable_reason": null
    },
    {
      "code": "flutterwave",
      "name": "Flutterwave",
      "description": "Card, bank, mobile money",
      "flow": "redirect",
      "enabled": true,
      "unavailable_reason": null
    },
    "… 1 more"
  ]
}
```

### Initialize payment

`POST /payments/initialize` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "order_id": "ord_4485",
  "gateway": "paystack",
  "return_url": "https://pilotwardrobe.com/payment/callback"
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "payment_id": "pay_z0zatcg",
    "gateway": "paystack",
    "status": "pending",
    "reference": "PW-2026-569051",
    "authorization_url": "/payment/mock-gateway?reference=PW-2026-569051&gateway=paystack&return_url=https%3A%2F%2Fpilotwardrobe.com%2Fpayment%2Fcallback"
  }
}
```

### Verify payment (poll)

`GET /payments/PW-2026-569051` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "pay_z0zatcg",
    "reference": "PW-2026-569051",
    "status": "pending",
    "gateway": "paystack",
    "order_id": "ord_4485",
    "amount": {
      "amount": 19061,
      "currency": "USD"
    }
  }
}
```

### Initialize — unsupported gateway (422)

`POST /payments/initialize` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "order_id": "ord_4485",
  "gateway": "",
  "return_url": "https://pilotwardrobe.com/payment/callback"
}
```

Response `422`:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "gateway": [
      "Choose a payment method."
    ]
  }
}
```
