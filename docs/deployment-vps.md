# Deploying to a VPS (step by step)

This runbook puts the Pilotwardrobe API into production on a single Ubuntu VPS: **Nginx → PHP-FPM 8.5 → Laravel**, with **PostgreSQL** and **Redis** on the same server, **Horizon** under Supervisor for queues, and **cron** for the scheduler. Deploys are zero-downtime (each deploy is a new release folder; a symlink switches to it) and can be rolled back in seconds.

The config files referenced below live in [`deploy/vps/`](../deploy/vps). Container-based hosting is covered in [deployment.md](deployment.md); security settings in [security.md](security.md).

Throughout, replace:

| Placeholder | Example |
|---|---|
| `api.example.com` | `api.pilotwardrobe.com` (the API host) |
| `example.com` | `pilotwardrobe.com` (where the SPA is served) |
| `YOUR_SERVER_IP` | the VPS public IP |
| `STRONG_DB_PASSWORD`, `STRONG_REDIS_PASSWORD` | generated with `openssl rand -base64 32` |

---

## 0. Before you start

- **A VPS** running **Ubuntu 24.04 LTS** (or 26.04 LTS), at least **2 vCPU, 4 GB RAM, 40 GB SSD**. One server is enough to launch; the database can move to a managed service later.
- **DNS:** an `A` record for `api.example.com` pointing at the server (and `AAAA` if it has IPv6). Create it first; HTTPS certificates need it.
- **Repository access:** you will add a read-only *deploy key* to `avwingsgroup/pilotwardrobe-api` on GitHub.
- **Provider accounts** with production (or sandbox, for a staging server) credentials: payment gateways, ZeptoMail, Termii/Twilio, Cloudinary, Firebase, Prembly, Google Workspace OAuth client. You can launch with a subset and enable the rest later.

Commands marked **`root$`** run as root (or with `sudo`); **`deploy$`** run as the `deploy` user.

---

## 1. Harden the server

```bash
root$ apt update && apt -y full-upgrade
root$ timedatectl set-timezone UTC
root$ apt -y install ufw fail2ban unattended-upgrades curl git unzip acl
root$ dpkg-reconfigure -plow unattended-upgrades          # answer "Yes"
```

Create the `deploy` user that owns the app, and give it your SSH key:

```bash
root$ adduser --disabled-password --gecos "" deploy
root$ usermod -aG sudo deploy
root$ mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/
root$ chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh && chmod 600 /home/deploy/.ssh/authorized_keys
root$ passwd deploy        # set a password: needed for interactive sudo only
```

Log in as `deploy` from a **new** terminal to confirm it works, then disable root and password logins:

```bash
root$ sed -i -E 's/^#?PermitRootLogin.*/PermitRootLogin no/; s/^#?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
root$ systemctl reload ssh
```

Firewall: only SSH, HTTP and HTTPS. PostgreSQL and Redis stay private.

```bash
root$ ufw allow OpenSSH && ufw allow 'Nginx Full' 2>/dev/null || ufw allow 80,443/tcp
root$ ufw --force enable && ufw status
```

Add 2 GB of swap (protects against out-of-memory kills during `composer install`):

```bash
root$ fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
root$ echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

---

## 2. Install PHP 8.5 and Composer

Ubuntu's own packages lag behind, so use the Ondřej Surý PPA (the standard source for current PHP on Ubuntu):

```bash
root$ add-apt-repository -y ppa:ondrej/php && apt update
root$  apt -y install php8.5-fpm php8.5-cli php8.5-pgsql php8.5-gd php8.5-intl php8.5-zip \
                     php8.5-bcmath php8.5-mbstring php8.5-xml php8.5-curl
root$ php -v          # PHP 8.5.x (the PPA ships the latest 8.5 patch release, e.g. 8.5.12)
root$ php -m | grep -E 'pdo_pgsql|intl|gd|zip|pcntl|posix|Zend OPcache'
```

OPcache, `pcntl` and `posix` (needed by Horizon) are built into PHP 8.5 on Ubuntu; no extra packages.

Production PHP settings (OPcache, upload limits, `expose_php=Off`) come from the repo:

```bash
root$ sudo curl -fsSL https://raw.githubusercontent.com/avwingsgroup/pilotwardrobe-api/main/docker/php.ini \
        -o /etc/php/8.5/fpm/conf.d/99-pilotwardrobe.ini
