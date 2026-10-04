#!/usr/bin/env bash
set -euo pipefail
umask 077
base=/opt/schoolmng
sha=${1:?Commit required}
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || exit 2
exec 9>/run/lock/schoolmng-deploy.lock
flock -n 9 || { echo 'Another deployment is running' >&2; exit 3; }
cd "$base"
archive=$(mktemp "$base/.incoming.XXXXXXXX.tar.gz")
previous_env=$(mktemp "$base/.previous.XXXXXXXX.env")
cp runtime.env "$previous_env"
stopped=0
committed=0
dc() { docker compose --env-file runtime.env -f compose.yml "$@"; }
cleanup() {
  local status=$?
  rm -f "$archive"
  if [[ "$stopped" == 1 && "$committed" == 0 ]]; then
    cp "$previous_env" runtime.env
    dc up -d main tawhaa demo worker provisioner gateway || true
    echo 'Previous code restored; additive database migrations were not rolled back.' >&2
  fi
  rm -f "$previous_env"
  exit "$status"
}
trap cleanup EXIT
head -c 41943041 > "$archive"
[[ "$(stat -c %s "$archive")" -le 41943040 ]] || exit 4
release="$base/releases/$sha"
archive_hash=$(sha256sum "$archive" | cut -d' ' -f1)
if [[ -d "$release" ]]; then
  [[ ! -L "$release" && -f "$release/.archive-sha256" && "$(cat "$release/.archive-sha256")" == "$archive_hash" ]] || { echo 'Existing release content does not match the submitted archive' >&2; exit 5; }
  if [[ -f "$base/shared/deployed-commit" && "$(cat "$base/shared/deployed-commit")" == "$sha" ]]; then echo "Commit $sha is already deployed."; exit 0; fi
else
  python3 "$base/bin/validate_archive.py" "$archive" "$release"
  printf '%s\n' "$archive_hash" > "$release/.archive-sha256"
fi
test -f "$release/config/runtime.php"
docker build -f "$release/deployment/Dockerfile" -t "schoolmng-app:$sha" "$release"
docker run --rm --network none --read-only --tmpfs /tmp --entrypoint sh "schoolmng-app:$sha" -c 'find /var/www/html -name "*.php" -print0 | xargs -0 -n1 php -l >/tmp/lint; ! grep -E "Parse error|Fatal error|Errors parsing" /tmp/lint'
dc stop main tawhaa provisioner
stopped=1
ERP_BACKUP_ALREADY_STOPPED=1 "$base/bin/backup.sh"
sed -i "s/^ERP_IMAGE=.*/ERP_IMAGE=schoolmng-app:$sha/" runtime.env
dc run --rm -T provisioner php cron/migrate_production.php
dc up -d main tawhaa demo worker provisioner gateway
healthy=0
for attempt in $(seq 1 45); do
  if curl -fsS --max-time 5 -H 'Host: schoolmng.techteamlead.com' http://127.0.0.1:18083/health.php >/dev/null &&
     curl -fsS --max-time 5 -H 'Host: demo.schoolmng.techteamlead.com' http://127.0.0.1:18083/health.php >/dev/null &&
     curl -fsS --max-time 5 -H 'Host: tawhaa.schoolmng.techteamlead.com' http://127.0.0.1:18083/ >/dev/null; then healthy=1; break; fi
  sleep 2
done
[[ "$healthy" == 1 ]] || { echo 'Candidate health failed' >&2; exit 6; }
ln -sfn "$release" "$base/current.next"
mv -Tf "$base/current.next" "$base/current"
printf '%s\n' "$sha" > "$base/shared/deployed-commit"
committed=1
echo "Deployed $sha with persistent school data retained."
