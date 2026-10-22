# Design screen inventory

Source: `docs/pilotwardrobe-designs` (75 frames). Every supplied frame is listed with its route, the role that can reach it, the API it depends on and its implementation status.

**Mobile designs:** the coverage audit (VB0) references mobile frames (A0–A8, K1–K7, B1–B12, S1–S9, C1–C5, T1–T6, M1–M5, W1–W5, F1–F4). These were **not supplied**. Mobile layouts were derived from the desktop frames and from the app mock-ups in V1 (bottom tab bar, search under the header, chip filters). See *Design gaps* at the end.

Legend — **Status:** ✅ implemented to the design · 🟡 implemented with a documented deviation · ➕ derived screen (no frame supplied).

## Overview / audit frames

| Frame | Screen | Route | Notes |
|---|---|---|---|
| VB0 | Web coverage audit | — | Reference only (maps journeys → frames). |
| VA0 | Auth flow & build notes | — | Rules implemented: routes, validation, lockout, OTP, `?next=` preservation. |
| AD0 | Control Tower sitemap | — | Section grouping and dual-control rules implemented. |

## Public marketplace (web-basic, web-plus)

| Frame | Screen name | Desktop | Mobile | Route | Role | Required API | Status |
|---|---|---|---|---|---|---|---|
| V1 | Marketplace home (logged out) | ✔ | derived | `/` | visitor+ | `GET /home` | ✅ |
| V2 | Search results + filter rail | ✔ | derived (filter bottom drawer) | `/marketplace`, `/category/:slug`, `/search` → redirect | visitor+ | `GET /listings`, `GET /categories` | ✅ |
| V3 | Listing detail + size selector | ✔ | derived | `/marketplace/:slug` | visitor+ (actions gated) | `GET /listings/{slug}`, `GET /listings/{slug}/related` | ✅ |
| VB18 | Seller profile | ✔ | derived | `/seller/:username` | visitor+ | `GET /sellers/{username}`, `…/listings`, `…/reviews`, `POST/DELETE …/follow` | ✅ |
| VB19 | Photographer storefront | ✔ | derived | `/photographers/:username` | visitor+ | as VB18 + licensing block | ✅ |
| VB20 | System states (404, no results, empty hangar, offline, maintenance, verification required) | ✔ | derived | `/404` (catch-all), `/offline`, `/maintenance`, inline empty/error states | all | — | ✅ |
| — | All categories | — | — | `/categories` | visitor+ | `GET /categories` | ➕ |
| — | Aviators / photographers directory | — | — | `/aviators`, `/photographers` | visitor+ | `GET /sellers?type=` | ➕ |
| — | Help articles (CMS) | — | — | `/help/:slug` | visitor+ | `GET /pages/{slug}` | ➕ |
| — | 403 / 419 / 500 | — | — | `/403`, `/419`, `/500` | all | — | ➕ (VB20 style) |

## Information centre — guides, policies, support (info)

All thirteen are `meta.info` routes in `PublicLayout`, which swaps in a Guides · Policies · Support sub-nav (sticky on desktop, swipeable on phones) and the information footer under the usual header. Copy lives in typed content files under `src/content/info/`; platform timings (5-day dispatch, 72-hour inspection, 48-hour seller reply, 5-business-day mediation, 8% commission) come from one `PLATFORM` constant.

| Frame | Screen | Route | Role | Required API | Status |
|---|---|---|---|---|---|
| VC1 | How to swap | `/how-to-swap` (old `/how-it-works` redirects) | visitor+ | — | ✅ |
| VC2 | How to sell | `/how-to-sell` | visitor+ | — | ✅ |
| VC3 | Freight guides | `/freight-guide` | visitor+ | — | ✅ |
| VC4 | Seller fees | `/seller-fees` | visitor+ | — | ✅ |
| VC5 | Terms of service | `/terms` | visitor+ | — | ✅ |
| VC6 | Privacy policy | `/privacy` | visitor+ | — | 🟡 processor names generalised (see deviations) |
| VC7 | Returns & refunds | `/returns` | visitor+ | — | ✅ |
| VC8 | Escrow & disputes | `/escrow-disputes` | visitor+ | — | ✅ |
| VC9 | Cookie policy + preferences | `/cookies` | visitor+ | — (choice stored on the device) | ✅ |
| VC10 | Help Center | `/help` | visitor+ (active-order card when signed in) | `GET /help/summary`, `GET /me/orders?status=active` | ✅ |
| VC11 | Contact us | `/contact` | visitor+ (order picker when signed in) | `GET /support/contact-info`, `POST /contact`, `POST /media` | 🟡 live chat routed to the message form (see deviations) |
| VC12 | FAQs | `/faq` (`?topic=`, `?q=`, `#question-id`) | visitor+ | — | ✅ |
| VC13 | About us | `/about` | visitor+ | — | ✅ |

