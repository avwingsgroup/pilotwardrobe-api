# Integrations

Every third-party service sits behind a contract in `app/Integrations`, with a real adapter, a fake or log adapter for development and tests, and a driver switch in `.env`. Secrets live only in the server environment and are never returned to the SPA. All outbound HTTP goes through `ProviderHttp`, which sets timeouts and retries connection errors, and classifies failures as **transient** (retried by the queue with backoff) or **permanent** (not retried; recorded with a readable reason).

## Status

**Verification status, read before going live:** every adapter is implemented against the provider's published API and covered by tests with faked HTTP (`tests/Integration`, `tests/Feature`). **None has been exercised against a live or sandbox account in this environment**, because no provider credentials were available. Before launch, run each provider's sandbox checklist below with real test credentials.

| Provider | Purpose | Driver / switch | Contract | Tests (faked HTTP) |
|---|---|---|---|---|
| Paystack | Payments, refunds, transfers (NGN/GHS) | always available when keys are set | `PaymentGateway`, `TransferProvider` | initialize, verify, webhook signature, transfer reconciliation |
| Flutterwave | Payments, refunds, transfers | keys | `PaymentGateway`, `TransferProvider` | major-unit amounts, `verif-hash`, transfer failure reconciliation |
| Monnify | Payments (NGN bank transfer, card) | keys | `PaymentGateway` | OAuth token caching, signature |
| Stripe | Payments (card, Apple Pay, Google Pay via Checkout) | keys | `PaymentGateway` | signature tolerance, session verification |
| PayPal | Payments | keys | `PaymentGateway` | order capture, webhook verification API |
| Sandbox | Fake gateway for dev/staging | `PAYMENTS_SANDBOX_ENABLED` (never production) | `PaymentGateway` | full checkout flow |
| ZeptoMail | Transactional e-mail | `MAIL_MAILER=zeptomail` | Symfony mail transport | message mapping, HTML + text parts |
| Termii → Twilio | SMS with failover | `SMS_PROVIDERS=termii,twilio` | `SmsProvider` | failover without double-sending, stop after first success |
| WhatsApp Cloud API | Order updates (opt-in) | `WHATSAPP_DRIVER=cloud` | `WhatsAppProvider` | template payload |
| Firebase Cloud Messaging | Push | `PUSH_DRIVER=fcm` | `PushProvider` | OAuth service-account token, unregistered-token cleanup |
| Cloudinary | Media storage and delivery | `MEDIA_DRIVER=cloudinary` | `MediaStorage` | transformation URLs |
| Prembly (IdentityPass) | KYC document and identity checks | `KYC_DRIVER=prembly` | `KycProvider` | name matching on lookup results |
| Google / Facebook | Social sign-in (Socialite) | keys | — | redirect state, callback outcomes |
| Google Workspace + WebAuthn | Staff sign-in | `GOOGLE_STAFF_CLIENT_ID`, `WEBAUTHN_RP_ID` | `StaffAuthenticator` | domain restriction |

## Payments

**Contract:** `initialize()` creates the gateway transaction and returns a hosted `authorization_url`. `verify()` fetches the authoritative status, amount and currency. `refund()` refunds all or part of a payment. `parseWebhook()` authenticates and parses a webhook.

**Flow rules** (`App\Services\Payments\PaymentService`):

- The amount and currency are taken from the order on the server. The SPA sends only `order_id`, `gateway` and `return_url`, and `return_url` must be on `APP_FRONTEND_URL`.
- A payment becomes `successful` only after `verify()` returns a matching reference, amount and currency. A mismatch is recorded as failed and the order stays unpaid.
- **Webhooks:** `POST /api/v1/webhooks/{gateway}`. The signature is checked first; a forged webhook gets 400 and nothing is stored. The event is stored once (unique on provider + event id; duplicates get `200 {"duplicate": true}`) and processed by `ProcessPaymentWebhook` on the `payments` queue, which calls `verify()` and never trusts the webhook body.
- **Reconciliation:** `ReconcilePayments` runs every 10 minutes and re-verifies pending payments from the last 24 hours. `GET /payments/{reference}` also verifies on read.
- **Late payments:** a payment that succeeds after its order expired or was cancelled is refunded automatically.

