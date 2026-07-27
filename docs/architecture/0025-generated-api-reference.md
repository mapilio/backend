# ADR 0025: Generated API Reference

## Status

Accepted.

## Context

The modern OpenAPI 3.1 document is machine-readable and strictly linted, but contributors and API consumers also need a searchable reference. A manually maintained reference would drift from the contract. A runtime documentation service or CDN dependency would add deployment and supply-chain behavior that is unnecessary for a pre-rendered public artifact.

The repository has no selected project license yet. The API document previously declared MIT even though no reviewed `LICENSE` file exists, which could incorrectly imply a grant of rights.

## Decision

`docs/api/openapi-v1.json` remains the only API documentation source of truth. Redoc 2.5.3 is vendored as the reviewed runtime source at `resources/docs/redoc.standalone.js`, with its accompanying third-party bundle notice at `resources/docs/redoc.standalone.js.LICENSE.txt` and the exact Redoc package MIT license at `resources/docs/redoc.LICENSE`. The runtime and bundle notice provenance is the previously tracked public artifacts generated from `redoc@2.5.3`; the vendor operation copied those artifacts byte-for-byte. The package license is copied from the official `redoc@2.5.3` npm tarball. The build verifies all three SHA-256 checksums before copying them. The reviewed runtime checksum is `1320f442151c57c447d3b70c7ffc6c4f86d08464020fe34c8cc5d3164e9944f0`; the bundle notice checksum is `469cc94b600aac09643f70e167cd1f66f24301ebb546532fad5db7c60f7b30d0`; the package license checksum is `d3026d549cf68ab7355bcfa85877bf8f845b3334a7efbfdc63936432fb34ff0e`.

`npm run build:api-docs` parses the contract and deterministically generates `public/docs/api/index.html` from those vendored bytes. The vulnerable Redoc build dependency is no longer installed as a project dependency; `@redocly/cli` remains the contract linter. The runtime, third-party bundle notice, and Redoc package MIT license are copied beside the HTML, and the runtime is referenced with a generated SHA-384 integrity value. Vendoring the Redoc MIT license file documents Redoc's license and does not license Mapilio project code; the project license remains unselected.

The generated page:

- embeds the OpenAPI document and rendering options without credentials or live request execution;
- loads no remote script, stylesheet, font, image, or API resource;
- embeds a restrictive meta content security policy for resource loading; framing protection is supplied by the hosting or reverse-proxy response header because a meta CSP cannot enforce `frame-ancestors`;
- remains searchable and responsive as static files at `/docs/api/`;
- links the displayed license status to the owner-controlled public-release decision instead of claiming an unselected license.

Generated files are committed so releases and repository viewers receive the same reviewed artifact. `npm run check:api-docs` regenerates them and fails on any diff, while the checksum guard fails closed if a vendored source changes without review. The local release script and the OpenAPI GitHub Actions job both enforce this freshness check after strict contract linting. The generated page has no CDN or runtime network dependency. The hosting or reverse-proxy layer must send `Content-Security-Policy: frame-ancestors 'none'` for `/docs/api/` and retain the other appropriate security headers; deployment-specific configuration remains outside this ADR.

## Consequences

Every API contract change must include regenerated documentation. Reviewers can inspect the OpenAPI diff as the semantic change and use the generated diff only as freshness evidence. The checked-in Redoc runtime adds about 1.1 MB to the repository but removes a production CDN dependency and keeps runtime bytes tied to the checksum-guarded vendored source and its reviewed checksums.

This decision does not publish or stabilize an API version, authorize production calls, add an interactive credential flow, or select a project license. Hosting at a versioned public URL, release retention, and any future authenticated API console remain separate release and security decisions.
