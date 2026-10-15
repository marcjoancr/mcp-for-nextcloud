#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Runs ONE cell of the integration matrix on a Mac, in disposable containers on Colima, through the same harness.sh the
# GitHub workflow runs. The container engine is started only for the run and is always stopped again, on success, on
# error and on INT/TERM: the test containers, their network and volumes are removed first, then `colima stop` runs, but
# only when this script is the one that started it (an engine that was already running is left running).
#
# Usage: run-local.sh <nc 32|33|34|35> <db mariadb|pgsql|sqlite> <mode fresh|upgrade> [--prune-images]
#   --prune-images  also removes the images this run pulled (default: kept, they are only files on disk)
#
# Logs and artifacts (run.log, resources.log, mcp-it/artifacts/: junit.xml, nextcloud.log, observations.txt,
# provision.log) go to mcp/tests/Integration/.local-runs/<cell>-<timestamp>/ (git-ignored). Image digests, the
# baseline tag and the cell come from .github/workflows/integration.yml, so there is one list of pins.
set -eu

usage() {
    echo "Usage: $0 <nc 32|33|34|35> <db mariadb|pgsql|sqlite> <mode fresh|upgrade> [--prune-images]" >&2
    exit 2
}

[ $# -ge 3 ] || usage
CELL_NC=$1
CELL_DB=$2
CELL_MODE=$3
PRUNE_IMAGES=0
[ "${4:-}" = "--prune-images" ] && PRUNE_IMAGES=1
case "$CELL_MODE" in fresh|upgrade) ;; *) usage ;; esac

HERE=$(cd "$(dirname "$0")" && pwd)
REPO_ROOT=$(cd "$HERE/../../../.." && pwd)
WORKFLOW="$REPO_ROOT/.github/workflows/integration.yml"
STAMP=$(date +%Y%m%d-%H%M%S)
LOCAL_DIR="$REPO_ROOT/mcp/tests/Integration/.local-runs/nc$CELL_NC-$CELL_DB-$CELL_MODE-$STAMP"
mkdir -p "$LOCAL_DIR"

# cell_field <field>: the value of the cell's field in the workflow matrix ('' for an empty value).
cell_field() {
    awk -v nc="$CELL_NC" -v db="$CELL_DB" -v field="$1" '
        $1 == "-" && $2 == "nc:" { cur = $3; gsub(/[^0-9]/, "", cur); curdb = "" }
        $1 == "db:" { curdb = $2 }
        cur == nc && curdb == db && $1 == field ":" { value = $2; gsub(/\047/, "", value); print value; exit }
    ' "$WORKFLOW"
}

NC_IMAGE=$(cell_field nc_image)
DB_IMAGE=$(cell_field db_image)
BASELINE=$(sed -n 's/^ *MCP_IT_BASELINE: *//p' "$WORKFLOW" | head -n 1)
if [ -z "$NC_IMAGE" ] || { [ "$CELL_DB" != sqlite ] && [ -z "$DB_IMAGE" ]; }; then
    echo "No cell NC $CELL_NC / $CELL_DB in $WORKFLOW" >&2
    exit 2
fi

export MCP_IT_LOCAL=1
export RUNNER_TEMP="$LOCAL_DIR"
export MCP_IT_NC="$CELL_NC" MCP_IT_NC_IMAGE="$NC_IMAGE" MCP_IT_DB="$CELL_DB" MCP_IT_DB_IMAGE="$DB_IMAGE"
export MCP_IT_MODE="$CELL_MODE" MCP_IT_BASELINE="$BASELINE"
HARNESS="$HERE/harness.sh"
WORK="$LOCAL_DIR/mcp-it"
STARTED_COLIMA=0
SAMPLER_PID=""
START_TIME=$(date +%s)

log() {
    echo "[run-local $(date +%H:%M:%S)] $*"
}