| Gateway | Signature check | Amount units sent | Notes |
|---|---|---|---|
| Paystack | `x-paystack-signature` = HMAC-SHA512(body, secret key) | minor | Popup `public_key` may be exposed; it is public by design |
| Flutterwave | `verif-hash` header equals `FLUTTERWAVE_SECRET_HASH` | major | Set the same secret hash in the Flutterwave dashboard |
| Monnify | `monnify-signature` = HMAC-SHA512(body, secret key) | major | OAuth token cached until expiry |
| Stripe | `Stripe-Signature` v1 = HMAC-SHA256(`t.body`, webhook secret), 5-minute tolerance | minor | Checkout Sessions; wallets on the hosted page |
| PayPal | PayPal `verify-webhook-signature` API with `PAYPAL_WEBHOOK_ID` | major | Captures approved orders on verify |

**Sandbox checklist (per gateway):**

1. Set test keys in `.env`.
2. Register the webhook URL `https://<api-host>/api/v1/webhooks/<gateway>` in the dashboard.
3. Run checkout with a test card or account and check that the order becomes `paid` and escrow `held`.
4. Replay the webhook from the dashboard and check that it gets 200 with `duplicate: true` and nothing changes.
5. Issue a partial refund from `/admin/orders/{id}/refund`.
6. Pay, then wait more than 15 minutes before paying, so the order expires; check the late payment is refunded.

## Payouts

`TransferProvider` adapters: Paystack Transfers (NGN/GHS bank and mobile money), Flutterwave Transfers (bank), and Manual (finance pays outside the platform and records it with `mark-paid`). The provider is chosen per payout method (`TransferProviderRegistry::providerCodeFor`).

- Bank details are validated with the provider when a payout method is added (Paystack transfer recipient; Flutterwave account resolve). The account number, account name and provider recipient code are encrypted at rest; only the last 4 digits are stored in readable form.
- `ProcessPayout` sends the transfer. A "pending" response leaves the payout `processing`. The outcome is confirmed from the provider's status API, triggered by `transfer.*` webhooks or by `marketplace:sweep payouts` every 15 minutes. A failure returns the funds to the seller's wallet with a ledger reversal.
- Payouts above $2,000 need a second approver (dual control). Withdrawals need approved KYC and, when the seller has 2FA turned on, a current code.

**Sandbox checklist:** add a test bank account; withdraw; confirm the payout moves `processing` → `paid` after the provider's test transfer completes. Force a failure with the provider's documented test-failure account or scenario and check the wallet is credited back.

## E-mail: ZeptoMail

`App\Integrations\Mail\ZeptoMailTransport` is registered as the `zeptomail` mailer and posts to the ZeptoMail API with `Authorization: Zoho-enczapikey <token>`. Every e-mail is rendered by `TransactionalMail` with an HTML and a plain-text part, sent from inside the queued `DeliverNotification` job. Templates (subject, heading, lines, call to action) live in `config/notifications.php` and match the template list in `backend-api-contract.md` §3.

Setup: verify the sending domain (SPF, DKIM) in ZeptoMail, set `ZEPTOMAIL_TOKEN`, `MAIL_FROM_ADDRESS` and `ZEPTOMAIL_BOUNCE_ADDRESS`, then `MAIL_MAILER=zeptomail`.

## SMS: Termii with Twilio failover

`FailoverSmsSender` tries each provider in `SMS_PROVIDERS` order. It moves to the next only on a failure that guarantees the message was not sent, so the user never gets the same code twice. Termii posts to `{TERMII_BASE_URL}/api/sms/send` (channel `dnd` for OTPs to DND-registered Nigerian numbers). Twilio uses the Messages API, with a Messaging Service SID or a from number. SMS is used only for codes and a few critical events (D17).

