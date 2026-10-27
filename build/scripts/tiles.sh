#!/bin/bash
#
# Description: Builds Valhalla routing tiles for the configured regions and a runtime configuration.
#
# Requirements:
#   - osmium, valhalla_build_config, valhalla_build_admins, valhalla_build_tiles, valhalla_build_extract, jq
#
# Variables (with defaults):
#   DELI_GEO_DATA_DIRECTORY   Directory with cache/, work/ and builds/ (default: /data)
#   DELI_GEO_REGIONS          Comma-separated regions from build/regions (default: berlin)
#   DELI_GEO_BUILD_JOBS       Parallel jobs for the tile build (default: 2)
#

set -euo pipefail
set -o errtrace
IFS=$'\n\t'

SCRIPT_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
readonly REGIONS_DIRECTORY="${SCRIPT_DIRECTORY}/../regions"
readonly MAXIMUM_MATRIX_LOCATION_PAIRS=40000
readonly MAXIMUM_ROUTE_LOCATIONS=200
# shellcheck source=common.sh
source "${SCRIPT_DIRECTORY}/common.sh"
trap 'log_error "Failed in ${BASH_SOURCE[0]} at line ${LINENO}"' ERR

DELI_GEO_DATA_DIRECTORY="${DELI_GEO_DATA_DIRECTORY:-/data}"
DELI_GEO_REGIONS="${DELI_GEO_REGIONS:-berlin}"
DELI_GEO_BUILD_JOBS="${DELI_GEO_BUILD_JOBS:-2}"

main() {
    if [[ "${1:-}" == "--help" ]]; then
        show_help "${BASH_SOURCE[0]}"
        return 0
    fi

    require_command osmium valhalla_build_config valhalla_build_admins valhalla_build_tiles valhalla_build_extract jq

    local source="${DELI_GEO_DATA_DIRECTORY}/cache/source.osm.pbf"
    local work_directory="${DELI_GEO_DATA_DIRECTORY}/work"
    local valhalla_directory="${work_directory}/valhalla"
    local regions_extract="${work_directory}/regions.osm.pbf"
    local build_config="${work_directory}/valhalla-build.json"
    require_file "${source}"

    local bbox
    bbox="$(read_union_bbox "${REGIONS_DIRECTORY}" "${DELI_GEO_REGIONS}")"

    rm -rf "${valhalla_directory}"
    mkdir -p "${valhalla_directory}/tiles"

    log_info "Cutting regions ${DELI_GEO_REGIONS} (${bbox})"
    osmium extract --overwrite --bbox "${bbox}" --output "${regions_extract}" "${source}"

    valhalla_build_config \
        --mjolnir-tile-dir "${valhalla_directory}/tiles" \
        --mjolnir-tile-extract "${valhalla_directory}/valhalla_tiles.tar" \
        --mjolnir-admin "${valhalla_directory}/admins.sqlite" \
        --mjolnir-timezone "${valhalla_directory}/timezones.sqlite" \
        --mjolnir-concurrency "${DELI_GEO_BUILD_JOBS}" \
        > "${build_config}"

    log_info "Building administrative areas"
    log_info "valhalla_build_admins logs 'NOT NULL constraint failed: admin_access.admin_id' for every country outside the configured regions; those lines are expected"
    valhalla_build_admins --config "${build_config}" "${regions_extract}"

    log_info "Building routing tiles"
    valhalla_build_tiles --config "${build_config}" "${regions_extract}"

    log_info "Packing the tiles into one archive"
    valhalla_build_extract --config "${build_config}" --overwrite

    rm -rf "${valhalla_directory}/tiles" "${regions_extract}" "${build_config}"

    valhalla_build_config \
        --mjolnir-tile-dir /data/tiles \
        --mjolnir-tile-extract /data/valhalla_tiles.tar \
        --mjolnir-admin /data/admins.sqlite \
        --mjolnir-timezone /data/timezones.sqlite \
        --service-limits-auto-max-matrix-location-pairs "${MAXIMUM_MATRIX_LOCATION_PAIRS}" \
        --service-limits-auto-max-locations "${MAXIMUM_ROUTE_LOCATIONS}" \
        > "${valhalla_directory}/valhalla.json.partial"

    if ! jq --exit-status \
        --argjson pairs "${MAXIMUM_MATRIX_LOCATION_PAIRS}" \
        --argjson locations "${MAXIMUM_ROUTE_LOCATIONS}" \
        '.service_limits.auto.max_matrix_location_pairs == $pairs and .service_limits.auto.max_locations == $locations and .mjolnir.tile_extract == "/data/valhalla_tiles.tar"' \
        "${valhalla_directory}/valhalla.json.partial" > /dev/null; then
        log_error "Runtime configuration ${valhalla_directory}/valhalla.json.partial lacks the expected limits or paths"
        exit 1
    fi
    mv "${valhalla_directory}/valhalla.json.partial" "${valhalla_directory}/valhalla.json"

    require_file "${valhalla_directory}/valhalla_tiles.tar"
    log_info "Wrote ${valhalla_directory}"
}

main "$@"