# Samples the memory the engine and the containers use, every 10 s, for the summary at the end.
sample_resources() {
    while :; do
        {
            echo "t=$(date +%s)"
            ps -axo rss=,command= | awk 'tolower($0) ~ /limactl|colima|virtualization|qemu/ { sum += $1 } END { printf "vm_rss_kb=%d\n", sum }'
            docker stats --no-stream --format 'container={{.Name}} mem={{.MemUsage}} cpu={{.CPUPerc}}' 2>/dev/null || true
        } >> "$LOCAL_DIR/resources.log"
        sleep 10
    done
}

cleanup() {
    status=$?
    trap - EXIT INT TERM
    set +e
    [ -n "$SAMPLER_PID" ] && kill "$SAMPLER_PID" 2>/dev/null
    if docker info >/dev/null 2>&1; then
        log "removing the test containers, network and volumes"
        docker rm -f -v mcp-it-nextcloud mcp-it-db >/dev/null 2>&1
        docker network rm mcp-it >/dev/null 2>&1
        if [ "$PRUNE_IMAGES" = 1 ]; then
            for image in "$NC_IMAGE" "$DB_IMAGE"; do
                [ -n "$image" ] && docker rmi "$image" >/dev/null 2>&1
            done
        fi
    fi
    if [ -d "$WORK/baseline" ]; then
        git -C "$REPO_ROOT" worktree remove --force "$WORK/baseline" >/dev/null 2>&1
        git -C "$REPO_ROOT" worktree prune
    fi
    # The downloads and packages of this run are rebuilt every time; only the logs and artifacts stay.
    rm -rf "$WORK/tools" "$WORK/branch-pkg" "$WORK/baseline-pkg" "$WORK/pglog" "$WORK"/*.tar.gz
    if [ "$STARTED_COLIMA" = 1 ]; then
        log "stopping Colima (this run started it)"
        colima stop
    else
        log "Colima was already running before this run; left as it was"
    fi
    summary
    exit "$status"
}

summary() {
    elapsed=$(($(date +%s) - START_TIME))
    peak_vm=$(awk -F= '/^vm_rss_kb=/ { if ($2 > max) max = $2 } END { printf "%d", max / 1024 }' "$LOCAL_DIR/resources.log" 2>/dev/null)
    log "elapsed ${elapsed}s; peak resident memory of the engine processes on the host ${peak_vm:-0} MB; logs in $LOCAL_DIR"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for tool in colima docker git php composer curl; do
    command -v "$tool" >/dev/null 2>&1 || { echo "$tool is required (brew install colima docker)" >&2; exit 2; }
done

if colima status >/dev/null 2>&1; then
    log "Colima is already running; this run will not stop it"
else
    log "starting Colima (4 CPUs, 8 GiB)"
    STARTED_COLIMA=1
    colima start --vm-type vz --mount-type virtiofs --cpu 4 --memory 8 --disk 40
fi
docker info >/dev/null

log "cell: NC $CELL_NC, $CELL_DB, $CELL_MODE (baseline $BASELINE)"
# Leftovers of an earlier interrupted run use the same names.
docker rm -f -v mcp-it-nextcloud mcp-it-db >/dev/null 2>&1 || true
docker network rm mcp-it >/dev/null 2>&1 || true

# The pins are digests of the image index when they are multi-architecture; record what this machine actually got.
for image in "$NC_IMAGE" "$DB_IMAGE"; do
    [ -n "$image" ] || continue
    docker pull --quiet "$image" >/dev/null
    log "image $image -> $(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image")"
done

sample_resources &
SAMPLER_PID=$!

failed=0
for step in packages tools start-db start-nextcloud install-app fixtures provision report; do
    # The provisioning of groupfolders, external storage, Talk and Notes exists only on the lines that prove the
    # exclusion (issue #4); the harness of this line has no such step.
    if [ "$step" = provision ] && ! grep -q '^    provision)' "$HARNESS"; then
        continue
    fi
    log "step: $step"
    if ! sh "$HARNESS" "$step"; then
        log "step $step FAILED"
        failed=1
        break
    fi
done

test_status=0
if [ "$failed" = 0 ]; then
    log "step: test"
    sh "$HARNESS" test || test_status=$?
fi
log "step: collect"
sh "$HARNESS" collect || true

if [ "$failed" != 0 ]; then
    exit 1
fi
exit "$test_status"