## Authentication (web-auth)

| Frame | Screen | Route | Role | Required API | Status |
|---|---|---|---|---|---|
| VA1 | Welcome (email-first) | `/auth` (also `/register`) | visitor | `POST /auth/email-lookup` | ✅ |
| VA2 | Waiting for Google (popup) | modal on VA1/VA6/VA14 | visitor | `GET /auth/social/{provider}/redirect` | ✅ |
| VA3 | Finish setup (Google new user) | `/auth/finish-setup` | visitor | `GET /auth/username-availability`, `POST /auth/social/complete` | ✅ |
| VA4 | Create account | `/auth/signup` | visitor | `POST /auth/register` | ✅ |
| VA5 | Verify email (4-digit OTP) | `/auth/verify` (also `/verify-email`) | new user | `POST /auth/email/verify`, `POST /auth/email/resend` | ✅ |
| VA6 | Log in + error state | `/auth/login` (also `/login`) | visitor | `POST /auth/login` | ✅ |
| VA7 | Two-step verification | `/auth/2fa` | visitor w/ challenge | `POST /auth/two-factor`, `POST /auth/two-factor/sms` | ✅ |
| VA8 | Link Google account | `/auth/link-accounts` | visitor | `POST /auth/social/link` | ✅ |
| VA9 | Forgot password | `/auth/forgot` (also `/forgot-password`) | visitor | `POST /auth/password/forgot` | ✅ |
| VA10 | Link sent | `/auth/forgot` (sent state) | visitor | — | ✅ |
| VA11 | New password | `/auth/reset?token=&email=` (also `/reset-password`) | visitor | `GET /auth/password/reset/{token}`, `POST /auth/password/reset` | ✅ |
| VA12 | Link expired | `/auth/reset` (expired state) | visitor | as VA11 | ✅ |
| VA13 | Account ready | `/auth/ready` | user | — | ✅ |
| VA14 | Gated-action auth modal | modal on any page | visitor | as VA1 | ✅ |
| — | OAuth callback handler | `/auth/google/callback` | visitor | `POST /auth/social/{provider}/callback` | ➕ |

## Selling, trading and account — My Hangar (web-basic, web-plus)

