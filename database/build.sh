#!/usr/bin/env bash
# Regenerates database/tables/create_all.sql and database/tables/reinstall_all.sql from the
# per-object files. Edit the per-object files, then run:  bash database/build.sh
# CI runs:  bash database/build.sh --check   (fails if the generated files are stale)
set -euo pipefail
cd "$(dirname "$0")"

ORDER=(
    tables/auth.sql
    tables/user.sql
    tables/patient.sql
    tables/address.sql
    tables/user_patient.sql
    tables/visit.sql
    tables/tool.sql
    views/user_auth.sql
    views/ordered_visits.sql
)

header() {
    echo "-- GENERATED FILE - do not edit. Source: database/tables/*.sql, database/views/*.sql"
    echo "-- Regenerate with:  bash database/build.sh"
    echo "--"
    echo "-- $1"
    echo
}

build_create() {
    header "create_all.sql: creates every table and view (fails if they already exist)."
    for f in "${ORDER[@]}"; do
        echo "-- ---------------------------------------------------------------- $f"
        # strip the per-file DROP so a create-only script never destroys data
        grep -vE '^DROP (TABLE|VIEW) IF EXISTS' "$f"
        echo
    done
}

build_reinstall() {
    header "reinstall_all.sql: DROPS EVERYTHING and recreates it. All data is lost."
    cat tables/drop_all.sql
    echo
    for f in "${ORDER[@]}"; do
        echo "-- ---------------------------------------------------------------- $f"
        grep -vE '^DROP (TABLE|VIEW) IF EXISTS' "$f"
        echo
    done
}

if [ "${1:-}" = "--check" ]; then
    tmp=$(mktemp -d)
    build_create > "$tmp/create_all.sql"
    build_reinstall > "$tmp/reinstall_all.sql"
    status=0
    diff -u tables/create_all.sql "$tmp/create_all.sql" || { echo "create_all.sql is stale: run bash database/build.sh"; status=1; }
    diff -u tables/reinstall_all.sql "$tmp/reinstall_all.sql" || { echo "reinstall_all.sql is stale: run bash database/build.sh"; status=1; }
    rm -rf "$tmp"
    [ $status -eq 0 ] && echo "database/tables/*_all.sql are up to date"
    exit $status
fi

build_create > tables/create_all.sql
build_reinstall > tables/reinstall_all.sql
echo "generated tables/create_all.sql and tables/reinstall_all.sql"
