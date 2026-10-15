#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Disposable Nextcloud instance for the integration suite (.github/workflows/integration.yml).
# It starts containers, so it refuses to run anywhere but a GitHub Actions runner.
#
# Usage: harness.sh <packages|tools|start-db|start-nextcloud|install-app|fixtures|report|test|collect>
# Cell, from the workflow matrix:
#   MCP_IT_NC          Nextcloud major (32..35)
#   MCP_IT_NC_IMAGE    official Nextcloud Apache image, pinned as tag@digest
#   MCP_IT_DB          mariadb | pgsql | sqlite
#   MCP_IT_DB_IMAGE    database image, pinned as tag@digest (empty for sqlite)
#   MCP_IT_MODE        fresh (install the branch) | upgrade (install the baseline release, then upgrade to the branch)
#   MCP_IT_BASELINE    git tag of the released baseline for the upgrade mode
set -eu

if [ "${GITHUB_ACTIONS:-}" != "true" ] || [ -z "${RUNNER_TEMP:-}" ]; then
    echo "harness.sh starts Nextcloud and database containers; it runs only on a GitHub Actions runner." >&2
    exit 1
fi

REPO_ROOT=$(cd "$(dirname "$0")/../../../.." && pwd)
WORK="$RUNNER_TEMP/mcp-it"
TOOLS_DIR="$WORK/tools"
PG_LOG_DIR="$WORK/pglog"
ARTIFACTS="$WORK/artifacts"
NET=mcp-it
NC=mcp-it-nextcloud
DB=mcp-it-db
# Throwaway credentials of a throwaway instance; nothing here is a secret.
DB_PASSWORD=mcp-it-db-password
DB_ROOT_PASSWORD=mcp-it-db-root-password
ADMIN_PASSWORD=mcp-it-admin-password
PHPUNIT_VERSION=10.5.65
PHPUNIT_SHA256=ce85745a2ec7d8e536621ebed69dfa36a8d504daf73be6750e93bb8c5b088835

mkdir -p "$WORK" "$TOOLS_DIR" "$ARTIFACTS"

occ() {
    docker exec -u www-data "$NC" php /var/www/html/occ "$@"
}

# wait_for <label> <seconds> <command...>: retries the command every 2 s until it succeeds.
wait_for() {
    label=$1
    deadline=$(($(date +%s) + $2))
    shift 2
    until "$@" >/dev/null 2>&1; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            echo "Timed out waiting for $label" >&2
            return 1
        fi
        sleep 2
    done
    echo "$label is ready"
}

info_version() {
    sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$1"
}

# Builds the branch package, and the baseline package for the upgrade mode, each with its own official script.
cmd_packages() {
    archive=$(cd "$REPO_ROOT/mcp" && sh scripts/package.sh | tail -n 1)
    cp "$REPO_ROOT/mcp/$archive" "$WORK/mcp-branch.tar.gz"
    if [ "$MCP_IT_MODE" = upgrade ]; then
        git -C "$REPO_ROOT" worktree add --detach "$WORK/baseline" "$MCP_IT_BASELINE"
        archive=$(cd "$WORK/baseline/mcp" && sh scripts/package.sh | tail -n 1)
        cp "$WORK/baseline/mcp/$archive" "$WORK/mcp-baseline.tar.gz"
    fi
}

# PHPUnit runs inside the Nextcloud container as a PHAR, so none of its dependencies shadow the server's own.
cmd_tools() {
    curl -fsSL -o "$TOOLS_DIR/phpunit.phar" "https://phar.phpunit.de/phpunit-$PHPUNIT_VERSION.phar"
    echo "$PHPUNIT_SHA256  $TOOLS_DIR/phpunit.phar" | sha256sum -c -
}

cmd_start_db() {
    docker network create "$NET" >/dev/null
    case "$MCP_IT_DB" in
        mariadb)
            docker run -d --name "$DB" --network "$NET" \
                -e MARIADB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" -e MARIADB_DATABASE=nextcloud \
                -e MARIADB_USER=nextcloud -e MARIADB_PASSWORD="$DB_PASSWORD" \
                "$MCP_IT_DB_IMAGE" --transaction-isolation=READ-COMMITTED --log-bin=binlog --binlog-format=ROW >/dev/null
            # TCP only answers once the init phase (socket only) is over.
            wait_for MariaDB 180 docker exec "$DB" mariadb -h 127.0.0.1 -u nextcloud -p"$DB_PASSWORD" -e 'SELECT 1' nextcloud
            ;;
        pgsql)
            # Every statement of every session goes to a file the Nextcloud container can read, with the backend
            # pid in each line, so a test can attribute statements to the caller's session (contracts §3.3.6, F5).
            mkdir -p "$PG_LOG_DIR"
            chmod 0777 "$PG_LOG_DIR"
            docker run -d --name "$DB" --network "$NET" \
                -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud -e POSTGRES_PASSWORD="$DB_PASSWORD" \
                -v "$PG_LOG_DIR:/var/log/mcp-it-pg" \
                "$MCP_IT_DB_IMAGE" \
                -c logging_collector=on -c log_directory=/var/log/mcp-it-pg -c log_filename=postgresql.log \
                -c log_file_mode=0644 -c log_rotation_age=0 -c log_rotation_size=0 \
                -c log_statement=all -c log_min_error_statement=error \
                -c 'log_line_prefix=%m mcp-it[%p] xid=%x ' >/dev/null
            wait_for PostgreSQL 180 docker exec "$DB" pg_isready -h 127.0.0.1 -U nextcloud -d nextcloud
            ;;
        sqlite)
            ;;
        *)
            echo "Unknown database: $MCP_IT_DB" >&2
            exit 1
            ;;
    esac
}

