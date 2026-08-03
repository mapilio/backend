#!/usr/bin/env bash

set -uo pipefail

repository_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

extract_version() {
    local value=$1

    if [[ ${value} =~ ([0-9]+)\.([0-9]+)(\.([0-9]+))? ]]; then
        printf '%s.%s.%s\n' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[4]:-0}"
        return 0
    fi

    return 1
}

version_at_least() {
    local actual required
    local actual_parts required_parts index actual_part required_part

    actual=$(extract_version "$1") || return 1
    required=$(extract_version "$2") || return 1
    IFS=. read -r -a actual_parts <<< "${actual}"
    IFS=. read -r -a required_parts <<< "${required}"

    for index in 0 1 2; do
        actual_part=$((10#${actual_parts[${index}]:-0}))
        required_part=$((10#${required_parts[${index}]:-0}))

        if ((actual_part > required_part)); then
            return 0
        fi

        if ((actual_part < required_part)); then
            return 1
        fi
    done

    return 0
}

node_version_supported() {
    local version major minor

    version=$(extract_version "$1") || return 1
    major=${version%%.*}
    version=${version#*.}
    minor=${version%%.*}

    if ((major == 22)); then
        ((minor >= 12))
        return
    fi

    ((major == 24))
}

php_version_supported() {
    version_at_least "$1" '8.2.0'
}

composer_version_supported() {
    version_at_least "$1" '2.2.0'
}

npm_version_supported() {
    version_at_least "$1" '10.0.0'
}

doctor_main() {
    local failures=0
    local warnings=0
    local command_name version extension file
    local -a required_commands=(git php composer node npm)
    local -a required_extensions=(
        bcmath curl dom fileinfo gd intl mbstring openssl pdo pdo_sqlite
        sqlite3 tokenizer xml xmlwriter zip
    )
    local -a required_files=(composer.json composer.lock package.json package-lock.json .env.example)

    cd "${repository_root}"

    ok() {
        printf '[OK] %s\n' "$1"
    }

    warn() {
        printf '[WARN] %s\n' "$1"
        warnings=$((warnings + 1))
    }

    fail() {
        printf '[FAIL] %s\n' "$1" >&2
        failures=$((failures + 1))
    }

    printf 'Mapilio contributor environment doctor (read-only)\n'
    printf 'No local .env file, database, Docker daemon, or external service will be accessed.\n\n'

    for file in "${required_files[@]}"; do
        if [[ -f ${file} ]]; then
            ok "Repository file is present: ${file}"
        else
            fail "Repository file is missing: ${file}"
        fi
    done

    for command_name in "${required_commands[@]}"; do
        if command -v "${command_name}" >/dev/null 2>&1; then
            ok "Command is available: ${command_name}"
        else
            fail "Required command is unavailable: ${command_name}"
        fi
    done

    if command -v php >/dev/null 2>&1; then
        version=$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)

        if php_version_supported "${version}"; then
            ok "PHP ${version} satisfies the 8.2+ project floor"
        else
            fail "PHP ${version:-unknown} is unsupported; use 8.2+ and satisfy composer.lock"
        fi

        for extension in "${required_extensions[@]}"; do
            if php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "${extension}" >/dev/null 2>&1; then
                ok "PHP extension is available: ${extension}"
            else
                fail "Required PHP extension is unavailable: ${extension}"
            fi
        done
    fi

    if command -v composer >/dev/null 2>&1; then
        version=$(composer --version --no-ansi 2>/dev/null || true)

        if composer_version_supported "${version}"; then
            ok "Composer $(extract_version "${version}") satisfies the 2.2+ floor"
        else
            fail 'Composer 2.2+ is required by the locked runtime API'
        fi

        if composer validate --strict --no-check-publish --no-interaction --no-plugins --no-scripts >/dev/null 2>&1; then
            ok 'Composer metadata is valid'
        else
            fail 'Composer metadata validation failed'
        fi

        if composer check-platform-reqs --lock --no-interaction --no-plugins --no-scripts >/dev/null 2>&1; then
            ok 'The real PHP platform satisfies composer.lock'
        else
            fail 'The real PHP platform does not satisfy composer.lock'
        fi
    fi

    if command -v node >/dev/null 2>&1; then
        version=$(node -p 'process.versions.node' 2>/dev/null || true)

        if node_version_supported "${version}"; then
            ok "Node.js ${version} is in a supported release lane"
        else
            fail "Node.js ${version:-unknown} is unsupported; use Node.js 22.12+ within major 22 or Node 24.x"
        fi
    fi

    if command -v npm >/dev/null 2>&1; then
        version=$(npm --version 2>/dev/null || true)

        if npm_version_supported "${version}"; then
            ok "npm ${version} satisfies the 10+ floor"
        else
            fail "npm ${version:-unknown} is unsupported; use npm 10+"
        fi
    fi

    if command -v docker >/dev/null 2>&1; then
        ok 'Docker CLI is available for the optional disposable PostGIS gate (daemon not contacted)'
    else
        warn 'Docker is optional and needed only for the local disposable PostGIS gate'
    fi

    if command -v gitleaks >/dev/null 2>&1; then
        version=$(gitleaks version 2>/dev/null || true)

        if [[ $(extract_version "${version}" 2>/dev/null || true) == '8.30.1' ]]; then
            ok 'Gitleaks 8.30.1 is available for the complete release gate'
        else
            warn 'The complete release gate expects Gitleaks 8.30.1'
        fi
    else
        warn 'Gitleaks 8.30.1 is optional for quick start but required by the complete release gate'
    fi

    if command -v psql >/dev/null 2>&1; then
        ok 'PostgreSQL client is available for optional isolated database work'
    else
        warn 'PostgreSQL client is optional for the synthetic SQLite quick start'
    fi

    printf '\nDoctor summary: %d failure(s), %d warning(s).\n' "${failures}" "${warnings}"

    if ((failures > 0)); then
        return 1
    fi

    printf 'Contributor prerequisites are ready. No state was changed.\n'
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    doctor_main "$@"
fi