| Frame | Screen | Route | Role | Required API | Status |
|---|---|---|---|---|---|
| V5 | My Hangar seller dashboard | `/hangar` | user, aviator | `GET /me/hangar` | ✅ |
| V6 | Fit Kit manager | `/hangar/fit-kit` | user, aviator | `GET/POST/PATCH/DELETE /me/fit-kit` | ✅ |
| VB1 | Sell · photos & details | `/hangar/listings/new` (`/sell` → redirect), `/hangar/listings/:id/edit` | `listings.create` | `POST /media`, `POST /listings`, `PATCH /listings/{id}`, `GET /categories` | ✅ |
| VB2 | Sell · condition, pricing, freight | same (step 2) | `listings.create` | `POST /listings/price-check`, `POST /listings/fee-quote` | ✅ |
| VB3 | Sell · review & publish | same (step 3) | `listings.create` | `GET /boost-packages`, `POST /listings/{id}/publish` | ✅ |
| VB4 | Listings management | `/hangar/listings` | user, aviator | `GET /me/listings`, `POST /listings/{id}/{action}`, `POST /listings/bulk`, `DELETE /listings/{id}`, `POST /listings/imports` | ✅ |
| VB5 | Offers & swaps inbox | `/hangar/offers` | user, aviator | `GET /me/offers`, `GET /me/swap-requests`, `POST /offers/{id}/{accept\|decline\|counter}`, `POST /swap-requests/{id}/{accept\|reject}` | ✅ |
| VB6 | Swap builder | `/swap/:slug` | `swaps.create` + KYC | `GET /listings/{slug}`, `GET /me/listings?status=live`, `POST /listings/{id}/swap-requests` | ✅ |
| V4 | Checkout with Fit Kit applied | `/checkout?listing=…[&size][&offer]` or `?cart=` | `orders.create` | `GET /me/addresses`, `POST /checkout/quote`, `GET /payment-methods`, `POST /orders`, `POST /payments/initialize` | 🟡 card fields replaced with gateway selection (see deviations) |
| VB9 | Order confirmation | `/orders/:id/confirmed` | buyer | `GET /orders/{id}` (after `GET /payments/{ref}` = successful) | ✅ |
| VB7 | Order tracker | `/hangar/orders/:id` | buyer / seller | `GET /orders/{id}`, `GET /orders/{id}/invoice` | ✅ |
| VB8 | Confirm goods landed (modal) | `/hangar/orders/:id?confirm=1` | buyer | `POST /orders/{id}/confirm-delivery` | ✅ |
| VB10 | Messages | `/messages/:conversationId?` | user | `GET /conversations`, `GET /conversations/{id}/messages`, `POST …/messages`, `POST …/read`, `POST …/report`, `POST /listings/{id}/reserve` | ✅ |
| VB11 | Notifications | `/notifications` | user | `GET /notifications`, `PATCH /notifications/{id}/read`, `POST /notifications/read-all`, `GET/PUT /me/notification-preferences` | ✅ |
| VB12 | Wallet | `/hangar/wallet` | user | `GET /me/wallet`, `GET /me/wallet/transactions`, `GET /me/payout-methods`, `GET/PUT /me/payout-schedule`, `POST /me/wallet/statements` | ✅ |
| VB13 | Withdraw (modal) | on `/hangar/wallet` and `/hangar` | `payouts.request` | `POST /me/withdrawals/quote`, `POST /me/withdrawals` | ✅ |
| VB14 | Open a dispute | `/hangar/disputes/new?order=` | `disputes.create` | `GET /orders/{id}`, `POST /media`, `POST /disputes` | ✅ |
| VB15 | Dispute case | `/hangar/disputes/:id` | party | `GET /disputes/{id}`, `POST …/messages`, `POST …/withdraw`, `POST …/proposal/{accept\|decline}` | ✅ |
| VB16 | Identity verification (KYC) | `/hangar/verification` | user | `GET /me/kyc`, `POST /me/kyc/personal`, `POST /me/kyc/document`, `POST /me/kyc/submit` | ✅ (QR placeholder — see gaps) |
| VB17 | Settings · security | `/hangar/settings/:tab?` | user | `GET /me/security`, `PUT /me/password`, `DELETE /me/sessions/{id}`, `POST /me/sessions/logout-others`, `DELETE /me/social/{provider}`, `POST /me/deactivate`, `DELETE /me` | ✅ |
| — | Orders list (purchases / sales) | `/hangar/orders` | user | `GET /me/orders` | ➕ |
| — | Reviews | `/hangar/reviews` | user | `GET /me/reviews` | ➕ |
| — | Disputes list | `/hangar/disputes` | user | `GET /me/disputes` | ➕ |
| — | Saved items | `/hangar/favorites` | user | `GET /me/favorites` | ➕ |
| — | Addresses | `/hangar/addresses` | user | `/me/addresses` CRUD | ➕ |
| — | Cart | `/cart` | user | `GET /cart`, `DELETE /cart/items/{id}` | ➕ |
| — | Aviator credentials | `/hangar/aviator` | user (apply) / aviator (view) | `GET/POST /me/aviator` | ➕ |
| — | Payment callback / verification | `/payment/callback` | buyer | `GET /payments/{reference}` | ➕ |
| — | Gateway sandbox (mock mode only) | `/payment/mock-gateway` | buyer | — | ➕ dev only |

## Control Tower (admin)

