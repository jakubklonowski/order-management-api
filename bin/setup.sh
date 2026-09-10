#!/usr/bin/env bash
#
# Brings a fresh clone up to a working API. Safe to re-run.
#
# Usage: ./bin/setup.sh [admin-email]

set -euo pipefail

cd "$(dirname "$0")/.."

ADMIN_EMAIL="${1:-admin@example.com}"

step() {
    printf '\n==> %s\n' "$1"
}

step 'Starting containers'
# compose waits for the database healthcheck before starting php,
# because php declares depends_on: condition: service_healthy
docker compose up -d

step 'Installing dependencies'
# the bind mount hides whatever vendor/ the image built, so this is not optional
docker compose exec -T php composer install

step 'Migrating the dev database'
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction

step 'Migrating the test database'
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction --env=test

step 'Generating the JWT keypair'
docker compose exec -T php php bin/console lexik:jwt:generate-keypair --skip-if-exists

step "Creating admin account ${ADMIN_EMAIL}"
# validated with the same rules as POST /api/register
printf 'The password must be at least 8 characters.\n\n'

# no -T flag - the command prompts twice for a hidden password, which needs a tty.
if docker compose exec php php bin/console app:create-admin "${ADMIN_EMAIL}"; then
    admin_created=yes
else
    admin_created=no
fi

NGINX_PORT="$(grep -E '^NGINX_PORT=' .env | cut -d= -f2)"
ADMINER_PORT="$(grep -E '^ADMINER_PORT=' .env | cut -d= -f2)"

printf '\nThe API is at http://localhost:%s\n\n' "${NGINX_PORT:-8080}"
printf '  Run the tests:  docker compose exec php php bin/phpunit\n'
printf '  Browse the DB:  http://localhost:%s\n' "${ADMINER_PORT:-8081}"

if [ "${admin_created}" = no ]; then
    cat <<EOF

The admin account was not created. Read the error above:

  "already exists"  - nothing to do, setup is complete
  anything else     - everything else is ready, finish with:

  docker compose exec php php bin/console app:create-admin ${ADMIN_EMAIL}
EOF
    exit 0
fi

printf '\nDone.\n'
