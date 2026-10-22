# Final verification checklist

Status of each requirement for the Pilotwardrobe backend, as of 2026-10-22. **Verified** means it was checked by an automated test or tool run in this environment. **Implemented** means the code exists and was reviewed, but it could not be exercised end to end here; the reason is given.

Evidence at the time of writing: `php artisan test`: **101 tests, 979 assertions, all passing** (PostgreSQL 18). `vendor/bin/phpstan`: **0 errors** at level 5, no baseline. `vendor/bin/pint --test`: clean. `composer audit`: no advisories. Migrations: migrate → rollback → migrate cycle clean. Payload audit: 103/109 samples match, 6 not replayable here (see below).

## Contract and frontend compatibility

| Item | Status | Evidence |
|---|---|---|
| All contract endpoints implemented under `/api/v1` | ✅ Verified | 209/209 mapped to routes ([frontend-api-mapping.md](frontend-api-mapping.md)) |
| Response envelopes, pagination `meta`/`links`, error envelope and codes | ✅ Verified | Tests in every feature area; `ErrorCode` enum equals contract §1.5 |
| Payload shapes match `docs/api-payloads` | ✅ Verified | [payload-compatibility-audit.md](payload-compatibility-audit.md): 0 modified, 0 missing fields; 5 defects found by the audit and fixed |
| 422 errors use request field names and contract messages | ✅ Verified | Error samples in the audit; auth/listing/dispute tests |
| State machines return 409 on illegal transitions | ✅ Verified | Unit tests plus feature tests per machine |
| `allowed_actions` computed for the viewer | ✅ Verified | Order, offer, swap and dispute tests |
| Deviations and additions documented | ✅ Done | Contract §5, [backend-implementation-notes.md](backend-implementation-notes.md) |

## Authentication and authorisation

