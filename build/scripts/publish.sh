#!/bin/bash
#
# Description: Publishes the work directory as a dated geo build with SHA256SUMS and manifest.json, then keeps the newest builds.
#
# Requirements:
#   - jq, sha256sum, find, sort, xargs
#
# Variables (with defaults):
#   DELI_GEO_DATA_DIRECTORY   Directory with cache/, work/ and builds/ (default: /data)
#   DELI_GEO_REGIONS          Comma-separated regions from build/regions (default: berlin)
#   DELI_GEO_BUILD_ID         Build id, a UTC date (default: today)
#   DELI_GEO_KEEP_BUILDS      Number of builds to keep (default: 6)
#

set -euo pipefail
set -o errtrace
IFS=$'\n\t'

SCRIPT_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
readonly REGIONS_DIRECTORY="${SCRIPT_DIRECTORY}/../regions"
readonly FORMAT_VERSION=1
# shellcheck source=common.sh
source "${SCRIPT_DIRECTORY}/common.sh"
trap 'log_error "Failed in ${BASH_SOURCE[0]} at line ${LINENO}"' ERR

DELI_GEO_DATA_DIRECTORY="${DELI_GEO_DATA_DIRECTORY:-/data}"
DELI_GEO_REGIONS="${DELI_GEO_REGIONS:-berlin}"
DELI_GEO_BUILD_ID="${DELI_GEO_BUILD_ID:-$(date --utc +%F)}"
DELI_GEO_KEEP_BUILDS="${DELI_GEO_KEEP_BUILDS:-6}"

regions_as_json() {
    local -a region_names
    IFS=',' read -ra region_names <<< "${DELI_GEO_REGIONS}"
    local region_name
    for region_name in "${region_names[@]}"; do
        jq --null-input \
            --arg name "${region_name}" \
            --rawfile bbox "${REGIONS_DIRECTORY}/${region_name}.bbox" \
            '{name: $name, bbox: ($bbox | rtrimstr("\n") | split(",") | map(tonumber))}'
    done | jq --slurp '.'
}

require_keep_builds() {
    if [[ ! "${DELI_GEO_KEEP_BUILDS}" =~ ^[1-9][0-9]*$ ]]; then
        log_error "DELI_GEO_KEEP_BUILDS must be an integer of at least 1, got: ${DELI_GEO_KEEP_BUILDS}"
        exit 1
    fi
}

prune_builds() {
    local builds_directory="$1"
    local -a build_ids
    mapfile -t build_ids < <(
        find "${builds_directory}" -mindepth 1 -maxdepth 1 -type d \
            -regextype posix-extended -regex '.*/[0-9]{4}-[0-9]{2}-[0-9]{2}' -printf '%f\n' \
            | LC_ALL=C sort --reverse
    )
    local index
    for ((index = DELI_GEO_KEEP_BUILDS; index < ${#build_ids[@]}; index++)); do
        if [[ "${build_ids[index]}" == "${DELI_GEO_BUILD_ID}" ]]; then
            continue
        fi
        log_info "Removing old build ${build_ids[index]}"
        rm -rf -- "${builds_directory:?}/${build_ids[index]}"
    done
}

main() {
    if [[ "${1:-}" == "--help" ]]; then
        show_help "${BASH_SOURCE[0]}"
        return 0
    fi

    require_command jq sha256sum find sort xargs
    require_build_id "${DELI_GEO_BUILD_ID}"
    require_keep_builds

    local work_directory="${DELI_GEO_DATA_DIRECTORY}/work"
    local builds_directory="${DELI_GEO_DATA_DIRECTORY}/builds"
    local build_directory="${builds_directory}/${DELI_GEO_BUILD_ID}"
    local partial_directory="${builds_directory}/.${DELI_GEO_BUILD_ID}.partial"

    if [[ -e "${build_directory}" ]]; then
        log_error "Build ${DELI_GEO_BUILD_ID} already exists in ${builds_directory}"
        exit 1
    fi

    local required_file
    for required_file in \
        "${work_directory}/addresses.geojsonseq.gz" \
        "${work_directory}/postcode-areas.geojsonseq.gz" \
        "${work_directory}/valhalla/valhalla_tiles.tar" \
        "${work_directory}/valhalla/admins.sqlite" \
        "${work_directory}/valhalla/valhalla.json" \
        "${work_directory}/map/map.pmtiles" \
        "${work_directory}/map-sources.json" \
        "${DELI_GEO_DATA_DIRECTORY}/cache/source.json"; do
        require_file "${required_file}"
    done

    rm -rf "${partial_directory}"
    mkdir -p "${partial_directory}"
    mv "${work_directory}/addresses.geojsonseq.gz" "${work_directory}/postcode-areas.geojsonseq.gz" \
        "${work_directory}/valhalla" "${work_directory}/map" "${partial_directory}/"

    (cd "${partial_directory}" && find . -type f -printf '%P\n' | LC_ALL=C sort | xargs --delimiter='\n' sha256sum) \
        > "${work_directory}/SHA256SUMS"
    mv "${work_directory}/SHA256SUMS" "${partial_directory}/SHA256SUMS"

    local regions_json
    regions_json="$(regions_as_json)"
    jq --null-input \
        --argjson format_version "${FORMAT_VERSION}" \
        --arg build_id "${DELI_GEO_BUILD_ID}" \
        --arg created_at "$(date --utc +%Y-%m-%dT%H:%M:%SZ)" \
        --argjson regions "${regions_json}" \
        --slurpfile osm_source "${DELI_GEO_DATA_DIRECTORY}/cache/source.json" \
        --slurpfile map_sources "${work_directory}/map-sources.json" \
        '{
            format_version: $format_version,
            build_id: $build_id,
            created_at: $created_at,
            regions: $regions,
            sources: ({osm: $osm_source[0]} + $map_sources[0]),
            files: {
                addresses: "addresses.geojsonseq.gz",
                postcode_areas: "postcode-areas.geojsonseq.gz",
                valhalla_tiles: "valhalla/valhalla_tiles.tar",
                valhalla_admins: "valhalla/admins.sqlite",
                valhalla_config: "valhalla/valhalla.json",
                map: "map/map.pmtiles",
                map_fonts: "map/fonts",
                map_sprites: "map/sprites"
            },
            checksums_file: "SHA256SUMS"
        }' > "${partial_directory}/manifest.json"

    mv -T "${partial_directory}" "${build_directory}"
    log_info "Published ${build_directory}"
    prune_builds "${builds_directory}"
}

main "$@"
