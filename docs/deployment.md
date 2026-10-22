# Deployment

How to run the Pilotwardrobe API in production, plus backup and recovery. Security settings: [security.md](security.md). Provider setup: [integrations.md](integrations.md).

> The Dockerfile, Compose file and CI workflow in this repository were written but **not executed** in the development environment (Docker was not installed there, and the workflow has not run on GitHub yet). Build and run them once in staging before relying on them.

## Topology

| Component | Runs | Scale |
|---|---|---|
| **web** | `CONTAINER_ROLE=web`: FrankenPHP serving `public/` on port 8080 | Stateless; 2+ replicas behind a load balancer that terminates TLS |
| **horizon** | `CONTAINER_ROLE=horizon`: queue workers for `payments`, `notifications`, `default` and (on `redis-long`) `long` | 1 replica per environment (Horizon scales processes itself); more for throughput |
| **scheduler** | `CONTAINER_ROLE=scheduler`: `php artisan schedule:work` | **Exactly one** replica (tasks also use `onOneServer()` as a safeguard) |
| **migrate** | `CONTAINER_ROLE=migrate`: `php artisan migrate --force --isolated` | One-off job per release |
| PostgreSQL 15+ | Managed service (RDS, Cloud SQL, Neon, …) | Primary + standby; point-in-time recovery on |
| Redis 7 | Managed service | Persistence on (AOF); used for cache, sessions, queues, locks, rate limits |

All roles use the same image. Configuration comes only from environment variables; [`.env.example`](../.env.example) lists every key.

## Build

```bash
docker build -t pilotwardrobe-api:$(git rev-parse --short HEAD) .
```

The multi-stage build installs Composer dependencies without dev packages, starts from `dunglas/frankenphp:1-php8.5.12`, adds the PHP extensions the app needs (`pdo_pgsql`, `gd`, `intl`, `zip`, `bcmath`, `pcntl`, `exif`; OPcache is built into PHP 8.5), applies `docker/php.ini` (OPcache with JIT, `expose_php=Off`, upload limits) and runs as `www-data`. Config, route, event and view caches are built when each container starts, so they reflect that environment's variables.

## Release procedure

1. CI passes on the commit: Pint, Larastan, `composer audit`, tests against PostgreSQL, migration rollback check, OpenAPI export, Docker build.
2. Build and push the image.
3. Run the **migrate** job with the new image. Migrations are additive where possible; a destructive change ships in two releases (stop using the column, then drop it).
4. Roll out **web**, then restart **horizon** (`php artisan horizon:terminate` lets workers finish their current job; the orchestrator starts new ones) and **scheduler**.
5. Smoke test: `GET /health` returns 200 with every check `ok`; sign in; open `/admin/overview` as staff; place a sandbox order in staging.

Rollback: redeploy the previous image. Roll back migrations only if the release's migrations are not backwards compatible (`php artisan migrate:rollback --step=N`).

## Production checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` from the secret manager (and `APP_PREVIOUS_KEYS` when rotating)
- [ ] `APP_URL=https://api.pilotwardrobe.com`, `APP_FRONTEND_URL=https://pilotwardrobe.com`
- [ ] `SANCTUM_STATEFUL_DOMAINS=pilotwardrobe.com,www.pilotwardrobe.com`, `SESSION_DOMAIN=.pilotwardrobe.com`, `SESSION_SECURE_COOKIE=true`, `CORS_ALLOWED_ORIGINS=https://pilotwardrobe.com,https://www.pilotwardrobe.com`
- [ ] `DB_*` for the managed PostgreSQL (`DB_SSLMODE=require`), `REDIS_*` for the managed Redis
- [ ] `QUEUE_CONNECTION=redis`, `QUEUE_LONG_CONNECTION=redis-long`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`
- [ ] `LOG_CHANNEL=stderr` (optionally `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`), `LOG_LEVEL=info`
- [ ] `TRUSTED_PROXIES` = load-balancer CIDR
- [ ] Real drivers: `MAIL_MAILER=zeptomail`, `SMS_PROVIDERS=termii,twilio`, `PUSH_DRIVER=fcm`, `WHATSAPP_DRIVER=cloud` (only after Meta templates are approved), `MEDIA_DRIVER=cloudinary`, `KYC_DRIVER=prembly`
- [ ] Provider keys and webhook secrets for each enabled gateway; webhook URLs registered in each dashboard (`https://api.pilotwardrobe.com/api/v1/webhooks/{gateway}`, `/webhooks/prembly`, `/webhooks/whatsapp`)
- [ ] `PAYMENTS_SANDBOX_ENABLED=false`, `STAFF_PASSWORD_LOGIN=false`, `API_DOCS_PUBLIC=false`
- [ ] `GOOGLE_STAFF_CLIENT_ID`, `STAFF_EMAIL_DOMAIN`, `WEBAUTHN_RP_ID` = the admin console's domain
- [ ] `OPS_ALERT_EMAIL` for Horizon long-wait alerts
- [ ] Reference data seeded once: `php artisan db:seed --class=ReferenceDataSeeder --force` (categories, staff roles, commission rules, boost packages, exchange rates, pages). **Never run `DemoSeeder` in production** (it refuses to run there).
- [ ] First super admin created by invitation from a bootstrap account, or with `php artisan tinker` on the migrate job, then 2FA and a security key registered
- [ ] Review the business defaults in `/admin/settings` (escrow timings, duties, markets, gateways, moderation rules; see D21 in [backend-implementation-notes.md](backend-implementation-notes.md))

