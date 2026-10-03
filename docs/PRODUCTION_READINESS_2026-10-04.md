# Historical production readiness audit

Audit date: 2026-10-04, Asia/Dhaka. Live server checks around 02:58-03:02; local verification completed around 03:04.

This document records the original audit before repairs. See [current preparation evidence](PRODUCTION_WORK_LOG.md) and [production operations](PRODUCTION_OPERATIONS.md) for subsequent fixes and release status.

## Original decision

**Not ready for production.** The requested landing and public demo are implemented and work locally. Release is blocked by confirmed authorization and export defects, incomplete production infrastructure, and an absent delivery pipeline. No deployment, DNS change, GitHub write or production data change was made during this audit. Findings below describe the pre-repair state.

Current source: D:/works/School management ERP/School_ERP_Source.
The separate D:/works/Server-deployment/school-erp-deploy checkout contains older uncommitted deployment changes. It is not the current implementation.

## Progress

| Area | Status | Evidence / remaining work |
| --- | --- | --- |
| Main ERP landing, features, plans, why choose us, policies | Implemented locally | Quote-based plans; final commercial pricing is still an owner decision |
| English / Bangla | Passed local regression | Landing, demo guide and policy pages; language persists |
| Appearance | Implemented and previously browser-verified | Day/night/system, 14 colour themes, 14 backgrounds, optional colour rotation |
| Public demo school and three one-click portals | Passed local regression | Fictional Bangladesh school, admin/teacher/student |
| Sample records | Passed database checks | 240 students, 24 teachers, 7,200 student attendance rows, 720 exam marks, 60 books, 120 loans, 36 transport assignments |
| Visitor database and upload isolation | Passed local regression | Independent visitor edits and uploads, scoped SQL accounts and exports |
| No password changes | Passed tested paths | All three portal request guards; admin/employee/student SQL password locks. System-user trigger exists, but the test sandbox has no system-user rows, so its update path was not exercised |
| Clock-hour expiry and cleanup | Passed local regression | Expired database, SQL user and upload removal without HTTP traffic; main requests preserved. A midnight Dhaka reset was previously observed |
| Dedicated demo enquiry and master inbox | Passed local regression | Form submission, real master login, inbox visibility |
| Master authorization | **Blocked** | Denied edit/add permission does not stop execution |
| PDF exports | **Blocked** | mPDF cache location cannot be written by web user |
| New real-school provisioning | **Requires correction and end-to-end testing** | Legacy localhost SQL account creation conflicts with container deployment |
| Tawhaa school | **Not restored** | Local SQL and site ZIP exist; application-level restore is unverified |
| Production ERP hosting | **Inactive** | Previous ERP deployment remains removed |
| Stable-branch automatic deployment | **Absent** | GitHub API returned zero workflows and zero stable-branch runs |

## Confirmed release blockers

### 1. High: master authorization fails to stop denied writes

In master/_access.php:82, masterRequireAccess only redirects/exits when the denied action is view. Denied add/edit calls return normally. A temporary LOCAL viewer proved masterCanAccess(deploy, edit) returned false while masterRequireAccess continued. The same viewer received HTTP 200 and write forms at /master/create_tenant.php, /master/deploy.php and /master/settings.php.

Affected handlers include tenant creation, tenant edits, bulk tenant SQL execution and purge actions. Separately, master/toggle_status.php:6 checks authentication and CSRF but performs no role/action permission check before updating tenant status. master/settings.php also lacks a corresponding action authorization check.

Required gate: fail closed on every denied action; explicitly authorize every state-changing endpoint, including direct requests. Verify viewer and limited-admin POST attempts are rejected and leave records unchanged. The probe did not execute destructive SQL or change a real tenant; its temporary viewer was deleted.

### 2. High: production deployment and persistent write paths are missing

deployment/compose.local.yml explicitly uses APP_ENV=local and local HTTP hostnames. Main has a read-only source mount and no persistent upload/branding mount. Only the worker has a restart policy; gateway/main/demo/db will not automatically restart under the intended server recovery policy.

The runtime probe reported display_errors=1, log_errors=0 and expose_php=1 in PHP CLI. Dockerfile provides no production PHP configuration. This is a configuration gap, not proof of a currently exposed production error page. HTTPS cookie security is set by application code when ERP_HTTPS=1 and must be verified behind the production proxy.

Required gate: a separate production stack with exact host routing, trusted proxy/TLS, main and demo credential separation, persistent real-school and master branding uploads, private demo uploads, writable private PDF/cache/log paths, production error logging, restart/health checks and worker failure alerts. Never expose Apache directly or serve demo uploads through a public static mapping.

### 3. Medium: PDF export fails with a read-only cache

admin/result_bulk_pdf_export.php:220 directs mPDF to config/pdf_lib/mpdf_tmp under read-only source. Instantiating that same configuration as www-data in the local runtime throws:
Temporary files directory "/var/www/html/config/pdf_lib/mpdf_tmp/mpdf" is not writable.

CSV/JSON demo ZIP exports pass; this does not establish that PDF export works.

Required gate: route mPDF cache to a private writable runtime path, then test an actual Bangla result PDF and bulk ZIP for correct content, tenant separation and cleanup.

