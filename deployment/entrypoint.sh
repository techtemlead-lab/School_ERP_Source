#!/usr/bin/env bash
set -euo pipefail
if [[ "$1" == apache2-foreground ]]; then
  install -d -m 700 -o 33 -g 33 /var/lib/php/sessions
  install -d -m 750 -o 33 -g 33 /var/www/html/uploads
  cp /opt/erp/uploads.htaccess /var/www/html/uploads/.htaccess
  # আগে root/অন্য ইউজারে তৈরি সাবফোল্ডার থাকলে www-data যেন লিখতে পারে
  find /var/www/html/uploads -xdev ! -user 33 -exec chown 33:33 {} + 2>/dev/null || true
  if [[ "${ERP_ROLE:-main}" == main || "${ERP_ROLE:-main}" == tenant ]]; then
    install -d -m 750 -o 33 -g 33 /var/www/html/logs
    install -d -m 700 -o 33 -g 33 "${ERP_CACHE_DIR:-/tmp/school-erp-cache}"
  fi
  if [[ "${ERP_ROLE:-main}" == main ]]; then
    install -d -m 750 -o 33 -g 33 /var/www/html/master/assets/uploads /var/www/html/logs
    cp /opt/erp/uploads.htaccess /var/www/html/master/assets/uploads/.htaccess
    install -d -m 700 -o 33 -g 33 "${ERP_CACHE_DIR:-/tmp/school-erp-cache}"
  fi
fi
exec docker-php-entrypoint "$@"
