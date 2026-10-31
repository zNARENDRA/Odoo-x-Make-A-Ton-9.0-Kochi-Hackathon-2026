#!/bin/bash
set -euo pipefail

: "${DATABASE_URL:?Antideploy PostgreSQL connection is missing}"
: "${APP_KEY:?Set a persistent Laravel application key}"
: "${APP_URL:?Set the public HTTPS address}"
if [[ "${PGHOST:-}" == *-pooler.*.neon.tech ]]; then
    export DB_DIRECT_HOST="${PGHOST/-pooler./.}"
fi
if [[ "${MAIL_MAILER}" == resend ]]; then
    : "${RESEND_API_KEY:?Antideploy email is missing}"
    : "${RESEND_BASE_URL:?Antideploy email relay is missing}"
    : "${EMAIL_FROM:?Antideploy sender is missing}"
    sender="${EMAIL_FROM##*<}"
    export MAIL_FROM_ADDRESS="${sender%>}"
fi

php artisan package:discover --no-interaction
php artisan config:cache --no-interaction
php artisan migrate --force --no-interaction

apache2-foreground &
php artisan queue:work database --sleep=5 --tries=3 --timeout=60 --no-interaction &
php artisan schedule:work --no-interaction &

# Let the platform restart the container if any of its services stops.
wait -n
exit 1
