# Security

How the Pilotwardrobe API protects accounts, money and personal data, and what operators must do to keep it that way. Architecture context: [backend-architecture.md](backend-architecture.md). Deployment hardening: [deployment.md](deployment.md).

## Principles

1. **The server is the security boundary.** The SPA's route guards only shape the UX. Every endpoint re-checks the session, account status, permission and ownership.
2. **Never trust client values for anything that matters.** User id, role, permissions, prices, totals, fees, discounts, payment status and verification status are always derived on the server. Mass assignment is limited by `$fillable`; state and money fields are set only inside services with `forceFill`. Outside production, `Model::preventSilentlyDiscardingAttributes()` makes stray attributes fail loudly.
3. **Money moves only after server-side verification** with the provider, under row locks, through an append-only ledger.
4. **Secrets stay in the server environment.** Nothing secret is returned to the SPA, logged or committed.

## Authentication

| Control | Implementation |
|---|---|
| Passwords | bcrypt (12 rounds); at least 8 characters with letters and numbers, at most 128. Changing or resetting a password revokes other sessions (optional on reset) and sends `security.password_changed`. |
| Brute force | 5 failed logins → account locked for 15 minutes (423 `ACCOUNT_LOCKED` with `meta.retry_after`). Unknown e-mails get the same error as wrong passwords. Rate limits per IP on all auth routes. |
| Enumeration | `forgot-password` always returns 200. `email-lookup` never reveals sign-in methods for unknown e-mails. |
| Sessions (SPA) | Sanctum cookie sessions: `HttpOnly`, `Secure` and `SameSite=Lax` in production, regenerated on login, encrypted (`SESSION_ENCRYPT=true`). CSRF via `XSRF-TOKEN` / `X-XSRF-TOKEN`; a mismatch returns 419. |
| Tokens (other clients) | Sanctum personal access tokens, 60 minutes by default (30 days with "keep me signed in"), stored hashed, each tied to an `auth_sessions` row. |
| Session control | Every sign-in creates an `auth_sessions` row (device, location, IP). Users can list and revoke sessions (`/me/security`). Revoked or expired sessions are rejected on the next request. Sign-in from a new device triggers `security.new_device`. |
| Two-factor | TOTP (secret encrypted at rest), SMS fallback and single-use backup codes (stored as SHA-256 hashes, shown once). Challenges expire in 10 minutes. "Trust this device" sets a 30-day `HttpOnly` cookie holding a random token that is stored only as a SHA-256 hash. Withdrawals need a current code when 2FA is on. |
| E-mail verification and reset | 4-digit e-mail code (10 minutes, hashed, resend throttled). Password reset tokens are stored as SHA-256 hashes and valid for 60 minutes. |
| Staff | Google Workspace ID token (JWKS signature, audience, issuer, expiry, `hd` = company domain) followed by a WebAuthn security key. Staff sessions idle out after 30 minutes and are never "remembered". Staff cannot use the member login. Password login for staff is off unless `STAFF_PASSWORD_LOGIN=true` (development only). |
| Impersonation | `users.impersonate` only. Issues a 15-minute **read-only** token (mutations get 403), audit-logged, and visible to the user in their sessions. |
| Account status | Suspended users are signed out and blocked on every request; restricted users lose specific capabilities (listing, trading, payouts). |

## Authorisation

