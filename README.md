# Maxmila Homecare API

REST API for Maxmila Homecare's caregiver scheduling and visit tracking, built with **PHP 8.4** and
**Phalcon 5 (Micro)** on MySQL/MariaDB. Consumed by the Maxmila mobile and web apps.

- **Client integration guide** (endpoints, auth, payloads, enums, TypeScript types):
  [`documentation/MAXMILA-API-CLIENT.md`](documentation/MAXMILA-API-CLIENT.md) — copy it into any
  front-end project that talks to this API.
- **Security roadmap**: [`documentation/SECURITY-MIGRATION-PLAN.md`](documentation/SECURITY-MIGRATION-PLAN.md)

## Run it locally

Requirements: Docker (the Phalcon extension is compiled into the dev image; nothing else to install).

```bash
docker compose up --build          # API on http://localhost:8080, MariaDB on :3306, Mailpit UI on http://localhost:8025
curl http://localhost:8080/        # environment + database name
```

The database is created from `database/tables/reinstall_all.sql` on the first start (`docker compose down -v` to reset).
Every email the API sends is captured by Mailpit. The repository is bind-mounted, so edits are live.

Without Docker you need PHP 8.4 with `phalcon`, `pdo_mysql`, `fileinfo` (and optionally `imagick`), then
`php -S 0.0.0.0:8080 .htrouter.php` with the environment variables from `compose.yaml` exported.

## Develop

```bash
composer install                   # dev tools only (phpstan + Phalcon IDE stubs); the app has no vendor dependencies
composer lint                      # php -l over every tracked file
composer stan                      # phpstan level 1 (must stay clean; CI enforces it)
bash database/build.sh             # regenerate create_all.sql / reinstall_all.sql after editing database/tables|views
bash database/build.sh --check     # what CI runs
```

Integration requests live in `tests/maxmila/*.http` (JetBrains HTTP Client). Copy
`http-client.env.example.json` to `http-client.env.json` (git-ignored) and fill in the credentials/tokens.

Layout: `index.php` (bootstrap, loads every `routes/*.php`) → `configuration/middleware.php`
(CORS, content-type, token auth, body parsing, response envelope) → `routes/` → `controllers/`
(extend `BaseController`) → `models/` (Phalcon models; column-name constants double as request keys).
Constants for roles/status/progress are in `constants/`. Schema lives in `database/tables/*.sql` and
`database/views/*.sql`; `database/migrations/` holds hand-applied changes for the live databases.

## Configuration

All runtime configuration comes from environment variables (on the shared host: `SetEnv` in the
web root's `.htaccess`, which is not committed):

| Variable | Purpose |
|---|---|
| `APP_ENV` | `dev` enables request logging and debug details in the 500 handler; anything else is production |
| `API_BASE_URL`, `APP_BASE_URL` | used in emails (activation link) and the activation page |
| `DB_HOSTPATH`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE` | MySQL/MariaDB connection |
| `EMAIL_HOSTPATH`, `EMAIL_SERVPORT`, `EMAIL_SMTPAUTH`, `EMAIL_USERNAME`, `EMAIL_PASSWORD`, `EMAIL_SMTPSECURE` (`ssl`\|`tls`\|``), `EMAIL_REP_ADDR`, `EMAIL_REP_NAME` | SMTP |

Time zone is America/Detroit for PHP and the DB session.

## Deploy

- Push/merge to `main` → `.github/workflows/deploy_test.yml` lints, runs phpstan and the schema
  drift check, then FTP-syncs to the test server.
- Publish a GitHub release (or run the workflow manually) → `deploy_prod.yml` does the same for
  production (protect the `production` environment with required reviewers).
- `tests/`, `database/`, `cronjobs/`, `docker/`, `documentation/` and tooling files are never uploaded.
- Schema changes are applied by hand from `database/migrations/` before deploying code that needs them.
- `cronjobs/auto_checkout.php` is installed separately **outside the web root** with a
  `cronjobs/.env` (see `.env.example`) and scheduled daily after midnight Detroit time.

## License

See [`LICENSE`](LICENSE). Copyright Maxmila Homecare LLC & Trinketronix LLC.