```

If the repository is private, copy `docker/php.ini` over with `scp` instead.

Run PHP-FPM as the `deploy` user so the app can write its own storage without permission juggling:

```bash
root$ sed -i -E 's/^user = .*/user = deploy/; s/^group = .*/group = deploy/' /etc/php/8.5/fpm/pool.d/www.conf
root$ grep -E '^(user|group|listen.owner|listen.group) =' /etc/php/8.5/fpm/pool.d/www.conf
#   user = deploy / group = deploy / listen.owner = www-data / listen.group = www-data
root$ systemctl restart php8.5-fpm
```

Composer (verified installer):

```bash
root$ cd /tmp && EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
root$ php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
root$ [ "$EXPECTED" = "$(php -r "echo hash_file('sha384', 'composer-setup.php');")" ] && php composer-setup.php --install-dir=/usr/local/bin --filename=composer
root$ rm composer-setup.php && composer --version
```

---

## 3. Install PostgreSQL

Use the official PostgreSQL repository for a current major version:

```bash
root$ apt -y install postgresql-common
root$ /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
root$ apt -y install postgresql-18
```

Create the application role and database. The role **owns** the database (migrations create tables, triggers and functions) but is **not** a superuser:

```bash
root$ sudo -u postgres psql <<'SQL'
CREATE ROLE pilotwardrobe LOGIN PASSWORD 'STRONG_DB_PASSWORD';
CREATE DATABASE pilotwardrobe OWNER pilotwardrobe ENCODING 'UTF8' TEMPLATE template0;
REVOKE ALL ON DATABASE pilotwardrobe FROM PUBLIC;
SQL
```

PostgreSQL listens only on `localhost` by default; keep it that way.

---

## 4. Install Redis

```bash
root$ apt -y install redis-server
root$ sed -i -E 's/^#? ?requirepass .*/requirepass STRONG_REDIS_PASSWORD/; s/^supervised .*/supervised systemd/' /etc/redis/redis.conf
root$ grep -E '^(bind|requirepass|supervised|appendonly)' /etc/redis/redis.conf
root$ sed -i -E 's/^appendonly no/appendonly yes/' /etc/redis/redis.conf     # survive restarts
root$ systemctl restart redis-server && redis-cli -a 'STRONG_REDIS_PASSWORD' ping   # PONG
```

`bind 127.0.0.1 -::1` (the default) keeps Redis private.

---

## 5. Install Nginx and Supervisor

```bash
root$ apt -y install nginx supervisor certbot python3-certbot-nginx
root$ rm -f /etc/nginx/sites-enabled/default
```

---

## 6. Prepare the application folders and GitHub access

```bash
root$ mkdir -p /var/www/pilotwardrobe-api/{releases,shared}
root$ chown -R deploy:deploy /var/www/pilotwardrobe-api
```

As `deploy`, create the shared storage tree (logs, cache, sessions and generated files survive across releases):

```bash
deploy$ cd /var/www/pilotwardrobe-api/shared
deploy$ mkdir -p storage/app/public storage/app/private storage/framework/{cache/data,sessions,views,testing} storage/logs
```

Create a deploy key so the server can pull (read-only) from GitHub. Run this **on the VPS as `deploy`** (not on your own machine): the private key must live on the server that pulls the code. It is separate from your personal SSH key from step 1, which is for you logging in to the VPS.

```bash
deploy$ ssh-keygen -t ed25519 -C "pilotwardrobe-vps" -f ~/.ssh/id_ed25519 -N ""
deploy$ cat ~/.ssh/id_ed25519.pub
```

On GitHub: **avwingsgroup/pilotwardrobe-api → Settings → Deploy keys → Add deploy key**, paste the key, leave *Allow write access* **unchecked**. Then confirm:

```bash
deploy$ ssh -T git@github.com      # "Hi avwingsgroup/pilotwardrobe-api! You've successfully authenticated…"
```

Put the deploy script in place:

```bash
deploy$ git clone --depth 1 git@github.com:avwingsgroup/pilotwardrobe-api.git /tmp/pw
deploy$ install -m 755 /tmp/pw/deploy/vps/deploy.sh /var/www/pilotwardrobe-api/deploy.sh
deploy$ cp /tmp/pw/.env.example /var/www/pilotwardrobe-api/shared/.env && chmod 600 /var/www/pilotwardrobe-api/shared/.env
```

Allow `deploy` to reload PHP-FPM without a password (the deploy script needs it; OPcache does not re-read changed files on its own):

```bash
$ echo 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.5-fpm' | sudo tee /etc/sudoers.d/pilotwardrobe-deploy
$ sudo chmod 440 /etc/sudoers.d/pilotwardrobe-deploy && sudo visudo -cf /etc/sudoers.d/pilotwardrobe-deploy   # "parsed OK"
$ sudo -n /usr/bin/systemctl reload php8.5-fpm && echo ok    # as deploy: must not ask for a password
```

Use `sudo tee`, not `sudo echo … >`: the `>` redirection runs as your own user and is denied. Replace `deploy` with your user name if you run the app as a different user.

---

## 7. Write the production `.env`

Edit `/var/www/pilotwardrobe-api/shared/.env` (it started as a copy of `.env.example`). Set at least these; every key is explained in `.env.example`:

```dotenv
APP_NAME=Pilotwardrobe
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
APP_FRONTEND_URL=https://example.com

