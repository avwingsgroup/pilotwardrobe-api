# Backend implementation notes: decisions and assumptions

Decisions the Laravel team made where the contract, handoff or designs were silent or ambiguous. Each one is referenced from code or other docs by its number. Status as of 2026-10-21.

**For product/ops to confirm before launch:** D3, D4, D8, D10, D11, D12, D21 and the open items at the end.

## Data and money

**D1 · Prefixed string ids.** Every domain table uses an opaque id of the form `prefix_` + lowercase ULID (`usr_01j…`, `ord_01j…`). The ids sort by creation time, don't leak row counts, and match the contract's "opaque prefixed strings". Framework tables (`jobs`, `cache`, `personal_access_tokens`) keep integer ids.

**D2 · Money is integer minor units, and the listing's currency is the source of truth.** Orders, escrow, refunds and payouts are in the currency the seller listed in. `exchange_rates` is used only for display hints, dual-control thresholds (which are defined in USD) and admin reporting. Gateways receive major units through `Amounts::toMajor()`; Paystack and Stripe get minor units where their APIs expect them.

**D4 · One order per seller, one currency per checkout.** A multi-seller cart produces one order per seller, so dispatch, escrow, disputes and reviews stay per seller. Mixing currencies in one checkout returns 422. *Confirm with product: the designs only show single-seller checkout.*

**D5 · Escrow uses a double-entry ledger.** Each wallet has four buckets: `escrow`, then `pending_release`, then `available`, plus `reserved` while a withdrawal is in flight. Every balance change writes `ledger_entries`, which are append-only and protected by a database trigger. A `wallet_movements` marker per business event makes each movement idempotent, and balances only change under a row lock. Funds move from `pending_release` to `available` when the dispute window ends. For new sellers (account younger than 30 days, or no settled sale yet) they wait at least `new_seller_payout_hold_days` (default 7) after payment, whichever is later.

**D6 · Webhooks never mark payments paid on their own.** A webhook only triggers a server-side `verify()` call to the gateway API, which must match reference, amount and currency. `GET /payments/{reference}` verifies the same way when the webhook is late. `ReconcilePayments` runs every 10 minutes and catches webhooks that never arrived. A payment that succeeds for an order that was already cancelled or expired ("late" or "orphan") is refunded automatically.

**D7 · Concurrency.** Checkout reserves stock with `reserved_quantity` under `SELECT … FOR UPDATE`. Accepting offers and swaps, wallet movements, payouts and dispute decisions each lock their rows. Partial unique indexes back these up at the database level: one pending offer per buyer per listing, one open payment per payable and gateway, one active dispute per order, one default address.

## Identity and access

**D3 · Aviator role only on approval.** Registering with `account_type=aviator` creates an ordinary user. The `aviator` role (unlimited listings, aviator badge) is granted only when staff approve an aviator credential (`/admin/aviators/{id}/approve`). This prevents self-declared aviators. *Confirm with product.*

**D8 · Social sign-in is Google and Facebook only.** Apple sign-in is not implemented; it needs an Apple developer account, a Services ID and a private key. `/auth/social/apple/*` returns 404 until it is added (one new Socialite provider plus config).