## Health, monitoring and alerts

- **Load balancer health check:** `GET /health` returns 200 when the database, cache and queue respond, and 503 otherwise. It is rate-limited and reveals no secrets.
- **Queues:** Horizon at `/horizon` (staff with `settings.manage`). Alerts e-mail `OPS_ALERT_EMAIL` when wait time exceeds 30 s on `payments`, 120 s on `notifications`/`default`, or 15 minutes on `long`. Failed jobs are kept for 30 days (`queue:prune-failed`).
- **Status page:** `status:record` stores component status every 5 minutes; the public help page reads it.
- **Logs:** ship container stderr to your log platform; search by `X-Request-Id`.
- **Recommended external monitors:** uptime on `/health`; database CPU, connections and replication lag; Redis memory; the count of failed `webhook_events` and of `payouts` in `processing` for over an hour (both should be zero).

## Scheduled tasks

Defined in `routes/console.php`; all are idempotent.

| Task | Frequency |
|---|---|
| `marketplace:sweep orders` (expire unpaid orders) | every minute |
| `marketplace:sweep offers`, `swaps`, `reservations` | every 5 minutes |
| `marketplace:sweep release` (auto-release escrow), `settle`, `disputes`, `payouts` | every 15 minutes |
| `marketplace:sweep reminders`, `promotions` | hourly |
| `marketplace:sweep kyc` (document expiry) | daily 02:10 |
| `ReconcilePayments` (missed payment webhooks, last 24 h) | every 10 minutes |
| `status:record` | every 5 minutes |
| `pilotwardrobe:prune`, `sanctum:prune-expired`, `queue:prune-failed` | daily |
| `horizon:snapshot` | every 5 minutes |

## Backup and recovery

### What to back up

| Data | Where | Method |
|---|---|---|
| PostgreSQL (all business data, ledger, audit) | Managed database | Automated daily snapshots **plus** continuous WAL archiving for point-in-time recovery (PITR), 30-day retention; weekly logical dump (`pg_dump -Fc`) copied to a separate account/region, 90-day retention, encrypted |
| Media | Cloudinary | Cloudinary keeps originals; enable Cloudinary backup to your own storage bucket for KYC and evidence folders |
| Secrets (`APP_KEY` and provider keys) | Secret manager | Versioned. **Losing `APP_KEY` makes encrypted columns (KYC data, bank details, 2FA secrets) unreadable**, so keep an offline escrow copy |
| Redis | Managed Redis | Not a system of record; AOF persistence is enough. After a Redis loss, users sign in again, queued jobs are recovered by the reconciliation sweeps, and caches rebuild |

Targets: **RPO ≤ 5 minutes** (PITR) and **RTO ≤ 1 hour** for a full database restore.

### Restore procedures

**Point-in-time recovery** (bad migration, accidental deletion):

1. Put the API into maintenance: create a maintenance window in `/admin/settings`, or scale `web` to 0.
2. Restore the database to a new instance at a timestamp just before the incident, using the provider's PITR.
3. Point `DB_HOST` at the restored instance, and run `php artisan migrate:status` to confirm the schema matches the image.
4. Bring `web`, `horizon` and `scheduler` back. Let the `ReconcilePayments` and `marketplace:sweep payouts` tasks run: they re-verify every payment and transfer that was pending at the recovery point against the providers, so money state converges with the PSPs.
5. Compare gateway settlement reports for the gap window against `payments`, and refund any payment that has no order. Late payments for expired orders are already refunded automatically.

**Logical dump restore** (regional loss):

```bash
createdb -h <new-host> -U <admin> pilotwardrobe
pg_restore -h <new-host> -U <admin> -d pilotwardrobe --no-owner --role=pilotwardrobe -j 4 pilotwardrobe-<date>.dump
php artisan migrate --force    # applies anything newer than the dump
```

Then continue from step 4 above.

### Drills

Restore the latest snapshot into a scratch instance **quarterly**: run the test suite's migrations against it, and check row counts for `orders`, `payments`, `ledger_entries` and `audit_logs`. The `audit_logs` and `ledger_entries` triggers must still exist (`\dS audit_logs`). Record how long the restore took against the RTO.

## Local Docker stack

```bash
cp .env.example .env            # then set APP_KEY (php artisan key:generate) and DB_PASSWORD
docker compose up -d --build    # app :8000, Mailpit UI :8025, Postgres :5433
docker compose run --rm migrate
docker compose exec app php artisan db:seed
```

Without Docker (as developed): PHP 8.5.12, PostgreSQL and Redis locally, then `composer install`, `php artisan migrate --seed` and `php artisan serve`, with `QUEUE_CONNECTION=sync` and the log/fake drivers from `.env.example`.
