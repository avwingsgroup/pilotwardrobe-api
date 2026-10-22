# Pilotwardrobe — Frontend ↔ Backend handoff

For the Laravel team. This document explains how the Vue SPA talks to the API, which rules the backend must enforce, and which state machines the UI assumes. The per-endpoint reference is in [`backend-api-contract.md`](backend-api-contract.md), and full request/response bodies are in [`api-payloads/`](api-payloads/README.md).

> **Backend status (2026-10-21):** the Laravel API implements every endpoint in this handoff at `/api/v1`. Where it differs from or extends what is written here, the change is noted inline below as **Laravel:**. Route-by-route mapping: [`frontend-api-mapping.md`](frontend-api-mapping.md). Field-level comparison against `api-payloads/`: [`payload-compatibility-audit.md`](payload-compatibility-audit.md). Decisions: [`backend-implementation-notes.md`](backend-implementation-notes.md). OpenAPI: `GET /openapi` (UI `/api/documentation`, staff-only outside local).

**Working against the mock first.** The SPA ships with an in-browser mock API (`VITE_API_MODE=mock`) that implements the whole contract, including validation errors, permissions, state transitions and the payment round-trip. Run `npm run dev` and use the demo accounts in the README to see exactly what each screen sends and expects; DevTools → Network will not show these calls, so set a breakpoint in `src/mocks/adapter.ts`. To switch to Laravel, set `VITE_API_MODE=live` and `VITE_API_BASE_URL`.

---

## 1. Environment & deployment

| | Value |
|---|---|
| SPA origin (prod) | `https://pilotwardrobe.com` |
| API base (prod) | `https://api.pilotwardrobe.com/api/v1` |
| Versioning | URL prefix `/api/v1`. Breaking changes → `/api/v2` |
| SPA hosting | Static files (Vite `dist/`) with SPA fallback: every unknown path serves `index.html` |
| Frontend env | `VITE_API_BASE_URL`, `VITE_API_MODE`, `VITE_AUTH_MODE`, `VITE_SANCTUM_CSRF_URL`, `VITE_APP_URL`, `VITE_GOOGLE_CLIENT_ID` (public). See `.env.example` |

**Secrets never reach the SPA.** The following live only in the Laravel `.env`:

- Stripe, Paystack, Flutterwave and Monnify secret keys, and the PayPal client secret
- Cloudinary API secret
- ZeptoMail token
- `APP_KEY`
- Database credentials

Anything prefixed `VITE_` is compiled into public JavaScript.

Laravel config the SPA depends on:

```env
APP_FRONTEND_URL=https://pilotwardrobe.com
SANCTUM_STATEFUL_DOMAINS=pilotwardrobe.com,www.pilotwardrobe.com,localhost:5173
SESSION_DOMAIN=.pilotwardrobe.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CORS_ALLOWED_ORIGINS=https://pilotwardrobe.com,http://localhost:5173   # supports_credentials=true
```

---

## 2. Authentication model

### 2.1 Recommended: Sanctum SPA (cookie) mode — `VITE_AUTH_MODE=cookie`

1. Before the first mutating request, the SPA calls `GET {VITE_SANCTUM_CSRF_URL}` (`/sanctum/csrf-cookie`). Laravel sets `XSRF-TOKEN`.
2. Axios sends `X-XSRF-TOKEN` automatically (`withCredentials` and `withXSRFToken` are on).
3. `POST /auth/login` starts the session. The session cookie must be `HttpOnly; Secure; SameSite=Lax`. In this mode `access_token` may be omitted from the response.
4. A CSRF mismatch returns **419**. The SPA shows the "Session expired" screen and asks the user to sign in again.

### 2.2 Alternative: token mode — `VITE_AUTH_MODE=token`

This mode is for split domains where cookies cannot work. Login returns `access_token`, `token_type: "Bearer"` and `expires_in`. The SPA keeps the token in memory, mirrors it to `sessionStorage` (or `localStorage` when "Keep me signed in" is ticked) and sends `Authorization: Bearer …`. Tokens should be short-lived. Cookie mode is preferred because a token stored in JavaScript is exposed to any XSS.

### 2.3 The session principal

`GET /auth/me` is the **only** source of truth for who the user is. The SPA calls it on boot, after login, 2FA, registration and social completion, and after role or permission changes. It must return:

