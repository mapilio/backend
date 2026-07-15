# ADR 0017: Explicit trusted proxy boundary

## Status

Accepted for local implementation. Staging must supply and verify its actual reverse-proxy and CDN address ranges before public traffic is cut over.

## Context

Public login, newsletter, and feature-detail endpoints use client-IP rate limits. In production, requests can pass through Cloudflare and one or more reverse proxies before reaching Laravel. Ignoring forwarded client addresses would group users behind a shared proxy IP. Trusting forwarded headers from every caller would let a direct client spoof its address and bypass limits.

## Decision

`MAPILIO_TRUSTED_PROXIES` is the comma-separated allowlist of explicit IP addresses and CIDR ranges that form the trusted request chain. An empty value trusts no proxy. Wildcards, Laravel's `REMOTE_ADDR` shortcut, hostnames, malformed addresses, and invalid CIDR prefixes fail configuration loading.

Laravel accepts `X-Forwarded-For`, `X-Forwarded-Proto`, and `X-Forwarded-Port` only when the immediate caller is in that allowlist. `X-Forwarded-Host` and `X-Forwarded-Prefix` are intentionally not trusted. Application rate limits therefore use a forwarded browser address only across a configured proxy chain; direct or unexpected callers remain keyed by their socket address.

No CDN address range is hard-coded into the public repository. Operations owns synchronized deployment configuration because edge networks can change their published ranges.

## Deployment gate

1. Record every hop from the public edge to PHP and obtain the authoritative IP/CIDR ranges from each operator.
2. Keep the application origin firewalled to expected proxy sources wherever infrastructure permits.
3. Set `MAPILIO_TRUSTED_PROXIES` in staging, rebuild Laravel's configuration cache, and restart workers.
4. Verify login and newsletter limits from two real clients, including repeated failures, through the complete edge chain.
5. Send forged forwarded headers directly to the origin from an untrusted source and confirm they do not change the effective client IP or rate-limit bucket.
6. Verify HTTPS URL and secure-cookie behavior without trusting forwarded host values.
7. Repeat the checks after any CDN, load-balancer, ingress, or reverse-proxy change.

## Consequences

Rate limits can distinguish clients behind approved infrastructure while forwarded-header spoofing remains closed by default. A missing or stale allowlist can reduce availability by grouping users behind a proxy address, so proxy-range review is an explicit release and infrastructure-change responsibility.
