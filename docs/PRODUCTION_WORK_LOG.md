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

## Final session persistence correction

Container replacement previously erased tmpfs PHP sessions. Main/master and demo now have separate private persistent session mounts. Local regressions replace main and demo containers and prove all logins survive. The hourly worker also removes expired demo session files independently of HTTP requests. This correction requires its stable workflow to pass before promotion. The first storage cutover can require one re-login; stored school records are unaffected.

## Operator decisions and recovery access

Automatic approval review blocked copying VPS recovery data, credential files and server verification reports to the local computer. They remain root-only on the VPS. An encrypted off-server recovery copy requires the owner's pending approval. Same-server backups do not cover total VPS loss.

SMS, WhatsApp, GPS and 100ms integrations are disabled until real providers are configured and accepted. Plans are quote based. Additional schools need their DNS/HTTPS domain setup before publication; the three requested hosts are configured.

Root-only server evidence: /opt/schoolmng/private/production-release-verification.json, restore-verification.json and backup-restore-verification.json.
Initial portal roster: /opt/schoolmng/private/tawhaaschool-initial-passwords-*.csv.
Retained master credential file: /root/schoolmng-retained-20261003/bootstrap/credentials.

See [production operations](PRODUCTION_OPERATIONS.md) for update, backup, rollback and onboarding procedures.

## Pricing and runtime isolation release — 4 October 2026

Reviewed official Edufy, IskulAI and Fedena public plans. Published shared bilingual BDT prices using the existing monthly configuration, quarterly/yearly periods, active-student/branch allowances, discussed connection fees and scoped external costs. Existing contracts and school records are retained.

Added the bound-tenant web role, exact school-host validation, separate school credentials and profile, and a focused CI isolation regression. Production master, Tawhaa and demo now run on separate SQL engines/private networks. Tawhaa has private uploads/session mounts and no master credentials; master web has no school filesystem mount/network. The private operator migration verified every imported master table and the school data snapshot, retained backups, removed the active master copy from the school engine and redacted Tawhaa SQL credentials from the master registry. All nine ERP services and the minute-based metadata timer passed health checks.

Local PHP/shell lint, bilingual pricing, existing complete production gate and new bound-runtime login/SQL/host tests passed before promotion. Stable CI must pass its full gate and deploy this commit. Privileged operator/host compromise remains a shared trust boundary. Additional schools must receive their own bound runtime before publishing their DNS address.

Credential delivery and unknown-password resets await the owner's private handoff choice. Existing passwords remain unchanged.