#!/bin/bash
#
# Description: Cuts the map for the configured regions from a Protomaps daily build and adds pinned glyphs and sprites.
#
# Requirements:
#   - pmtiles, curl, sha256sum, tar, jq
#
# Variables (with defaults):
#   DELI_GEO_DATA_DIRECTORY   Directory with cache/, work/ and builds/ (default: /data)
#   DELI_GEO_REGIONS          Comma-separated regions from build/regions (default: berlin)
#   DELI_GEO_BASEMAP_URL      Protomaps build to cut from (default: newest daily build of the last seven days)
#

set -euo pipefail
set -o errtrace
IFS=$'\n\t'

SCRIPT_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
readonly REGIONS_DIRECTORY="${SCRIPT_DIRECTORY}/../regions"
readonly BASEMAP_BUILDS_URL="https://build.protomaps.com"
readonly MAP_MAXIMUM_ZOOM=15
readonly ASSETS_COMMIT="028c18f713baecad011301ff7a69acc39bcc2ae7"
readonly ASSETS_SHA256="57e40e8c512bd8042d0a3a251f19d0d1c8523ad963c666c3c6643bada4dc92d0"
readonly FONT_NAMES=("Noto Sans Regular" "Noto Sans Medium" "Noto Sans Italic")
readonly SPRITE_FLAVORS=("light" "dark")
# shellcheck source=common.sh
source "${SCRIPT_DIRECTORY}/common.sh"
trap 'log_error "Failed in ${BASH_SOURCE[0]} at line ${LINENO}"' ERR

DELI_GEO_DATA_DIRECTORY="${DELI_GEO_DATA_DIRECTORY:-/data}"
DELI_GEO_REGIONS="${DELI_GEO_REGIONS:-berlin}"
DELI_GEO_BASEMAP_URL="${DELI_GEO_BASEMAP_URL:-}"

find_basemap_url() {
    local days_back
    for days_back in 1 2 3 4 5 6 7; do
        local candidate
        candidate="${BASEMAP_BUILDS_URL}/$(date --utc --date="-${days_back} day" +%Y%m%d).pmtiles"
        if curl --fail --silent --head --output /dev/null "${candidate}"; then
            printf '%s\n' "${candidate}"
            return 0
        fi
    done
    log_error "No Protomaps daily build found in the last seven days"
    return 1
}

main() {
    if [[ "${1:-}" == "--help" ]]; then
        show_help "${BASH_SOURCE[0]}"
        return 0
    fi

    require_command pmtiles curl sha256sum tar jq

    local work_directory="${DELI_GEO_DATA_DIRECTORY}/work"
    local map_directory="${work_directory}/map"
    local assets_archive="${work_directory}/basemaps-assets.tar.gz"
    local assets_directory="${work_directory}/basemaps-assets"
    local assets_url="https://codeload.github.com/protomaps/basemaps-assets/tar.gz/${ASSETS_COMMIT}"

    local bbox
    bbox="$(read_union_bbox "${REGIONS_DIRECTORY}" "${DELI_GEO_REGIONS}")"
    local basemap_url="${DELI_GEO_BASEMAP_URL}"
    if [[ -z "${basemap_url}" ]]; then
        basemap_url="$(find_basemap_url)"
    fi

    rm -rf "${map_directory}" "${assets_directory}"
    mkdir -p "${map_directory}/fonts" "${map_directory}/sprites" "${assets_directory}"

    log_info "Cutting the map ${bbox} from ${basemap_url}"
    pmtiles extract "${basemap_url}" "${map_directory}/map.pmtiles" --bbox="${bbox}" --maxzoom="${MAP_MAXIMUM_ZOOM}"

    log_info "Adding glyphs and sprites from basemaps-assets ${ASSETS_COMMIT}"
    curl --fail --location --silent --show-error --output "${assets_archive}" "${assets_url}"
    if ! printf '%s  %s\n' "${ASSETS_SHA256}" "${assets_archive}" | sha256sum --check --status; then
        log_error "Checksum mismatch for ${assets_url}"
        exit 1
    fi
    tar --extract --gzip --file "${assets_archive}" --directory "${assets_directory}" --strip-components=1

    local font_name
    for font_name in "${FONT_NAMES[@]}"; do
        cp -R "${assets_directory}/fonts/${font_name}" "${map_directory}/fonts/"
    done
    cp "${assets_directory}/fonts/OFL.txt" "${map_directory}/fonts/OFL.txt"

    local flavor
    for flavor in "${SPRITE_FLAVORS[@]}"; do
        cp "${assets_directory}/sprites/v4/${flavor}".* "${assets_directory}/sprites/v4/${flavor}@2x".* "${map_directory}/sprites/"
    done

    jq --null-input \
        --arg basemap_url "${basemap_url}" \
        --arg assets_url "${assets_url}" \
        --arg assets_sha256 "${ASSETS_SHA256}" \
        '{basemap: {url: $basemap_url}, assets: {url: $assets_url, sha256: $assets_sha256}}' \
        > "${work_directory}/map-sources.json"

    rm -rf "${assets_directory}" "${assets_archive}"
    log_info "Wrote ${map_directory}"
}

main "$@"
