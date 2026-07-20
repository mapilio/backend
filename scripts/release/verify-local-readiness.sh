#!/usr/bin/env bash

set -euo pipefail

repository_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "${repository_root}"

for command_name in php composer node npm gitleaks; do
    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "${command_name} is required for the local release gate." >&2
        exit 127
    fi
done

node_version=$(node -p 'process.versions.node')
node_major=${node_version%%.*}
node_remainder=${node_version#*.}
node_minor=${node_remainder%%.*}

if ! {
    [[ ${node_major} -eq 20 && ${node_minor} -ge 19 ]] ||
        [[ ${node_major} -eq 22 && ${node_minor} -ge 12 ]] ||
        [[ ${node_major} -ge 24 ]];
}; then
    echo "Node.js ${node_version} is unsupported; use 20.19+, 22.12+, or 24+." >&2
    exit 2
fi

cleanup() {
    php artisan config:clear >/dev/null 2>&1 || true
}

trap cleanup EXIT

run_gate() {
    local label=$1
    shift

    printf '\n==> %s\n' "${label}"
    "$@"
}

run_gate 'Validate Composer metadata' composer validate --strict
run_gate 'Install locked PHP dependencies' composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader
run_gate 'Audit locked PHP dependencies' composer audit --locked --no-interaction
run_gate 'Check PHP formatting' composer format:test
run_gate 'Run baseline-free PHP static analysis' composer analyse
run_gate 'Verify Laravel configuration caching' php artisan config:cache
run_gate 'Clear Laravel configuration cache' php artisan config:clear
run_gate 'Run the complete backend test suite' php artisan test
run_gate 'Install locked npm dependencies' npm ci --ignore-scripts
run_gate 'Audit locked npm dependencies' npm audit --audit-level=high
run_gate 'Validate the OpenAPI contract' npm run lint:openapi
run_gate 'Verify generated API documentation' npm run check:api-docs
run_gate 'Build backend web assets' npm run build
run_gate 'Scan Git history and commit-candidate files for secrets' scripts/security/scan-secrets.sh
run_gate 'Audit public repository content and history' npm run audit:public-content

printf '\nLocal repository release gates passed. Staging, infrastructure, privacy, and operator approvals are still required.\n'
