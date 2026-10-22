# Payload compatibility audit

Every request/response sample in [`api-payloads/`](api-payloads/README.md) (produced by the SPA mock server) was replayed against the real Laravel API and the response key paths were compared with the sample. Method:

- `scripts/payload-audit.php` boots the application in-process against the seeded development database (`php artisan migrate:fresh --seed`), builds missing fixtures through the API itself (orders paid through the sandbox gateway, dispatched and delivered; a dispute with a seller proposal; a swap; an offer; a KYC case; a report; a payout), and replays each sample as the same demo user, inside a transaction that is rolled back.
- Mock ids in paths and bodies (`ord_4471`, `lst_003`, `adr_1`, …) are mapped to real records in the same state.
- A key counts as **missing** only when the real response lacks it although its parent object is present and non-empty. Keys under an empty list or a `null` are reported as *unverifiable*, not missing. **Extra** keys (for example `formatted` money strings) are additive and allowed by the contract ("unknown fields: ignored").
- Status codes and, for errors, the `code` and the `errors` field names are compared too.

Regenerate: `php scripts/payload-audit.php > storage/app/payload-audit.json && python scripts/generate-payload-audit-doc.py storage/app/payload-audit.json`.

## Result

| Status | Samples | Meaning |
|---|---|---|
| **Supported** | 103 | Same status, every documented key present (or a documented null omission / data variant). |
| **Modified** | 0 | Different status code or error code. |
| **Missing fields** | 0 | A documented key is absent from a non-empty parent. |
| **Deprecated** | 0 | Endpoint or field removed. |
| **Not exercised** | 6 | Cannot be replayed here (needs live credentials, multipart, or a previous sample's side effect); covered by tests instead. |
| Total | 109 | |

Nothing is deprecated: all 209 contract endpoints exist (see [frontend-api-mapping.md](frontend-api-mapping.md)).

### Defects the audit found and that were fixed

| Endpoint | Problem | Fix |
|---|---|---|
| `GET /sellers/{username}` | `rating_breakdown` was serialised as a JSON **list** because `array_merge` renumbers numeric keys | `array_replace`, plus a regression assertion in `ListingBrowseTest` |
| `POST /admin/disputes/{id}/decision` | **500** when the optional `note` was omitted | Optional field read safely; new `DisputesMessagingTest::test_staff_decision_settles_dispute_and_needs_dispute_permission` |
| `GET /admin/orders/{id}` | `parties[].risk` missing | Added (`low`/`medium`/`high` from the risk score) |
| `POST /admin/kyc/{id}/approve\|reject\|…` | Returned the summary row instead of the review item (`document`, `selfie`, `extracted`, `automated_checks` missing) | Returns the same shape as `GET /admin/kyc/{id}` |
| `GET /me/hangar` | `stats.previous_rating` missing | Added (average rating of reviews older than 30 days; `null` without any) |

## Sample by sample

### [admin.md](api-payloads/admin.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Overview | `GET /admin/overview` as super@example.com | Supported | 3 nested keys not verifiable (empty list/null in fixture data). |
| Users table | `GET /admin/users?tab=all&per_page=2&sort=-joined_at` as admin@example.com | Supported |  |
| User detail | `GET /admin/users/usr_10422` as admin@example.com | Supported | 12 nested keys not verifiable (empty list/null in fixture data). |
| Forbidden for staff without permission (403) | `GET /admin/settings` as admin@example.com | Supported |  |
| KYC review item | `GET /admin/kyc/kyc_7731` as super@example.com | Supported | 7 nested keys not verifiable (empty list/null in fixture data). |
| Moderation decision | `POST /admin/moderation/decisions` as super@example.com | Supported |  |
| Admin order detail | `GET /admin/orders/ord_4479a` as super@example.com | Supported |  |
| Refund — dual control | `POST /admin/orders/ord_4479a/refund` as super@example.com | Supported | The fixture refund was below the $1,000 threshold and executed directly (`status: executed`). The `pending_approval` + `approver` response is covered by `ControlTowerTest`. |
| Dispute decision | `POST /admin/disputes/dsp_2041/decision` as super@example.com | Supported |  |
| Report action | `POST /admin/reports/rep_1/actions` as super@example.com | Supported |  |
| Payouts table | `GET /admin/payouts?per_page=2` as super@example.com | Supported | 12 nested keys not verifiable (empty list/null in fixture data). |
| Fee rule change — dual control | `POST /admin/commission-rules` as super@example.com | Supported |  |
| Hero slide update | `PATCH /admin/content/hero-slides/hs_1` as super@example.com | Supported | 20 nested keys not verifiable (empty list/null in fixture data). |
| Roles | `GET /admin/roles` as super@example.com | Supported |  |
| Update role permissions | `PATCH /admin/roles/role_ts` as super@example.com | Supported |  |
| Audit log | `GET /admin/audit-logs?per_page=2` as super@example.com | Supported |  |
| Platform settings | `GET /admin/settings` as super@example.com | Supported |  |
| Suspend user | `POST /admin/users/usr_10422/suspend` as super@example.com | Supported | 3 nested keys not verifiable (empty list/null in fixture data). |
| KYC approve | `POST /admin/kyc/kyc_7731/approve` as super@example.com | Supported | 7 nested keys not verifiable (empty list/null in fixture data). |
| Hold payout | `POST /admin/payouts/po_1/hold` as super@example.com | Supported |  |
### [auth.md](api-payloads/auth.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Email lookup | `POST /auth/email-lookup` as visitor | Supported |  |
| Login — authenticated | `POST /auth/login` as visitor | Supported |  |
| Login — two-factor required | `POST /auth/login` as visitor | Supported | The demo aviator has no 2FA enrolled, so login succeeded. The `two_factor_required` variant (`challenge_id`, `methods`, `sms_hint`) is covered by `AuthenticationTest::test_two_factor_challenge_flow`. |
| Login — wrong password (422) | `POST /auth/login` as visitor | Supported |  |
| Register | `POST /auth/register` as visitor | Supported |  |
| Register — validation failure (422) | `POST /auth/register` as visitor | Supported |  |
| Verify email OTP | `POST /auth/email/verify` as visitor | Not exercised | Needs the code e-mailed by the preceding registration sample (each sample is rolled back). Covered by `AuthenticationTest::test_email_otp_verification`. |
| Resend email OTP | `POST /auth/email/resend` as visitor | Supported |  |
| Forgot password | `POST /auth/password/forgot` as visitor | Supported |  |
| Check reset token | `GET /auth/password/reset/demo-reset-token` as visitor | Not exercised | Mock-only token. Unknown tokens return 410 `TOKEN_EXPIRED`; valid tokens return `{ valid, email }` (`AuthenticationTest`). |
| Reset password — expired token (410) | `POST /auth/password/reset` as visitor | Supported |  |
| Current user (/auth/me) | `GET /auth/me` as pilot@example.com | Supported |  |
| Current user — signed out (401) | `GET /auth/me` as visitor | Supported |  |
| Username availability | `GET /auth/username-availability?username=captain_ada` as visitor | Supported |  |
| Social redirect URL | `GET /auth/social/google/redirect` as visitor | Not exercised | Needs `GOOGLE_CLIENT_ID`; without it the API returns 503 `GATEWAY_UNAVAILABLE`. |
| Logout | `POST /auth/logout` as pilot@example.com | Supported |  |
### [disputes.md](api-payloads/disputes.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| My disputes | `GET /me/disputes` as pilot@example.com | Supported | 22 nested keys not verifiable (empty list/null in fixture data). |
| Dispute case | `GET /disputes/dsp_2041` as pilot@example.com | Supported | 22 nested keys not verifiable (empty list/null in fixture data). |
| Open dispute — validation (422) | `POST /disputes` as pilot@example.com | Supported |  |
| Reply on dispute | `POST /disputes/dsp_2041/messages` as pilot@example.com | Supported | 22 nested keys not verifiable (empty list/null in fixture data). |
| Accept seller proposal | `POST /disputes/dsp_2041/proposal/accept` as pilot@example.com | Supported | 22 nested keys not verifiable (empty list/null in fixture data). |
### [listings.md](api-payloads/listings.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Search listings | `GET /listings?search=headset&category=headsets&condition=like_new%2Cgood&min_price=10000&sort=price_asc&page=1&per_page=2` as visitor | Supported |  |
| My listings | `GET /me/listings?status=active&per_page=2` as pilot@example.com | Supported |  |
| Create draft listing | `POST /listings` as pilot@example.com | Supported | 12 nested keys not verifiable (empty list/null in fixture data). |
| Publish listing — no photos (422) | `POST /listings/lst_p12jfpt/publish` as pilot@example.com | Supported |  |
| Fee quote | `POST /listings/fee-quote` as pilot@example.com | Supported |  |
| Bulk action | `POST /listings/bulk` as pilot@example.com | Supported |  |
| Upload media (multipart → MediaAsset) | `POST /media` as pilot@example.com | Not exercised | Multipart upload, which the JSON replay cannot send. Covered by `ListingLifecycleTest` (returns the `MediaAsset` shape with `width`/`height`/`variants`). |
### [messages.md](api-payloads/messages.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Conversations | `GET /conversations` as pilot@example.com | Supported |  |
| Conversation messages | `GET /conversations/cnv_1/messages` as pilot@example.com | Supported | Null field omitted while unread (D19). |
| Send message | `POST /conversations/cnv_1/messages` as pilot@example.com | Supported |  |
| Send message — empty (422) | `POST /conversations/cnv_1/messages` as pilot@example.com | Supported |  |
### [notifications.md](api-payloads/notifications.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Notifications | `GET /notifications?per_page=2` as pilot@example.com | Supported |  |
| Unread count | `GET /notifications/unread-count` as pilot@example.com | Supported |  |
| Mark read | `PATCH /notifications/ntf_1/read` as pilot@example.com | Supported | Null field omitted when the notification has no actions (D19). |
| Mark all read | `POST /notifications/read-all` as pilot@example.com | Supported |  |
### [offers.md](api-payloads/offers.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Make offer | `POST /listings/lst_003/offers` as pilot@example.com | Supported |  |
| Make offer — below floor (422) | `POST /listings/lst_003/offers` as pilot@example.com | Supported |  |
| My offers inbox | `GET /me/offers?box=received` as pilot@example.com | Supported | Null field omitted when there is no counter-offer (D19). |
| Counter offer | `POST /offers/off_9281/counter` as pilot@example.com | Supported |  |
### [orders.md](api-payloads/orders.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Cart | `GET /cart` as pilot@example.com | Supported | 37 nested keys not verifiable (empty list/null in fixture data). |
| Add to cart | `POST /cart/items` as pilot@example.com | Supported |  |
| Checkout quote | `POST /checkout/quote` as pilot@example.com | Supported | 2 nested keys not verifiable (empty list/null in fixture data). |
| Create order | `POST /orders` as pilot@example.com | Supported | 2 nested keys not verifiable (empty list/null in fixture data). |
| My orders | `GET /me/orders?role=buying&per_page=2` as pilot@example.com | Supported |  |
| Order detail | `GET /orders/ord_4471` as pilot@example.com | Supported |  |
| Confirm delivery — wrong state (409) | `POST /orders/ord_4402/confirm-delivery` as pilot@example.com | Not exercised | The sample shows the 409 for an order in the wrong state; the audit fixture order was deliverable, so it returned 200. The 409 path is covered by `DisputesMessagingTest`. |
### [payments.md](api-payloads/payments.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Available payment methods | `GET /payment-methods?currency=NGN&country=NG` as pilot@example.com | Supported |  |
| Initialize payment | `POST /payments/initialize` as pilot@example.com | Not exercised | Paystack keys are not configured locally (503 `GATEWAY_UNAVAILABLE`). Same response shape is verified with the sandbox gateway (`CheckoutPaymentFlowTest`) and the Paystack adapter in `PaymentGatewayAdaptersTest`. |
| Verify payment (poll) | `GET /payments/PW-2026-569051` as pilot@example.com | Supported |  |
| Initialize — unsupported gateway (422) | `POST /payments/initialize` as pilot@example.com | Supported |  |
### [products.md](api-payloads/products.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Listing detail | `GET /listings/pilotwardrobe-aviator-uniform-shirt` as visitor | Supported | 3 nested keys not verifiable (empty list/null in fixture data). |
| Related listings | `GET /listings/pilotwardrobe-aviator-uniform-shirt/related` as visitor | Supported | 2 nested keys not verifiable (empty list/null in fixture data). |
| Categories | `GET /categories` as visitor | Supported | 20 nested keys not verifiable (empty list/null in fixture data). |
| Category detail | `GET /categories/headsets` as visitor | Supported | 20 nested keys not verifiable (empty list/null in fixture data). |
| Home feed | `GET /home` as visitor | Supported | 43 nested keys not verifiable (empty list/null in fixture data). |
| Favourite listing | `POST /listings/lst_003/favorite` as pilot@example.com | Supported |  |
| Not found (404) | `GET /listings/does-not-exist` as visitor | Supported |  |
### [profiles.md](api-payloads/profiles.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Seller directory | `GET /sellers?per_page=2` as visitor | Supported |  |
| Seller profile | `GET /sellers/pilotwardrobe` as visitor | Supported | 20 nested keys not verifiable (empty list/null in fixture data). |
| Seller listings | `GET /sellers/pilotwardrobe/listings?per_page=2` as visitor | Supported | 3 nested keys not verifiable (empty list/null in fixture data). |
| Follow seller | `POST /sellers/pilotwardrobe/follow` as pilot@example.com | Supported |  |
### [reviews.md](api-payloads/reviews.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Seller reviews (public) | `GET /sellers/pilotwardrobe/reviews?per_page=2` as visitor | Supported | 10 nested keys not verifiable (empty list/null in fixture data). |
| My reviews | `GET /me/reviews` as pilot@example.com | Supported |  |
| Review an order | `POST /orders/ord_4471/reviews` as pilot@example.com | Supported |  |
### [support.md](api-payloads/support.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Help Center summary | `GET /help/summary` as visitor | Supported |  |
| Contact page info | `GET /support/contact-info` as visitor | Supported |  |
| Contact — callback request | `POST /contact` as pilot@example.com | Supported |  |
| Contact — validation (422) | `POST /contact` as visitor | Supported |  |
### [swaps.md](api-payloads/swaps.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Propose swap | `POST /listings/lst_003/swap-requests` as aviator@example.com | Supported |  |
| Propose swap — KYC required (403) | `POST /listings/lst_003/swap-requests` as newbie@example.com | Supported |  |
| My swap requests | `GET /me/swap-requests` as pilot@example.com | Supported |  |
| Accept swap | `POST /swap-requests/swp_772/accept` as pilot@example.com | Supported |  |
### [transactions.md](api-payloads/transactions.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Wallet | `GET /me/wallet` as pilot@example.com | Supported |  |
| Wallet transactions | `GET /me/wallet/transactions?per_page=2` as pilot@example.com | Supported |  |
| Payout methods | `GET /me/payout-methods` as pilot@example.com | Supported |  |
| Withdrawal quote | `POST /me/withdrawals/quote` as pilot@example.com | Supported |  |
| Request withdrawal | `POST /me/withdrawals` as pilot@example.com | Supported |  |
### [users.md](api-payloads/users.md)

| Sample | Request | Status | Notes |
|---|---|---|---|
| Update profile | `PATCH /me` as pilot@example.com | Supported |  |
| Security overview | `GET /me/security` as pilot@example.com | Supported | 5 nested keys not verifiable (empty list/null in fixture data). |
| Change password — validation (422) | `PUT /me/password` as pilot@example.com | Supported |  |
| Notification preferences | `GET /me/notification-preferences` as pilot@example.com | Supported |  |
| Fit Kit profiles | `GET /me/fit-kit` as pilot@example.com | Supported |  |
| Update Fit Kit profile | `PATCH /me/fit-kit/fit_1` as pilot@example.com | Supported |  |
| Addresses | `GET /me/addresses` as pilot@example.com | Supported |  |
| Create address | `POST /me/addresses` as pilot@example.com | Supported |  |
| KYC status | `GET /me/kyc` as newbie@example.com | Supported |  |
| Aviator credentials | `GET /me/aviator` as aviator@example.com | Supported |  |
| Hangar summary | `GET /me/hangar` as pilot@example.com | Supported |  |

## Additive fields

The API returns these extra keys in addition to the samples. They are safe to ignore and some are useful:

- `formatted` on every money object (locale-aware via `Accept-Language`).
- `condition_label`, `status_label`, `kind` on listings and orders; `permissions_version` and `staff_role` on the session principal.
- `meta` pagination on `GET /conversations`; `approval_id` on dual-control responses; `skipped` on bulk actions.
- Admin presenters add identifiers (`target_type`, `target_id`, `request_id`, `slug`, `system`) used for linking and auditing.
