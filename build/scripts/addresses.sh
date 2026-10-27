#!/bin/bash
#
# Description: Exports every OSM object with addr:housenumber as a gzip GeoJSON sequence.
#
# Requirements:
#   - osmium (1.19 or newer), gzip
#
# Variables (with defaults):
#   DELI_GEO_DATA_DIRECTORY   Directory with cache/, work/ and builds/ (default: /data)
#

set -euo pipefail
set -o errtrace
IFS=$'\n\t'

SCRIPT_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
readonly CONFIG_DIRECTORY="${SCRIPT_DIRECTORY}/../config"
# shellcheck source=common.sh
source "${SCRIPT_DIRECTORY}/common.sh"
trap 'log_error "Failed in ${BASH_SOURCE[0]} at line ${LINENO}"' ERR

DELI_GEO_DATA_DIRECTORY="${DELI_GEO_DATA_DIRECTORY:-/data}"

main() {
    if [[ "${1:-}" == "--help" ]]; then
        show_help "${BASH_SOURCE[0]}"
        return 0
    fi

    require_command osmium gzip

    local source="${DELI_GEO_DATA_DIRECTORY}/cache/source.osm.pbf"
    local work_directory="${DELI_GEO_DATA_DIRECTORY}/work"
    require_file "${source}"
    mkdir -p "${work_directory}"

    log_info "Filtering objects with addr:housenumber"
    osmium tags-filter --overwrite --output "${work_directory}/addresses.osm.pbf" "${source}" 'nwr/addr:housenumber'

    log_info "Exporting addresses"
    osmium export --overwrite \
        --config "${CONFIG_DIRECTORY}/addresses-export.json" \
        --output-format geojsonseq \
        --geometry-types point,polygon \
        --index-type "sparse_file_array,${work_directory}/addresses-node-locations.bin" \
        --output - \
        "${work_directory}/addresses.osm.pbf" \
        | gzip -6 > "${work_directory}/addresses.geojsonseq.gz.partial"

    mv "${work_directory}/addresses.geojsonseq.gz.partial" "${work_directory}/addresses.geojsonseq.gz"
    rm -f "${work_directory}/addresses.osm.pbf" "${work_directory}/addresses-node-locations.bin"
    log_info "Wrote ${work_directory}/addresses.geojsonseq.gz"
}

main "$@"
