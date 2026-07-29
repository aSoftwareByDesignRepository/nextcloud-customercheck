# CustomerCheck

Customer & revenue command center for Nextcloud: one place for a workspace’s
customer book, shared mailbox, renewals, chase, coverage, Pulse, Brief, and
Team coaching — joining ProjectCheck / InvoiceCheck / TicketCheck instead of
replacing them.

Part of the Check app family by [Software by Design](https://nextcloud.software-by-design.de).

## Features

- **Workspaces** — circles of responsibility with roles (viewer/member/manager); cross-workspace access returns 404
- **Today Command Board** — chase, commitments, KAM touches, promises, renewals, unmatched mail, team stuck
- **Business Pulse** — twelve book-health questions; unbilled never fakes €0 (`honesty_blocked`)
- **Weekly Brief** — WIP-limited CEO commitments + cash calendar that excludes pipeline
- **My Chase / Pipeline** — quote age, loss codes, forecast labels (not cash), contacted/negotiation/won sequences
- **Coverage & My Accounts** — buying roles, next touch, customer promises, prepare-meeting pack
- **Team Coaching** — stuck board, OOO cover (never steals ownership), bulk reassign
- **Shared mailbox** — workspace IMAP index with unavoidable disclosure; personal Mail stays Private
- **Companies & 360** — agreements, renewals (−60/−30/−7/0/+7 exactly once), partner hours, GDPR erase
- **Mobile companion** — optional Android app with CUC2 seats (web app never gated)

## Privacy & access

- **All data stays on your Nextcloud instance.** No telemetry, no phone-home,
  no external services are contacted by this app.
- **Fail-closed access control:** access restriction is **ON by default** —
  only app administrators and explicitly allowlisted users or groups can open
  the app. Customer data is never exposed to every instance user by accident.
- GDPR: user deletion frees companion seats automatically; company data export
  includes merged history. Suite erase order (PC → IC → TC → CUC): see
  [docs/SUITE-GDPR-ERASE.md](docs/SUITE-GDPR-ERASE.md).

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
docker compose exec -u www-data nextcloud bash -lc \
  'cd /var/www/html/custom_apps/customercheck && php vendor/bin/phpunit --testsuite unit'
docker compose exec -u www-data nextcloud bash -lc \
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

## Product bible status (v1.5.2)

Implements `planning/app-ideas/customercheck/CUSTOMERCHECK-PRODUCT.md` including workspaces,
Pulse/Brief/Chase/Coverage/Team/Strategy, shared mail sync+send with Private-to-you badges,
Brief done-evidence gate, Digest v2 Brief deep-links, Circles roll-call, OOO/escalation
maintenance jobs, and workspace-scoped money (no cross-WS € blend).

## License

AGPL-3.0-or-later. Canonical product specification:
`planning/app-ideas/customercheck/CUSTOMERCHECK-PRODUCT.md`.
