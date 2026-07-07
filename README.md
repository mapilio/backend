# Mapilio Modern Backend

Clean Laravel backend foundation for the Mapilio platform migration.

This project does not port PyroCMS module structure one-to-one. It preserves external contracts where active clients depend on them, then rebuilds internals around Mapilio domains.

## Current Foundation

- Laravel Framework 12.x on local PHP 8.2.
- Composer selected Laravel 12 because Laravel 13 requires PHP 8.3.
- API prefix: `/api/v1`.
- First health endpoint: `/api/v1/system/health`.
- First preserved legacy endpoint: `/api/country-image-count`.
- Versioned alias for the first preserved endpoint: `/api/v1/imagery/country-image-count`.
- Domain notes: `app/Domain/README.md`.
- Architecture decision record: `docs/architecture/0001-modern-backend-foundation.md`.

## Local Commands

```bash
composer install
php artisan test
php artisan serve
```

## Migration Rule

Legacy compatibility means public behavior compatibility, not implementation compatibility.

Old PyroCMS modules can be:

- rewritten as Laravel domain code
- replaced with maintained packages
- extracted into services
- archived for data access
- retired when unused

The old backend remains the source of truth for current behavior until contract tests and owner-reviewed decisions replace assumptions.