LOG_CHANNEL=daily
LOG_LEVEL=info
LOG_DAILY_DAYS=14
TRUSTED_PROXIES=                     # empty = trust Nginx on this server only; add Cloudflare/LB ranges if you use one

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pilotwardrobe
DB_USERNAME=pilotwardrobe
DB_PASSWORD=STRONG_DB_PASSWORD
DB_SSLMODE=prefer

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=STRONG_REDIS_PASSWORD
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
QUEUE_LONG_CONNECTION=redis-long

SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=.example.com
SANCTUM_STATEFUL_DOMAINS=example.com,www.example.com
CORS_ALLOWED_ORIGINS=https://example.com,https://www.example.com

STAFF_EMAIL_DOMAIN=example.com
STAFF_PASSWORD_LOGIN=false
WEBAUTHN_RP_ID=example.com
GOOGLE_STAFF_CLIENT_ID=…             # Google Workspace OAuth client for the Control Tower
API_DOCS_PUBLIC=false
OPS_ALERT_EMAIL=ops@example.com

PAYMENTS_SANDBOX_ENABLED=false
MAIL_MAILER=zeptomail
MEDIA_DRIVER=cloudinary
SMS_PROVIDERS=termii,twilio
PUSH_DRIVER=fcm
WHATSAPP_DRIVER=log                  # switch to cloud after Meta approves the templates
KYC_DRIVER=prembly
```

…plus the credentials for each provider you enable (Paystack, Flutterwave, Monnify, Stripe, PayPal, ZeptoMail, Termii, Twilio, Cloudinary, Firebase, Prembly, Google, Facebook). Providers you leave blank simply show as unavailable.

Generate the application key **once** and keep a copy offline. It encrypts KYC data, bank details and 2FA secrets; losing it makes that data unreadable.

```bash
deploy$ cd /tmp/pw && composer install --no-dev --no-interaction -q && \
        php artisan key:generate --show          # prints base64:…