### 4. Medium: demo integration-settings restriction misses the actual GPS route

config/demo.php:58 blocks gps_settings, while the real route is transport_gps_settings. A LOCAL private demo visitor successfully POSTed fictional GPS configuration with a blank password: HTTP 302, and the saved values appeared on reload. Expected response is 403. This did not change any password or make an external connection; the internal demo network remains the second containment layer.

Required gate: protect actual integration route names and direct handlers, then verify writes are denied even when password fields are blank. Review other restricted settings routes against the router.

### 5. High for SaaS onboarding: legacy provisioning is not compatible with the planned container model

master/create_tenant.php:109-111 runs database/user creation through the master web PDO and grants an account only at localhost. The local master web account has privileges on the master database, not global CREATE USER/CREATE DATABASE/GRANT. A separate web container connecting to the database needs an appropriately restricted network account. Automatic provisioning therefore conflicts with the current separation. Database and user identifiers are also interpolated without strict identifier validation.

Creation imports original schema.sql; this is not a reviewed, versioned migration path for existing schools. It must not be used to reinitialize Tawhaa or another populated database.

Required gate: define a trusted provisioning path with validated names, container-compatible grants, cleanup on partial failure and an empty-database prerequisite; test creating a new disposable real tenant, logging into all roles and uploading files. Keep provisioning credentials out of public web runtimes.

### 6. High for automatic updates and data protection: no current CI/CD path

The active source has no .git directory and no .github/workflows directory. GitHub repository techtemlead-lab/School_ERP_Source has no workflows or stable-branch runs. The old stable checkout contains modified deployment files and is stale. VPS schoolmng-ci access remains removed.

Required gate: commit the current implementation to the correct repository, add stable push/merge deployment with serialized releases and restricted access, persistent data outside release folders, verified pre-migration backups, additive migrations, smoke checks and a previous code release for rollback. Demonstrate an update preserving real school records and uploads on staging. A code rollback alone cannot reverse schema changes.

### 7. High for serving the requested live schools: TLS and restoration are unfinished

All three A records currently resolve to 198.54.121.88. HTTPS requests for schoolmng.techteamlead.com, demo.schoolmng.techteamlead.com and tawhaa.schoolmng.techteamlead.com failed certificate hostname validation. There are no schoolmng containers or enabled nginx routes, and /opt/schoolmng is absent.

Tawhaa_DB_clean.sql (596,692 bytes) and Tawhaa_School_Site.zip (19,683,073 bytes) exist locally. Their presence is not an application-level restore test.

Required gate: isolated staging restore, compatibility and data checks, then correct virtual hosts and valid certificates for the exact names. Preserve backup copies and confirm balances, students, users, attendance, documents and portal access before cutover.

## Verification performed in this audit

- All 961 PHP files passed syntax checks.
- tests/public_landing.py passed English/Bangla, persistence, translated guide/policies, stable form values, 14 colour themes, 14 backgrounds and 3 modes.
- 206 ordinary admin module GET pages returned HTTP 200 with no scanned visible PHP fatal errors, warnings or SQL exceptions. Import/export/print/reset/connect/send/generate routes were skipped. This checks page rendering, not every business workflow.
- tests/demo_integration.py passed isolation, private uploads, rejected executable/oversized uploads, role exports, password request locks, host/master separation, payment blocking and dedicated enquiry submission.
- tests/master_requests.py passed real master login and enquiry inbox.
- tests/demo_database.php passed seed counts, scoped SQL isolation, tested password locks, clock boundaries and unattended expiry cleanup. Main enquiry count survived cleanup.
- New local permission, PDF and GPS probes reproduced the defects above. Machine-readable results: docs/READINESS_EVIDENCE_2026-10-04.json.

These checks do not constitute a complete security audit, concurrency/load test or validation of billing/payment/integration correctness for a real school.

## Current VPS and recovery evidence

Read-only SSH confirmed nginx and Docker active; nginx configuration validation passed. Server uptime was approximately 17 days. Root filesystem: 118 GB total, about 52 GB available, 55% used. Memory: 5.8 GiB total, 2.2 GiB used; swap 1.1/4.0 GiB. This is a health snapshot, not a capacity guarantee for 60 concurrent demo databases alongside the other services.

The retained schoolmng_schoolmng_db volume and /root/schoolmng-retained-20261003 directory exist. Retained dated backup sets contain databases.sql.gz, uploads.tar.gz and SHA256SUMS. Their existence was rechecked; full restore verification and a fresh checksum pass were not repeated in this audit.

## Recommended release order

1. Fix authorization, PDF runtime paths and demo settings guards; add focused regression checks.
2. Correct and verify real tenant provisioning; prepare versioned, non-destructive migrations.
3. Build a production stack and staging environment; verify persistent uploads, restart recovery and independent hourly cleanup.
4. Restore and verify Tawhaa in staging; verify backup restore, record preservation, financial workflows and expected concurrency.
5. Commit the current source and validate stable-branch CI/CD against staging, including rollback.
6. Configure the exact production routes/certificates and perform a reviewed cutover.

**Release gate: keep production deployment paused until the confirmed defects and data-preservation checks above are resolved.**