**D9 · Staff authentication.** Production staff sign in with Google Workspace (ID token verified against Google's JWKS and restricted to `STAFF_EMAIL_DOMAIN`), followed by a WebAuthn security key. Staff sessions idle out after 30 minutes. Password login for staff exists only when `STAFF_PASSWORD_LOGIN=true`, which is meant for local development and demo seeding.

**D13 · Permissions are resolved server-side and cached.** Effective permissions are role defaults, plus the staff-role bundle, plus user grants, minus revocations. They are cached per user and keyed by `permissions_version`, which is bumped on every change and returned by `/auth/me` so the SPA can tell when to refresh.

## Payments and integrations

**D10 · Prembly endpoints are configurable, not verified live.** The Prembly (IdentityPass) adapter follows the published API, but its endpoint paths are configuration (`PREMBLY_*_ENDPOINT`) because no sandbox account was available. Treat KYC automation as unverified until it is tested against a Prembly sandbox. Human review in `/admin/kyc` works regardless.

**D11 · Import duties come from a settings table.** `defaults.duties` holds basis points by destination country, and the value is shown as an estimate at checkout for cross-border orders only. Both Nigeria and Ghana default to 0. *Ops must enter real rates or turn the line off.*

**D14 · Google Pay goes through Stripe Checkout.** Stripe's hosted page offers card, Apple Pay and Google Pay, so there is no separate wallet-token integration.

**D15 · Sandbox gateway instead of the SPA mock.** In non-production with `PAYMENTS_SANDBOX_ENABLED=true`, `gateway=sandbox` sends the buyer to the SPA's existing `/payment/mock-gateway` page, and the outcome is set by `POST /sandbox/payments/{ref}/{approve|decline|cancel}`. The gateway refuses to run in production.

**D16 · Payout providers.** Payouts go through Paystack Transfers (NGN/GHS) or Flutterwave Transfers. Any other currency or country falls back to a **manual** provider: finance pays outside the platform and records it with `/admin/payouts/{id}/mark-paid`. Transfers the provider accepts as pending are settled from the provider's own status (`GET /transfer/verify/{ref}` or `GET /transfers/{id}`). That check is triggered by signed `transfer.*` webhooks and by the `marketplace:sweep payouts` task every 15 minutes, so a webhook body alone never marks a payout paid or failed, and a missed webhook can't strand a payout.

**D17 · SMS is used only for codes and critical events.** Termii is the primary SMS provider with automatic Twilio failover (`SMS_PROVIDERS=termii,twilio`). SMS is sent only for 2FA and verification codes and a few critical order and payout events. WhatsApp templates (`order_paid`, `order_new_sale`, `order_dispatched`, …) must be created and approved in Meta Business Manager under those names before `WHATSAPP_DRIVER=cloud` is switched on.

**D18 · Media goes through Laravel.** Uploads are checked by MIME (finfo), size and dimensions. Images are re-encoded with GD, which strips EXIF and GPS data, then uploaded to Cloudinary under server-generated public ids. Private types (`kyc`, `dispute_evidence`) use authenticated delivery and signed URLs that expire in 10 minutes. HEIC is converted to JPEG by a Cloudinary incoming transformation, because GD cannot decode it. The `local` driver (development only) cannot convert HEIC: it stores the original bytes under a `.jpg` name without dimensions, so test HEIC against Cloudinary.

## Platform

**D12 · No real-time transport.** The SPA polls for messages and notifications. Payloads are designed so Reverb or Pusher can be added later without changing them.

**D19 · Optional null fields may be omitted.** Some presenters drop keys whose value is `null`. The handoff states the SPA treats missing optional fields as absent; the [payload audit](payload-compatibility-audit.md) lists where this happens.

**D20 · Caches hold only scalars and arrays.** Laravel 13 refuses to unserialize objects from the cache by default (`serializable_classes=false`), so commission rules, settings and permissions are cached as plain arrays and rehydrated. The test suite runs with array-cache serialization turned on so a regression fails CI.

**D21 · Defaults that ops should review.** Auto-release 72 h after delivery, dispatch SLA 3 days, dispute window 72 h, offers expire after 24 h, swaps after 48 h, checkout reservation 15 minutes, 3 live listings before KYC is required, and moderation auto-approves official stores, approved aviators and KYC-verified sellers. All of these live in `config/platform.php` and the `platform_settings` table (editable in `/admin/settings`, under dual control where marked).

**D22 · Queues.** There are four queues: `payments` (webhooks, refunds, payouts, reconciliation; isolated so a notification backlog can't delay money), `notifications`, `default`, and `long` (exports and broadcasts) on its own Redis connection with a larger `retry_after`. Horizon supervisors match this layout.

**D23 · API docs are not public by default.** `/api/documentation` and `/openapi` are open in `local` and otherwise limited to signed-in staff, unless `API_DOCS_PUBLIC=true`.

## Not implemented / open

- Apple sign-in (D8).
- Real-time transport (D12).
- SEO helpers from handoff §11: Laravel does not yet serve OG-tag HTML for `/marketplace/{slug}` or `sitemap.xml`. Removed listings already return **410 Gone**.
- Live verification of every third-party integration against its sandbox. All adapters are covered by contract tests with recorded/faked HTTP (`tests/Integration`), but none has been exercised against real sandbox credentials in this environment. See [integrations.md](integrations.md).
- The Docker image and CI workflow were written but not executed here: Docker was not available on the development machine, and the workflow has not run on GitHub yet.
