#!/bin/bash
#
# Description: Downloads the OpenStreetMap extract into the cache, only when the upstream file is newer.
#
# Requirements:
#   - curl, md5sum, jq
#
# Variables (with defaults):
#   DELI_GEO_SOURCE_URL       OSM extract to download (default: https://download.geofabrik.de/europe/germany-latest.osm.pbf)
#   DELI_GEO_DATA_DIRECTORY   Directory with cache/, work/ and builds/ (default: /data)
#

set -euo pipefail
set -o errtrace
IFS=$'\n\t'

SCRIPT_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
# shellcheck source=common.sh
source "${SCRIPT_DIRECTORY}/common.sh"
trap 'log_error "Failed in ${BASH_SOURCE[0]} at line ${LINENO}"' ERR

DELI_GEO_SOURCE_URL="${DELI_GEO_SOURCE_URL:-https://download.geofabrik.de/europe/germany-latest.osm.pbf}"
DELI_GEO_DATA_DIRECTORY="${DELI_GEO_DATA_DIRECTORY:-/data}"

is_cached_from_other_source() {
    local source_file="$1"
    [[ -s "${source_file}" ]] \
        && [[ "$(jq --raw-output '.url // ""' "${source_file}")" != "${DELI_GEO_SOURCE_URL}" ]]
}

main() {
    if [[ "${1:-}" == "--help" ]]; then
        show_help "${BASH_SOURCE[0]}"
        return 0
    fi

    require_command curl md5sum jq

    local cache_directory="${DELI_GEO_DATA_DIRECTORY}/cache"
    local target="${cache_directory}/source.osm.pbf"
    local download="${target}.download"
    mkdir -p "${cache_directory}"
    rm -f "${download}"

    local -a time_condition=()
    if [[ -s "${target}" ]]; then
        if is_cached_from_other_source "${cache_directory}/source.json"; then
            log_info "The cached extract comes from another URL; downloading without a time condition"
        else
            time_condition=(--time-cond "${target}")
        fi
    fi

    log_info "Checking ${DELI_GEO_SOURCE_URL}"
    curl --fail --location --silent --show-error --remote-time "${time_condition[@]}" \
        --output "${download}" "${DELI_GEO_SOURCE_URL}"

    if [[ ! -s "${download}" ]]; then
        rm -f "${download}"
        log_info "The cached extract is current"
        return 0
    fi

    local expected_md5
    expected_md5="$(curl --fail --location --silent --show-error "${DELI_GEO_SOURCE_URL}.md5" | cut -d' ' -f1)"
    local actual_md5
    actual_md5="$(md5sum "${download}" | cut -d' ' -f1)"
    if [[ "${expected_md5}" != "${actual_md5}" ]]; then
        rm -f "${download}"
        log_error "MD5 mismatch for ${DELI_GEO_SOURCE_URL}: expected ${expected_md5}, got ${actual_md5}"
        exit 1
    fi

    mv "${download}" "${target}"
    jq --null-input \
        --arg url "${DELI_GEO_SOURCE_URL}" \
        --arg md5 "${actual_md5}" \
        --arg last_modified "$(date --utc --reference="${target}" +%Y-%m-%dT%H:%M:%SZ)" \
        '{url: $url, md5: $md5, last_modified: $last_modified}' > "${cache_directory}/source.json"
    log_info "Downloaded $(du -h "${target}" | cut -f1) to ${target}"
}

main "$@"
