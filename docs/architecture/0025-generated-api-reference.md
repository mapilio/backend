# ADR 0025: Generated API Reference

## Status

Accepted.

## Context

The modern OpenAPI 3.1 document is machine-readable and strictly linted, but contributors and API consumers also need a searchable reference. A manually maintained reference would drift from the contract. A runtime documentation service or CDN dependency would add deployment and supply-chain behavior that is unnecessary for a pre-rendered public artifact.

The repository has no selected project license yet. The API document previously declared MIT even though no reviewed `LICENSE` file exists, which could incorrectly imply a grant of rights.

## Decision

`docs/api/openapi-v1.json` remains the only API documentation source of truth. `npm run build:api-docs` parses that file and deterministically generates `public/docs/api/index.html` with the exact `redoc` 2.5.3 runtime from the npm lockfile. The runtime and its third-party license notice are copied beside the HTML, and the runtime is referenced with a generated SHA-384 integrity value.

The generated page:

- embeds the OpenAPI document and rendering options without credentials or live request execution;
- loads no remote script, stylesheet, font, image, or API resource;
- applies a restrictive content security policy and system fonts;
- remains searchable and responsive as static files at `/docs/api/`;
- links the displayed license status to the owner-controlled public-release decision instead of claiming an unselected license.

Generated files are committed so releases and repository viewers receive the same reviewed artifact. `npm run check:api-docs` regenerates them and fails on any diff. The local release script and the OpenAPI GitHub Actions job both enforce this freshness check after strict contract linting.

## Consequences

Every API contract change must include regenerated documentation. Reviewers can inspect the OpenAPI diff as the semantic change and use the generated diff only as freshness evidence. The checked-in Redoc runtime adds about 1.1 MB to the repository but removes a production CDN dependency and keeps runtime bytes tied to the audited lockfile.

This decision does not publish or stabilize an API version, authorize production calls, add an interactive credential flow, or select a project license. Hosting at a versioned public URL, release retention, and any future authenticated API console remain separate release and security decisions.
