# Production operations

## Hosts

- ERP landing and demo guide: https://schoolmng.techteamlead.com and /demo/
- Real superadmin login: https://schoolmng.techteamlead.com/master/
- Public fictional school: https://demo.schoolmng.techteamlead.com
- One-click public demo portals: /admin/, /teacher/, /student/
- Restored real school: https://tawhaa.schoolmng.techteamlead.com

Public demo visitors have separate disposable SQL accounts, databases and private uploads. Their fixed expiry is the next Asia/Dhaka clock hour, independent of activity. Background cleanup removes expired databases, accounts and files. Password changes, real credentials, payment integrations, resets and external service connections are blocked. Exports and printing remain available. Browser drafts follow the same expiry; language and appearance preferences persist.

## Infrastructure and credentials

Root-owned deployment directory: /opt/schoolmng. Source releases and immutable images are separate from persistent MariaDB volumes and shared uploads. Main and demo use different MariaDB engines and isolated private networks. Apache is reachable only through a loopback gateway and host Nginx.

- secrets/: root-only environment files; never commit or print these.
- private/: verification hashes and initial real portal password roster, outside web roots.
- shared/uploads and shared/master-uploads: persistent real media.
- shared/demo-uploads: disposable visitor media.
- backups/: verified application database and upload archives.
- releases/: source releases. shared/deployed-commit identifies the current promoted commit.

Existing master and school passwords are preserved during restore. Passwordless active teacher/student accounts receive cryptographically random initial credentials in a root-only CSV at private/tawhaaschool-initial-passwords-*.csv. Distribute each student's own credential privately; never publish the whole roster. Users must change initial credentials before accessing further portal features. The retained previous master credential file is /root/schoolmng-retained-20261003/bootstrap/credentials.

ERP_INTEGRATIONS_ENABLED=0 disables configured SMS, WhatsApp, GPS and 100ms calls until an operator supplies and tests the real providers. Public demo always disables external integrations. Commercial plans are quote based.

## Stable pipeline

GitHub Actions checks pushes and pull requests against stable. It builds disposable infrastructure and verifies PHP syntax, bilingual pages, demo isolation, database password triggers, scheduled expiry, master permissions, new-school provisioning, persistent uploads and bulk PDF exports. Successful stable pushes or merges promote the tested commit. Pull requests do not deploy.

The GitHub production environment permits stable only. Encrypted environment secrets SCHOOLMNG_DEPLOY_KEY and SCHOOLMNG_SSH_KNOWN_HOSTS hold the restricted deploy key and validated host identity. The CI account accepts only deploy followed by one 40-character hexadecimal commit SHA. It denies interactive commands, PTYs and forwarding.

The server serializes deployments, validates a bounded source archive, builds an immutable image, lints PHP, briefly stops real application writes, verifies a database/media backup, applies additive migrations and checks main/demo/Tawhaa health before promotion. Failed promotion restores the previous application image. Source updates never replace DB volumes, uploads or secrets.

Infrastructure secrets, proxy settings and privileged deployment scripts are root-managed; code pushes cannot silently replace that configuration. Update them deliberately when an infrastructure change requires it.

## Backup and rollback

Run /opt/schoolmng/bin/backup.sh as root. It briefly pauses main/provisioning writes, dumps application databases including routines/events, captures real/master uploads and verifies gzip, tar and SHA256 checksums. Demo visitor state is intentionally disposable.

schoolmng-backup.timer runs daily at 03:20 server time. schoolmng-monitor.timer checks container health and logs failures to the system journal every minute; unhealthy background workers are restarted. Review journalctl -u schoolmng-monitor and disk capacity. Backups are retained; removal requires an explicit retention policy. Keep an approved encrypted off-server copy for recovery from total server loss.

Code rollback does not reverse database migrations. Restore a backup to a separate temporary database engine first, compare records and uploads, and plan a maintenance window before changing production data. Never import original vendor schema or demo seed SQL into an existing real school, run compose down -v, or overwrite retained backups.

## School provisioning

Superadmins create a school in the master panel. A separate CLI worker with main-engine provisioning privileges handles new school creation. Web apps do not hold engine-wide DB credentials. The worker refuses an existing database and records success/failure in the master provisioning page. New school credentials must be unique and strong. The three requested hosts are configured. Before publishing an additional school or custom domain, create its DNS record, add its hostname to the host Nginx site and issue a certificate covering that hostname. Verify HTTPS and tenant routing before sharing its address; database provisioning does not grant domain ownership or configure a new certificate.

## Verification scope

Record-preservation and backup-restore tests compare original business column hashes and upload SHA256 values. Tests cover the restored Tawhaa portals and 206 admin routes, permission denials, demo isolation/reset, PDF exports and onboarding. A passing gate establishes the tested deployment's readiness; later vendor module changes still require regression verification. External provider integrations require their own configured-provider acceptance test.
