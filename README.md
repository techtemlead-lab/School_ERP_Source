# School ERP

Multi-school PHP ERP with a bilingual ERP landing page, separate superadmin area, fictional public demo school and isolated hourly visitor sandboxes.

## Run locally

See [demo setup](docs/DEMO_SETUP.md). Local infrastructure and test accounts are generated separately from production.

## Production

See [production operations](docs/PRODUCTION_OPERATIONS.md) for architecture, stable branch updates, backups, rollback and private credentials. [Preparation evidence](docs/PRODUCTION_WORK_LOG.md) records verification and remaining work. The [original audit](docs/PRODUCTION_READINESS_2026-10-04.md) is a historical baseline.

Production deployment keeps databases, uploads and secrets outside immutable source releases. Public demo resets never target real-school data.
