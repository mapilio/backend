# Security Incident Response

This runbook covers security and privacy incidents affecting the modern Mapilio backend and its contracts with the web platform, mobile apps, image server and storage, AI processing, GeoServer, and anonymizer. It is an operational baseline, not a substitute for the incident commander's judgment, legal advice, or regulatory assessment.

Do not place credentials, tokens, personal data, unblurred imagery, database records, exploit payloads, or private advisory details in public issues, chat rooms, normal application logs, or this repository.

## Roles

Assign these roles when an incident is declared. One person may hold more than one role for a small incident, but the incident commander must remain explicit.

| Role | Responsibility |
| --- | --- |
| Incident commander | Owns severity, priorities, decisions, timeline, handoffs, and closure. |
| Technical lead | Coordinates investigation, containment, remediation, and recovery. |
| Evidence custodian | Preserves logs and artifacts, records access, and maintains an immutable event timeline. |
| Communications lead | Coordinates private internal, user, partner, and public communications. |
| Service owner | Confirms expected behavior and recovery for each affected Mapilio service. |

The incident commander decides who may access the private incident record. Legal, privacy, insurance, hosting, law-enforcement, or regulatory contacts are involved by an authorized owner when the facts require them.

## Severity

Use the highest plausible severity until evidence supports reducing it.

| Severity | Examples | Initial response goal |
| --- | --- | --- |
| Critical | Active account takeover at scale; exposed signing, database, storage, or infrastructure credentials; public access to private or unblurred imagery; destructive database or storage access; remote code execution; broad GeoServer write access. | Declare immediately and begin containment within 15 minutes. |
| High | Privilege escalation; cross-account data access; repeatable anonymization bypass; forged AI callbacks or published detections; significant upload path traversal; material secret exposure with limited scope. | Declare within 30 minutes and begin containment within 60 minutes. |
| Medium | Bounded information disclosure; exploitable validation or rate-limit weakness; stored injection without privileged execution; dependency issue behind effective controls. | Triage during the same business day. |
| Low | Hardening gap with no demonstrated sensitive access or integrity impact. | Triage in the normal security queue. |

Response goals are guidance. Active exploitation, privacy exposure, or uncertain blast radius takes priority over the stated category.

## First Response

### First 15 Minutes

1. Open a private incident record and assign an incident commander, technical lead, evidence custodian, and communications lead.
2. Record discovery time, reporter, affected services, known indicators, current deployment revisions, and a single UTC timeline.
3. Preserve the original report and volatile evidence without forwarding sensitive material into broad channels.
4. Decide whether exploitation is active and whether the suspected path affects authentication, imagery privacy, upload/storage integrity, AI results, geospatial publication, or database access.
5. Apply the smallest reversible containment that stops ongoing harm. Do not destroy evidence merely to restore service quickly.

### First 60 Minutes

1. Establish the blast radius across backend, web, mobile, image server, storage, AI, GeoServer, anonymizer, queues, caches, and third-party providers.
2. Capture configuration and deployment metadata, access logs, database audit evidence, queue state, object metadata, and relevant hashes before rotation or cleanup where feasible.
3. Revoke or rotate compromised credentials at their source, then update dependent services through the encrypted deployment secret store.
4. Disable affected flags, routes, callbacks, jobs, publication workers, storage credentials, or accounts when narrower controls cannot contain the issue.
5. Preserve request IDs and normalized route metadata. Export sensitive logs to restricted evidence storage; do not increase application logging to include bodies, tokens, user identities, or imagery paths.
6. Set the next decision time and communication cadence.

## Ecosystem Playbooks

### Authentication, Sessions, and Secrets

- Revoke affected tokens, sessions, OAuth clients, signing keys, provider keys, and database credentials at the authoritative system.
- Check password, refresh, social verification, mobile bridge, and service-account paths separately.
- Inspect authentication, authorization, rate-limit, and deployment logs for the exposure window using request IDs where available.
- Treat a committed or browser-bundled secret as compromised even if no abuse is yet visible. Follow the secret-management policy before any history rewrite.

### Imagery Privacy and Anonymization

- Stop public serving and downstream cache population for affected originals and variants.
- Pause upload completion or publication when anonymizer status cannot be proven.
- Identify original, cached, resized, exported, AI, and GeoServer-derived copies without copying sensitive imagery into the incident record.
- Invalidate caches only after evidence and affected object identifiers are preserved in restricted storage.
- Resume serving only after blur output, holdback rules, cache invalidation, and a representative sample are independently verified.

