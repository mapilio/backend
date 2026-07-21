#!/usr/bin/env bash

set -euo pipefail

script_directory=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

# shellcheck source=doctor.sh
source "${script_directory}/doctor.sh"

assert_success() {
    local label=$1
    shift

    if ! "$@"; then
        printf 'Expected success: %s\n' "${label}" >&2
        exit 1
    fi
}

assert_failure() {
    local label=$1
    shift

    if "$@"; then
        printf 'Expected failure: %s\n' "${label}" >&2
        exit 1
    fi
}

assert_success 'equal semantic version' version_at_least '8.2.0' '8.2.0'
assert_success 'newer semantic version' version_at_least '8.3.1' '8.2.0'
assert_failure 'older semantic version' version_at_least '8.1.99' '8.2.0'
assert_failure 'invalid semantic version' version_at_least 'unknown' '8.2.0'

assert_success 'PHP floor' php_version_supported '8.2.0'
assert_failure 'PHP below floor' php_version_supported '8.1.30'
assert_success 'Composer runtime API floor' composer_version_supported '2.2.0'
assert_failure 'Composer below floor' composer_version_supported '2.1.14'
assert_success 'npm floor' npm_version_supported '10.0.0'
assert_failure 'npm below floor' npm_version_supported '9.9.9'

assert_success 'Node 20 supported lane' node_version_supported '20.19.0'
assert_failure 'Node 20 below supported lane' node_version_supported '20.18.9'
assert_success 'Node 22 supported lane' node_version_supported '22.12.0'
assert_failure 'Node 22 below supported lane' node_version_supported '22.11.0'
assert_failure 'Node 23 unsupported lane' node_version_supported '23.11.1'
assert_success 'Node 24 supported lane' node_version_supported '24.0.0'

printf 'Doctor rule tests passed: 16 checks.\n'
