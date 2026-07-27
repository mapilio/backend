# Synthetic GeoJSON Fixtures

Use the local-only validator with exactly one input path:

```bash
npm run validate:geojson -- path/to/fixture.geojson
```

The file must be a regular, non-symlink file no larger than 1 MiB and contain strict UTF-8 JSON with a maximum nesting depth of 32. Accepted top-level shapes are `Point`, `Feature`, and `FeatureCollection`. Every geometry must be a `Point`; its coordinates are exactly `[longitude, latitude]`, with finite numbers in `[-180, 180]` and `[-90, 90]`. Feature `properties` must be a non-null object, not an array.

The command performs no database, network, or external-service access. It prints only a concise result and does not print fixture contents, coordinate values, or local paths. A swapped pair cannot be identified semantically when both numbers happen to fit both global longitude and latitude ranges; the validator only enforces positional ranges.
