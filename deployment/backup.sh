#!/usr/bin/env bash
set -euo pipefail
umask 077
base=${ERP_BASE:-/opt/schoolmng}
[[ "$base" == /opt/schoolmng || "$base" == /opt/schoolmng-stage ]] || exit 2
cd "$base"
dc() { docker compose --env-file runtime.env -f compose.yml "$@"; }
owned_stop=0
if [[ "${ERP_BACKUP_ALREADY_STOPPED:-0}" != 1 ]]; then
  dc stop main tawhaa provisioner
  owned_stop=1
fi
recover() { if [[ "$owned_stop" == 1 ]]; then dc up -d main tawhaa provisioner; fi; }
trap recover EXIT
mkdir -p backups
target="backups/$(date -u +%Y%m%dT%H%M%S)-$(openssl rand -hex 3)"
mkdir "$target"
# Both engine dumps are concatenated into the existing multi-database SQL archive.
{
 for service in master-db main-db; do
  mapfile -t databases < <(dc exec -T "$service" sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('"'"'mysql'"'"','"'"'sys'"'"','"'"'information_schema'"'"','"'"'performance_schema'"'"')"')
  [[ "${#databases[@]}" -gt 0 ]] || exit 3
  for name in "${databases[@]}"; do [[ "$name" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || exit 4; done
  dc exec -T "$service" sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --quick --routines --events --databases "$@"' sh "${databases[@]}"
 done
} | gzip -1 > "$target/databases.sql.gz.partial"

gzip -t "$target/databases.sql.gz.partial"
mv "$target/databases.sql.gz.partial" "$target/databases.sql.gz"
tar -C shared -czf "$target/uploads.tar.gz.partial" uploads master-uploads
tar -tzf "$target/uploads.tar.gz.partial" >/dev/null
mv "$target/uploads.tar.gz.partial" "$target/uploads.tar.gz"
(cd "$target"; sha256sum databases.sql.gz uploads.tar.gz > SHA256SUMS; sha256sum -c SHA256SUMS >/dev/null)
cp runtime.env "$target/release.env"
printf '%s\n' "$base/$target"
