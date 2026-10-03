# Production preparation checkpoint
2026-10-04 Asia/Dhaka. Work in progress; do not treat this as a release approval.

Completed:
- Fail-closed master permissions, direct-handler authorization and restricted-admin regressions.
- Private writable PDF caches; bulk ZIP test produced 48 valid PDFs.
- Demo settings/password/reset restrictions.
- Scoped CLI school provisioning; new-school login and persistent upload tested.
- Pinned production images, read-only apps, separate main/demo DB engines and networks, health checks.
- Additive portal and missing-module migrations; restored Tawhaa passed 206 admin pages and all three role logins in local staging.
- Original populated business-column hashes preserved; full backup restored into separate engine with all 132 table hashes matching.
- Original 125 upload hashes preserved.
- Secure private issuance for previously passwordless real portal accounts; existing passwords remain unchanged.
- Backup/deploy/rollback/monitor scripts and stable workflow prepared, not published yet.
- Current source reconnected to existing Git stable history; no push yet.

Pending:
- Latest retained production backup download requires owner's answer to automatic approval review.
- Prepare server candidate and restore latest master/Tawhaa state, preserving real credentials.
- Configure restricted CI, GitHub secrets, TLS, monitoring/backups.
- Complete CI run and live HTTPS/role/isolation/data-preservation checks.
- Final release report and private owner credentials handoff.

Local source: D:/works/School management ERP/School_ERP_Source
Local production-like staging: D:/works/Server-deployment/schoolmng-stage
Server retained recovery: /root/schoolmng-retained-20261003