- `role` — `user | aviator | admin | super_admin`
- `permissions` — the **effective** list (role defaults, plus grants, minus revocations)
- `status` — `active | restricted | suspended | deactivated | pending_verification`
- `verification` — `{ email, identity, aviator, kyc_status }`
- `counters` — badges: unread messages and notifications, cart items, open offers and so on

The SPA never derives permissions from the role on its own. If `permissions` is empty, the user sees no gated UI.

### 2.4 Login variants

`POST /auth/login` returns one of these:

- `{ "status": "authenticated", "user": {…}, "access_token"?: … }`
- `{ "status": "two_factor_required", "challenge_id": "2fa_…", "methods": ["totp","sms","backup_code"], "sms_hint": "•••• 4417" }`, completed with `POST /auth/two-factor`

`status` is optional for backward compatibility. If it is omitted, the SPA infers `authenticated` when `user` is present and `two_factor_required` when `challenge_id` is present.

Staff never use `/auth/login`. The Control Tower sign-in (`/admin/sign-in`) is Google Workspace SSO, restricted to the company domain, followed by a WebAuthn security key: `POST /admin/auth/google` then `POST /admin/auth/webauthn`. A staff session should have a shorter idle timeout (30 min).

### 2.5 Social sign-in

The backend owns the OAuth client secrets and `state`.

1. The SPA calls `GET /auth/social/{provider}/redirect` and navigates to the returned URL.
2. The provider redirects back to the SPA at `/auth/google/callback?code=…&state=…`.
3. The SPA posts `code` and `state` to `POST /auth/social/{provider}/callback`. The response has one of three statuses:
   - `new_account` (with `pending_token` and `profile`) → **finish setup** (`POST /auth/social/complete`)
   - `link_required` (with `existing_account`) → **link accounts** (`POST /auth/social/link`, which needs the password)
   - `authenticated`

### 2.6 Logout

`POST /auth/logout` invalidates the session or token. Whatever the response, the SPA clears every user-scoped store: cart, notifications, admin caches and in-memory tokens.

### 2.7 Redirect safety

The SPA only honours `?next=` values that are same-origin relative paths (`/hangar/orders`). Absolute URLs, `//host` and `javascript:` URLs are dropped. Every link the backend generates for emails must point at `APP_FRONTEND_URL`.

---

## 3. Authorisation

- **Backend authorisation is the security boundary.** The SPA's route guards and `v-can` directives only shape the UX. Every endpoint must re-check the session, role, permission and ownership. Use Laravel Policies (`ListingPolicy@update` checks that the caller is the seller, and so on) together with permission middleware such as `can:users.suspend`.
- Route meta in the SPA mirrors the backend rules: `requiresAuth`, `roles`, `permission`, `requiresKyc`. A user who lacks access sees `/403`, and a signed-out user is sent to `/auth/login?next=…`.
- If the API returns **403** on a page the SPA allowed, the SPA shows an inline "You don't have access" state. That is correct behaviour, but it means the `/auth/me` permissions are out of date. Refresh them after role changes; a `permissions_version` on the user would let the SPA notice.
- Admin and super-admin are separate roles, but in the SPA **all staff access is permission-driven**. `admin` with the Trust & safety bundle sees Users, KYC, Moderation, Disputes, Reports and Audit, and does **not** see Settings, Team, Fees or Payouts approval.