| Frame | Screen | Route | Roles / permission | Required API | Status |
|---|---|---|---|---|---|
| AD1 | Staff sign-in | `/admin/sign-in` | staff | `POST /admin/auth/google`, `POST /admin/auth/webauthn` | ✅ |
| AD2 | Overview | `/admin/overview` (alias `/admin/dashboard`) | `dashboard.view` | `GET /admin/overview` | ✅ |
| AD3 | Users | `/admin/users` (alias `/admin/aviators`) | `users.view` | `GET /admin/users`, `POST /admin/users/invitations`, `POST /admin/exports` | ✅ |
| AD4 | User detail | `/admin/users/:id` | `users.view` (+`users.update`/`users.suspend`/`users.impersonate`) | `GET /admin/users/{id}`, `POST …/suspend\|reinstate\|restrict`, `PATCH …/limits`, `POST …/notes`, `POST …/impersonate` | 🟡 sub-tabs other than Overview are empty states |
| AD5 | KYC review | `/admin/kyc/:id?` (alias `/admin/verifications`) | `kyc.view` / `kyc.review` | `GET /admin/kyc`, `GET /admin/kyc/{id}`, `POST /admin/kyc/{id}/{approve\|reject\|escalate\|request-info}` | ✅ |
| AD6 | Listing moderation | `/admin/listings` | `listings.view` / `listings.approve` / `listings.reject` | `GET /admin/moderation`, `POST /admin/moderation/decisions` | 🟡 "Assign to" & "Moderation rules" omitted (no API) |
| AD7 | Orders & escrow | `/admin/orders` | `orders.view` | `GET /admin/orders`, `GET /admin/orders/stats`, `POST /admin/orders/reconcile` | ✅ |
| AD8 | Order detail · refund | `/admin/orders/:id` | `orders.view` / `orders.manage` / `transactions.refund` | `GET /admin/orders/{id}`, `POST …/refund\|release\|hold` | ✅ |
| AD9 | Disputes | `/admin/disputes/:id?` | `disputes.view` / `disputes.resolve` | `GET /admin/disputes`, `GET /admin/disputes/{id}`, `POST …/messages`, `POST …/decision` | ✅ |
| AD10 | Reports & flags | `/admin/reports` (alias `/admin/messages/reports`) | `reports.view` / `reports.manage` | `GET /admin/reports`, `POST /admin/reports/{id}/actions` | ✅ |
| AD11 | Payouts | `/admin/payouts` (aliases `/admin/transactions`, `/admin/payments`) | `payouts.view` / `payouts.approve` | `GET /admin/payouts`, `GET /admin/payouts/stats`, `POST /admin/payouts/approve`, `POST …/{id}/hold\|reject`, `POST /admin/payouts/batches` | ✅ |
| AD12 | Fees & revenue | `/admin/fees` (alias `/admin/commissions`) | `fees.view` / `fees.manage` | `GET /admin/fees`, `POST/PATCH /admin/commission-rules`, `PUT /admin/escrow-timings` | ✅ |
| AD13 | Promotions | `/admin/promotions` | `promotions.manage` | `GET /admin/campaigns`, `GET/POST /admin/promo-codes`, `POST /admin/campaigns/{id}/duplicate` | 🟡 campaign builder not designed |
| AD14 | Broadcasts | `/admin/broadcasts` (alias `/admin/notifications`) | `broadcasts.manage` | `POST /admin/broadcasts/reach`, `POST /admin/broadcasts`, `POST /admin/broadcasts/test` | ✅ |
| AD15 | Content & pages (home hero) | `/admin/content` | `content.manage` | `GET /admin/content/hero-slides`, `PATCH …/{id}`, `PUT …/order`, `POST /admin/content/publish` | 🟡 other content tabs not designed |
| AD16 | Catalogue & sizes | `/admin/catalogue` (alias `/admin/categories`) | `catalogue.manage` | `GET /admin/catalogue`, `GET /admin/catalogue/{id}` | 🟡 read-only (editors not designed) |
| AD17 | Analytics | `/admin/analytics` | `analytics.view` | `GET /admin/analytics` | ✅ |
| AD18 | Team & roles | `/admin/team` (aliases `/admin/administrators`, `/admin/roles`, `/admin/permissions`) | `team.view` / `roles.manage` | `GET /admin/roles`, `POST/PATCH /admin/roles`, `GET /admin/team`, `POST /admin/team/invitations`, `PATCH /admin/team/{id}` | ✅ |
| AD19 | Audit log | `/admin/audit-log` | `audit.view` | `GET /admin/audit-logs` | ✅ |
| AD20 | Platform settings | `/admin/settings/:tab?` (alias `/admin/payment-gateways`) | **super_admin** + `settings.manage` | `GET/PATCH /admin/settings`, `POST /admin/maintenance-windows` | 🟡 Markets, PSPs, flags implemented; other tabs pending provider decisions |

