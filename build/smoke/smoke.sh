#!/bin/bash
#
# Description: Smoke-tests Valhalla (matrix and route) and VROOM (one optimized tour) with fixed coordinates of a region.
#
# Requirements:
#   - curl, jq
#
# Variables (with defaults):
#   DELI_GEO_VALHALLA_URL     Valhalla base URL (default: http://localhost:8002)
#   DELI_GEO_VROOM_URL        VROOM base URL (default: http://localhost:3000)
#   DELI_GEO_SMOKE_REGION     Region whose smoke coordinates are used (default: berlin)
#

set -euo pipefail
set -o errtrace
IFS=$'\n\t'

SCRIPT_DIRECTORY="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIRECTORY
readonly REGIONS_DIRECTORY="${SCRIPT_DIRECTORY}/../regions"
# shellcheck source=../scripts/common.sh
source "${SCRIPT_DIRECTORY}/../scripts/common.sh"
trap 'log_error "Failed in ${BASH_SOURCE[0]} at line ${LINENO}"' ERR

DELI_GEO_VALHALLA_URL="${DELI_GEO_VALHALLA_URL:-http://localhost:8002}"
DELI_GEO_VROOM_URL="${DELI_GEO_VROOM_URL:-http://localhost:3000}"
DELI_GEO_SMOKE_REGION="${DELI_GEO_SMOKE_REGION:-berlin}"

post_json() {
    local url="$1"
    local body="$2"
    curl --fail-with-body --silent --show-error --max-time 60 \
        --header 'Content-Type: application/json' --data "${body}" "${url}"
}

check_matrix() {
    local smoke_file="$1"
    local request
    request="$(jq '([.depot] + .stops | map({lon: .[0], lat: .[1]})) as $locations
        | {sources: $locations, targets: $locations, costing: "auto", verbose: true}' "${smoke_file}")"
    local response
    if ! response="$(post_json "${DELI_GEO_VALHALLA_URL}/sources_to_targets" "${request}")"; then
        log_error "Valhalla matrix request failed: ${response}"
        return 1
    fi
    if ! jq --exit-status '[.sources_to_targets[][] | select(.from_index != .to_index) | .time]
        | length > 0 and all(. != null and . > 0)' <<< "${response}" >/dev/null; then
        log_error "Valhalla matrix has unreachable pairs: ${response}"
        return 1
    fi
    log_info "Valhalla matrix OK"
}

check_route() {
    local smoke_file="$1"
    local request
    request="$(jq '{locations: [.depot, .stops[0]] | map({lon: .[0], lat: .[1]}), costing: "auto"}' "${smoke_file}")"
    local response
    if ! response="$(post_json "${DELI_GEO_VALHALLA_URL}/route" "${request}")"; then
        log_error "Valhalla route request failed: ${response}"
        return 1
    fi
    if ! jq --exit-status '.trip.status == 0' <<< "${response}" >/dev/null; then
        log_error "Valhalla route failed: ${response}"
        return 1
    fi
    log_info "Valhalla route OK"
}

check_optimization() {
    local smoke_file="$1"
    local request
    request="$(jq '{
        vehicles: [{id: 1, profile: "auto", start: .depot, end: .depot}],
        jobs: (.stops | to_entries | map({id: (.key + 1), location: .value}))
    }' "${smoke_file}")"
    local response
    if ! response="$(post_json "${DELI_GEO_VROOM_URL}/" "${request}")"; then
        log_error "VROOM optimization request failed: ${response}"
        return 1
    fi
    if ! jq --exit-status '.code == 0 and .summary.unassigned == 0 and (.routes | length) == 1' <<< "${response}" >/dev/null; then
        log_error "VROOM optimization failed: ${response}"
        return 1
    fi
    log_info "VROOM optimization OK"
}

main() {
    if [[ "${1:-}" == "--help" ]]; then
        show_help "${BASH_SOURCE[0]}"
        return 0
    fi

    require_command curl jq

    local smoke_file="${REGIONS_DIRECTORY}/${DELI_GEO_SMOKE_REGION}.smoke.json"
    require_file "${smoke_file}"

    check_matrix "${smoke_file}"
    check_route "${smoke_file}"
    check_optimization "${smoke_file}"
    log_info "Smoke test passed for ${DELI_GEO_SMOKE_REGION}"
}

main "$@"
