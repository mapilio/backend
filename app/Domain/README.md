# Mapilio Domain Boundaries

This backend is not a PyroCMS module port. The old codebase is discovery input; the new application is organized around Mapilio domains.

Initial domains:

- `IdentityAccess`: users, operators, permissions, API clients, legacy token compatibility.
- `ImagerySequences`: imagery metadata, sequences, upload state, image URL generation.
- `AiJobsPredictions`: AI requests, callbacks, parsing, retries, idempotency, queue state.
- `InventoryFeatures`: detected inventory, measurements, bbox/relation/segmentation, correction workflows.
- `GeoPublishing`: GeoServer publication, geospatial APIs, exports, OSM/community integration.
- `OperationsDashboard`: admin dashboard, health, incidents, backup readiness, operational metrics, support tools.
- `CommunityIntegrations`: public/community data access and external integration contracts.

Compatibility rule:

External API behavior can be preserved behind `v1` compatibility controllers. Internal code should be rewritten, replaced, or extracted when that produces a cleaner and safer design.