nextcloud_ready() {
    occ status --output=json | grep -q '"installed":true' \
        && docker exec "$NC" php -r 'exit(@file_get_contents("http://localhost/status.php") === false ? 1 : 0);'
}

# The official image installs Nextcloud itself from these variables (docker-entrypoint.sh) and then starts Apache.
cmd_start_nextcloud() {
    set -- -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASSWORD"
    case "$MCP_IT_DB" in
        mariadb)
            set -- "$@" -e MYSQL_HOST="$DB" -e MYSQL_DATABASE=nextcloud -e MYSQL_USER=nextcloud -e MYSQL_PASSWORD="$DB_PASSWORD"
            ;;
        pgsql)
            set -- "$@" -e POSTGRES_HOST="$DB" -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud \
                -e POSTGRES_PASSWORD="$DB_PASSWORD" -v "$PG_LOG_DIR:/mcp-it-pglog:ro"
            ;;
        sqlite)
            # One database file under the data directory; both test connections open this same file.
            set -- "$@" -e SQLITE_DATABASE=nextcloud
            ;;
    esac
    docker run -d --name "$NC" --network "$NET" "$@" \
        -v "$REPO_ROOT:/mcp-it-src:ro" -v "$TOOLS_DIR:/mcp-it-tools:ro" \
        "$MCP_IT_NC_IMAGE" >/dev/null
    if ! wait_for "Nextcloud $MCP_IT_NC" 600 nextcloud_ready; then
        docker logs "$NC" >&2 || true
        exit 1
    fi
}

assert_installed_version() {
    actual=$(occ config:app:get mcp installed_version)
    if [ "$actual" != "$1" ]; then
        echo "Expected mcp $1 to be installed, found '$actual'" >&2
        exit 1
    fi
    occ app:list --enabled | grep -q -- '- mcp:' || { echo "mcp is not enabled" >&2; exit 1; }
    echo "mcp $actual is installed and enabled"
}

# Installs through the server's own installer (app:enable runs the app's migrations); upgrade goes through occ upgrade.
cmd_install_app() {
    if [ "$MCP_IT_MODE" = upgrade ]; then
        mkdir -p "$WORK/baseline-pkg"
        tar -xzf "$WORK/mcp-baseline.tar.gz" -C "$WORK/baseline-pkg"
        baseline_version=$(info_version "$WORK/baseline-pkg/mcp/appinfo/info.xml")
        docker cp "$WORK/mcp-baseline.tar.gz" "$NC:/tmp/mcp-baseline.tar.gz"
        docker exec -u www-data "$NC" tar -xzf /tmp/mcp-baseline.tar.gz -C /var/www/html/custom_apps
        occ app:enable mcp
        assert_installed_version "$baseline_version"
        occ migrations:status mcp || true
    fi

    mkdir -p "$WORK/branch-pkg"
    tar -xzf "$WORK/mcp-branch.tar.gz" -C "$WORK/branch-pkg"
    expected=$(info_version "$WORK/branch-pkg/mcp/appinfo/info.xml")
    if [ "$MCP_IT_MODE" = upgrade ]; then
        # While the branch still carries the baseline version, the server would see nothing to upgrade and skip the
        # pending migrations. This runner-only copy gets a higher version; the package itself is never changed.
        if ! php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$expected" "$baseline_version"; then
            expected="$baseline_version.1"
            sed -i "s:<version>.*</version>:<version>$expected</version>:" "$WORK/branch-pkg/mcp/appinfo/info.xml"
            echo "Runner-only version bump of the branch copy to $expected so that occ upgrade runs its migrations"
        fi
        docker cp "$WORK/branch-pkg/mcp" "$NC:/tmp/mcp-next"
        docker exec "$NC" sh -c 'mv /var/www/html/custom_apps/mcp /tmp/mcp-previous \
            && mv /tmp/mcp-next /var/www/html/custom_apps/mcp && chown -R www-data:www-data /var/www/html/custom_apps/mcp'
        occ upgrade
    else
        docker cp "$WORK/mcp-branch.tar.gz" "$NC:/tmp/mcp-branch.tar.gz"
        docker exec -u www-data "$NC" tar -xzf /tmp/mcp-branch.tar.gz -C /var/www/html/custom_apps
        occ app:enable mcp
    fi
    assert_installed_version "$expected"
    occ migrations:status mcp || true
    echo "$expected" > "$WORK/expected-version"
}

