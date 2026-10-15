#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Disposable Nextcloud instance for the integration suite (.github/workflows/integration.yml).
# It starts containers, so it refuses to run anywhere but a GitHub Actions runner.
#
# Usage: harness.sh <packages|tools|start-db|start-nextcloud|install-app|fixtures|provision|report|test|collect>
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
# Real mounts of the exclusion proof (issue #4, A4): the groupfolder and the three local external storages the
# tests rely on (tests/Integration/Exclusion/Mounts.php). Both external aliases point at the same directory; the
# narrow one at a subdirectory of it.
GF_NAME=mcp-it-gf
EXT_ROOT=/mcp-it-ext
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
    build_package "$REPO_ROOT/mcp" "$WORK/mcp-branch.tar.gz"
    if [ "$MCP_IT_MODE" = upgrade ]; then
        git -C "$REPO_ROOT" worktree add --detach "$WORK/baseline" "$MCP_IT_BASELINE"
        build_package "$WORK/baseline/mcp" "$WORK/mcp-baseline.tar.gz"
    fi
}

# build_package <app dir> <destination>: the script's last output line is the archive path. Its output goes through
# a file, not a pipe, so a failing package.sh fails the step.
build_package() {
    (cd "$1" && sh scripts/package.sh) > "$WORK/package.out"
    cat "$WORK/package.out"
    archive=$(tail -n 1 "$WORK/package.out")
    cp "$1/$archive" "$2"
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
    # Only on this throwaway instance: the default password_policy app would check the fixture passwords against
    # the external HIBP service, which makes this step depend on the network. Nothing in the suite relies on it.
    enabled=$(occ app:list --enabled)
    case "$enabled" in
        *"- password_policy:"*) occ app:disable password_policy ;;
    esac
    password=$(openssl rand -hex 24)
    for uid in mcp-it-alice mcp-it-bob; do
        docker exec -u www-data -e OC_PASS="$password" "$NC" php /var/www/html/occ user:add --password-from-env "$uid"
    done
    occ group:add mcp-it-staff
    occ group:add mcp-it-empty
    occ group:adduser mcp-it-staff mcp-it-alice
}

# Downloads a release archive from the project's release repository, checks it against the pinned checksum and
# unpacks it into custom_apps, then enables the app through the server. The archive is read inside the container
# from the read-only tools mount, so nothing else is copied.
install_release_app() {
    app=$1
    repo=$2
    tag=$3
    sha=$4
    archive="$TOOLS_DIR/$app.tar.gz"
    curl -fsSL -o "$archive" "https://github.com/nextcloud-releases/$repo/releases/download/$tag/$app-$tag.tar.gz"
    echo "$sha  $archive" | sha256sum -c -
    docker exec -u www-data "$NC" tar -xzf "/mcp-it-tools/$app.tar.gz" -C /var/www/html/custom_apps
    occ app:enable "$app"
}

