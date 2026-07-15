#!/usr/bin/env bash

set -euo pipefail

if ! command -v gitleaks >/dev/null 2>&1; then
    echo 'gitleaks is required. Install the pinned version documented in docs/security/secret-management.md.' >&2
    exit 127
fi

gitleaks git \
    --redact \
    --no-banner \
    --verbose \
    --log-opts='--all' \
    .

scan_dir=$(mktemp -d)
trap 'rm -rf "${scan_dir}"' EXIT

git ls-files --cached --others --exclude-standard -z \
    | tar --null --files-from=- -cf - \
    | tar -xf - -C "${scan_dir}"

gitleaks dir \
    --redact \
    --no-banner \
    --verbose \
    --max-target-megabytes=20 \
    "${scan_dir}"