### Upload, Image Server, NAS, and Cache

- Disable the affected upload mode or ingress route while preserving unrelated read traffic where possible.
- Quarantine partial chunks, archives, extracted files, and generated variants. Do not execute or preview unknown files on an operator workstation.
- Check traversal, archive extraction, MIME decoding, size limits, hash ownership, resumable offsets, symlink behavior, and cleanup jobs.
- Compare storage objects with backend metadata and identify orphaned, overwritten, or cross-account artifacts.
- Restore only from a verified source and invalidate stale cache entries after storage integrity is established.

### AI Requests and Callbacks

- Pause dispatch, callback ingestion, completion projection, and Geo publication independently as required.
- Rotate callback and outbound authentication keys when authenticity is uncertain.
- Reconcile nonce, signature, idempotency, ownership, class allowlist, imagery allowlist, and canonical result records.
- Quarantine suspect predictions. Do not publish or silently overwrite detections until source imagery and result ownership are verified.

### GeoServer and Geospatial Publication

- Disable writable services, compromised stores, unsafe SQL views, or publication workers without altering unaffected legacy layers unnecessarily.
- Review catalog, workspace, store, layer, style, service, cache, and administrator access separately.
- Reconcile canonical database features, published views, tile caches, and public API detail responses before reopening publication.
- Treat unexpected feature counts, geometries, SRIDs, layer permissions, or cache contents as integrity indicators.

### PostgreSQL and PostGIS

- Remove exposed network or role access and rotate credentials before restoring application connectivity.
- Preserve server, connection, role, statement, migration, and backup evidence according to access and retention policy.
- Use a verified restore or replica for forensic queries when production investigation could alter evidence or availability.
- Validate row counts, ownership boundaries, checksums where available, spatial validity, migrations, and replication state before recovery.

### Dependency and Build Supply Chain

- Pin or remove the affected package, action, image, or build artifact and suspend releases from an untrusted pipeline.
- Preserve lockfiles, build provenance, checksums, CI logs, deployment manifests, and artifact digests.
- Rotate CI, package registry, deployment, and repository credentials reachable from the affected job.
- Rebuild from a known-good revision in a clean environment and rerun tests plus the full-history secret scan.

## Evidence Handling

- Store evidence in a restricted, encrypted location outside the public repository.
- Record collector, source, UTC timestamp, method, hash, storage location, and every transfer or access.
- Prefer immutable copies and provider-native exports. Preserve originals and perform analysis on working copies.
- Minimize collection of personal data and imagery to what the investigation requires.
- Record commands and queries used during investigation without embedding their sensitive output.
- Follow approved retention and deletion decisions; do not invent a retention period during the incident.

## Communication and Notification

The communications lead maintains one approved factual summary. State what is known, unknown, contained, and next. Do not speculate about attribution or impact.

An authorized owner, with legal and privacy advice where appropriate, decides whether and when to notify affected users, maintainers, OSM/community integrations, mobile stores, service providers, insurers, regulators, or law enforcement. Notification decisions must record the affected data, jurisdictions, exposure window, containment status, and decision owner.

Public disclosure follows [SECURITY.md](../../SECURITY.md) and must not reveal an active exploit path before users and services can be protected.

## Recovery

1. Define measurable recovery criteria for every affected service and data set.
2. Deploy the smallest reviewed fix from a known-good build and preserve a tested rollback path.
3. Rotate affected credentials, expire sessions, reprocess or quarantine data, and rebuild caches or projections as required.
4. Validate authorization, privacy holdback, upload integrity, AI authenticity, database consistency, GeoServer output, rate limits, logging boundaries, and monitoring before restoring full traffic.
5. Increase privacy-safe monitoring for the exposure window and expected recurrence indicators.
6. Obtain incident commander and service-owner approval before closure.

## Post-Incident Review

Complete a blameless review after containment and recovery. It must include:

- UTC timeline, detection source, root cause, contributing conditions, and blast radius
- what data, users, accounts, services, partners, and releases were affected
- containment, eradication, recovery, communication, and notification decisions
- controls that succeeded, failed, or were missing
- assigned corrective actions with owners, priorities, due dates, tests, and rollout evidence
- updates required to threat models, architecture decisions, monitoring, backups, restore tests, dependency policy, and this runbook

Run a tabletop exercise before a stable release or production cutover and at least annually thereafter. Include one imagery-privacy scenario and one credential or service-to-service integrity scenario; record only sanitized lessons and corrective actions in the repository.