# Release pinned per Nextcloud major: groupfolders, Talk and Notes each have one build per supported major.
pin_releases() {
    case "$MCP_IT_NC" in
        32)
            GF_TAG=v20.1.19; GF_SHA=e59e6a11a50f60bf3bd23c243b7ec4b01af371c89e4ad65d4ec7e788058f50ff
            TALK_TAG=v22.0.19; TALK_SHA=c053dbce9c2a4a76c42e937d4d095502ba48a38c2e6fa02d46c36762573d6bff
            NOTES_TAG=v5.0.2; NOTES_SHA=1bf9e97a92bae5901a17c93666c4ac0f36ddfe68c69caa5d2368b18be6af7fdc
            ;;
        33)
            GF_TAG=v21.0.15; GF_SHA=1ecdd644a2e913de8b333560717660f0ba75836309c4463af858e886cdc3a68c
            TALK_TAG=v23.0.12; TALK_SHA=a907070cb8c0eb69a0ef808f62ea6eea822365c97a1c0e6f4a86aa748eda6c79
            NOTES_TAG=v6.0.2; NOTES_SHA=da362fb5a574b45cc0e10f4ea7f81547cc3dd10bf916f9d9b590358c1ed8e101
            ;;
        34)
            GF_TAG=v22.0.6; GF_SHA=bfff357b12bbd24257d8d127cf30e83a72256e7659f1cc3e32bd09fe647d2f9b
            TALK_TAG=v24.0.6; TALK_SHA=4a8d5cf52ed42223ea9937860f70c2e9dca1df8bfd5f75b871ae7569d2106110
            NOTES_TAG=v6.0.2; NOTES_SHA=da362fb5a574b45cc0e10f4ea7f81547cc3dd10bf916f9d9b590358c1ed8e101
            ;;
        35)
            GF_TAG=v23.0.1; GF_SHA=3949e6a45947188090c170c6983aae11a1d66134ec317ffe7b7609062f3aecf3
            TALK_TAG=v25.0.6; TALK_SHA=2b3e3eae42d9bf8ba8da2ab244d16347a3cfacbb8bff346bd99cf32cd6f9c65e
            NOTES_TAG=v6.0.2; NOTES_SHA=da362fb5a574b45cc0e10f4ea7f81547cc3dd10bf916f9d9b590358c1ed8e101
            ;;
        *)
            echo "No pinned releases for Nextcloud $MCP_IT_NC" >&2
            return 1
            ;;
    esac
}

provision_groupfolders() {
    install_release_app groupfolders groupfolders "$GF_TAG" "$GF_SHA"
    # The last line of the command is the new folder's id.
    folder_id=$(occ groupfolders:create "$GF_NAME" | tail -n 1)
    case "$folder_id" in
        ''|*[!0-9]*) echo "groupfolders:create gave no folder id: '$folder_id'" >&2; return 1 ;;
    esac
    occ groupfolders:group "$folder_id" mcp-it-staff read write share delete
    occ groupfolders:list
}

# One global mount per name, applicable to both fixture users. "alias" reaches the same directory as "wide"; "narrow"
# a subdirectory of it, which Nextcloud treats as a separate storage.
provision_files_external() {
    occ app:enable files_external
    occ config:system:set files_external_allow_create_new_local --value=true --type=boolean
    docker exec -u root "$NC" sh -c "mkdir -p '$EXT_ROOT/Open' && chown -R www-data:www-data '$EXT_ROOT' && chmod -R 0770 '$EXT_ROOT'"
    for spec in "wide:$EXT_ROOT" "alias:$EXT_ROOT" "narrow:$EXT_ROOT/Open"; do
        name=${spec%%:*}
        dir=${spec#*:}
        mount_id=$(occ files_external:create --output=json "/mcp-it-ext-$name" local null::null -c "datadir=$dir" | tail -n 1)
        case "$mount_id" in
            ''|*[!0-9]*) echo "files_external:create gave no mount id for $name: '$mount_id'" >&2; return 1 ;;
        esac
        occ files_external:applicable "$mount_id" --add-user=mcp-it-alice --add-user=mcp-it-bob
        occ files_external:option "$mount_id" filesystem_check_changes 1
    done
    occ files_external:list
}

