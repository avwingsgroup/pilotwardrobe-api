# Wallet, transactions & payouts

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Seller wallet (VB7), ledger, payout methods and withdrawals.

- Balances are derived from an append-only ledger; `available` excludes escrow and pending withdrawals.
- `POST /me/withdrawals/quote` returns fee + net + ETA; `POST /me/withdrawals` requires `two_factor_code` when the account has 2FA, and is rejected (422) if `amount` > `available`.
- Withdrawals above the platform threshold (default $2,000) are created as `pending` and require a staff approval in AD12 (dual control).
- Payout method creation stores only gateway recipient codes / masked account numbers; full bank details are verified via the gateway (Paystack/Flutterwave transfer recipients, Stripe Connect) and never echoed back unmasked.

## Samples

### Wallet

`GET /me/wallet` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "available": {
      "amount": 341055,
      "currency": "USD"
    },
    "in_escrow": {
      "amount": 128400,
      "currency": "USD"
    },
    "pending_release": {
      "amount": 14500,
      "currency": "USD"
    },
    "lifetime_earnings": {
      "amount": 1892040,
      "currency": "USD"
    },
    "total": {
      "amount": 469455,
      "currency": "USD"
    },
    "escrow_orders": 2,
    "pending_release_in": "71h",
    "this_month_earnings": {
      "amount": 214000,
      "currency": "USD"
    }
  }
}
```

### Wallet transactions

`GET /me/wallet/transactions?per_page=2` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "tx_1",
      "occurred_at": "2026-10-20T13:08:31.290Z",
      "description": "Sale · Aviator Uniform Shirt",
      "detail": "Order #PW-4468 · fee $11.60",
      "type": "sale",
      "status": "pending",
      "amount": {
        "amount": 13340,
        "currency": "USD"
      },
      "direction": "credit",
      "order_id": "ord_4468"
    },
    {
      "id": "tx_2",
      "occurred_at": "2026-10-18T13:08:31.290Z",
      "description": "Sale · Jeppesen Captain Flight Bag",
      "detail": "Order #PW-4471",
      "type": "sale",
      "status": "in_escrow",
      "amount": {
        "amount": 16560,
        "currency": "USD"
      },
      "direction": "credit",
      "order_id": "ord_4471"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 8,
    "last_page": 4,
    "from": 1,
    "to": 2
  },
  "links": {
    "first": "?page=1",
    "last": "?page=4",
    "next": "?page=2",
    "prev": null
  }
}
```

### Payout methods

`GET /me/payout-methods` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "pm_1",
      "kind": "bank",
      "label": "GTBank · NGN",
      "currency": "NGN",
      "last4": "0921",
      "is_default": true
    },
    {
      "id": "pm_2",
      "kind": "wise",
      "label": "Wise · USD",
      "currency": "USD",
      "last4": "7714",
      "is_default": false
    }
  ]
}
```

### Withdrawal quote

`POST /me/withdrawals/quote` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "amount": 20000,
  "currency": "USD",
  "payout_method_id": "pm_1"
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "amount": {
      "amount": 20000,
      "currency": "USD"
    },
    "exchange_rate": {
      "from": "USD",
      "to": "NGN",
      "rate": 1532.4,
      "label": "1 USD = ₦1,532.40"
    },
    "fee": {
      "amount": 0,
      "currency": "USD"
    },
    "recipient_receives": {
      "amount": 30648000,
      "currency": "NGN"
    },
    "arrives_between": {
      "from": "2026-10-22T13:08:31.554Z",
      "to": "2026-10-23T13:08:31.554Z"
    },
    "requires_two_factor": false
  }
}
```

### Request withdrawal

`POST /me/withdrawals` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "amount": 20000,
  "currency": "USD",
  "payout_method_id": "pm_1",
  "two_factor_code": "123456"
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Withdrawal requested.",
  "data": {
    "id": "tx_6tqr2ig",
    "occurred_at": "2026-10-21T13:08:31.554Z",
    "description": "Payout to GTBank · NGN •••• 0921",
    "detail": "Processing",
    "type": "withdrawal",
    "status": "pending",
    "amount": {
      "amount": 20000,
      "currency": "USD"
    },
    "direction": "debit"
  }
}
```
