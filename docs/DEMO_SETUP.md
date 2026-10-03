# School ERP landing and public demo

## Current state

Built and tested locally. The previous VPS deployment remains removed at the owner's request. This work does not restart it, restore the Tawhaa backup, alter DNS, configure GitHub secrets, or publish a stable-branch workflow.

| Production address | Purpose |
| --- | --- |
| https://schoolmng.techteamlead.com/ | ERP features, quote-based plans, why choose us and policies |
| https://schoolmng.techteamlead.com/master/ | Real superadmin login |
| https://schoolmng.techteamlead.com/demo/ | Demo guide and dedicated demo request |
| https://demo.schoolmng.techteamlead.com/ | Fictional Shapla school website |
| https://demo.schoolmng.techteamlead.com/admin/ | One-click demo school administrator |
| https://demo.schoolmng.techteamlead.com/teacher/ | One-click demo teacher |
| https://demo.schoolmng.techteamlead.com/student/ | One-click demo student |

The demo host cannot access /master. Main-host /admin redirects to /master. Master and demo use separate host-only session cookies. The legacy school administrator Super Admin flag is scoped to the visitor's school database and grants no SaaS master access.

## Local preview

Docker Desktop is required. From the source root:

    docker compose --env-file deployment/.env.local -f deployment/compose.local.yml up -d --build

The ignored deployment/.env.local contains random local credentials. To initialize a fresh LOCAL database:

    docker compose --env-file deployment/.env.local -f deployment/compose.local.yml run --rm -T init

Wait for the worker to seed the initial five school databases.

- Main: http://schoolmng.localhost:8087/
- Guide: http://schoolmng.localhost:8087/demo/
- School: http://demo.schoolmng.localhost:8088/
- Panels: append /admin/, /teacher/ or /student/.
- Local master username: owner. Password: LOCAL_ADMIN_PASSWORD in the ignored local env file. Never publish that file.

Services bind only to 127.0.0.1. Source mounts are read-only. Demo uploads and database data use persistent volumes. Nginx dynamically resolves Docker service addresses. Stop with compose stop; never delete volumes if data must be retained.

## Visitor isolation and clock resets

A random HttpOnly cookie identifies each browser's private database, SQL account and upload directory. All three panels in the same browser share its school records. Different browsers receive separate school records. localStorage holds optional form drafts only; it does not provide authentication or server isolation.

The scoped SQL account cannot access master data or other visitor databases. The demo WEB runtime receives only the control SELECT/UPDATE account and token secret. It must never receive master or provisioning credentials.

The independent daemon runs through php cron/demo_worker.php:

- Sandboxes expire at each clock hour in Asia/Dhaka. Activity never extends their lifetime.
- Expired records become inaccessible at the boundary. The worker drops expired databases/accounts and deletes uploads; physical deletion can take several seconds.
- Every five minutes it replenishes unused capacity. During the last five minutes it prepares the next hour's pool.
- Defaults: five unused sandboxes, maximum 60 total per hour, 10 allocations per client IP/hour. Local tests allow 50 per IP. Pool and claimed databases both count toward capacity.
- Database advisory locks prevent workers overlapping across containers.
- Writes from stale sessions receive 409. Active pages reload into fresh sample data.
- No HTTP request creates a database or triggers reset cleanup. If capacity is unavailable, the visitor sees a busy message.

The banner uses server time. Optional local drafts omit passwords/tokens/hidden/file inputs and expire with the same hour. Active pages clear them at the boundary. A closed browser cannot execute JavaScript: expired drafts are purged next time the demo opens. Files already exported or printed remain on the visitor's device.

Seed data includes 240 fictional Bangladesh students, 24 teachers, five classes, 15 subjects, attendance history, routines, fees, homework, 720 sample exam marks, 60 library books, 120 loans, transport, scholarships, notices, facilities and a school event. Email uses example.invalid; phone numbers are masked. School/profile illustrations are local SVG assets.

## Restrictions and exports

