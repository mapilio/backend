#!/usr/bin/env bash

set -euo pipefail

repository_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "${repository_root}"

scripts/development/doctor.test.sh
scripts/development/doctor.sh

if ! command -v gitleaks >/dev/null 2>&1; then
    echo 'gitleaks is required for the local release gate.' >&2
    exit 127
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
run_gate 'Run level 6 static analysis for queue jobs' composer analyse:jobs
run_gate 'Run level 6 static analysis for AI job predictions' composer analyse:ai-predictions
run_gate 'Run level 6 static analysis for GeoPublishing' composer analyse:geo-publishing
run_gate 'Run level 6 static analysis for IdentityAccess' composer analyse:identity-access
run_gate 'Run level 6 static analysis for ImageryReports' composer analyse:imagery-reports
run_gate 'Run level 6 static analysis for Organizations' composer analyse:organizations
run_gate 'Run level 6 static analysis for Projects' composer analyse:projects
run_gate 'Run level 6 static analysis for BillingCatalog' composer analyse:billing-catalog
run_gate 'Run level 6 static analysis for PublicContent' composer analyse:public-content
run_gate 'Run level 6 static analysis for Gamification' composer analyse:gamification
run_gate 'Run level 6 static analysis for ImageryUploads' composer analyse:imagery-uploads
run_gate 'Run level 6 static analysis for OperationsDashboard' composer analyse:operations-dashboard
run_gate 'Run level 6 static analysis for DataMigration' composer analyse:data-migration
run_gate 'Run level 6 static analysis for InventoryFeatures' composer analyse:inventory-features
run_gate 'Verify Laravel configuration caching' php artisan config:cache
run_gate 'Clear Laravel configuration cache' php artisan config:clear
run_gate 'Run the complete backend test suite' php artisan test
run_gate 'Install locked npm dependencies' npm ci --ignore-scripts
run_gate 'Test the local GeoJSON validator' npm run test:geojson-validator
run_gate 'Test GitHub administrator/signing live-state verifier' npm run test:github-administrator-signing-readiness
run_gate 'Audit locked npm dependencies' npm audit --audit-level=high
run_gate 'Verify pending project-license state' npm run check:license-state
run_gate 'Check tracked Markdown links' npm run check:markdown-links
run_gate 'Validate the OpenAPI contract' npm run lint:openapi
run_gate 'Validate OpenAPI examples' npm run validate:api-examples
run_gate 'Verify generated API documentation' npm run check:api-docs
run_gate 'Build backend web assets' npm run build
run_gate 'Scan Git history and commit-candidate files for secrets' scripts/security/scan-secrets.sh
run_gate 'Audit public repository content and history' npm run audit:public-content

printf '\nLocal repository release gates passed. Staging, infrastructure, privacy, and operator approvals are still required.\n'
