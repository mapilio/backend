# Contributor Platform Matrix

This matrix describes the public, synthetic development path. It does not authorize a production, legacy, shared, or remotely write-capable database connection.

## Supported Hosts

| Host | Support level | Installation baseline | Notes |
| --- | --- | --- | --- |
| Ubuntu 24.04 | CI reference | Git, Composer 2, PHP CLI and extensions, Node.js 22.12+, npm 10+ | Every push runs the application suite here with PHP 8.2 and Node.js 22.12.0. |
| macOS with Homebrew | Contributor path | `git`, `php@8.2`, `composer`, `node@22` | Verified with the read-only doctor. Versioned PHP and Node formulae are keg-only, so ensure their `bin` directories precede older tools in `PATH`. |
| Windows 11 with WSL2 Ubuntu | Contributor path | Use the Ubuntu packages inside WSL2 | Run the repository, PHP, Composer, Node, and SQLite inside the same WSL filesystem. |
| Native Windows | Not currently supported | None | Repository scripts require Bash and the path/permission behavior is not covered by CI. |

Ubuntu/WSL contributors need the distribution equivalents of:

```bash
sudo apt update
sudo apt install git unzip composer php-cli php-bcmath php-curl php-gd \
  php-intl php-mbstring php-sqlite3 php-xml php-zip
```

Install a supported Node.js release from a trusted vendor or version manager; do not assume the distribution default satisfies the required release lane.

The Homebrew baseline is:

```bash
brew install git php@8.2 composer node@22
export PATH="$(brew --prefix php@8.2)/bin:$(brew --prefix node@22)/bin:${PATH}"
```

Homebrew's versioned PHP and Node formulae are keg-only. The PHP formula includes or depends on the libraries used by the required extensions. Put the exports in the shell profile only after the one-session doctor succeeds. Package installation and shell-profile changes remain operator actions and are never performed by the repository doctor.

## Toolchain Contract

| Component | Required for | Supported/required state | Automated evidence |
| --- | --- | --- | --- |
| Bash | Repository scripts | macOS Bash or Linux Bash | Doctor tests exercise portable Bash rules. |
| Git | Clone and candidate/history checks | Any maintained client | Doctor checks command availability only. |
| PHP | Laravel and tooling | 8.2+ and all constraints in `composer.lock` | CI uses 8.2; doctor runs Composer's real platform check. |
| Composer | Locked PHP install | 2.2+ | Locked packages require Composer runtime API `^2.2`. |
| PHP extensions | Tests and SQLite workflow | `bcmath`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo`, `pdo_sqlite`, `sqlite3`, `tokenizer`, `xml`, `xmlwriter`, `zip` | Doctor checks every extension without loading application configuration. |
| Node.js | OpenAPI, docs, and assets | 20.19+, 22.12+, or 24+ release lanes; 21 and 23 are unsupported | CI uses 22.12.0. |
| npm | Locked JS install | 10+ | `npm ci` and advisory audit run in CI. |
| SQLite | Safe contributor database | PHP PDO SQLite and SQLite3 extensions | Demo seed fails closed outside local/testing SQLite. |
| PostgreSQL/PostGIS | Migration/staging proof only | PostgreSQL 14 and PostGIS 3.5 reference | Digest-pinned disposable CI service; not needed for quick start. |
| Docker | Optional local PostGIS gate | CLI plus a local daemon | Doctor checks only CLI presence and never contacts the daemon. |
| Gitleaks | Complete local release gate | Exactly 8.30.1 | Optional for quick start; CI installs a checksum-verified binary. |

## Read-Only Doctor

Run before installing dependencies:

```bash
scripts/development/doctor.sh
```

The doctor checks repository manifests, required commands, versions, PHP extensions, Composer metadata, and lockfile platform compatibility. It does not:

- create or edit `.env`;
- install or update packages;
- create, migrate, seed, or query a database;
- contact Docker, PostgreSQL, Mapilio, image, AI, GeoServer, NAS, or anonymizer services;
- print command paths or environment values.

Missing Docker, PostgreSQL client tools, or Gitleaks produce quick-start warnings. Missing core commands, unsupported versions, required PHP extensions, invalid Composer metadata, or lockfile platform incompatibility fail the check.

Rule tests are independently available:

```bash
scripts/development/doctor.test.sh
```

After the doctor passes, continue with [local development](local-development.md). The complete release gate has stricter tooling and evidence requirements than the contributor quick start.