| Item | Status | Evidence |
|---|---|---|
| Sanctum cookie (SPA) and bearer token modes | ✅ Verified | `AuthenticationTest` (token mode), CORS preflight test; cookie mode via Sanctum stateful config |
| Lockout after 5 failures (423), enumeration-safe errors | ✅ Verified | `AuthenticationTest` |
| 2FA (TOTP, SMS, backup codes), trusted devices | ✅ Verified | `test_two_factor_challenge_flow` |
| Password reset with hashed, expiring tokens; session revocation | ✅ Verified | `AuthenticationTest` |
| Google / Facebook social sign-in | ⚠️ Implemented | Needs real OAuth clients to exercise; Apple not implemented (D8) |
| Staff Google Workspace + WebAuthn | ⚠️ Implemented | JWKS and WebAuthn verification code reviewed; needs a Workspace domain and a security key to exercise |
| RBAC: role defaults, staff bundles, overrides, matrix | ✅ Verified | `ControlTowerTest` permission boundaries; matrix expansion test |
| Ownership (other users' records are 404) | ✅ Verified | Listing, order and dispute tests |
| Dual control; requester cannot approve | ✅ Verified | Refund, commission rule and payout tests |
| Suspended and restricted accounts | ✅ Verified | `AuthenticationTest`, `ControlTowerTest` |

## Marketplace and money

| Item | Status | Evidence |
|---|---|---|
| Listing lifecycle, moderation, KYC cap of 3 | ✅ Verified | `ListingLifecycleTest` |
| Search, facets, Fit Kit matching | ✅ Verified | `ListingBrowseTest` |
| Server-side pricing; client totals ignored | ✅ Verified | `CheckoutPaymentFlowTest` |
| Payment verification (amount, currency, reference) | ✅ Verified | Mismatch and success tests |
| Webhook signature, dedupe, async processing | ✅ Verified | Paystack end to end; adapter tests per gateway |
| Late/orphan payments auto-refunded | ✅ Verified | `test_late_payment_for_expired_order_is_refunded_automatically` |
| Double purchase prevented (locks, idempotency keys) | ✅ Verified | Checkout idempotency and concurrency tests |
| Escrow → pending release → available, ledger immutability | ✅ Verified | Checkout, dispute and wallet tests; `test_ledger_amounts_are_immutable_and_rows_cannot_be_deleted` |
| Offers, counters, swaps | ✅ Verified | `OffersSwapsWalletTest` |
| Disputes: freeze, proposals, staff decision, withdrawal | ✅ Verified | `DisputesMessagingTest` (decision test added after the audit found a 500) |
| Withdrawals, payouts, transfer reconciliation | ✅ Verified | `OffersSwapsWalletTest`, `PayoutReconciliationTest` (added: pending transfers were never settled before) |
| Money as integer minor units | ✅ Verified | `MoneyAndStateTest`; schema `bigint` + checks |

## Integrations

| Item | Status | Evidence |
|---|---|---|
| Paystack, Flutterwave, Monnify, Stripe, PayPal adapters | ⚠️ Implemented, tested with faked HTTP | `PaymentGatewayAdaptersTest`; **not run against live sandboxes** (no credentials) |
| Sandbox gateway | ✅ Verified | Full checkout flow in tests and in the payload audit |
| ZeptoMail, Termii → Twilio failover, WhatsApp, FCM | ⚠️ Implemented, tested with faked HTTP | `MessagingProvidersTest`; not run against live accounts |
| Cloudinary (signed/private delivery) | ⚠️ Implemented | URL building tested; uploads exercised with the local driver only |
| Prembly KYC | ⚠️ Implemented | Endpoint paths are configuration and unconfirmed against a live account (D10) |
| Sandbox checklists per provider | ✅ Documented | [integrations.md](integrations.md) |

## Platform quality

| Item | Status | Evidence |
|---|---|---|
| PostgreSQL schema: constraints, partial indexes, triggers, `jsonb`, full text | ✅ Verified | [database-schema.md](database-schema.md) generated from the live schema |
| Redis: cache (scalars only), locks, rate limits, queues | ✅ Verified (cache/locks/limits) | Tests run with serialized array cache; Redis itself used locally via predis |
| Horizon queues and supervisors | ⚠️ Configured | Horizon needs `pcntl` (Linux); not run on the Windows dev machine |
| Rate limiting with contract envelope | ✅ Verified | `test_rate_limit_returns_contract_envelope` |
| Idempotency keys | ✅ Verified | Checkout and withdrawal replay tests |
| Request ids, security headers, CORS | ✅ Verified | Platform tests |
| Maintenance windows (503) and health check | ✅ Verified | Platform tests |
| Errors never leak internals | ✅ Verified | `test_production_errors_never_leak_internals` |
| Sensitive notification payloads redacted | ✅ Verified | Registration test (added) |
| Audit log for admin mutations, immutable | ✅ Verified | `ControlTowerTest` |
| Seeders and factories | ✅ Verified | Used by tests and the payload audit |
| OpenAPI document and Postman/Bruno collection | ✅ Verified | Export runs with 225 operations (5 warnings for rules it could not evaluate); collection generated |
| Static analysis and style | ✅ Verified | Larastan level 5 clean, Pint clean |

## Delivery

| Item | Status | Evidence |
|---|---|---|
| `.env.example` with placeholders only; no secrets committed | ✅ Done | Reviewed; `.env` git-ignored and Docker-ignored |
| Dockerfile and Compose (web, horizon, scheduler, migrate, Postgres, Redis, Mailpit) | ⚠️ Written, not built | Docker not available on the dev machine |
| CI workflow (Pint, Larastan, audit, tests on Postgres + Redis, migration cycle, OpenAPI, Docker build) | ⚠️ Written, not run | Runs on the first push to GitHub |
| Docs: README, architecture, contract update, mapping, handoff update, schema, integrations, security, deployment with backup/recovery, decisions, audit, this checklist | ✅ Done | `docs/` |
| Changes committed | ❌ Not done | Nothing has been committed or pushed; that is left to the repository owner |

## Before production

1. Build the image and run the Compose stack in staging; let CI run once on GitHub.
2. Run each provider's sandbox checklist ([integrations.md](integrations.md)) with real test credentials, especially Prembly.
3. Have the frontend team run `contract-payloads.spec.ts` against staging, point the mock-gateway page at the sandbox endpoint, and hide Apple sign-in.
4. Confirm decisions D3, D4, D8, D10, D11, D12 and D21 with product and ops.
5. Work through the production checklist in [deployment.md](deployment.md#production-checklist) and [security.md](security.md#operational-checklist).