# Fixture accounts the integration tests rely on (tests/Integration/Fixtures.php).
cmd_fixtures() {
    password=$(openssl rand -hex 24)
    for uid in mcp-it-alice mcp-it-bob; do
        docker exec -u www-data -e OC_PASS="$password" "$NC" php /var/www/html/occ user:add --password-from-env "$uid"
    done
    occ group:add mcp-it-staff
    occ group:add mcp-it-empty
    occ group:adduser mcp-it-staff mcp-it-alice
}

db_version() {
    case "$MCP_IT_DB" in
        mariadb) docker exec "$DB" mariadb -h 127.0.0.1 -u root -p"$DB_ROOT_PASSWORD" -N -e 'SELECT VERSION()' ;;
        pgsql) docker exec "$DB" psql -U nextcloud -d nextcloud -tAc 'SELECT version()' ;;
        sqlite) docker exec "$NC" php -r 'echo "SQLite ", SQLite3::version()["versionString"], PHP_EOL;' ;;
    esac
}

# Records what actually ran: image digests, PHP, Nextcloud and database versions, app version.
cmd_report() {
    {
        echo "### NC $MCP_IT_NC · $MCP_IT_DB · $MCP_IT_MODE"
        echo '```'
        echo "Nextcloud image: $(docker image inspect --format '{{join .RepoDigests ", "}}' "$MCP_IT_NC_IMAGE")"
        if [ -n "${MCP_IT_DB_IMAGE:-}" ]; then
            echo "Database image: $(docker image inspect --format '{{join .RepoDigests ", "}}' "$MCP_IT_DB_IMAGE")"
        fi
        echo "Database: $(db_version)"
        docker exec "$NC" php -v
        occ status
        echo "mcp installed_version: $(occ config:app:get mcp installed_version)"
        echo '```'
    } | tee -a "${GITHUB_STEP_SUMMARY:-/dev/null}"
}

cmd_test() {
    set -- -e NEXTCLOUD_ROOT=/var/www/html -e MCP_IT_NC="$MCP_IT_NC" -e MCP_IT_DB="$MCP_IT_DB" \
        -e MCP_IT_MODE="$MCP_IT_MODE" -e MCP_IT_EXPECTED_VERSION="$(cat "$WORK/expected-version")"
    if [ "$MCP_IT_DB" = pgsql ]; then
        set -- "$@" -e MCP_IT_PG_LOG=/mcp-it-pglog/postgresql.log
    fi
    docker exec -u www-data "$@" "$NC" php -d memory_limit=1G /mcp-it-tools/phpunit.phar \
        -c /mcp-it-src/mcp/tests/Integration/phpunit.xml --log-junit /tmp/mcp-it-junit.xml
}

# Best effort: gathers logs for the uploaded artifact even when an earlier step failed.
cmd_collect() {
    docker logs "$NC" > "$ARTIFACTS/nextcloud-container.log" 2>&1 || true
    docker logs "$DB" > "$ARTIFACTS/database-container.log" 2>&1 || true
    docker cp "$NC:/var/www/html/data/nextcloud.log" "$ARTIFACTS/nextcloud.log" 2>/dev/null || true
    docker cp "$NC:/tmp/mcp-it-junit.xml" "$ARTIFACTS/junit.xml" 2>/dev/null || true
    if [ -f "$PG_LOG_DIR/postgresql.log" ]; then
        cp "$PG_LOG_DIR/postgresql.log" "$ARTIFACTS/postgresql.log"
    fi
}

case "${1:-}" in
    packages) cmd_packages ;;
    tools) cmd_tools ;;
    start-db) cmd_start_db ;;
    start-nextcloud) cmd_start_nextcloud ;;
    install-app) cmd_install_app ;;
    fixtures) cmd_fixtures ;;
    report) cmd_report ;;
    test) cmd_test ;;
    collect) cmd_collect ;;
    *)
        echo "Usage: $0 <packages|tools|start-db|start-nextcloud|install-app|fixtures|report|test|collect>" >&2
        exit 1
        ;;
esac
