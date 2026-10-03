#!/usr/bin/env bash
set -euo pipefail
if [[ "${SSH_ORIGINAL_COMMAND:-}" =~ ^deploy\ ([a-f0-9]{40})$ ]]; then
  exec sudo -n /opt/schoolmng/bin/deploy.sh "${BASH_REMATCH[1]}"
fi
echo 'Only deploy COMMIT_SHA is allowed.' >&2
exit 2
