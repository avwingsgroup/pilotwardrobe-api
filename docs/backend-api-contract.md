# Pilotwardrobe — Backend API contract

The REST contract the Vue SPA is built against. The Laravel API must implement it as written; the in-browser mock server (`src/mocks/`) already does, so it doubles as the executable reference.

- **Every endpoint**, with its guard, statuses and error codes: [§Endpoint reference](#endpoint-reference). This is generated from the mock handlers.
- **Full request/response bodies for each domain**: [`docs/api-payloads/`](api-payloads/README.md). These are generated from a contract test.
- **Integration narrative** (auth model, state machines, media, payments, SEO): [`docs/frontend-backend-handoff.md`](frontend-backend-handoff.md).

Whenever you change a handler in `src/mocks/handlers`, regenerate both references:

```bash
python scripts/generate-api-contract.py   # endpoint tables in this file
npm run docs:payloads                      # docs/api-payloads/*.md (also runs the contract test)
```

---

## 1. Conventions

### 1.1 Base URL and versioning

```
https://api.pilotwardrobe.com/api/v1
```

The SPA reads this from `VITE_API_BASE_URL`, with the version prefix included. Breaking changes ship under `/api/v2`. Additive changes (new fields, new endpoints) do not need a new version, and the SPA ignores unknown fields.

### 1.2 Request headers the SPA sends

| Header | Value | Notes |
|---|---|---|
| `Accept` | `application/json` | |
| `Content-Type` | `application/json`, or `multipart/form-data` for uploads | |
| `X-Requested-With` | `XMLHttpRequest` | Makes Laravel return JSON 401s instead of redirects |
| `X-Request-Id` | random UUID per request | Echo it back as `X-Request-Id` and log it, so support can correlate errors |
| `Accept-Language` | `en`, `en-NG`, … | Localise `message`, the `formatted` money strings and validation text |
| `X-XSRF-TOKEN` | from the `XSRF-TOKEN` cookie | Cookie mode only (Sanctum SPA) |
| `Authorization` | `Bearer <token>` | Token mode only |

The SPA sends requests `withCredentials: true` in cookie mode. CORS must allow the SPA origin with credentials, and `SANCTUM_STATEFUL_DOMAINS` and `SESSION_DOMAIN` must cover it.

### 1.3 Casing

The wire format is **snake_case** in both directions. The SPA converts object **keys** to and from camelCase at the HTTP boundary. Values are never touched, so enum values stay snake_case (`pending_payment`, `like_new`).

### 1.4 Success envelope

```json
{ "success": true, "message": "Optional human message", "data": { } }
```

Paginated collections:

```json
{
  "success": true,
  "data": [ ],
  "meta": { "current_page": 1, "per_page": 20, "total": 132, "last_page": 7, "from": 1, "to": 20 },
  "links": { "first": "…?page=1", "last": "…?page=7", "next": "…?page=2", "prev": null }
}
```

`meta` may carry extra keys: `facets` on `/listings`, and `counts` (tab badges) on admin tables.

`204 No Content` responses have an empty body.

### 1.5 Error envelope

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "VALIDATION_ERROR",
  "errors": { "email": ["An account with this email already exists."] },
  "meta": { "attempts_remaining": 2 }
}
```

- `errors` keys are snake_case field names, and nested fields use dot notation (`items.0.size`). The SPA maps them onto form fields and shows the first message for each field.
- `message` is shown to users, so it must never contain stack traces, SQL or gateway responses. Production must run with `APP_DEBUG=false`.

| HTTP | `code` | When | What the SPA does |
|---|---|---|---|
| 400 | `UNKNOWN` | Malformed request | Toast |
| 401 | `UNAUTHENTICATED` | No session / token expired | Clears the session and redirects to `/auth/login?next=…` |
| 403 | `FORBIDDEN` | Missing role or permission, or not the owner | Shows the 403 page or an inline notice |
| 403 | `KYC_REQUIRED` | The action needs verified identity | Shows the "Verify to continue" CTA |
| 403 | `EMAIL_NOT_VERIFIED` | Unverified email | Redirects to email verification |
| 404 | `NOT_FOUND` | | Shows the 404 state |
| 409 | `CONFLICT` | Duplicate or business-rule clash | Toast with `message` |
| 409 | `INVALID_STATE_TRANSITION` | Action illegal in the current state | Toast, then refetches the resource |
| 410 | `TOKEN_EXPIRED` | Expired reset link / invitation | Shows the expired state |
| 419 | `SESSION_EXPIRED` | CSRF mismatch or expired auth challenge | Session-expired page |
| 422 | `VALIDATION_ERROR` | Validation | Field errors |
| 423 | `ACCOUNT_LOCKED` | Too many attempts (`meta.retry_after`) | Lock notice |
| 429 | `RATE_LIMITED` | Throttled (`Retry-After` header) | "Slow down" toast |
| 402 / 422 | `PAYMENT_FAILED` | Gateway declined | Payment-failed state |
| 503 | `GATEWAY_UNAVAILABLE` | PSP down | Suggests another method |
| 503 | `MAINTENANCE` | Maintenance mode | Maintenance page |
| 500 | `SERVER_ERROR` | Unexpected | Generic error with the request id |

### 1.6 Data types

| Type | Wire format | Example |
|---|---|---|
| Id | opaque string, prefixed | `"lst_001"`, `"ord_4471"`, `"usr_10422"` |
| Money | `{ "amount": int (minor units), "currency": ISO-4217, "formatted"?: string }` | `{ "amount": 74500, "currency": "USD", "formatted": "$745.00" }` |
| Money in **requests** | flat `amount` (int, minor units) + `currency` | `{ "amount": 12325, "currency": "USD" }` |
| Timestamp | ISO-8601 **UTC** with `Z` | `"2026-07-31T10:42:08Z"` |
| Date | `YYYY-MM-DD` | `"1990-04-12"` |
| Country | ISO-3166-1 alpha-2 | `"NG"` |
| Media | `MediaAsset` (see §1.8) | |
| Booleans in query strings | `true` / `false` | `?verified_only=true` |
| Lists in query strings | comma-separated | `?condition=new,like_new` |

Supported currencies: `USD`, `NGN`, `GHS`, `GBP`, `EUR`. Never use floats for money.

### 1.7 Query conventions

- Pagination: `page` (1-based) and `per_page` (default 20, max 100).
- Sorting: `sort=field` for ascending and `sort=-field` for descending on admin tables. Marketplace sorts are named values (`newest`, `price_asc`, …).
- Search: `search=` for full-text search.
- Filters: flat snake_case params (`status=`, `country=`, `category=`).
- Admin tables also take a `tab=` parameter, and return `meta.counts` for the tab badges.

### 1.8 Media (Cloudinary)

Laravel owns the Cloudinary credentials and every upload. The SPA uploads files to `POST /media` (multipart) and only ever receives:

```json
{
  "id": "med_7Jk2",
  "public_id": "pw/listings/lst_001/bose-a20-1",
  "url": "http://res.cloudinary.com/pilotwardrobe/image/upload/v1/pw/listings/…",
  "secure_url": "https://res.cloudinary.com/pilotwardrobe/image/upload/v1/pw/listings/…",
  "width": 1600, "height": 1067, "format": "jpg", "bytes": 284113,
  "alt": "Bose A20 headset, left ear cup",
  "placeholder": "#d9dde3",
  "variants": {
    "thumbnail": "…/c_fill,w_160,h_160,q_auto,f_auto/…",
    "small": "…/c_limit,w_320,q_auto,f_auto/…",
    "medium": "…/c_limit,w_640,q_auto,f_auto/…",
    "large": "…/c_limit,w_1440,q_auto,f_auto/…",
    "original": "…/q_auto,f_auto/…"
  },
  "sources": [
    { "url": "…/c_limit,w_320,f_avif,q_auto/…", "width": 320, "format": "avif" },
    { "url": "…/c_limit,w_320,f_webp,q_auto/…", "width": 320, "format": "webp" }
  ]
}
```

- `width` and `height` are **required**. The SPA reserves the aspect ratio from them, so there is no layout shift.
- `sources` is optional. When it is present, the SPA builds `<picture>` with AVIF, then WebP, then JPEG. When it is absent, the SPA builds `srcset` from `variants`.
- `alt` is required for listing photos. Default it to the listing title plus the photo position.
- Private media (`kyc`, `dispute_evidence`, and staff-only documents) must use Cloudinary `type=authenticated`, with signed URLs that expire within 10 minutes. Never return private media in public payloads.
- Upload limits: 15 MB; JPEG, PNG, WebP or HEIC (PDF is also allowed for `kyc` and `dispute_evidence`). Strip EXIF GPS data on ingest.

### 1.9 Rate limits (recommended)

| Scope | Limit |
|---|---|
| `/auth/*` per IP | 10 per minute |
| `/auth/login` per email | 5 failures, then a 15-minute lock (423) |
| OTP resend | 1 per 60 s |
| `POST /conversations/*/messages` | 30 per minute per user |
| `POST /listings/*/offers` | 20 per hour per user |
| Everything else | 120 per minute per user |

### 1.10 Idempotency

`POST /payments/initialize`, `POST /orders`, `POST /me/withdrawals` and `POST /admin/payouts/batches` should accept an optional `Idempotency-Key` header and return the original response when they see a repeated key. Gateway webhooks must be idempotent on the gateway reference.

---

## 2. Roles & permissions

The roles are `user`, `aviator`, `admin` and `super_admin`. A signed-out visitor has the implicit role `visitor`.

`GET /auth/me` returns `role` **and** `permissions`, the effective list after role defaults, grants and revocations. The SPA uses that list for every guard, so a permission can be granted to one person without a frontend change. **Every endpoint must re-check authorisation server-side.** Hiding a control in the UI is not access control.

| Permission | user | aviator | admin (Trust & safety default) | super_admin |
|---|:-:|:-:|:-:|:-:|
| `listings.create`, `listings.update`, `listings.promote` | ✓ | ✓ | | ✓ |
| `offers.create`, `swaps.create`, `orders.create` | ✓ | ✓ | | ✓ |
| `payouts.request`, `reviews.create`, `disputes.create`, `messages.send` | ✓ | ✓ | | ✓ |
| `aviator.credentials.manage`, `listings.unlimited` | | ✓ | | ✓ |
| `admin.access`, `dashboard.view` | | | ✓ | ✓ |
| `users.view`, `users.update`, `users.suspend` | | | ✓ | ✓ |
| `users.create`, `users.impersonate` | | | per staff role | ✓ |
| `kyc.view`, `kyc.review` | | | ✓ | ✓ |
| `listings.view`, `listings.approve`, `listings.reject` | | | ✓ | ✓ |
| `orders.view`, `transactions.view` | | | ✓ | ✓ |
| `orders.manage`, `transactions.refund` | | | Finance role | ✓ |
| `disputes.view`, `disputes.resolve` | | | ✓ | ✓ |
| `reports.view`, `reports.manage` | | | ✓ | ✓ |
| `payouts.view` | | | ✓ | ✓ |
| `payouts.approve`, `fees.view`, `fees.manage` | | | Finance role | ✓ |
| `promotions.manage`, `broadcasts.manage`, `content.manage` | | | Marketing role | ✓ |
| `catalogue.manage`, `analytics.view` | | | analytics ✓ | ✓ |
| `team.view`, `roles.manage`, `permissions.manage` | | | | ✓ |
| `audit.view` | | | ✓ | ✓ |
| `settings.manage`, `payment_gateways.manage` | | | | ✓ |

Staff roles (Trust & safety, Finance, Support agent, Marketing, Super admin) are permission bundles managed in AD18 (`/admin/roles`). Two invariants hold: a `super_admin` role's permissions cannot be edited, and the last super admin cannot be demoted.

Ownership rules apply on top of permissions:

- Only the seller can edit, publish or reserve a listing.
- Only the buyer can cancel an offer, open a dispute or confirm delivery.
- Only the seller can accept, decline or counter an offer, or mark an order dispatched.
- Order, dispute and conversation reads are limited to the parties and staff holding the matching `*.view` permission.

---

## 3. Email notifications (ZeptoMail)

All email is sent by Laravel through ZeptoMail. The SPA never calls ZeptoMail, and the ZeptoMail token lives only in the Laravel `.env`. Send from queued jobs (`ShouldQueue`) so API latency does not depend on email delivery. Every template has an HTML part and a plain-text part, and every link points to the SPA (`APP_FRONTEND_URL`).

| Template key | Trigger | Recipient | Deep link |
|---|---|---|---|
| `auth.verify_email` | register, resend | user | — (4-digit OTP in the body) |
| `auth.welcome` | email verified / social account complete | user | `/hangar` |
| `auth.password_reset` | forgot password | user | `/auth/reset?token=…&email=…` |
| `auth.invitation`, `staff.invitation` | admin invites | invitee | `/auth/signup?invite=…` / `/admin/sign-in` |
| `security.new_device` | login from an unseen device | user | `/hangar/settings/security` |
| `security.password_changed` | password reset or change | user | `/hangar/settings/security` |
| `security.sessions_revoked` | logout-others | user | `/hangar/settings/security` |
| `security.provider_linked` | social link | user | `/hangar/settings/security` |
| `security.payout_method_added` | new payout method | user | `/hangar/wallet` |
| `account.deactivated` / `deleted` / `suspended` / `reinstated` / `restricted` | account status change | user | `/contact` |
| `kyc.submitted` / `approved` / `rejected` / `more_info` | KYC lifecycle | user | `/hangar/verification` |
| `aviator.submitted` / `approved` / `rejected` | aviator credentials | user | `/hangar/aviator` |
| `listing.published` / `in_review` / `approved` / `changes_requested` / `removed` | listing lifecycle | seller | `/hangar/listings/{id}/edit` |
| `offer.received` / `countered` / `accepted` / `declined` / `expired` | offers | counterparty | `/hangar/offers` |
| `swap.proposed` / `accepted` / `rejected` / `expired` | swaps | counterparty | `/hangar/offers?tab=swaps` |
| `order.paid` | payment verified | buyer | `/hangar/orders/{id}` |
| `order.new_sale` | payment verified | seller | `/hangar/orders/{id}` |
| `order.dispatched` | seller dispatch | buyer | `/hangar/orders/{id}` |
| `order.delivered_confirm` | carrier delivered and no confirmation after 48 h | buyer | `/hangar/orders/{id}` |
| `order.completed` | escrow released | seller | `/hangar/wallet` |
| `order.cancelled` / `refunded` | cancellation / refund | both | `/hangar/orders/{id}` |
| `payout.requested` / `sent` / `on_hold` / `rejected` / `available` | wallet | seller | `/hangar/wallet` |
| `dispute.opened` / `received` / `updated` / `resolved` | disputes | parties | `/hangar/disputes/{id}` |
| `review.received` | new review | reviewee | `/hangar/reviews` |
| `message.received` | message while the recipient is offline (digest, at most 1 per 15 min per conversation) | recipient | `/messages/{conversation}` |
| `marketing.confirm_subscription` | newsletter opt-in | subscriber | confirm link |
| `support.ticket_received` | contact form | sender | — |
| `system.maintenance_scheduled` | maintenance scheduled | active users | — |

Transactional email (auth, security, orders, payments, disputes) always sends. Marketing and digest email respect `/me/notification-preferences` and include an unsubscribe link.

---

## 4. Key flows in detail

### 4.1 Session bootstrap

1. SPA boot. In cookie mode, the SPA calls `GET /sanctum/csrf-cookie` lazily before the first mutating request.
2. `GET /auth/me`. A 200 returns `AuthUser`. A 401 means the visitor is signed out, and the SPA does not redirect from public pages.
3. Login returns either `status: authenticated` (with `user`) or `status: two_factor_required` (with `challenge_id`, then `POST /auth/two-factor`). After either path the SPA calls `/auth/me` again.
4. Logout calls `POST /auth/logout`. The SPA then clears all user-scoped stores (cart, notifications, admin caches) whatever the response.

### 4.2 Listing search

`GET /listings` takes `search`, `category`, `condition`, `deal_type`, `min_price`, `max_price`, `verified_only`, `min_rating`, `free_delivery`, `format`, `seller`, `aircraft_type`, `promoted`, `sort`, `page` and `per_page`. It returns paginated `Listing[]` plus `meta.facets`. Only `active` and `reserved` listings from non-suspended sellers are returned. For a signed-in viewer, each listing includes `is_favorite`, and `sizes.fit_match` when they have a default Fit Kit profile.

### 4.3 Checkout and payment

```
POST /checkout/quote      → totals (pure)
POST /orders              → order { status: pending_payment, escrow.status: not_funded }
POST /payments/initialize → { reference, authorization_url, status: pending }
   ⤷ browser → gateway hosted page → return_url (/payment/callback?reference=…)
GET  /payments/{reference} (poll) → { status: successful | failed | pending | processing | cancelled }
POST /webhooks/{gateway}  (gateway → Laravel) → order: paid, escrow: held
```

`POST /payments/initialize` request:

```json
{ "order_id": "ord_5f1", "gateway": "paystack", "return_url": "https://pilotwardrobe.com/payment/callback" }
```

The response may include `public_key`, but only for gateways whose browser SDK needs one, and **never** a secret. `authorization_url` must be on the gateway's own domain, because the SPA refuses to redirect anywhere else. Validate `return_url` against `APP_FRONTEND_URL` as well, so the endpoint cannot be used as an open redirect.

Verification: `GET /payments/{reference}` must return the **server-verified** status. Laravel verifies with the gateway API (Paystack `transaction/verify`, Flutterwave `transactions/{id}/verify`, Stripe PaymentIntent/Checkout Session retrieve, PayPal order capture, Monnify transaction status) when the webhook has not arrived yet. Before marking the payment successful, check that the amount, the currency and the reference all match the order.

### 4.4 Dual control (admin)

These actions return `200` with `{ "status": "pending_approval", "approver": "Super admin" }` instead of applying immediately:

- Refunds above $1,000
- Payouts above $2,000
- Every commission-rule change and escrow-timing change
- Payment-gateway settings changes

A second staff member with the same permission approves the change, and the person who requested it cannot approve it (`409 CONFLICT`). Both steps are written to the audit log.

### 4.5 Audit log

Every mutating `/admin/*` call, and every security-relevant user action, writes an immutable row with `actor`, `action` (for example `USER.SUSPENDED` or `REFUND.REQUESTED`), `target`, `detail`, `ip`, `user_agent`, `severity` (`info | success | warning | danger`) and `created_at`. Rows are never updated or deleted, and `GET /admin/audit-logs` is read-only.

---

## 5. Laravel implementation status

_Added by the backend team, 2026-10-21. Everything above this section is the contract as written for the SPA; this section records how the Laravel API implements it._

**All 209 endpoints in the reference below are implemented** at `/api/v1`, with the envelopes, error codes (the `ErrorCode` enum matches §1.5 exactly), state machines, permissions and e-mail templates described here. The route-by-route mapping, including the middleware that enforces each guard, is in [`frontend-api-mapping.md`](frontend-api-mapping.md). The field-by-field comparison of real responses against `api-payloads/` is in [`payload-compatibility-audit.md`](payload-compatibility-audit.md). The machine-readable OpenAPI 3.1 document is served at `GET /openapi` (UI at `/api/documentation`) and exported to [`openapi.json`](openapi.json), with a Postman/Bruno collection in [`postman/`](postman/).

### 5.1 Behaviour the contract left open

| Topic | Implementation |
|---|---|
| Rate limits (§1.9) | Named limiters per route group: `auth` 10/min/IP, `otp` 1/min + 10/h, `password-reset` 1/min + 20/h, `search` 90/min, `messages` 30/min, `offers` 20/h, `uploads` 30/min, `payments` 10/min, `verification` 20/h, `contact` 5/min, `admin` 300/min, `webhooks` 600/min, global `api` 120/min. 429 uses the standard error envelope with `meta.retry_after`. |
| Idempotency (§1.10) | `Idempotency-Key` honoured on every mutation marked *idempotent* in the mapping. Same key + same body replays the stored response with `Idempotent-Replayed: true`; same key + different body → 409 `CONFLICT`. Keys expire after 24 h. |
| Money | Integer minor units everywhere; responses add a locale-aware `formatted` string (allowed by §1.6). |
| Optional fields | Keys whose value is `null` are sometimes omitted (for example `read_at` on unread messages, `counter_amount` on offers without a counter). The SPA already treats missing optional fields as absent. |
| Cross-currency (handoff open question 1) | The **listing's currency is the source of truth**. Orders, escrow and payouts are in the listing currency; a cart can only check out items in one currency (422 otherwise). Exchange rates (`exchange_rates`) are used for display hints, dual-control thresholds and admin reporting only. |
| One order per seller | Checkout creates one order per seller, so escrow, dispatch and disputes stay per seller. |
| Auto-moderation (open question 4) | Listings go to `pending_review` unless a moderation rule in `admin/settings` (`auto_approve_official_stores`, `auto_approve_verified_aviators`, `auto_approve_kyc_verified`) applies. |
| KYC provider (open question 2) | Prembly (IdentityPass) behind a `KycProvider` contract; document checks run asynchronously and a human reviewer decides in `/admin/kyc`. |
| Real-time (open question 3) | Not implemented; the SPA keeps polling. Payloads would not change if Reverb is added later. |
| Aviator role | Granted only when an aviator credential is **approved** by staff, never at registration (decision D3 in [`backend-implementation-notes.md`](backend-implementation-notes.md)). |
| Social sign-in | Google and Facebook. **Apple is not implemented** (`/auth/social/apple/*` returns 404) until an Apple developer account and key are available. |
| Google Pay | Offered through Stripe Checkout's hosted page (with Apple Pay); there is no separate Google Pay gateway. |
| Sandbox payments | `gateway=sandbox` (non-production only, `PAYMENTS_SANDBOX_ENABLED=true`) replaces the SPA mock gateway: `authorization_url` points to `{APP_FRONTEND_URL}/payment/mock-gateway`, and the outcome is set through `POST /sandbox/payments/{reference}/{approve\|decline\|cancel}`. |
| Dual control (§4.4) | Refunds > $1,000, payouts > $2,000, dispute decisions above the configured threshold, commission rules, escrow timings and gateway settings return `{ "status": "pending_approval", "approver", "approval_id" }`. A different staff member with the same permission approves through `/admin/approvals`; the requester gets 409 if they try. |

### 5.2 Backend additions

<a id="backend-additions"></a>Endpoints the SPA does not call yet. They are additive and documented in OpenAPI. All decisions and assumptions are listed in [`backend-implementation-notes.md`](backend-implementation-notes.md).

| Method | Path | Guard | Purpose |
|---|---|---|---|
| `GET` | `/me/devices` | auth | List the caller's push-notification device registrations. |
| `POST` | `/me/devices` | auth | Register an FCM device token (`token`, `platform`). |
| `DELETE` | `/me/devices/{id}` | auth | Unregister a device. |
| `POST` | `/me/two-factor` | auth | Start TOTP enrolment: returns `secret`, `otpauth_url` and `qr_svg` (SVG markup). |
| `POST` | `/me/two-factor/confirm` | auth | Confirm enrolment with a TOTP code; returns one-time backup codes. |
| `DELETE` | `/me/two-factor` | auth | Turn off 2FA (requires password and a current code). |
| `POST` | `/me/two-factor/backup-codes` | auth | Regenerate backup codes. |
| `DELETE` | `/me/payout-methods/{id}` | auth | Remove a payout method (soft delete; payouts already in flight still complete to it). |
| `POST` | `/orders/{id}/tracking` | auth (seller) | Seller tracking update (`out_for_delivery`, `delivered`); starts the auto-release timer. |
| `POST` | `/disputes/{id}/proposal` | auth (seller) | Seller offers a settlement (`kind`, `amount`, `summary`) that the buyer can accept. |
| `POST` | `/newsletter/confirm` | public | Double opt-in confirmation from the e-mail link. |
| `POST` | `/sandbox/payments/{reference}/{outcome}` | auth, non-production | Sandbox gateway outcome (see above). |
| `GET` | `/admin/approvals` | staff | Dual-control queue, filtered to what the caller may approve. |
| `POST` | `/admin/approvals/{id}/approve` | staff + the approval's permission | Execute a pending change (not by its requester). |
| `POST` | `/admin/approvals/{id}/reject` | staff + the approval's permission | Reject a pending change. |
| `GET` | `/admin/aviators` | perm `kyc.view` | Aviator credential review queue. |
| `POST` | `/admin/aviators/{id}/{approve\|reject}` | perm `kyc.review` | Decide an aviator credential (approval grants the aviator role). |
| `POST` | `/admin/payouts/{id}/mark-paid` | perm `payouts.approve` | Record a manual (off-PSP) payout as paid. |
| `PUT` | `/admin/content/pages/{slug}` | perm `content.manage` | Edit CMS pages (terms, privacy, help articles); HTML is sanitised server-side. |
| `POST` | `/webhooks/{paystack\|flutterwave\|monnify\|stripe\|paypal}` | signature | Payment webhooks (the contract's `/webhooks/{gateway}`). |
| `POST` | `/webhooks/prembly` | signature | KYC check results. |
| `GET`/`POST` | `/webhooks/whatsapp` | verify token / signature | WhatsApp Cloud API verification and delivery-status callbacks. |

## Endpoint reference

<!-- endpoints:start -->
_209 endpoints. Generated by `python scripts/generate-api-contract.py` from `src/mocks/handlers`; do not edit by hand._

Guard legend: **public** — no session · **optional** — works signed out, personalised when signed in · **auth** — any signed-in user (ownership rule in brackets) · **challenge** — requires a pending auth challenge id · **perm: x** — staff/user must hold permission `x` (see §Roles & permissions).

### Authentication

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `POST` | `/auth/email-lookup` | public | 200 | 422 VALIDATION_ERROR | Email-first step (VA1): does an account exist and which sign-in methods it has. | Rate-limit 10/min/IP. Never reveal methods for unknown emails. |
| `POST` | `/auth/login` | public | 200 | 422 VALIDATION_ERROR<br>423 ACCOUNT_LOCKED | Password sign-in; may return a 2FA challenge. | Lock 15 min after 5 failures (423). E-mail `security.new_device` on first login from a new device. |
| `POST` | `/auth/two-factor` | challenge | 200 | 419 SESSION_EXPIRED<br>422 VALIDATION_ERROR | Complete sign-in with TOTP / SMS / backup code. | `trust_device` sets a 30-day device cookie. |
| `POST` | `/auth/two-factor/sms` | challenge | 200 | — | Send the SMS fallback code for a pending challenge. | Throttle 1/30 s. |
| `POST` | `/auth/register` | public | 201 | 422 VALIDATION_ERROR | Create a user or aviator account (VA4). | E-mail `auth.verify_email` (4-digit OTP, 10 min). |
| `POST` | `/auth/email/verify` | public | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Verify the email OTP (VA5). | E-mail `auth.welcome`. |
| `POST` | `/auth/email/resend` | public | 200 | — | Resend the email OTP. | Throttle 1/60 s; e-mail `auth.verify_email`. |
| `POST` | `/auth/password/forgot` | public | 200 | — | Request a password-reset link (VA9). | Always 200. E-mail `auth.password_reset` (link valid 60 min) if the account exists. |
| `GET` | `/auth/password/reset/{token}` | public | 200 | — | Check a reset token before showing the form (VA10). | — |
| `POST` | `/auth/password/reset` | public | 200 | 410 TOKEN_EXPIRED<br>422 VALIDATION_ERROR | Set a new password with a reset token. | Optionally revoke other sessions. E-mail `security.password_changed`. |
| `GET` | `/auth/me` | optional | 200 | 401 UNAUTHENTICATED | Session principal: user, role, effective permissions, counters. | Source of truth for every frontend guard. |
| `POST` | `/auth/logout` | auth | 204 | — | End the session / revoke the token. | — |
| `GET` | `/auth/username-availability` | public | 200 | — | Live username check for social finish-setup (VA3). | — |
| `GET` | `/auth/social/{provider}/redirect` | public | 200 | — | Provider authorisation URL (Google / Apple / Facebook). | Backend owns client secrets and `state`. |
| `POST` | `/auth/social/{provider}/callback` | public | 200 | 422 VALIDATION_ERROR | Exchange the provider `code` + `state`; returns new_account / link_required / authenticated. | — |
| `POST` | `/auth/social/complete` | public | 201 | 419 SESSION_EXPIRED<br>422 VALIDATION_ERROR | Finish a new social account (VA3). | E-mail `auth.welcome`. |
| `POST` | `/auth/social/link` | public | 200 | 419 SESSION_EXPIRED<br>422 VALIDATION_ERROR | Link a social identity to an existing password account (VA8). | E-mail `security.provider_linked`. |
| `POST` | `/admin/auth/google` | public (staff domain only) | 200 | — | Staff step 1: Google Workspace SSO (company domain only). | Returns a WebAuthn challenge. |
| `POST` | `/admin/auth/webauthn` | challenge | 200 | — | Staff step 2: verify the security-key assertion; starts a staff session. | Audit `STAFF.SIGNED_IN`. |

### Account & profile (self)

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/me/security` | auth | 200 | — | Sign-in methods, 2FA state, active sessions (VB15 Security). | — |
| `PUT` | `/me/password` | auth | 200 | 422 VALIDATION_ERROR | Change password (requires current password). | E-mail `security.password_changed`. |
| `DELETE` | `/me/sessions/{id}` | auth | 204 | 404 NOT_FOUND<br>409 CONFLICT | Sign out one device. | 409 when targeting the current session. |
| `POST` | `/me/sessions/logout-others` | auth | 204 | — | Sign out all other devices. | E-mail `security.sessions_revoked`. |
| `DELETE` | `/me/social/{provider}` | auth | 204 | 404 NOT_FOUND<br>409 CONFLICT | Unlink a social provider. | 409 if it is the last sign-in method. |
| `POST` | `/me/deactivate` | auth | 200 | — | Temporarily deactivate the account (listings hidden). | E-mail `account.deactivated`. |
| `DELETE` | `/me` | auth | 204 | 409 CONFLICT | Permanently delete the account. | 409 while orders/escrow/payouts are open. E-mail `account.deleted`. |
| `GET` | `/me/notification-preferences` | auth | 200 | — | Email/push preference matrix. | — |
| `PUT` | `/me/notification-preferences` | auth | 200 | — | Save preferences. | Transactional e-mails (orders, security) cannot be disabled. |
| `GET` | `/me/fit-kit` | auth | 200 | — | Fit Kit size profiles (VB16). | — |
| `POST` | `/me/fit-kit` | auth | 201 | 422 VALIDATION_ERROR | Create a size profile. | — |
| `PATCH` | `/me/fit-kit/{id}` | auth | 200 | 404 NOT_FOUND | Update a size profile / set default. | Re-computes `fit_match` on listings. |
| `DELETE` | `/me/fit-kit/{id}` | auth | 204 | — | Delete a size profile. | — |
| `PATCH` | `/me` | auth | 200 | — | Update profile fields (name, username, phone, country, currency, locale, bio, avatar media id). | — |
| `GET` | `/me/addresses` | auth | 200 | — | Saved delivery addresses. | — |
| `POST` | `/me/addresses` | auth | 201 | 422 VALIDATION_ERROR | Add an address. | — |
| `PATCH` | `/me/addresses/{id}` | auth | 200 | 404 NOT_FOUND | Edit / set default. | — |
| `DELETE` | `/me/addresses/{id}` | auth | 204 | — | Delete an address. | — |

### Verification (KYC & aviator)

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/me/kyc` | auth | 200 | — | Current KYC flow state and step (VB2). | Polled during the liveness step. |
| `POST` | `/me/kyc/personal` | auth | 200 | 422 VALIDATION_ERROR | KYC step 1: legal name, DOB, nationality. | — |
| `POST` | `/me/kyc/document` | auth | 200 | 422 VALIDATION_ERROR | KYC step 2: document type + private media ids. | — |
| `POST` | `/me/kyc/submit` | auth | 200 | — | Submit for review after liveness (provider SDK/hand-off). | Status → pending. E-mail `kyc.submitted`; later `kyc.approved` / `kyc.rejected`. |
| `GET` | `/me/aviator` | auth | 200 | — | Aviator credentials status. | — |
| `POST` | `/me/aviator` | auth | 201 | 403 KYC_REQUIRED<br>422 VALIDATION_ERROR | Apply for aviator verification (licence details + private licence media). | Requires KYC approved. E-mail `aviator.submitted`. |

### Catalogue, content & support

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `POST` | `/listings` | perm: listings.create | 201 | — | Create a draft listing (V6 step 1). | — |
| `GET` | `/home` | public | 200 | — | Home feed: hero slides, categories, curated rails, gallery (V1). | Cache 60 s at the edge. |
| `GET` | `/listings` | optional | 200 | — | Search / browse with filters, sort, facets (V2). | Viewer-specific fields (`is_favorite`, `fit_match`) when signed in. |
| `GET` | `/listings/{slug}/related` | public | 200 | 404 NOT_FOUND | Related listings rail. | — |
| `GET` | `/listings/{slug}` | public | 200 | 404 NOT_FOUND | Listing detail (V3). | Increments view counter (deduplicated). |
| `GET` | `/categories` | public | 200 | — | Category tree with counts. | — |
| `GET` | `/categories/{slug}` | public | 200 | 404 NOT_FOUND | Category with children, attributes and size chart. | — |
| `GET` | `/boost-packages` | public | 200 | — | Promotion packages for listing boosts. | — |
| `GET` | `/pages/{slug}` | public | 200 | 404 NOT_FOUND | CMS content page (about, terms, privacy, how-it-works…). `body_html` must be server-sanitised. | — |
| `POST` | `/contact` | public | 201 | 422 VALIDATION_ERROR | Contact form (VC11): `topic`, `subject`, `message`, optional `order_id`, `phone` (required for `callback`), `attachment_ids` (≤ 5, uploaded via /media `type=message`). | E-mail `support.ticket_received` to sender; `trust_safety` pages the on-call T&S queue; `callback` queues a phone callback. |
| `GET` | `/help/summary` | public | 200 | — | Help Center (VC10) panels: popular questions (with view counts) and per-component system status for the last 30 days. | Cache 5 min. Status must reflect real monitoring, never a hardcoded value. |
| `GET` | `/support/contact-info` | public | 200 | — | Contact page (VC11) panels: chats per hour (WAT), average reply time, office addresses. | Cache 1 h. |
| `POST` | `/newsletter` | public | 204 | 422 VALIDATION_ERROR | Newsletter opt-in. | Double opt-in e-mail `marketing.confirm_subscription`. |

### Favourites & profiles

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/me/favorites` | auth | 200 | — | Saved listings. | — |
| `POST` | `/listings/{id}/favorite` | auth | 200 | 404 NOT_FOUND | Save a listing. | Enables `price_drop` notifications. |
| `DELETE` | `/listings/{id}/favorite` | auth | 200 | 404 NOT_FOUND | Unsave a listing. | — |
| `GET` | `/sellers` | public | 200 | — | Directory (photographers, official stores, aviators). | — |
| `GET` | `/sellers/{username}` | public | 200 | 404 NOT_FOUND | Public seller / photographer profile (V7, VB19). | — |
| `GET` | `/sellers/{username}/listings` | public | 200 | — | Seller storefront listings (paginated). | — |
| `POST` | `/sellers/{username}/follow` | auth | 200 | 404 NOT_FOUND | Follow a seller. | Notify seller (in-app). |
| `DELETE` | `/sellers/{username}/follow` | auth | 200 | 404 NOT_FOUND | Unfollow. | — |

### Listings (seller) & media

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/me/hangar` | auth | 200 | — | Seller dashboard aggregate (V5). | — |
| `GET` | `/me/listings` | auth | 200 | — | Seller listings table with status tabs (VB3). | — |
| `GET` | `/me/listings/{id}` | auth | 200 | 404 NOT_FOUND | Editable listing draft for the wizard. | — |
| `PATCH` | `/listings/{id}` | perm: listings.update | 200 | 404 NOT_FOUND | Autosave draft / edit live listing. | Edits to live listings may re-enter moderation. |
| `POST` | `/listings/{id}/publish` | perm: listings.create | 200 | 403 KYC_REQUIRED<br>404 NOT_FOUND<br>422 VALIDATION_ERROR | Validate and publish. | 3-live-listing cap without KYC. E-mail `listing.published` or `listing.in_review`. |
| `DELETE` | `/listings/{id}` | perm: listings.update | 204 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Delete a draft / archived listing. | 409 for listings with orders. |
| `POST` | `/listings/bulk` | perm: listings.update | 200 | — | Bulk pause / resume / archive / price change. | — |
| `POST` | `/listings/imports` | perm: listings.create | 201 | 422 VALIDATION_ERROR | CSV import (multipart `file`). | Returns created count + failed rows. |
| `POST` | `/listings/price-check` | auth | 200 | — | Suggested price range from comparable sales. | — |
| `POST` | `/listings/fee-quote` | public | 200 | — | Commission + net payout for a price. | — |
| `POST` | `/listings/{id}/reserve` | auth (listing seller) | 204 | 403 FORBIDDEN<br>404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Seller marks a listing reserved for a buyer (from Messages). | Seller only; listing must be active. |
| `POST` | `/media` | auth | 201 | 422 VALIDATION_ERROR | Upload one image/file (multipart `file`, `type`). Laravel streams to Cloudinary and returns a MediaAsset. | Max 15 MB; JPEG/PNG/WebP/HEIC (PDF for kyc/evidence). `kyc`, `dispute_evidence` → private assets. |
| `DELETE` | `/media/{id}` | auth | 204 | — | Delete an unattached upload. | Destroys the Cloudinary asset server-side. |
| `POST` | `/listings/{id}/pause` | perm: listings.update | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Pause (active → paused). | — |
| `POST` | `/listings/{id}/resume` | perm: listings.update | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Resume (paused → active). | — |
| `POST` | `/listings/{id}/archive` | perm: listings.update | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Archive. | — |
| `POST` | `/listings/{id}/mark-sold` | perm: listings.update | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Mark sold off-platform. | — |
| `POST` | `/listings/{id}/mark-unavailable` | perm: listings.update | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Temporarily unavailable. | — |
| `POST` | `/listings/{id}/relist` | perm: listings.update | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Relist a sold/archived item. | — |
| `POST` | `/listings/{id}/resubmit` | perm: listings.update | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Resubmit after moderation requested changes. | — |
| `POST` | `/listings/{id}/promote` | perm: listings.update | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Apply a boost (payment handled via boost order). | — |

### Offers

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `POST` | `/listings/{id}/offers` | perm: offers.create | 201 | 404 NOT_FOUND<br>409 CONFLICT<br>422 VALIDATION_ERROR | Make an offer. | Notify + e-mail seller `offer.received`. |
| `GET` | `/me/offers` | auth | 200 | — | Offers inbox (`box=received|sent`). | — |
| `POST` | `/offers/{id}/accept` | auth (listing seller) | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION<br>422 VALIDATION_ERROR | Accept an offer. | Listing reserved; e-mail buyer `offer.accepted` with checkout link. |
| `POST` | `/offers/{id}/decline` | auth (listing seller) | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION<br>422 VALIDATION_ERROR | Decline. | E-mail buyer `offer.declined`. |
| `POST` | `/offers/{id}/counter` | auth (listing seller) | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION<br>422 VALIDATION_ERROR | Counter-offer. | E-mail buyer `offer.countered`. |
| `POST` | `/offers/{id}/cancel` | auth (offer buyer) | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION<br>422 VALIDATION_ERROR | Buyer withdraws an offer. | — |

### Swaps

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `POST` | `/listings/{id}/swap-requests` | perm: swaps.create, offers.create | 201 | 403 KYC_REQUIRED<br>404 NOT_FOUND<br>409 CONFLICT<br>422 VALIDATION_ERROR | Propose a swap (V8). | Requires KYC. E-mail counterparty `swap.proposed`. |
| `GET` | `/me/swap-requests` | auth | 200 | — | Swap inbox. | — |
| `POST` | `/swap-requests/{id}/accept` | auth (counterparty) | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Accept a swap. | Reserves listings; creates linked orders; e-mail `swap.accepted`. |
| `POST` | `/swap-requests/{id}/reject` | auth (counterparty) | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Reject. | E-mail `swap.rejected`. |
| `POST` | `/swap-requests/{id}/cancel` | auth (proposer) | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Proposer withdraws. | — |

### Cart, checkout & orders

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/cart` | auth | 200 | — | Cart with availability re-checked. | — |
| `POST` | `/cart/items` | perm: orders.create | 200 | 409 CONFLICT<br>422 VALIDATION_ERROR | Add a listing (with size). | 409 if unavailable / own listing. |
| `DELETE` | `/cart/items/{id}` | auth | 200 | — | Remove from cart. | — |
| `POST` | `/checkout/quote` | perm: orders.create | 200 | 409 CONFLICT | Price breakdown: items, shipping options, fees, promo, totals. | No side effects. |
| `GET` | `/me/orders` | auth | 200 | — | Orders (`role=buying|selling`, status tabs). | — |
| `GET` | `/orders/{id}` | auth | 200 | 404 NOT_FOUND | Order detail with escrow, timeline, `allowed_actions`. | Buyer, seller or staff only. |
| `POST` | `/orders` | perm: orders.create | 201 | 409 CONFLICT<br>422 VALIDATION_ERROR | Create an order in pending_payment (re-quoted server-side). | Reserves stock 15 min. |
| `POST` | `/orders/{id}/cancel` | auth | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Cancel before dispatch. | Refund if paid; e-mail both parties `order.cancelled`. |
| `POST` | `/orders/{id}/confirm-delivery` | auth | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Buyer confirms landed (optionally with review). | Escrow released; e-mail seller `order.completed`, `payout.available`. |
| `POST` | `/orders/{id}/dispatch` | auth | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Seller adds carrier + tracking. | E-mail buyer `order.dispatched`. |
| `GET` | `/orders/{id}/invoice` | auth | 200 | 404 NOT_FOUND | PDF invoice. | — |
| `POST` | `/orders/{order}/downloads/{asset}/authorize` | auth | 200 | 403 FORBIDDEN<br>404 NOT_FOUND | Short-lived signed URL for a purchased digital asset. | Paid orders only; log download. |

### Payments

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/payment-methods` | public | 200 | — | Gateways available for currency/country. | — |
| `POST` | `/payments/initialize` | perm: orders.create | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION<br>422 VALIDATION_ERROR | Create a gateway transaction; returns `authorization_url` + `reference`. | Idempotent per order + gateway while pending. |
| `GET` | `/payments/{reference}` | auth | 200 | 404 NOT_FOUND | Verified payment status (polled after redirect). | Verifies with the gateway if the webhook has not arrived. |
| `POST` | `/webhooks/{gateway}` | gateway signature | 200 | 400 invalid signature | Gateway → Laravel webhook. **Not called by the SPA.** | Verify signature; idempotent; on success order → paid, escrow → held; e-mail buyer `order.paid` + seller `order.new_sale`. |

### Wallet & payouts

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/me/wallet` | auth | 200 | — | Balances: available, in escrow, pending, lifetime. | — |
| `GET` | `/me/wallet/transactions` | auth | 200 | — | Ledger (paginated, filter by type). | — |
| `GET` | `/me/payout-methods` | auth | 200 | — | Saved payout methods (masked). | — |
| `POST` | `/me/payout-methods` | perm: payouts.request | 201 | 422 VALIDATION_ERROR | Add bank / mobile money / PayPal payout method. | Verify via gateway; e-mail `security.payout_method_added`. |
| `GET` | `/me/payout-schedule` | auth | 200 | — | Automatic payout schedule. | — |
| `PUT` | `/me/payout-schedule` | auth | 200 | — | Update schedule. | — |
| `POST` | `/me/withdrawals/quote` | perm: payouts.request | 200 | 422 VALIDATION_ERROR | Fee, net and ETA for a withdrawal. | — |
| `POST` | `/me/withdrawals` | perm: payouts.request | 201 | 422 VALIDATION_ERROR | Request a withdrawal (2FA code when enabled). | > $2,000 needs staff approval. E-mail `payout.requested`, later `payout.sent`. |
| `POST` | `/me/wallet/statements` | auth | 200 | — | Signed URL for a monthly statement PDF. | — |

### Reviews

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/me/reviews` | auth | 200 | — | Reviews received and given (VB13). | — |
| `POST` | `/orders/{id}/reviews` | perm: reviews.create | 204 | — | Leave a review. | E-mail counterparty `review.received`. |
| `GET` | `/sellers/{username}/reviews` | public | 200 | — | Seller reviews (paginated). | — |

### Messaging

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/conversations` | auth | 200 | — | Conversation list (`context=buying|selling`). | — |
| `GET` | `/conversations/{id}` | auth | 200 | 404 NOT_FOUND | Conversation header (participant, listing, offer context). | — |
| `GET` | `/conversations/{id}/messages` | auth | 200 | — | Messages (cursor/paginated). | — |
| `POST` | `/conversations/{id}/messages` | perm: messages.send | 201 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Send a message. | Flags off-platform payment patterns. E-mail digest `message.received` if recipient offline. |
| `POST` | `/conversations` | perm: messages.send | 201 | — | Start / fetch a conversation about a listing. | — |
| `POST` | `/conversations/{id}/read` | auth | 204 | — | Mark read. | — |
| `POST` | `/conversations/{id}/report` | auth | 200 | — | Report a conversation. | Creates an AD10 report. |

### Notifications

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/notifications` | auth | 200 | — | Notification centre (category filter). | — |
| `GET` | `/notifications/unread-count` | auth | 200 | — | Badge counts. | Polled every 60 s. |
| `PATCH` | `/notifications/{id}/read` | auth | 200 | 404 NOT_FOUND | Mark one read. | — |
| `POST` | `/notifications/read-all` | auth | 204 | — | Mark all read. | — |

### Disputes

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/me/disputes` | auth | 200 | — | My disputes. | — |
| `GET` | `/disputes/{id}` | auth | 200 | 404 NOT_FOUND | Case view with timeline, messages, evidence (VB9). | Parties and staff only. |
| `POST` | `/disputes` | perm: disputes.create | 201 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION<br>422 VALIDATION_ERROR | Open a dispute (VB8). | Freezes escrow; e-mail seller `dispute.opened`, buyer `dispute.received`. |
| `POST` | `/disputes/{id}/messages` | auth | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Add a statement / evidence. | E-mail counterparty `dispute.updated`. |
| `POST` | `/disputes/{id}/withdraw` | auth | 200 | 404 NOT_FOUND | Buyer withdraws the dispute. | Unfreezes escrow. |
| `POST` | `/disputes/{id}/proposal/accept` | auth | 200 | 404 NOT_FOUND | Accept the seller's settlement proposal. | Executes the refund split. |
| `POST` | `/disputes/{id}/proposal/decline` | auth | 200 | 404 NOT_FOUND | Decline the proposal (escalates to staff). | — |

### Admin — overview, users & KYC

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/admin/overview` | perm: dashboard.view | 200 | — | Control Tower KPIs, queues, alerts (AD2). | — |
| `POST` | `/admin/exports` | perm: admin.access | 201 | — | Queue a CSV export of a table. | Audit `EXPORT.CREATED`; e-mail link when ready. |
| `GET` | `/admin/users` | perm: users.view | 200 | — | Users table (AD3). | — |
| `POST` | `/admin/users/invitations` | perm: users.create | 201 | 422 VALIDATION_ERROR | Invite a user. | E-mail `auth.invitation`. |
| `GET` | `/admin/users/{id}` | perm: users.view | 200 | 404 NOT_FOUND | User 360 (AD4). | — |
| `POST` | `/admin/users/{id}/suspend` | perm: users.suspend | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Suspend (reason required). | Audit; e-mail `account.suspended`. |
| `POST` | `/admin/users/{id}/reinstate` | perm: users.suspend | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Reinstate. | Audit; e-mail `account.reinstated`. |
| `POST` | `/admin/users/{id}/restrict` | perm: users.update | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Restrict selling/withdrawals. | Audit; e-mail `account.restricted`. |
| `PATCH` | `/admin/users/{id}/limits` | perm: users.update | 200 | 404 NOT_FOUND | Change selling/withdrawal limits. | Audit. |
| `POST` | `/admin/users/{id}/notes` | perm: users.update | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Internal note. | Audit. |
| `POST` | `/admin/users/{id}/impersonate` | perm: users.impersonate | 200 | — | Read-only impersonation session (15 min). | Audit; user sees it in security log. |
| `POST` | `/admin/users/{id}/role` | perm: roles.manage | 200 | 404 NOT_FOUND | Change role. | Audit; super_admin cannot be assigned here. |
| `GET` | `/admin/kyc` | perm: kyc.view | 200 | — | KYC queue (AD5). | — |
| `GET` | `/admin/kyc/{id}` | perm: kyc.view | 200 | 404 NOT_FOUND | KYC case with signed document URLs and automated checks. | — |
| `POST` | `/admin/kyc/{id}/approve` | perm: kyc.review | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Approve. | Audit; e-mail `kyc.approved`. |
| `POST` | `/admin/kyc/{id}/reject` | perm: kyc.review | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Reject (reason). | Audit; e-mail `kyc.rejected`. |
| `POST` | `/admin/kyc/{id}/escalate` | perm: kyc.review | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Escalate to senior reviewer. | Audit. |
| `POST` | `/admin/kyc/{id}/request-info` | perm: kyc.review | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Ask the user for more documents. | E-mail `kyc.more_info`. |

### Admin — moderation, orders, disputes & reports

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/admin/moderation` | perm: listings.view | 200 | — | Listing moderation queue (AD6). | — |
| `POST` | `/admin/moderation/decisions` | perm: listings.approve, listings.reject | 200 | 422 VALIDATION_ERROR | Approve / request changes / remove (bulk). | Audit; e-mail seller `listing.approved` / `listing.changes_requested` / `listing.removed`. |
| `GET` | `/admin/orders` | perm: orders.view | 200 | — | Orders & escrow table (AD7). | — |
| `GET` | `/admin/orders/stats` | perm: orders.view | 200 | — | Escrow buckets. | — |
| `GET` | `/admin/orders/{id}` | perm: orders.view | 200 | 404 NOT_FOUND | Order detail with ledger and PSP events (AD8). | — |
| `POST` | `/admin/orders/{id}/refund` | perm: transactions.refund | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Full/partial refund. | > $1,000 → pending_approval. Audit; e-mail buyer `order.refunded`. |
| `POST` | `/admin/orders/{id}/release` | perm: orders.manage | 200 | 404 NOT_FOUND<br>409 INVALID_STATE_TRANSITION | Force-release escrow. | Audit. |
| `POST` | `/admin/orders/{id}/hold` | perm: orders.manage | 200 | 404 NOT_FOUND | Put escrow on hold. | Audit. |
| `POST` | `/admin/orders/reconcile` | perm: orders.manage | 200 | — | Start PSP reconciliation job. | — |
| `GET` | `/admin/disputes` | perm: disputes.view | 200 | — | Disputes queue (AD9). | — |
| `GET` | `/admin/disputes/{id}` | perm: disputes.view | 200 | 404 NOT_FOUND | Mediation workspace. | — |
| `POST` | `/admin/disputes/{id}/messages` | perm: disputes.resolve | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Mediator message (optionally internal). | E-mail parties `dispute.updated` unless internal. |
| `POST` | `/admin/disputes/{id}/decision` | perm: disputes.resolve | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Rule on a dispute. | Dual control above threshold; e-mail `dispute.resolved`. |
| `GET` | `/admin/reports` | perm: reports.view | 200 | — | User reports queue (AD10). | — |
| `POST` | `/admin/reports/{id}/actions` | perm: reports.manage | 200 | 404 NOT_FOUND | Dismiss / warn / remove / suspend. | Audit. |

### Admin — money (payouts, fees)

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/admin/payouts` | perm: payouts.view | 200 | — | Payout queue (AD12). | — |
| `GET` | `/admin/payouts/stats` | perm: payouts.view | 200 | — | Payout totals. | — |
| `POST` | `/admin/payouts/approve` | perm: payouts.approve | 200 | 409 CONFLICT | Approve selected payouts (second approver). | 409 if the requester approves their own. |
| `POST` | `/admin/payouts/{id}/hold` | perm: payouts.approve | 200 | 404 NOT_FOUND | Hold a payout. | E-mail `payout.on_hold`. |
| `POST` | `/admin/payouts/{id}/reject` | perm: payouts.approve | 200 | 404 NOT_FOUND | Reject a payout (funds back to wallet). | E-mail `payout.rejected`. |
| `POST` | `/admin/payouts/batches` | perm: payouts.approve | 201 | — | Create a payout batch for the PSP. | — |
| `GET` | `/admin/fees` | perm: fees.view | 200 | — | Commission rules, escrow timings (AD13). | — |
| `POST` | `/admin/commission-rules` | perm: fees.manage | 200 | 422 VALIDATION_ERROR | Create a commission rule. | Dual control. |
| `PATCH` | `/admin/commission-rules/{id}` | perm: fees.manage | 200 | 422 VALIDATION_ERROR | Edit a commission rule. | Dual control. |
| `PUT` | `/admin/escrow-timings` | perm: fees.manage | 200 | — | Change auto-release / dispute windows. | Dual control. |

### Admin — growth & content

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/admin/campaigns` | perm: promotions.manage | 200 | — | Promotions (AD14). | — |
| `GET` | `/admin/promo-codes` | perm: promotions.manage | 200 | — | Promo codes. | — |
| `POST` | `/admin/promo-codes` | perm: promotions.manage | 201 | 422 VALIDATION_ERROR | Create a promo code. | Audit. |
| `POST` | `/admin/campaigns/{id}/duplicate` | perm: promotions.manage | 201 | 404 NOT_FOUND | Duplicate a campaign. | — |
| `POST` | `/admin/broadcasts/reach` | perm: broadcasts.manage | 200 | — | Estimate audience reach. | — |
| `POST` | `/admin/broadcasts` | perm: broadcasts.manage | 201 | 422 VALIDATION_ERROR | Send / schedule a broadcast (email/push/in-app). | Marketing e-mails via ZeptoMail bulk; respects opt-outs. |
| `POST` | `/admin/broadcasts/test` | perm: broadcasts.manage | 200 | — | Send a test to the author. | — |
| `GET` | `/admin/content/hero-slides` | perm: content.manage | 200 | — | Home hero slides (AD15). | — |
| `PATCH` | `/admin/content/hero-slides/{id}` | perm: content.manage | 200 | 404 NOT_FOUND<br>422 VALIDATION_ERROR | Edit a slide (draft). | — |
| `PUT` | `/admin/content/hero-slides/order` | perm: content.manage | 200 | — | Reorder slides. | — |
| `POST` | `/admin/content/publish` | perm: content.manage | 200 | — | Publish content drafts. | Purge home cache. |
| `GET` | `/admin/catalogue` | perm: catalogue.manage | 200 | — | Category tree (AD16). | — |
| `GET` | `/admin/catalogue/{id}` | perm: catalogue.manage | 200 | — | Category attributes and size chart. | — |
| `GET` | `/admin/analytics` | perm: analytics.view | 200 | — | Analytics dashboard (AD17). | — |

### Admin — team, audit & settings

| Method | Path | Guard | OK | Errors | Purpose | Side effects · e-mails |
|---|---|---|---|---|---|---|
| `GET` | `/admin/roles` | perm: team.view | 200 | — | Staff roles and permission matrix (AD18). | — |
| `PATCH` | `/admin/roles/{id}` | perm: roles.manage | 200 | 404 NOT_FOUND<br>409 CONFLICT | Edit a role matrix. | 409 for super admin. Audit. |
| `POST` | `/admin/roles` | perm: roles.manage | 201 | 422 VALIDATION_ERROR | Create a role. | Audit. |
| `GET` | `/admin/team` | perm: team.view | 200 | — | Staff members. | — |
| `POST` | `/admin/team/invitations` | perm: users.create | 201 | 422 VALIDATION_ERROR | Invite staff. | E-mail `staff.invitation`. |
| `PATCH` | `/admin/team/{id}` | perm: roles.manage | 200 | 404 NOT_FOUND<br>409 CONFLICT | Change a member's role / deactivate. | 409 for the last super admin. |
| `GET` | `/admin/audit-logs` | perm: audit.view | 200 | — | Immutable audit log (AD19). | — |
| `GET` | `/admin/settings` | perm: settings.manage | 200 | — | Platform settings (AD20). | — |
| `PATCH` | `/admin/settings` | perm: settings.manage | 200 | — | Change markets, gateways, feature flags. | Dual control for payment settings. |
| `POST` | `/admin/maintenance-windows` | perm: settings.manage | 200 | 422 VALIDATION_ERROR | Schedule maintenance (serves 503 MAINTENANCE). | E-mail `system.maintenance_scheduled` to active users. |

<!-- endpoints:end -->