deploy$ nano /var/www/pilotwardrobe-api/shared/.env    # paste it into APP_KEY=
deploy$ rm -rf /tmp/pw
```

---

## 8. First deploy

```bash
deploy$ /var/www/pilotwardrobe-api/deploy.sh main
```

The script clones `main` into a new release folder, links the shared `.env` and `storage`, installs dependencies without dev packages, runs migrations, caches config/routes/events/views, switches `current` to the new release, reloads PHP-FPM and restarts Horizon. The health check at the end warns until Nginx and HTTPS are set up in step 9; that's expected on the first run.

Seed the reference data (categories, staff roles, commission rules, boost packages, exchange rates, CMS pages). **Never** run the full `db:seed` or `DemoSeeder` in production; the demo seeder refuses to run there anyway.

```bash
deploy$ cd /var/www/pilotwardrobe-api/current
deploy$ php artisan db:seed --class=ReferenceDataSeeder --force
```

Give the storefront its images: uploads the bundled photos (`docs/PW-images`) to Cloudinary and attaches them to the hero slides and categories that have none. It never replaces an image set in the Control Tower and is safe to re-run (`--dry-run` previews):

```bash
deploy$ php artisan content:seed-images
```

Listings show photos once sellers create them; a fresh production install has no listings.

Create the first Control Tower super admin (must be on `STAFF_EMAIL_DOMAIN`):

```bash
deploy$ php artisan staff:create-super-admin you@example.com --first-name=Your --last-name=Name
```

---

## 9. Nginx and HTTPS

```bash
root$ cp /var/www/pilotwardrobe-api/current/deploy/vps/nginx.conf /etc/nginx/sites-available/pilotwardrobe-api
root$ sed -i 's/api.example.com/api.YOURDOMAIN.com/' /etc/nginx/sites-available/pilotwardrobe-api
root$ ln -s /etc/nginx/sites-available/pilotwardrobe-api /etc/nginx/sites-enabled/
root$ nginx -t && systemctl reload nginx
root$ certbot --nginx -d api.YOURDOMAIN.com --redirect -m ops@YOURDOMAIN.com --agree-tos -n
root$ systemctl list-timers | grep certbot      # automatic renewal is scheduled
```

Check it:

```bash
$ curl -s https://api.YOURDOMAIN.com/health
{"status":"ok","checks":{"database":{"status":"up",…},"cache":{"status":"up",…},"queue":{"status":"up",…}},…}
```

---

## 10. Queues (Horizon) and scheduler

Horizon under Supervisor:

```bash
root$ cp /var/www/pilotwardrobe-api/current/deploy/vps/horizon.conf /etc/supervisor/conf.d/pilotwardrobe-horizon.conf
root$ supervisorctl reread && supervisorctl update && supervisorctl status
#   pilotwardrobe-horizon   RUNNING   pid …
```

The scheduler runs every minute from cron (sweeps, payment and payout reconciliation, pruning; all idempotent):

```bash
deploy$ (crontab -l 2>/dev/null; echo '* * * * * cd /var/www/pilotwardrobe-api/current && php artisan schedule:run >> /dev/null 2>&1') | crontab -
deploy$ crontab -l
```

Confirm both are working:

```bash
deploy$ cd /var/www/pilotwardrobe-api/current
deploy$ php artisan horizon:status            # Horizon is running.
deploy$ php artisan schedule:list             # lists marketplace:sweep, ReconcilePayments, pilotwardrobe:prune, …
```

---

## 11. Connect the outside world

1. **Payment webhooks:** in each gateway dashboard, set the webhook URL to `https://api.YOURDOMAIN.com/api/v1/webhooks/{paystack|flutterwave|monnify|stripe|paypal}` and copy the signing secret into `.env` (`STRIPE_WEBHOOK_SECRET`, `FLUTTERWAVE_SECRET_HASH`, `PAYPAL_WEBHOOK_ID`; Paystack and Monnify sign with the secret key).
2. **Prembly:** webhook `https://api.YOURDOMAIN.com/api/v1/webhooks/prembly`, secret in `PREMBLY_WEBHOOK_SECRET`.
3. **WhatsApp (later):** callback `https://api.YOURDOMAIN.com/api/v1/webhooks/whatsapp`, verify token in `WHATSAPP_VERIFY_TOKEN`.
4. **ZeptoMail:** verify the sending domain (SPF and DKIM DNS records).
5. **Google OAuth clients:** add `https://example.com` as an authorised JavaScript origin for the staff client, and the SPA callback URLs for member sign-in.
6. **The SPA:** build it with `VITE_API_MODE=live`, `VITE_API_BASE_URL=https://api.YOURDOMAIN.com/api/v1` and `VITE_AUTH_MODE=cookie`, and serve it from `https://example.com`.

After changing `.env`, apply it:

```bash
deploy$ cd /var/www/pilotwardrobe-api/current && php artisan config:cache && sudo systemctl reload php8.5-fpm && php artisan horizon:terminate
```

Each gateway's sandbox test (pay, replay the webhook, refund, late payment) is listed in [integrations.md](integrations.md#payments).

---

## 12. Go-live checks

```bash
deploy$ cd /var/www/pilotwardrobe-api/current
deploy$ php artisan about --only=environment    # Environment: production · Debug Mode: OFF · PHP 8.5.x
deploy$ curl -sI https://api.YOURDOMAIN.com/health | grep -iE 'HTTP/|strict-transport|x-request-id'
deploy$ curl -s -o /dev/null -w '%{http_code}\n' https://api.YOURDOMAIN.com/openapi    # 403 (docs are staff-only)
```

