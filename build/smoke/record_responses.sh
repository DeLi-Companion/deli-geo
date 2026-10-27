#!/bin/bash
#
# Description: Sends the reference requests of a region to Valhalla and VROOM and stores each request, response and HTTP status.
#
# Usage: record_responses.sh OUTPUT_DIRECTORY
#
# Requirements:
#   - curl, jq
#
# Variables (with defaults):
#   DELI_GEO_VALHALLA_URL     Valhalla base URL (default: http://localhost:8002)
#   DELI_GEO_VROOM_URL        VROOM base URL (default: http://localhost:3000)
#   DELI_GEO_SMOKE_REGION     Region whose reference requests are sent (default: berlin)
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

read_service_url() {
    local service="$1"
    case "${service}" in
        valhalla) printf '%s\n' "${DELI_GEO_VALHALLA_URL}" ;;
        vroom) printf '%s\n' "${DELI_GEO_VROOM_URL}" ;;
        *)
            log_error "Unknown service: ${service}"
            return 1
            ;;
    esac
}

# Prints the HTTP status of the recorded request; the files land in the output directory.
record_request() {
    local requests_file="$1"
    local index="$2"
    local output_directory="$3"

    local name service method path
    name="$(jq --raw-output ".[${index}].name" "${requests_file}")"
    service="$(jq --raw-output ".[${index}].service" "${requests_file}")"
    method="$(jq --raw-output ".[${index}].method" "${requests_file}")"
    path="$(jq --raw-output ".[${index}].path" "${requests_file}")"

    local url
    url="$(read_service_url "${service}")${path}"
    local response_file="${output_directory}/${name}.response"
    local -a request_options=()

    if [[ "${method}" == "POST" ]]; then
        jq ".[${index}].body" "${requests_file}" > "${output_directory}/${name}.request.json"
        request_options=(--header 'Content-Type: application/json' --data "@${output_directory}/${name}.request.json")
    fi

    local status
    status="$(curl --silent --show-error --max-time 60 "${request_options[@]}" \
        --output "${response_file}" --write-out '%{http_code}' "${url}")"

    jq --null-input \
        --arg name "${name}" \
        --arg service "${service}" \
        --arg method "${method}" \
        --arg path "${path}" \
        --argjson status "${status}" \
        '{name: $name, service: $service, method: $method, path: $path, status: $status}' \
        > "${output_directory}/${name}.meta.json"

    printf '%s\n' "${status}"
}

main() {
    if [[ "${1:-}" == "--help" ]]; then
        show_help "${BASH_SOURCE[0]}"
        return 0
    fi

    if [[ $# -ne 1 ]]; then
        log_error "Usage: record_responses.sh OUTPUT_DIRECTORY"
        exit 1
    fi

    require_command curl jq

    local output_directory="$1"
    local requests_file="${REGIONS_DIRECTORY}/${DELI_GEO_SMOKE_REGION}.recordings.json"
    require_file "${requests_file}"
    mkdir -p "${output_directory}"

    local count
    count="$(jq 'length' "${requests_file}")"
    local unexpected=0
    local index
    for ((index = 0; index < count; index++)); do
        local name expected_status status
        name="$(jq --raw-output ".[${index}].name" "${requests_file}")"
        expected_status="$(jq --raw-output ".[${index}].expected_status // \"any\"" "${requests_file}")"
        status="$(record_request "${requests_file}" "${index}" "${output_directory}")"

        if [[ "${expected_status}" != "any" && "${status}" != "${expected_status}" ]]; then
            log_error "Recorded ${name}: HTTP ${status}, expected ${expected_status}"
            unexpected=$((unexpected + 1))
        else
            log_info "Recorded ${name}: HTTP ${status}"
        fi
    done

    if ((unexpected > 0)); then
        log_error "${unexpected} recordings returned an unexpected status"
        exit 1
    fi

    log_info "Recorded ${count} reference responses in ${output_directory}"
}

main "$@"
