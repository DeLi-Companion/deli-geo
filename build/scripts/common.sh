#!/bin/bash
#
# Description: Shared helpers for the geo build scripts; sourced, never executed.
#

log_info() { printf '[INFO] %s\n' "$*" >&2; }
log_error() { printf '[ERROR] %s\n' "$*" >&2; }

show_help() {
    local script_path="$1"
    sed -n '2,/^$/p' "${script_path}" | sed 's/^# \{0,1\}//' >&2
}

require_command() {
    local command_name
    for command_name in "$@"; do
        if ! command -v "${command_name}" >/dev/null 2>&1; then
            log_error "Required command not found: ${command_name}"
            exit 1
        fi
    done
}

require_file() {
    local file_path="$1"
    if [[ ! -s "${file_path}" ]]; then
        log_error "Required file missing or empty: ${file_path}"
        exit 1
    fi
}

require_build_id() {
    local build_id="$1"
    if [[ ! "${build_id}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
        log_error "Build id must be a date like 2026-10-27, got: ${build_id}"
        exit 1
    fi
}

# Prints the bounding box min_lon,min_lat,max_lon,max_lat that covers all given regions.
read_union_bbox() {
    local regions_directory="$1"
    local regions="$2"
    local -a region_names
    IFS=',' read -ra region_names <<< "${regions}"
    local -a bbox_files=()
    local region_name
    for region_name in "${region_names[@]}"; do
        local bbox_file="${regions_directory}/${region_name}.bbox"
        if [[ ! -f "${bbox_file}" ]]; then
            log_error "Unknown region ${region_name}: ${bbox_file} is missing"
            return 1
        fi
        bbox_files+=("${bbox_file}")
    done
    awk -F',' '
        NR == 1 || $1 < min_lon { min_lon = $1 }
        NR == 1 || $2 < min_lat { min_lat = $2 }
        NR == 1 || $3 > max_lon { max_lon = $3 }
        NR == 1 || $4 > max_lat { max_lat = $4 }
        END { printf "%s,%s,%s,%s\n", min_lon, min_lat, max_lon, max_lat }
    ' "${bbox_files[@]}"
}