## Intentional deviations from the designs

1. **V4 checkout payment card fields.** The frame shows raw card number / expiry / CVC inputs. The brief forbids collecting or storing card data and requires a gateway-agnostic architecture. The Payment step is therefore a gateway selector (from `GET /payment-methods`), and card entry happens on the gateway's hosted page. This keeps PCI scope with Paystack / Flutterwave / Monnify / Stripe / PayPal.
2. **Top navigation for visitors vs. members.** V1 (visitor) and V2+ (member) headers are both implemented and switch on auth state.
3. **Mobile bottom tab bar.** Not in a web frame. It mirrors the iOS/Android app shown in V1 and is shown under 768px only.
4. **VC11/VC10 live chat.** There is no live-chat provider yet, so “Start chat” / “Start live chat” open the contact form (relabelled “Start a message” / “Message the crew”). Swap the buttons to the chat widget once a provider (Intercom, Zendesk, Crisp…) is chosen.
5. **VC6 named processors.** The frame lists Stripe · PayPal, Onfido · Persona, Postmark and AWS. Email actually goes through ZeptoMail and the KYC provider isn’t chosen, so the page names categories (“Payment providers”, “Identity verification”, “Email delivery”). Legal should insert the real processor list before launch. The info footer likewise drops “via Stripe Connect”.
6. **Gold buttons (VC1, VC2, VC13).** Navy text on gold instead of white: white on `#c9a227` is ~2.3:1 and fails WCAG AA.
7. **Policy “Download PDF”.** Opens the print dialog (“Save as PDF”) with a print stylesheet that strips site chrome; no pre-rendered PDFs exist. Point it at a backend URL if legal wants signed, versioned PDFs.
8. **VC10 article counts.** The frame’s “24 articles” style counts imply a knowledge base that doesn’t exist; cards show the real number of FAQ answers per topic, or link to the relevant policy/page.
9. **VC12 default tab.** The frame shows “Escrow & payments” selected; the page opens on “All” unless `?topic=` is set.

## Design gaps (please supply)

| Gap | Impact | Current handling |
|---|---|---|
| Mobile frames (A0–A8, B, S, C, T, M, W, K, F series) | Mobile fidelity is inferred | Responsive layouts derived from desktop and app mock-ups; validated at 320–1920px |
| Vector logo (SVG) | Raster logo is soft at large sizes | Logo extracted from VA1 at 2× (200×92) into `src/assets/brand/` |
| High-resolution product photos for `p-0`…`p-9` (120–300px originals) | Cards and gallery look soft on retina | Used as-is; replace with ≥1600px originals |
| High-resolution photography (`Im-1`…`Im-5`, ~600px) | Gallery tiles soft on retina | Used as-is |
| `Circular-Polarizing-Filter.png`, `Telephoto-Camera-Lens.png` | Checkerboard "transparency" baked into pixels | Not used |
| Category banners, help centre, legal page editors (AD15 tabs) | Not designed | Empty state + publish workflow note |
| Campaign builder, category/size-chart editors, moderation rules, "Assign to" | Not designed | Omitted / read-only |
| Admin user-detail sub-tabs (Listings, Orders, Wallet…) | Not designed | Empty state |
| KYC hand-off QR | Needs real QR image | Placeholder icon; backend should return `handoff_qr_svg` or a signed image URL |
| Help-centre knowledge base (VC10 “135 articles”) | Only FAQ answers and policies exist | Topic cards link to FAQ topics and policies; `/help/:slug` serves CMS articles |
| Live chat | No provider chosen | Contact form; see deviation 4 |
| Payment-method logos | Not supplied | Coloured monogram fallback; `logo` URL from API is rendered when present |
| Locale / currency switcher UI ("English - USD") | Interaction not designed | Display only; preference saved under Settings → Preferences |