- No demo user can change ANY password. Request guards reject password fields, change UI is disabled, and SQL triggers reject admin/employee/student/system-user password changes.
- Account, security and integration settings are read-only.
- SMS/WhatsApp are simulated. Online payments, OAuth and external integrations are blocked.
- Demo web MUST use an internal Docker network without outbound Internet. Only the gateway may join an Internet-enabled network.
- Keep source read-only and allow writes only to the demo upload volume.
- Allowed uploads: JPG, PNG, WebP, PDF, CSV, TXT, XLSX; 2 MB each and 20 MB per visitor. Private uploads require the owner's sandbox cookie and use nosniff/sandbox headers.
- Export provides CSV/JSON ZIP files for supported records. Passwords/secrets are stripped and spreadsheet formula prefixes escaped. Teacher/student exports contain only their role's records.
- Existing print pages and banner Print remain available.
- Never expose the demo upload volume through a direct nginx/CDN static mapping.

## Dedicated demo requests

The main-host form validates CSRF, consent, contact values and lengths and limits submissions to three per client IP/hour. It saves requests in the master database independently of demo resets and sends no external email/message.

On an existing main database, install the new tables from the MAIN runtime:

    php cron/install_demo_requests.php

This creates demo_requests and demo_request_limits without deleting existing rows. Master admin/superadmin users review and update New/Contacted/Closed status at /master/demo_requests.php; viewer users cannot access it.

Plans are quote-based because actual prices were not provided. Update landing/index.php once commercial terms are decided.

## Production deployment requirements

The local compose file is a test stack, not a production cutover script. Before publishing configure:

1. Separate main and demo web services with ERP_ROLE=main and ERP_ROLE=demo.
2. Exact production hostnames/HTTPS URLs from .env.example. Use ERP_HTTPS=1 behind TLS termination.
3. Master DB credentials ONLY in main.
4. Dedicated demo control DB, a web SQL account with SELECT/UPDATE only, and random DEMO_TOKEN_SECRET of at least 32 characters.
5. Separate CLI worker provisioning account with database/user creation, scoped grants and cleanup privileges for disposable ds_* databases and du_* accounts. NEVER pass it to web processes.
6. Persistent real-school DB/uploads separately from demo uploads. Demo and worker share the demo volume; worker assigns Apache UID/GID 33 ownership.
7. Trusted reverse proxy routing exact demo hostname to demo and primary/real-school domains to main. Preserve Host and overwrite X-Real-IP at the trusted edge. Apache remoteip trusts private proxy networks in this Dockerfile: never expose Apache directly to clients.
8. Apache AllowOverride/rewrite from this repository. Deny config/sql/cron/tests/deployment/.env/.git and uploaded scripts. Never bypass the private upload handler.
9. Continuous worker supervision and failure alerts. Do not register real-school databases in demo_sandboxes.
10. Review existing vendor school onboarding and migrations before production cutover.

Some vendor public pages use browser CDN fonts/styles. Fully offline browser operation would require vendoring those assets.

## Stable-branch pipeline

The earlier deployment was canceled before this production repair. The replacement workflow and persistent production architecture are described in [production operations](PRODUCTION_OPERATIONS.md):

- Build/lint and run checks on disposable test data before promotion.
- Serialize deployment and use a restricted deploy key.
- Back up and verify real databases/uploads before reviewed additive migrations.
- Never import demo seeds or original schema over an existing real school.
- Keep env, persistent DB volumes and real uploads outside release directories.
- Update application containers without deleting volumes.
- Smoke-test host separation and role portals; retain the previous release for rollback. Code rollback does not undo schema migrations automatically.

## Verified local checks

    python tests/demo_integration.py
    python tests/master_requests.py
    docker compose --env-file deployment/.env.local -f deployment/compose.local.yml run --rm -T worker php tests/demo_database.php

Checks cover visitor edits/private uploads, executable/oversized uploads, role exports, password locks, host separation, request submission/master inbox login, SQL grants/triggers, clock boundaries, deletion of expired DB/account/uploads without HTTP traffic, and preservation of main requests.

The database check deliberately expires one UNUSED local sandbox and is gated to LOCAL CLI. It never targets production. Run HTTP tests before the inbox check. A real midnight Dhaka reset was observed during this implementation.

## Landing languages and appearance

Public pages offer server-rendered English/Bangla with a year-long host-only language cookie. Appearance preferences use schoolERP.public.appearance.v1 in localStorage, separately from expiring demo drafts. Choose day, night or OS-following system mode, 14 colour themes and 14 backgrounds. Optional theme rotation changes every 45 seconds while visible and respects reduced-motion settings. Reset appearance restores the forest/plain/system defaults.
