#!/bin/bash
#
# Description: Points the Valhalla tiles link at a published build, recreates Valhalla and runs the smoke test; switches back on failure.
#
# Usage: activate_tiles.sh BUILDS_DIRECTORY BUILD_ID
#
# Requirements:
#   - docker with the compose plugin, curl, jq
#
# Variables (with defaults):
#   DELI_GEO_TILES_LINK         Symbolic link that Valhalla mounts at /data (required)
#   DELI_GEO_COMPOSE_FILE       Compose file that defines the Valhalla service (required)
#   DELI_GEO_VALHALLA_SERVICE   Name of the Valhalla service (default: valhalla)
#   DELI_GEO_SMOKE_REGION       Region whose smoke coordinates are used (default: berlin)
#

set -euo pipefail
set -o errtrace
IFS=$'\n\t'

SCRIPT_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
readonly SMOKE_SCRIPT="${SCRIPT_DIRECTORY}/../smoke/smoke.sh"
# shellcheck source=common.sh
source "${SCRIPT_DIRECTORY}/common.sh"
trap 'log_error "Failed in ${BASH_SOURCE[0]} at line ${LINENO}"' ERR

DELI_GEO_VALHALLA_SERVICE="${DELI_GEO_VALHALLA_SERVICE:-valhalla}"

point_link() {
    local target="$1"
    local temporary_link="${DELI_GEO_TILES_LINK}.new"
    ln -sfn "${target}" "${temporary_link}"
    mv -T "${temporary_link}" "${DELI_GEO_TILES_LINK}"
}

recreate_valhalla() {
    docker compose --file "${DELI_GEO_COMPOSE_FILE}" up --detach --force-recreate --wait "${DELI_GEO_VALHALLA_SERVICE}"
}

main() {
    if [[ "${1:-}" == "--help" ]]; then
        show_help "${BASH_SOURCE[0]}"
        return 0
    fi

    if [[ $# -ne 2 ]]; then
        log_error "Usage: activate_tiles.sh BUILDS_DIRECTORY BUILD_ID"
        exit 1
    fi

    : "${DELI_GEO_TILES_LINK:?DELI_GEO_TILES_LINK must be set}"
    : "${DELI_GEO_COMPOSE_FILE:?DELI_GEO_COMPOSE_FILE must be set}"
    require_command docker curl jq realpath
    require_build_id "$2"

    local tiles_directory
    tiles_directory="$(realpath "$1")/$2/valhalla"
    require_file "${tiles_directory}/valhalla_tiles.tar"
    require_file "${tiles_directory}/valhalla.json"

    local previous_target=""
    if [[ -L "${DELI_GEO_TILES_LINK}" ]]; then
        previous_target="$(readlink "${DELI_GEO_TILES_LINK}")"
    fi

    log_info "Activating tiles of build $2"
    point_link "${tiles_directory}"

    if recreate_valhalla && "${SMOKE_SCRIPT}"; then
        log_info "Activated tiles of build $2"
        return 0
    fi

    log_error "Activation of build $2 failed"
    if [[ -n "${previous_target}" ]]; then
        point_link "${previous_target}"
        if recreate_valhalla; then
            log_info "Switched back to ${previous_target}"
        else
            log_error "Valhalla did not start on ${previous_target} either"
        fi
    fi
    exit 1
}

main "$@"
