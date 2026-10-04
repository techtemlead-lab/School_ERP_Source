#!/usr/bin/env bash
set -euo pipefail
cd /opt/schoolmng
dc() { docker compose --env-file runtime.env -f compose.yml "$@"; }
status=0
for service in master-db main-db demo-db main tawhaa demo gateway worker provisioner; do
  id=$(dc ps -q "$service")
  state=$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null || echo missing)
  if [[ "$state" != healthy ]]; then
    logger -t schoolmng-monitor "$service state=$state"
    if [[ "$state" == unhealthy && ( "$service" == worker || "$service" == provisioner ) ]]; then dc restart "$service"; fi
    status=1
  fi
done
exit "$status"
