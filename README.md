# Pilotwardrobe API

Laravel 13 REST API for **Pilotwardrobe**, the marketplace for buying, selling and swapping aviation gear with escrow. It serves the Vue SPA at `/api/v1` and implements the full contract in [`docs/backend-api-contract.md`](docs/backend-api-contract.md): 209 endpoints, plus 22 documented additions.

**Stack:** PHP 8.5 (8.5.12) · Laravel 13 · PostgreSQL 15+ · Redis 7 · Horizon · Sanctum · FrankenPHP (Docker)
**Integrations:** Paystack, Flutterwave, Monnify, Stripe (incl. Google Pay / Apple Pay), PayPal · ZeptoMail · Termii with Twilio failover · WhatsApp Cloud API · Firebase Cloud Messaging · Cloudinary · Prembly KYC

## Quick start (local)

Requirements: PHP 8.5 with `pdo_pgsql`, `gd`, `intl`, `zip`, `bcmath`; Composer; PostgreSQL; Redis optional for local (the `.env.example` defaults use sync queues).

```bash
composer install
cp .env.example .env
php artisan key:generate
# create databases "pilotwardrobe" and "pilotwardrobe_test", set DB_USERNAME / DB_PASSWORD in .env
php artisan migrate --seed          # reference data + demo users, listings and an order
php artisan storage:link
php artisan serve                   # http://localhost:8000
```

Point the SPA at it with `VITE_API_MODE=live` and `VITE_API_BASE_URL=http://localhost:8000/api/v1`. The defaults in `.env.example` use safe local drivers: payments through the **sandbox** gateway, SMS/WhatsApp/push written to the log, media on local disk and a fake KYC provider. No third-party account is needed to run the full flow.

Or with Docker: `docker compose up -d --build && docker compose run --rm migrate` (see [deployment.md](docs/deployment.md#local-docker-stack)).

### Demo accounts (non-production only)

Password for all: `Password1`

| E-mail | Role |
|---|---|
| `pilot@example.com` | Member, KYC approved (buyer and seller) |
| `aviator@example.com` | Verified aviator |
| `official@example.com` | Official store |
| `photographer@example.com` | Photographer (digital prints) |
| `buyer@example.com` | Member with a completed order |
| `newbie@example.com` | Member, KYC not started |
| `admin@example.com` | Staff, Trust & safety |
| `finance@example.com` | Staff, Finance |
| `super@example.com` | Super admin |

Staff password login works only with `STAFF_PASSWORD_LOGIN=true` (local default in `.env.example` is `false`; set it to `true` to use the staff accounts). Production staff use Google Workspace and a security key.

## Commands

| Command | Purpose |
|---|---|
| `php artisan test` | Full test suite (needs the `pilotwardrobe_test` PostgreSQL database) |
| `vendor/bin/pint` | Code style |
| `vendor/bin/phpstan analyse --memory-limit=2G` | Static analysis (Larastan level 5, no baseline) |
| `php artisan horizon` | Queue workers (Linux/macOS; on Windows use `queue:work`) |
| `php artisan schedule:work` | Scheduler (sweeps, reconciliation, pruning) |
| `php artisan marketplace:sweep {orders\|offers\|swaps\|release\|settle\|payouts\|…}` | Run one time-based sweep |
| `php artisan scramble:export --path=docs/openapi.json` | Export the OpenAPI document |
| `php artisan dev:postman` | Regenerate the Postman/Bruno collection from `docs/openapi.json` |
| `php artisan dev:model-docs` | Regenerate model `@property` docblocks from the schema |
| `php scripts/payload-audit.php > storage/app/payload-audit.json` | Replay `docs/api-payloads` samples against the seeded database |
| `python scripts/generate-frontend-api-mapping.py` | Regenerate `docs/frontend-api-mapping.md` |

## API documentation

- **OpenAPI 3.1:** `GET /openapi` (JSON) and `GET /api/documentation` (interactive). Open in `local`; staff-only elsewhere unless `API_DOCS_PUBLIC=true`. Static copy: [`docs/openapi.json`](docs/openapi.json).
- **Postman / Bruno:** [`docs/postman/pilotwardrobe.postman_collection.json`](docs/postman/pilotwardrobe.postman_collection.json). Import it, set `baseUrl`, run *Auth / POST /auth/login*, and the bearer token is stored automatically.
- **Health:** `GET /health` (also served at `GET /`).

## Documentation

| Document | Contents |
|---|---|
| [backend-api-contract.md](docs/backend-api-contract.md) | The API contract: conventions, errors, roles and permissions, e-mails, flows, endpoint reference, and §5 Laravel implementation status |
| [frontend-backend-handoff.md](docs/frontend-backend-handoff.md) | How the SPA talks to the API: auth model, state machines, payments, with Laravel notes |
| [frontend-api-mapping.md](docs/frontend-api-mapping.md) | Every SPA endpoint → Laravel route, controller and enforced middleware |
| [payload-compatibility-audit.md](docs/payload-compatibility-audit.md) | Real responses compared with every payload sample |
| [backend-architecture.md](docs/backend-architecture.md) | Code layout, request lifecycle, domains, cross-cutting concerns |
| [database-schema.md](docs/database-schema.md) | ER diagrams, constraints, indexes, triggers |
| [integrations.md](docs/integrations.md) | Each provider, webhook verification, sandbox checklists |
| [security.md](docs/security.md) | Authentication, authorisation, money safety, PII, hardening |
| [deployment-vps.md](docs/deployment-vps.md) | Step-by-step production setup on a single Ubuntu VPS (Nginx, PHP-FPM 8.5, PostgreSQL, Redis, Horizon), zero-downtime deploys and rollback |
| [deployment.md](docs/deployment.md) | Container topology, release procedure, production checklist, backup and recovery |
| [backend-implementation-notes.md](docs/backend-implementation-notes.md) | Decisions and assumptions (D1–D23) and what is not implemented |
| [verification-checklist.md](docs/verification-checklist.md) | Final verification status, item by item |

## Project layout

```
app/Http/Controllers/Api/V1   thin controllers per area (Auth, Me, Catalogue, Selling, Trading, Payments, Wallet, Admin, …)
app/Services                  business logic and transactions
app/Integrations              provider adapters behind contracts
app/Jobs                      queued work (notifications, webhooks, refunds, payouts, KYC, exports)
app/Models, app/Enums         Eloquent models; enums with state-machine transitions
config/platform.php           marketplace rules and defaults
config/permissions.php        permission catalogue and role bundles
config/notifications.php      notification templates and channels
routes/                       api.php, admin.php, webhooks.php, system.php, console.php (scheduler)
tests/                        Feature, Unit, Integration (PostgreSQL)
```

## License

MIT, see [LICENSE](LICENSE).
