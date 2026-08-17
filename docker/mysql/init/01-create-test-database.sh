#!/bin/bash
set -e

# Runs only on a container's FIRST start (empty data directory).
#
# The MySQL entrypoint grants the app user rights on $MYSQL_DATABASE only, but
# PHPUnit runs against a separate "<database>_test" schema (doctrine.yaml sets
# dbname_suffix under when@test). Without this the app user gets error 1044
# when the test suite tries to touch it.
mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_test\`
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_test\`.* TO '${MYSQL_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