- **Permissions** come from `config/permissions.php`: role defaults, plus the staff-role bundle, plus per-user grants, minus revocations. They are resolved server-side, cached per user and invalidated through `permissions_version`. Routes declare them with `perm:` middleware, and they also work as gate abilities.
- **Admin routes** require `auth`, a usable account, staff role, the specific permission and the `admin` rate limit. Super-admin-only invariants are enforced in services: the last super admin cannot be demoted, staff cannot edit their own role, and system roles cannot be deleted.
- **Ownership** is enforced where records are loaded. Queries are scoped to the caller (my orders, my listings, conversations I'm part of), so other users' records return **404**, not 403, which avoids confirming that they exist. Services re-check the actor (for example only the seller can dispatch, only the buyer can confirm delivery).
- **KYC gates:** swaps, more than 3 live listings and withdrawals need approved KYC (403 `KYC_REQUIRED`).
- **Dual control:** refunds over $1,000, payouts over $2,000, dispute decisions above the threshold, commission rules, escrow timings and gateway settings need a second staff member with the same permission. The requester cannot approve their own request (409).

## Payments and money

- Payment amounts come from the order on the server; `return_url` must be on `APP_FRONTEND_URL` (open-redirect protection), and gateway redirect hosts are allow-listed.
- Webhooks are authenticated (HMAC or provider verification API) before anything is stored, deduplicated on the provider event id, and processed asynchronously. They only trigger a call to the provider's verify or status API, so a replayed, reordered or forged-but-valid-looking payload cannot change state. This applies to payments, refunds and payout transfers.
- Amount, currency and reference must match the order exactly. Mismatches fail the payment.
- Wallet changes happen only in `WalletService`, inside transactions holding row locks, with idempotency markers. Ledger rows cannot be updated or deleted (database trigger), and balances have non-negative `CHECK` constraints.
- `Idempotency-Key` prevents double checkouts, payments, offers, refunds and withdrawals.
- Card data never touches Pilotwardrobe; the gateways' hosted pages collect it (PCI SAQ-A scope).

## Personal data

| Data | Protection |
|---|---|
| KYC legal name, date of birth, document number | Encrypted at rest (Laravel `encrypted` cast with `APP_KEY`), hidden from serialisation, shown only to staff with `kyc.view` |
| KYC documents, selfies, dispute evidence, message attachments | Private Cloudinary assets (`authenticated` delivery), signed URLs valid for 10 minutes, only for the owner and permitted staff |
| Bank account number and name, payout recipient codes | Encrypted at rest; only the last 4 digits are readable |
| 2FA secret | Encrypted; backup codes hashed |
| E-mail verification codes, password-reset links, invitations | Stored only as hashes (codes bcrypt, tokens SHA-256). The rendered delivery payload is replaced by `{redacted: true}` once the message is sent or has failed for good (`sensitive` templates) |
| Contact details on public profiles | Never included in public payloads (`email` and `phone` are absent, enforced by tests) |
| Photos | Re-encoded on upload, which strips EXIF and GPS data |
| Account deletion | `DELETE /me` erases or anonymises personal data, revokes sessions and removes addresses and payout methods. Orders, ledger and audit rows are kept for financial record-keeping but no longer identify the person. Refused while money or obligations are open. |

## Input, output and files

- **Validation:** every input is validated with explicit rules, and errors use the request field names. String lengths, array sizes and numeric ranges are bounded.
- **SQL:** Eloquent and the query builder with bound parameters. The few `whereRaw` calls use bindings, and sort fields come from allow-lists.
- **HTML:** CMS page bodies pass through `symfony/html-sanitizer` with an allow-list. Every other text field is stored and returned as plain text; the SPA escapes on render.
- **Uploads:** MIME type from file contents (finfo), 15 MB limit, minimum dimensions for listing photos, images re-encoded by GD, public ids generated by the server. Unattached uploads are purged after 24 hours.
- **Messaging safety:** messages that ask for payment off the platform (bank details, "pay outside", wallet handles) are flagged and create a moderation report automatically.

## Transport and HTTP hardening

- HTTPS only in production. HSTS (`max-age=31536000; includeSubDomains`) is sent on secure requests; set `TRUSTED_PROXIES` so Laravel sees the original scheme and client IP behind the load balancer.
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, a restrictive `Permissions-Policy`, and `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` on JSON responses. Authenticated responses get `Cache-Control: no-store, private`.
- CORS allows only the configured SPA origins, with credentials (`config/cors.php`).
- Rate limits on every route group (see [backend-api-contract.md §5.1](backend-api-contract.md#51-behaviour-the-contract-left-open)); 429 responses include `Retry-After`.
- Errors never leak internals: production 500s return a generic message with the request id; stack traces go only to the log.

## Logging and audit

- `audit_logs` records every admin mutation and security event: actor, action, target, before/after, IP, user agent, request id. Rows cannot be updated or deleted (database trigger). Staff with `audit.view` can search and export them.
- Application logs never include passwords, tokens, card data, provider secrets or KYC values. Provider failures log provider, operation and status only. Phone numbers are masked.
- The `log` SMS and WhatsApp drivers exist for development. Outside `local`/`testing`, the SMS log driver masks digit sequences so codes cannot leak if it is left on by mistake. Production must use real drivers ([deployment.md](deployment.md#production-checklist)).
- Every response carries `X-Request-Id`, which links client reports to log lines and audit rows.

## Secrets and supply chain

- Secrets only in the environment or the hosting platform's secret store. `.env.example` lists every key with placeholders. `.env`, `.env.*` (except the example), keys and `auth.json` are git-ignored and excluded from the Docker build context.
- `APP_KEY` encrypts sessions and encrypted columns. **Rotating it needs `APP_PREVIOUS_KEYS`** so existing encrypted data stays readable; never replace it without that.
- CI runs `composer audit` on locked dependencies, Pint, Larastan (level 5, no baseline) and the full test suite against PostgreSQL. Workflow files contain no credentials; tests use fakes and `Http::preventStrayRequests()`.
- The runtime image runs as `www-data`, contains no dev dependencies, tests or `.env`, and sets `expose_php=Off`.

## Operational checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY` stored in the secret manager
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` set to the real domains
- [ ] `PAYMENTS_SANDBOX_ENABLED=false`, `STAFF_PASSWORD_LOGIN=false`, `API_DOCS_PUBLIC=false`
- [ ] Real drivers: `MAIL_MAILER=zeptomail`, `SMS_PROVIDERS=termii,twilio`, `PUSH_DRIVER=fcm`, `MEDIA_DRIVER=cloudinary`, `KYC_DRIVER=prembly`
- [ ] Webhook secrets configured for every enabled gateway, Prembly and WhatsApp
- [ ] `TRUSTED_PROXIES` set to the load balancer range
- [ ] Database and Redis on private networks, TLS where offered, least-privilege database user (no superuser)
- [ ] Horizon dashboard restricted (`settings.manage`); `/horizon` and `/api/documentation` not exposed to the public internet if possible
- [ ] Backups encrypted and restore-tested ([deployment.md](deployment.md#backup-and-recovery))

## Reporting a vulnerability

E-mail the security contact (configure `TRUST_SAFETY_EMAIL` or a dedicated address) with steps to reproduce. Do not open public issues for security problems.