- [ ] `/health` returns 200 and every check is `up`
- [ ] Signing in from the SPA works (cookie session; check the browser receives `XSRF-TOKEN` from `/sanctum/csrf-cookie`)
- [ ] Control Tower sign-in works with Google Workspace; the first sign-in registers your security key
- [ ] A small real payment completes: the order shows as paid in the SPA and in Control Tower → Orders; then refund it from there
- [ ] `supervisorctl status` shows Horizon `RUNNING`; `/horizon` loads for a staff user with `settings.manage`
- [ ] The full production checklist in [security.md](security.md#operational-checklist) is done

---

## 13. Backups

> **Non-default PostgreSQL port?** The commands below assume 5432. If `DB_PORT` in `shared/.env` is different (e.g. 5433, common when two PostgreSQL versions are installed; `pg_lsclusters` shows them), add `-p 5433` to every `pg_dump`, `pg_restore`, `createdb` and `psql` command.

Nightly compressed database dump at 02:30 UTC, keeping 14 days locally:

```bash
root$ mkdir -p /var/backups/pilotwardrobe && chown postgres:postgres /var/backups/pilotwardrobe && chmod 700 /var/backups/pilotwardrobe
root$ sudo tee /etc/cron.d/pilotwardrobe-backup <<'EOF'
30 2 * * * postgres pg_dump -Fc pilotwardrobe > /var/backups/pilotwardrobe/pilotwardrobe-$(date +\%F).dump && find /var/backups/pilotwardrobe -name '*.dump' -mtime +14 -delete
EOF
```

**Copy the dumps off the server** every night (a backup on the same disk is not a backup). For example with [rclone](https://rclone.org) to an S3-compatible bucket in another provider/region, encrypted:

```bash
root$ apt -y install rclone && rclone config        # create a remote, e.g. "offsite" (use rclone's crypt wrapper)
root$ echo '15 3 * * * root rclone copy /var/backups/pilotwardrobe offsite:pilotwardrobe-db --max-age 48h' | sudo tee /etc/cron.d/pilotwardrobe-offsite
```

Also keep **outside the server**: a copy of `shared/.env` (in your password manager), and especially `APP_KEY`.

**Restore** (test this quarterly on a scratch server):

```bash
deploy$ sudo -u postgres createdb -O pilotwardrobe pilotwardrobe_restore
deploy$ sudo -u postgres pg_restore -d pilotwardrobe_restore --no-owner --role=pilotwardrobe /var/backups/pilotwardrobe/pilotwardrobe-YYYY-MM-DD.dump
# point DB_DATABASE at it (or rename databases), then: php artisan config:cache && php artisan migrate --force
```

After a restore, let the scheduler run once: `ReconcilePayments` and `marketplace:sweep payouts` re-check every pending payment and transfer with the providers. Full recovery guidance: [deployment.md](deployment.md#backup-and-recovery).

Media (Cloudinary) is stored and backed up by Cloudinary; enable its backup option for the private KYC and evidence folders.

---

## 14. Deploying updates and rolling back

Every update is the same command; there is no downtime:

```bash
deploy$ /var/www/pilotwardrobe-api/deploy.sh main
```

If something is wrong after a deploy, switch back to the previous release:

```bash
deploy$ /var/www/pilotwardrobe-api/deploy.sh --rollback
```

Rollback switches the code only; it does **not** undo database migrations. Write migrations so the previous release still works with the new schema (add columns first, remove them in a later release). If a migration itself must be undone: `cd current && php artisan migrate:rollback --step=1 --force`.

The script keeps the last 5 releases.

---

## 15. Day-to-day operations

| Task | Command |
|---|---|
| Application log | `tail -f /var/www/pilotwardrobe-api/shared/storage/logs/laravel-$(date +%F).log` |
| Horizon log | `tail -f /var/www/pilotwardrobe-api/shared/storage/logs/horizon.log` |
| Nginx errors | `tail -f /var/log/nginx/pilotwardrobe-api.error.log` |
| Failed jobs | `php artisan queue:failed`, retry with `php artisan queue:retry all` |
| Put the API in maintenance | Control Tower → Settings → maintenance window (staff, webhooks and `/health` stay up) |
| Run one sweep by hand | `php artisan marketplace:sweep payouts` |
| OS security updates | applied automatically (unattended-upgrades); reboot when `/var/run/reboot-required` exists |

---

## 16. Automatic deploys from GitHub (push to `main`)

Once this is set up, every push to `main` deploys itself: GitHub Actions runs the checks (Pint, Larastan, audit, migrations, tests), and **only if they all pass** the `Deploy to production (VPS)` job connects to the server over SSH and runs `deploy.sh main`. A failed check means no deploy; a deploy whose health check fails marks the run as failed (the previous release stays available via `deploy.sh --rollback`). Deploys never overlap and are never cancelled halfway by a newer push. Until the secrets below are added, the deploy job is skipped with a warning (CI stays green); if only some are set, it fails and names the missing ones.

The workflow uses a **dedicated SSH key that can do exactly one thing on the server: run the deploy**. Even if the key leaked, it could not open a shell, forward ports or run anything else.

Replace `/var/www/pilotwardrobe-api` below with your app folder if it differs, and `deploy` with your user.

**1. Create the deploy key (on the VPS, as the app user):**

```bash
deploy$ ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/github-actions-deploy -N ""
```

**2. Authorise it for the deploy command only:**

```bash
deploy$ echo "restrict,command=\"/var/www/pilotwardrobe-api/deploy.sh main\" $(cat ~/github-actions-deploy.pub)" >> ~/.ssh/authorized_keys
deploy$ tail -1 ~/.ssh/authorized_keys     # restrict,command="…/deploy.sh main" ssh-ed25519 AAAA… github-actions-deploy
```

`restrict` turns off shells, port forwarding and agent forwarding for this key; `command=` makes the server run the deploy script whatever the client asks for. Your own login key (step 1) is unaffected.

**3. Copy the private key into GitHub, then delete it from the server:**

```bash
deploy$ cat ~/github-actions-deploy           # copy everything, including the BEGIN/END lines
deploy$ shred -u ~/github-actions-deploy && rm ~/github-actions-deploy.pub
```

**4. Get the server's host key** (so GitHub can verify it is talking to your server, not an impostor). Run on your own computer:

```bash
ssh-keyscan -p 22 YOUR_SERVER_IP 2>/dev/null        # macOS / Linux / Git Bash
ssh-keyscan -p 22 YOUR_SERVER_IP 2>$null             # Windows PowerShell
```

Cross-check one fingerprint against the server itself, `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` (on the VPS), and `ssh-keyscan -p 22 YOUR_SERVER_IP 2>/dev/null | ssh-keygen -lf -` (on your computer): the `ED25519` lines must show the same `SHA256:…`.

**5. Add the secrets in GitHub:** *avwingsgroup/pilotwardrobe-api → Settings → Secrets and variables → Actions → New repository secret*

| Secret | Value |
|---|---|
| `VPS_HOST` | the server IP (or hostname): **the same value you passed to `ssh-keyscan`** |
| `VPS_USER` | `deploy` (or your app user, e.g. `usir`) |
| `VPS_PORT` | only if SSH is not on port 22 |
| `VPS_SSH_KEY` | the private key from step 3 |
| `VPS_KNOWN_HOSTS` | the full output of `ssh-keyscan` from step 4 |

**6. Optional: require a manual approval.** *Settings → Environments → production → Required reviewers*. The deploy job then waits for a click on **Review deployments** before running. The `production` environment is created automatically on the first run.

**7. One-time refresh of the installed script.** From now on every deploy refreshes `deploy.sh` from the release it deployed, but the copy installed before this feature existed still needs one manual update (after the commit that added this section is deployed):

```bash
deploy$ /var/www/pilotwardrobe-api/deploy.sh main
deploy$ install -m 755 /var/www/pilotwardrobe-api/current/deploy/vps/deploy.sh /var/www/pilotwardrobe-api/deploy.sh
```

**Watch a deploy:** *GitHub → Actions → CI → latest run → Deploy to production (VPS)* shows the same output as running the script by hand. To deploy without a code change, re-run that job from the Actions page. Manual deploys and rollbacks on the server still work exactly as before.

If SSH on the server is restricted to certain IP addresses (provider firewall or `ufw allow from …`), GitHub's runners will be blocked: their IPs change, so key-only access (as set up here) is the practical choice.

---

## 17. Temporary demo data for testing (and removing it)

To try the storefront end to end before launch, you can load the same demo data as local development: six member accounts, 20 listings with photos across all home-page sections, a completed order with a review, an open offer and a conversation. Staff demo accounts are **not** created on a server.

The demo data is removed by **restoring a database backup taken just before seeding**. Deleting it row by row is not possible: orders, ledger entries and audit records are protected against deletion by design. So treat the test window as disposable:

- Anything created between the backup and the restore (real sign-ups, settings changes, admin edits) is rolled back too. Do this before launch, or in a quiet window.
- The demo accounts use the public password `Password1`. Keep the window short, and don't take real payments with them.
- While seeding, notifications are written to the log instead of being sent (the demo e-mails and phone numbers aren't real customers).

Run everything as the app user, from the app folder (replace `/var/www/pilotwardrobe-api` and the database name if yours differ):

> **Non-default PostgreSQL port?** The commands in this section assume 5432. If `DB_PORT` in `shared/.env` is different (e.g. 5433, common when two PostgreSQL versions are installed; `pg_lsclusters` shows them), add `-p 5433` to every `pg_dump`, `pg_restore`, `createdb` and `psql` command.

**1. Back up the database**

```bash
deploy$ BACKUP=/var/backups/pilotwardrobe/before-demo-$(date +%F-%H%M).dump
deploy$ sudo -u postgres sh -c "pg_dump -Fc pilotwardrobe > $BACKUP" && sudo ls -lh $BACKUP && echo "$BACKUP"
```

Note the file name it prints; you need it in step 5. (`/var/backups/pilotwardrobe` is created in §13; if you skipped that, run `sudo mkdir -p /var/backups/pilotwardrobe && sudo chown postgres:postgres /var/backups/pilotwardrobe` first.)

**2. Seed the demo data**

```bash
deploy$ cd /var/www/pilotwardrobe-api/current
deploy$ php artisan demo:production seed
```

It asks you to confirm the backup, uploads the listing photos to Cloudinary, and lists the demo logins (`pilot@example.com`, `buyer@example.com`, … with password `Password1`).

**3. Test.** Deploys keep working normally during the window.

**4. Remove the demo photos from Cloudinary** (must run *before* the restore, while the database still knows which files are demo):

```bash
deploy$ php artisan demo:production cleanup
```

**5. Restore the backup** (removes every demo row in one transaction; if anything fails, nothing changes):

```bash
deploy$ sudo supervisorctl stop pilotwardrobe-horizon
deploy$ sudo -u postgres pg_restore --clean --if-exists --single-transaction -d pilotwardrobe /var/backups/pilotwardrobe/before-demo-YYYY-MM-DD-HHMM.dump
deploy$ php artisan cache:clear
deploy$ sudo supervisorctl start pilotwardrobe-horizon
```

**6. Check it's gone:**

```bash
deploy$ php artisan tinker --execute="echo App\Models\Listing::count(), ' listings, ', App\Models\User::where('email', 'like', '%@example.com')->count(), ' demo users';"
#   0 listings, 0 demo users
deploy$ curl -s https://api.YOURDOMAIN.com/health
```

Members who signed in during the test have sessions that no longer match any user; they are simply signed out.

## Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| `502 Bad Gateway` | PHP-FPM is down or the socket path differs: `systemctl status php8.5-fpm`; check `fastcgi_pass` in the Nginx site |
| `could not find driver` | `php8.5-pgsql` missing for FPM: `php-fpm8.5 -m \| grep pdo_pgsql`, then install and restart FPM |
| `.env` changes ignored | Config is cached: `php artisan config:cache` and reload FPM (see step 11) |
| Code changes not visible after a manual edit | OPcache doesn't re-check files: `sudo systemctl reload php8.5-fpm` (the deploy script does this) |
| Jobs not processing | `supervisorctl status`; `php artisan horizon:status`; Redis password in `.env` matches `redis.conf` |
| SPA gets 419 or CORS errors | `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` must match the SPA's real domain; both sites must use HTTPS |
| Every visitor shares one rate limit / IP shows `127.0.0.1` | A proxy in front of Nginx (Cloudflare, load balancer) isn't trusted: add its IP ranges to `TRUSTED_PROXIES` |
| `Permission denied` under `storage/` | PHP-FPM must run as `deploy` (step 2) and `shared/storage` must be owned by `deploy` |
| `Missing repository secrets` in the deploy job | Add the secrets from step 16.5 (names are case-sensitive) |
| Deploy job: `Host key verification failed` | `VPS_KNOWN_HOSTS` does not match `VPS_HOST` (use the same IP/hostname for both) or the server was rebuilt; redo step 16.4 |
| Deploy job: `Permission denied (publickey)` | The public key line is missing from `~/.ssh/authorized_keys`, or `VPS_SSH_KEY` was pasted without its BEGIN/END lines |
