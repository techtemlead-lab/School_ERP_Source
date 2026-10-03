# Production repair and release evidence

2026-10-04, Asia/Dhaka.

## Release status

The replacement ERP is live on the requested main, public-demo and Tawhaa HTTPS hosts. The known application and deployment blockers in the original audit were repaired. The stable workflow passed its verification and production deployment jobs:
https://github.com/techtemlead-lab/School_ERP_Source/actions/runs/37158920920

Validated application commit: 06107601ef07db78f297f118f9227ed6ec2877c7. This follow-up commit documents release evidence and clarifies domain setup for future schools; its own stable workflow must also finish before it is treated as promoted.

## Completed and verified

- Fail-closed master permissions, direct-handler authorization and viewer/limited-admin write denials.
- Private writable PDF caches; 48 valid student result PDFs generated and downloaded in a ZIP.
- Public demo password, integration, GPS, account and database reset restrictions, including populated system-user SQL password triggers.
- Scoped CLI school provisioning; new-school login, persistent upload and refusal to initialize an existing database.
- Pinned production images, normalized source permissions, read-only apps, separate main/demo engines and networks, persistent uploads, health checks and restart supervision.
- Production PHP logging with display_errors disabled, secure host-only session cookies and trusted edge proxy headers.
- Versioned additive tenant/master migrations; local Tawhaa restore passed 206 admin pages and all role logins.
- Latest retained VPS backup restored entirely on the VPS. All 132 original table hashes and all 125 upload hashes matched immediately after restore/migration, before private password initialization.
- Previously passwordless real teacher/student credentials issued to a root-only private roster. Existing passwords were preserved. Initial credentials require a password change.
- Actual production database/upload backup passed gzip/tar/SHA256 checks. A separate-engine restore matched 184 application table hashes; volatile audit/session/rate-limit/worker-heartbeat state is excluded from the business comparison.
- HTTPS certificate covers all three hosts, expires 2027-01-01; all seven production services healthy. Existing unrelated Nginx routes were preserved.
- Daily verified backup timer and one-minute health monitor active. Restricted CI key rejects arbitrary commands, PTYs and forwarding.
- Actual Git archive validation and malicious/private archive entry rejection.
- Stable CI successfully ran all local gates and promoted the tested commit without replacing school data.
- Live checks passed bilingual landing/guide/policy pages, all three one-click demo portals, private visitor edits, exports, password denials, clock-hour cookies and simultaneous main/demo/real-school requests.
- Browser verified Bangla/English, 3 appearance modes, 14 colours, 14 backgrounds and mobile layout.

## Operator decisions and recovery access

Automatic approval review blocked copying VPS recovery data, credential files and server verification reports to the local computer. They remain root-only on the VPS. An encrypted off-server recovery copy requires the owner's pending approval. Same-server backups do not cover total VPS loss.

SMS, WhatsApp, GPS and 100ms integrations are disabled until real providers are configured and accepted. Plans are quote based. Additional schools need their DNS/HTTPS domain setup before publication; the three requested hosts are configured.

Root-only server evidence: /opt/schoolmng/private/production-release-verification.json, restore-verification.json and backup-restore-verification.json.
Initial portal roster: /opt/schoolmng/private/tawhaaschool-initial-passwords-*.csv.
Retained master credential file: /root/schoolmng-retained-20261003/bootstrap/credentials.

See [production operations](PRODUCTION_OPERATIONS.md) for update, backup, rollback and onboarding procedures.