## WhatsApp Cloud API

`MetaCloudWhatsAppProvider` sends approved **templates** (`POST https://graph.facebook.com/{version}/{phone_number_id}/messages`). Users opt in through notification preferences, and the feature flag `whatsapp_notifications` is off by default. Webhooks: `GET /api/v1/webhooks/whatsapp` answers Meta's verification challenge with `WHATSAPP_VERIFY_TOKEN`; `POST` checks `X-Hub-Signature-256` (HMAC-SHA256 with `WHATSAPP_APP_SECRET`) and records delivery statuses.

Setup: create the templates named in `config/notifications.php` (`whatsapp` keys) in Meta Business Manager and wait for approval, then set the phone number id, token and app secret, then `WHATSAPP_DRIVER=cloud`.

## Push: Firebase Cloud Messaging (HTTP v1)

`FcmPushProvider` signs a JWT with the service-account key (`FIREBASE_CREDENTIALS`: a path, inline JSON or base64 JSON), exchanges it for an OAuth token (cached), and sends to each registered device token. Tokens that FCM reports as unregistered are deleted. Devices register through `POST /me/devices`.

## Media: Cloudinary

Uploads go through Laravel (`POST /media`). The file is validated (finfo MIME, size limit 15 MB, minimum dimensions for listing photos) and re-encoded to strip EXIF and GPS data, then uploaded with the server's credentials under a server-generated public id (`<CLOUDINARY_ROOT_FOLDER>/<type>/<owner id>/<id>`). The database stores only the public id, format, dimensions and bytes. Public images are delivered through transformation URLs (`q_auto,f_auto`, widths 160/320/640/1440, AVIF/WebP sources). Private types (`kyc`, `dispute_evidence`, `message`) are uploaded as `authenticated` and served only through signed URLs that expire after 10 minutes, to their owner and to staff with the matching permission. Media not attached to anything within 24 hours is deleted by `pilotwardrobe:prune`.

`MEDIA_DRIVER=local` stores files on the local disk and serves them through signed routes, for development only.

## KYC: Prembly (IdentityPass)

`KycService` runs document and identity checks asynchronously (`RunKycChecks` job): a document lookup (NIN, BVN, passport, driver's licence, voter's card) with name and date-of-birth comparison, and an optional face comparison against the selfie. The results are stored as advisory `automated_checks` for the human reviewer in `/admin/kyc`; the reviewer always makes the decision. Webhooks: `POST /api/v1/webhooks/prembly`, HMAC-SHA512 of the body with `PREMBLY_WEBHOOK_SECRET` (header configurable via `kyc.prembly.signature_header`). Endpoint paths are configuration because they could not be confirmed against a live account (D10).

PII handling: legal names, date of birth and document numbers are encrypted at rest and hidden from serialisation. Document images are private Cloudinary assets.

## Social and staff sign-in

- **Google and Facebook** (Laravel Socialite, stateless). The backend creates the authorisation URL with its own `state` (cached for 10 minutes); the SPA posts `code` and `state` back. Apple is not implemented (D8).
- **Staff:** the Google ID token from Google Identity Services is verified against Google's JWKS (signature, audience `GOOGLE_STAFF_CLIENT_ID`, issuer, expiry, `hd` = `STAFF_EMAIL_DOMAIN`), then a WebAuthn assertion is verified with `lbuchs/webauthn` (relying party `WEBAUTHN_RP_ID`, which must be the admin console's domain).

## Adding a provider

1. Implement the contract in `app/Integrations/<Kind>/` using `ProviderHttp` and throwing `ProviderException::transient()` or `::permanent()`.
2. Register it in the kind's registry or service-provider binding (`IntegrationsServiceProvider`), with credentials read from `config/services.php` (env only).
3. Add an integration test with `Http::fake()` covering the request mapping, response normalisation and signature verification.
4. Document the env keys in `.env.example` and the provider in this file.
