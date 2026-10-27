#!/bin/bash
#
# Description: Runs a complete geo build: download, addresses, postcode areas, tiles, map, publish.
#
# Requirements:
#   - the geo-build image (osmium, Valhalla tools, pmtiles, curl, jq, flock)
#
# Variables (with defaults):
#   DELI_GEO_SOURCE_URL       OSM extract (default: https://download.geofabrik.de/europe/germany-latest.osm.pbf)
#   DELI_GEO_DATA_DIRECTORY   Directory with cache/, work/ and builds/ (default: /data)
#   DELI_GEO_REGIONS          Comma-separated regions for tiles and map (default: berlin)
#   DELI_GEO_BUILD_ID         Build id, a UTC date (default: today)
#   DELI_GEO_BUILD_JOBS       Parallel jobs for the tile build (default: 2)
#   DELI_GEO_KEEP_BUILDS      Number of builds to keep (default: 6)
#   DELI_GEO_BASEMAP_URL      Protomaps build to cut the map from (default: newest daily build of the last seven days)
#

set -euo pipefail
set -o errtrace
IFS=$'\n\t'

SCRIPT_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
readonly STEPS=(download addresses postcode_areas tiles map publish)
# shellcheck source=common.sh
source "${SCRIPT_DIRECTORY}/common.sh"
trap 'log_error "Failed in ${BASH_SOURCE[0]} at line ${LINENO}"' ERR

export DELI_GEO_SOURCE_URL="${DELI_GEO_SOURCE_URL:-https://download.geofabrik.de/europe/germany-latest.osm.pbf}"
export DELI_GEO_DATA_DIRECTORY="${DELI_GEO_DATA_DIRECTORY:-/data}"
export DELI_GEO_REGIONS="${DELI_GEO_REGIONS:-berlin}"
export DELI_GEO_BUILD_ID="${DELI_GEO_BUILD_ID:-$(date --utc +%F)}"
export DELI_GEO_BUILD_JOBS="${DELI_GEO_BUILD_JOBS:-2}"
export DELI_GEO_KEEP_BUILDS="${DELI_GEO_KEEP_BUILDS:-6}"
export DELI_GEO_BASEMAP_URL="${DELI_GEO_BASEMAP_URL:-}"

main() {
    if [[ "${1:-}" == "--help" ]]; then
        show_help "${BASH_SOURCE[0]}"
        return 0
    fi

    require_command flock
    require_build_id "${DELI_GEO_BUILD_ID}"

    mkdir -p "${DELI_GEO_DATA_DIRECTORY}"
    local lock_descriptor
    exec {lock_descriptor}>"${DELI_GEO_DATA_DIRECTORY}/.build.lock"
    if ! flock --nonblock "${lock_descriptor}"; then
        log_error "Another geo build is running in ${DELI_GEO_DATA_DIRECTORY}"
        exit 1
    fi

    if [[ -e "${DELI_GEO_DATA_DIRECTORY}/builds/${DELI_GEO_BUILD_ID}" ]]; then
        log_error "Build ${DELI_GEO_BUILD_ID} already exists; set DELI_GEO_BUILD_ID to build again"
        exit 1
    fi

    local work_directory="${DELI_GEO_DATA_DIRECTORY}/work"
    rm -rf "${work_directory}"
    mkdir -p "${work_directory}" "${DELI_GEO_DATA_DIRECTORY}/builds"

    local step
    for step in "${STEPS[@]}"; do
        local started_at="${SECONDS}"
        log_info "Step ${step}"
        "${SCRIPT_DIRECTORY}/${step}.sh"
        log_info "Step ${step} took $((SECONDS - started_at)) s"
    done

    rm -rf "${work_directory}"
    log_info "Build ${DELI_GEO_BUILD_ID} is ready in ${DELI_GEO_DATA_DIRECTORY}/builds/${DELI_GEO_BUILD_ID}"
}

main "$@"