# Each part is attempted on its own and a failure is recorded, never fatal here: the suite then fails by name at
# the scenario that needs the part (Exclusion/ProvisioningTest), with this log next to it in the uploaded artifact.
cmd_provision() {
    pin_releases
    # Core keeps the app config in the local cache (APCu) of the web server and invalidates it only for writes made
    # by that same cache. The suite writes the app's settings from the PHP CLI and reads them in the web server's
    # requests, so without this the server would keep serving a stale value (for example an exclusion list the test
    # just emptied). Runner-only: the production path, a single web pool that writes and reads, is unaffected.
    occ config:system:set cache_app_config --value=false --type=boolean
    log="$ARTIFACTS/provision.log"
    : > "$log"
    : > "$WORK/provision-failures"
    for part in groupfolders files_external talk notes; do
        echo "=== provisioning $part" | tee -a "$log"
        case "$part" in
            groupfolders) step=provision_groupfolders ;;
            files_external) step=provision_files_external ;;
            talk) step="install_release_app spreed spreed $TALK_TAG $TALK_SHA" ;;
            notes) step="install_release_app notes notes $NOTES_TAG $NOTES_SHA" ;;
        esac
        # Not inside an if or ||: there errexit would be ignored in the subshell and a failing command would not
        # fail the part.
        set +e
        (set -e; $step) >> "$log" 2>&1
        status=$?
        set -e
        if [ "$status" -eq 0 ]; then
            echo "$part: provisioned" | tee -a "$log"
        else
            echo "$part: FAILED (exit $status)" | tee -a "$log"
            echo "$part" >> "$WORK/provision-failures"
        fi
    done
    occ app:list --enabled >> "$log" 2>&1 || true
    cat "$log"
}

db_version() {
    case "$MCP_IT_DB" in
        mariadb) docker exec "$DB" mariadb -h 127.0.0.1 -u root -p"$DB_ROOT_PASSWORD" -N -e 'SELECT VERSION()' ;;
        pgsql) docker exec "$DB" psql -U nextcloud -d nextcloud -tAc 'SELECT version()' ;;
        sqlite) docker exec "$NC" php -r 'echo "SQLite ", SQLite3::version()["versionString"], PHP_EOL;' ;;
    esac
}

image_digests() {
    docker image inspect --format '{{join .RepoDigests ", "}}' "$1"
}

# Records what actually ran: image digests, PHP, Nextcloud and database versions, app version. Each value is
# captured by its own assignment, so a failing command fails the step (set -e) before anything is published,
# instead of leaving a green step with a partial summary. The report is also kept with the uploaded logs.
cmd_report() {
    nc_digests=$(image_digests "$MCP_IT_NC_IMAGE")
    db_digests=none
    if [ -n "${MCP_IT_DB_IMAGE:-}" ]; then
        db_digests=$(image_digests "$MCP_IT_DB_IMAGE")
    fi
    database=$(db_version)
    php_version=$(docker exec "$NC" php -v)
    status=$(occ status)
    installed=$(occ config:app:get mcp installed_version)
    expected=$(cat "$WORK/expected-version")
    fence='```'
    report="$ARTIFACTS/runtime.md"
    cat > "$report" <<REPORT
### NC $MCP_IT_NC · $MCP_IT_DB · $MCP_IT_MODE
$fence
Nextcloud image: $nc_digests
Database image: $db_digests
Database: $database
$php_version
$status
mcp installed_version: $installed (expected $expected)
$fence
REPORT
    cat "$report"
    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        cat "$report" >> "$GITHUB_STEP_SUMMARY"
    fi
}

cmd_test() {
    expected=$(cat "$WORK/expected-version")
    set -- -e NEXTCLOUD_ROOT=/var/www/html -e MCP_IT_NC="$MCP_IT_NC" -e MCP_IT_DB="$MCP_IT_DB" \
        -e MCP_IT_MODE="$MCP_IT_MODE" -e MCP_IT_EXPECTED_VERSION="$expected" \
        -e MCP_IT_PROVISION_FAILURES="$(tr '\n' ' ' < "$WORK/provision-failures" 2>/dev/null || true)" \
        -e MCP_IT_GF_NAME="$GF_NAME"
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
    docker cp "$NC:/tmp/mcp-it-observations.txt" "$ARTIFACTS/observations.txt" 2>/dev/null || true
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
    provision) cmd_provision ;;
    report) cmd_report ;;
    test) cmd_test ;;
    collect) cmd_collect ;;
    *)
        echo "Usage: $0 <packages|tools|start-db|start-nextcloud|install-app|fixtures|provision|report|test|collect>" >&2
        exit 1
        ;;
esac
