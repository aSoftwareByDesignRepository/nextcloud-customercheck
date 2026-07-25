# CustomerCheck

Internal customer & revenue hub for the Software by Design Check family.

**Not for app-store publication** — contains sales data. Install manually like `sbdlicenseops`.

Canonical product specification: `planning/app-ideas/customercheck/README.md` (PSD `1.0.0`).

## Requirements

- Nextcloud 32–34, PHP 8.2+, MySQL/MariaDB or PostgreSQL

## Dev

```bash
cd nextcloud
docker compose exec nextcloud php occ app:enable customercheck
cd apps/customercheck && composer install && npm install
```

## Gauntlet (must be green before release)

Run from the host against the Docker Nextcloud service (`custom_apps` mount):

```bash
# Unit + integration (Docker)
docker compose exec nextcloud bash -lc \
  'cd /var/www/html/custom_apps/customercheck && php vendor/bin/phpunit --testsuite unit'
docker compose exec nextcloud bash -lc \
  'cd /var/www/html/custom_apps/customercheck && php vendor/bin/phpunit --testsuite integration'

# Frontend unit + critical mutation MSI
cd apps/customercheck
npm run test:js
npm run test:mutation   # ≥ 85% MSI gate (critical service mutators; 100% killed)
# Full Infection (infection.json) needs pcov/xdebug in the PHP runtime;
# the Docker nextcloud:34 image does not ship them — use the mutator suite above.

# E2E (Playwright — needs tests/e2e/.env credentials)
set -a && source tests/e2e/.env && set +a
npm run test:e2e

# DB standards + repair-step DI
cd ../..   # nextcloud/
bash scripts/check-custom-apps-db.sh --gate=full --strict-warnings --app=customercheck
php scripts/check-repair-step-di.php --app=customercheck
```

## Version

`1.0.0` — M1–M5 from the PSD (registry, renewals, pipeline, hub integrations, polish).
