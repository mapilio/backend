# ADR 0001: Modern Backend Foundation

Date: 2026-07-07

## Decision

Build the new backend as a clean Laravel application organized around Mapilio product domains, not around PyroCMS modules, Streams fields, or old Composer package boundaries.

## Context

The existing backend is production-critical and serves web, mobile, AI, GeoServer, image, and OSM/community workflows. Its public behavior must be preserved while internals are modernized.

The old project is useful as a source of facts:

- active API behavior
- database schema and data relationships
- production workflows
- dashboard/admin tasks
- integrations and side effects

It is not a target architecture.

## Consequences

- Legacy API behavior is protected by OpenAPI documentation and contract tests.
- New internals can use Laravel-native code, maintained packages, separate services, or rewrites.
- Old modules are classified individually as keep, rewrite, replace, extract, archive, or retire.
- Dashboard work starts from actual Mapilio operator workflows, not PyroCMS feature parity.