Full matrix: [`backend-api-contract.md` §2](backend-api-contract.md#2-roles--permissions).

---

## 4. Wire format

- **Envelope:** `{ success, message?, data }`. Paginated responses add `{ meta, links }`. Errors are `{ success: false, message, code, errors?, meta? }`.
- **Casing:** snake_case keys. The SPA converts keys only, never values.
- **Validation:** 422 with `errors: { field: [messages] }`. Field names must match the request field names (`first_name`, `items.0.size`) so the SPA can map them to inputs. When a 422 has a message but no `errors`, the SPA shows the message as a form-level alert (as for wrong password).
- **Money:** integer **minor units** everywhere. Responses use `{ amount, currency, formatted? }`. Requests use flat `amount` + `currency`. Never use floats. `formatted` is optional but recommended: it is locale-aware via `Accept-Language`, and the SPA falls back to `Intl.NumberFormat`.
- **Dates:** ISO-8601 UTC with `Z`. The SPA renders them in the viewer's time zone. Relative labels ("6h ago") are computed client-side, so do not send them, except for admin table helper labels such as `age_label`, which are already in the contract.
- **IDs:** opaque prefixed strings. The SPA never parses them.
- **Unknown fields:** ignored. Missing optional fields render as "—" or are hidden.
- **Enums:** the SPA has a label for every documented value. An unknown value renders as Title Case, so adding a status is safe, but please tell the frontend team.
- **`allowed_actions`:** orders, offers and swaps carry `allowed_actions: string[]` computed for the **viewer**. The SPA renders buttons from this list rather than re-deriving business rules. For example, an order can carry `pay | cancel | confirm_delivery | open_dispute | review | mark_dispatched | print_label | download | message`.

---

## 5. Pagination, filtering, sorting, search

| Concern | Contract |
|---|---|
| Pagination | `page` (1-based), `per_page` (default 20, max 100). Response `meta.current_page/per_page/total/last_page/from/to` |
| Marketplace filters | `search`, `category` (comma slugs; a parent includes its children), `condition`, `deal_type`, `min_price`/`max_price` (minor units), `verified_only`, `min_rating`, `free_delivery`, `format`, `seller`, `aircraft_type`, `promoted` |
| Marketplace sort | `newest` (default) · `recommended` · `price_asc` · `price_desc` · `popular` · `rating` · `nearest` |
| Facets | `meta.facets.{categories,conditions,deal_types,…}: [{ value, label, count }]` |
| Admin tables | `tab`, `search`, flat filters (`status`, `country`, `risk`, …), `sort=field` / `sort=-field`, plus `meta.counts` for tab badges |

The SPA keeps the full query in the URL, so links are shareable and the back button works. Keep parameter names stable. If you rename one, keep accepting the old name for a release.

Search runs on submit, and filter changes take effect immediately. The SPA aborts the in-flight request whenever the query changes, so the backend will see cancelled connections.

---

## 6. Media (Cloudinary)

- Uploads go **through Laravel**: `POST /media` (multipart `file` + `type`, where `type ∈ listing | avatar | cover | kyc | dispute_evidence | message | content`). Laravel validates the file, strips EXIF GPS data, uploads to Cloudinary with its own credentials, persists a `media` row and returns the `MediaAsset`.
- The SPA never sees the Cloudinary API key or secret and never signs uploads. Direct-to-Cloudinary signed uploads are possible later only if Laravel issues the signature; the SPA would still not hold the secret.
- **`width` and `height` are mandatory.** They let the SPA reserve space before the image loads, so there is no layout shift.
- Delivery: `variants` holds fixed-width transformation URLs (`thumbnail` 160, `small` 320, `medium` 640, `large` 1440, `original`), each with `q_auto,f_auto`. Optionally, `sources` holds per-format renditions (`f_avif` and `f_webp` at 320/640/960/1440/1920), and the SPA then builds `<picture>`. `placeholder` is an optional dominant colour.
- Private types (`kyc`, `dispute_evidence`) use authenticated delivery with signed URLs that expire within 10 minutes, are returned only to their owner and to staff with the right permission, and must never appear in public listing payloads.
- Deleting a listing photo removes the Cloudinary asset server-side (`DELETE /media/{id}`).

---

## 7. Payments

### 7.1 Principles

1. The SPA **never** decides that a payment succeeded. Gateway redirect query strings (`?status=success`, `?trxref=…`) are ignored. Only `GET /payments/{reference}` counts.
2. Card data never touches Pilotwardrobe. Each gateway's hosted checkout collects it. The checkout screen deliberately has no card fields.
3. Gateway secret keys exist only in Laravel. `public_key` may be returned for inline/popup SDKs (Paystack Popup, Flutterwave Inline), but nothing secret.
4. The SPA redirects only to an allow-listed gateway host: `checkout.stripe.com`, `*.paypal.com`, `checkout.paystack.com`, `*.flutterwave.com`, `*.monnify.com`. If you need another host, tell the frontend team (`src/constants/breakpoints.ts → PAYMENT_GATEWAY_HOSTS`).

### 7.2 Sequence

```
Buyer           SPA                         Laravel                         Gateway
 │  Pay ───────▶ POST /orders ─────────────▶ order(pending_payment)
 │              POST /payments/initialize ─▶ create txn (secret key) ─────▶ init
 │              ◀── { reference, authorization_url }
 │  ◀──────── redirect to authorization_url ─────────────────────────────▶ hosted page
 │                                                     webhook ◀────────── charge.success
 │                                           verify signature, amount, currency, reference
 │                                           order → paid, escrow → held, emails
 │  ◀──────── gateway redirects to /payment/callback?reference=PW-…
 │              GET /payments/{reference} (poll 1.5 s → 8 s, ~60 s) ─▶ verified status
 │              successful → /orders/{id}/confirmed
```

### 7.3 Backend requirements

- **Webhooks:** `POST /webhooks/{stripe|paypal|paystack|flutterwave|monnify}` (**Laravel:** `/api/v1/webhooks/{gateway}`; the webhook only triggers server-side verification). Verify the signature header: Stripe `Stripe-Signature`, Paystack `x-paystack-signature` (HMAC-SHA512), Flutterwave `verif-hash`, Monnify `monnify-signature`, PayPal webhook verification API. Be idempotent on the gateway reference, and accept retries with a 200 response.
- **Verification on read:** if `GET /payments/{reference}` is called before the webhook arrives, verify against the gateway API. Never mark a payment paid without checking that **amount, currency and reference** match the order.
- **Statuses:** `pending | processing | successful | failed | cancelled | refunded | partially_refunded`. On `failed`, include `failure_reason` in plain language.
- **Timeouts:** after ~60 s of `pending`, the SPA shows "We're still confirming your payment — we'll email you", with a link to the order. Send the `order.paid` email when the payment completes.
- **Retry:** an order in `pending_payment` can be re-initialised with a different gateway. Expire stale gateway transactions.
- **Refunds and payouts** are initiated only by Laravel (admin or automatic), never by the SPA calling a gateway.

### 7.4 Mock gateway

In mock mode, `authorization_url` points to `/payment/mock-gateway?reference=…`. That page lets you Approve, Decline or Cancel, and it calls `POST /__mock/gateway/{reference}`, which simulates the webhook. It exists only in mock builds and is stripped from live builds.

**Laravel:** against a non-production API with `PAYMENTS_SANDBOX_ENABLED=true`, choose `gateway: "sandbox"`. `authorization_url` then points to the same `/payment/mock-gateway?reference=…` page, which should call `POST /api/v1/sandbox/payments/{reference}/{approve|decline|cancel}` (authenticated) instead of `/__mock/gateway/{reference}`. The SPA then polls `GET /payments/{reference}` as usual. The sandbox gateway appears in `GET /payment-methods` only when it is enabled.

---

## 8. State machines

The SPA labels and colours each state and offers actions only through `allowed_actions`. These transitions are what the UI assumes. Illegal transitions must return **409 `INVALID_STATE_TRANSITION`**.

### 8.1 Listing

```
draft ──publish──▶ pending_review ──approve──▶ active
  │                    │  └──request_changes──▶ needs_changes ──resubmit──▶ pending_review
  └──publish (no moderation)──▶ active          └──remove──▶ removed
active ──pause──▶ paused ──resume──▶ active
active ──(offer/swap accepted · seller reserve)──▶ reserved ──(order paid)──▶ sold
active|paused ──archive──▶ archived ──relist──▶ active
active|reserved|paused ──mark-sold──▶ sold ──relist──▶ active
active ──(swap completed)──▶ swapped
pending_review ──reject──▶ rejected
```

Public search returns only `active` and `reserved` listings.

### 8.2 Offer

```
pending ──accept (seller)──▶ accepted ──(buyer pays)──▶ [order]
pending ──decline (seller)──▶ declined
pending ──counter (seller)──▶ countered → a new pending offer for the buyer
pending ──cancel (buyer)──▶ cancelled
pending ──(24 h)──▶ expired
```

### 8.3 Swap

```
pending ──accept──▶ accepted ──(both legs paid/shipped)──▶ completed
pending ──reject──▶ rejected     pending ──cancel──▶ cancelled
pending ──counter──▶ countered   pending ──(expiry)──▶ expired
accepted|completed ──dispute──▶ disputed
```

### 8.4 Order

```
pending_payment ──payment verified──▶ paid ──▶ awaiting_dispatch ──dispatch──▶ in_transit
     │                                                                     │
     └──(15 min, unpaid)──▶ cancelled           out_for_delivery ◀─────────┘
                                                      │
                                         delivered ──confirm-delivery / 72 h auto──▶ completed
awaiting_dispatch ──cancel──▶ cancelled (refund)
delivered|completed (within window) ──open dispute──▶ disputed ──resolution──▶ completed | refunded | partially_refunded
any (staff risk rule) ──hold──▶ on_hold ──release──▶ previous state
```

### 8.5 Payment

```
pending ──▶ processing ──▶ successful ──refund──▶ refunded | partially_refunded
   └──────────────────────▶ failed | cancelled
```

### 8.6 Escrow (`order.escrow.status`)

```
not_funded ──payment verified──▶ held ──confirm delivery / auto-release──▶ releasing ──▶ released
held ──dispute opened──▶ frozen ──decision──▶ releasing | refunded
held ──risk rule / staff──▶ on_hold ──staff──▶ held | refunded
```

Defaults (AD13, editable under dual control):

| Setting | Default |
|---|---|
| Auto-release after delivery | 72 h |
| Dispatch SLA | 3 days |
| Dispute window | 72 h after landing |
| New-seller payout hold | 7 days |

### 8.7 Dispute

```
open ──▶ awaiting_seller (48 h) ──seller responds──▶ under_review ──staff decision──▶ resolved_buyer | resolved_seller | resolved_split
open|awaiting_seller ──buyer accepts proposal──▶ resolved_split | resolved_buyer
any open state ──buyer withdraws──▶ withdrawn
resolved_* ──appeal (within the appeal window)──▶ appealed ──▶ resolved_*
```

The staff decision is one of `release_to_seller | partial_refund | full_refund_with_return | full_refund_no_return`, with `amount_to_buyer` and `policy_reference`.

### 8.8 KYC

`not_started → pending → approved | rejected | escalated`, and `approved → expired` when the document expires. Swaps, more than 3 live listings and withdrawals need `approved`. Otherwise the API returns **403 `KYC_REQUIRED`**.

---

## 9. Error codes

The full table is in [`backend-api-contract.md` §1.5](backend-api-contract.md#15-error-envelope). Behaviours the SPA hard-codes:

| Code | SPA behaviour |
|---|---|
| 401 `UNAUTHENTICATED` | Clears the session and redirects to login with `next` (except for `/auth/me` on public pages) |
| 403 `FORBIDDEN` | Inline forbidden state / `/403` |
| 403 `KYC_REQUIRED` | "Verify your identity" CTA linking to `/hangar/verification` |
| 409 `INVALID_STATE_TRANSITION` | Toast plus a refetch, because the page is stale |
| 419 | `/419` session-expired page |
| 422 | Field errors, or a form alert |
| 423 `ACCOUNT_LOCKED` | Lock notice using `meta.retry_after` |
| 429 | "Too many requests" toast. The SPA does not auto-retry mutations |
| 503 `MAINTENANCE` | `/maintenance` page |
| 5xx / network | Error state with a Retry button. No automatic retries |

---

## 10. Contract decisions & deviations from the brief

| Topic | Brief | Implemented | Why |
|---|---|---|---|
| Login response | `{ user, access_token, … }` | Adds a `status` discriminator and a 2FA challenge variant | 2FA (VA6) needs a second response shape. The SPA tolerates a missing `status` |
| Media upload field | `type=listing` | Same (`type`) | — |
| Request money | Offers use flat `amount` + `currency`; listing create uses a `price` object | Both, as specified: flat for offers, withdrawals and disputes; `price` objects for listing drafts | Follows the brief's examples |
| Checkout payment UI | V4 design shows card fields | Gateway picker plus hosted checkout | PCI scope. The brief forbids handling card data |
| Listing search `currency` param | `currency=USD` in the example | Optional. Price bounds are in the listing's currency | Multi-currency conversion is a backend decision; please confirm |
| Review tags | — | Sent as display labels (`"As described"`) | Matches the design copy. Switch to slugs before launch if localisation is planned |
| Staff sign-in | — | Google Workspace SSO + WebAuthn (AD1) | From the AD1 design |
| Order actions | — | `allowed_actions` on resources | Keeps business rules on the server |
| Admin dual control | — | `{ status: "pending_approval" }` responses | From the AD8/AD12/AD13 designs |

Open questions for the backend, now answered by the Laravel implementation:

1. ~~Is the seller's currency or the buyer's currency the source of truth?~~ **The listing's (seller's) currency.** Orders, escrow and payouts are in the listing currency; one checkout cannot mix currencies (422). `exchange_rates` is for display hints only.
2. ~~Which KYC provider?~~ **Prembly (IdentityPass)** runs automated document and identity checks; a human reviewer always decides. The SPA flow (hand off, then poll `GET /me/kyc`) is unchanged.
3. ~~Real-time transport?~~ **None yet**: keep polling. Payloads are unchanged if Reverb is added.
4. ~~Auto-approve some sellers?~~ **Yes, configurable** in `/admin/settings` → moderation: official stores, approved aviators and KYC-verified sellers skip review by default.

Further backend decisions the SPA should know about:

- The `aviator` role is granted only when staff approve an aviator credential, not at registration (D3).
- Apple sign-in is not implemented (`/auth/social/apple/*` → 404). Hide the Apple button until it ships (D8).
- Keys whose value is `null` are sometimes omitted (for example `read_at` on unread messages). Treat missing as absent, as §4 already says.
- Money objects include `formatted`.

---

## 11. SEO & rendering assessment

The SPA is client-rendered. It manages `<title>`, meta description, canonical URLs, Open Graph and Twitter tags and JSON-LD (`WebSite` + `SearchAction` on home; `Product`, `Brand`, `Offer` and `AggregateRating` on listings; `Person` on seller pages) per route, through `useSeo`. Private routes (`/hangar`, `/checkout`, `/admin`, `/auth`) are `noindex`.

That is enough for Googlebot, which runs JavaScript, but **not** for link-preview crawlers: WhatsApp, iMessage, Facebook, LinkedIn and X do not run JavaScript. Most marketplace sharing happens through those apps, so this needs fixing before launch. Options, cheapest first:

1. **Edge/server meta injection (recommended for v1).** Laravel (or a CDN worker) serves `index.html` for `/marketplace/{slug}`, `/seller/{username}` and `/photographers/{username}` with the listing's OG/Twitter tags and JSON-LD already filled in. The SPA then hydrates normally.
2. **Prerendering** of the public routes at build time or on demand (for example Rendertron, or prerender.io). This also helps Core Web Vitals.
3. **Nuxt 3 SSR** later. The components, stores and API layer are framework-agnostic Vue 3, so the move is mechanical.

Backend tasks either way:

- `sitemap.xml` (listings, categories, sellers) and `robots.txt` on the public domain
- `410 Gone` for removed listings, so crawlers drop them
- Stable slugs, with 301s from old slugs when a listing is renamed

---

## 12. Analytics

The SPA emits typed events through `src/utils/analytics.ts`: `marketplace_viewed`, `product_viewed`, `search_performed`, `filter_applied`, `favorite_added`, `offer_started`/`offer_submitted`, `swap_started`/`swap_submitted`, `checkout_started`, `payment_gateway_selected`, `payment_completed` (fired only after server verification), `payment_failed`, `listing_created`, `sign_up_completed` and `login_completed`. The default sink is a no-op in production. Wire it to GA4, Segment or PostHog by registering a sink in `main.ts`. Events contain no PII apart from opaque ids.

---

## 13. Checklist before switching `VITE_API_MODE=live`

Status as of 2026-10-21 (backend side):

- [x] CORS with credentials, Sanctum stateful domains and session cookie domain configured (`config/cors.php`, `.env.example`). Set the real domains per environment.
- [x] `GET /auth/me` returns effective `permissions` (plus `permissions_version`)
- [x] All list endpoints return `meta` + `links` in the documented shape
- [x] 422s use request field names (checked against every error sample in the payload audit)
- [x] Money in minor units everywhere, with `formatted` localised via `Accept-Language`
- [x] Media responses include `width`/`height` and the `variants`
- [x] `/payments/initialize` returns a gateway-hosted `authorization_url`, and `/payments/{reference}` is server-verified
- [x] Webhooks verified and idempotent (payments, refunds and payout transfers)
- [x] Ownership enforced on every mutating endpoint (caller-scoped queries return 404 for others' records, and services re-check the actor; this is done in services rather than Laravel Policy classes)
- [x] Audit log written for every `/admin/*` mutation (immutable rows)
- [x] ZeptoMail templates from `backend-api-contract.md` §3 created: they are rendered by Laravel (`config/notifications.php`), so nothing needs creating in ZeptoMail beyond domain verification
- [x] Payload samples replayed against a seeded API: 103 of 109 match, 6 need live credentials or multipart and are covered by tests ([`payload-compatibility-audit.md`](payload-compatibility-audit.md))
- [ ] Run the SPA's `tests/unit/contract-payloads.spec.ts` against a seeded **staging** API (frontend team)
- [ ] Point the mock-gateway page at `POST /api/v1/sandbox/payments/{reference}/{outcome}` for staging (frontend team)
- [ ] Hide the Apple sign-in button (frontend team)
