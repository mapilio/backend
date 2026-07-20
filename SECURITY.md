# Security Policy

Mapilio handles account data, street-level imagery, geospatial data, and service-to-service integrations. Please report suspected vulnerabilities privately and avoid including secrets, personal data, unblurred imagery, or production records in a report.

## Supported Versions

The modern backend has not reached its first stable public release. Until versioned releases are published, security fixes are made on the latest commit of the `main` branch only. Legacy PyroCMS deployments and other Mapilio repositories have separate release lifecycles.

## Reporting a Vulnerability

Use the repository's **Security** tab and select **Report a vulnerability**. This opens a private GitHub security advisory visible only to the reporter and repository maintainers.

Do not report a vulnerability through a public issue, discussion, pull request, commit message, or social-media post. If **Report a vulnerability** is unavailable, stop and do not publish the details. The repository is not ready for public release until its owners enable and verify GitHub private vulnerability reporting.

Include only the information needed to reproduce and assess the issue:

- affected endpoint, component, and tested revision or release
- security impact and the conditions required to trigger it
- minimal reproduction steps using accounts and data you control
- sanitized request and response metadata, including a request ID when available
- suggested mitigation, if known

Never attach production credentials, access tokens, database exports, private keys, personal data, unblurred faces or license plates, or street imagery that you are not authorized to share. Redact these values rather than replacing one sensitive sample with another.

## Safe Research Rules

Good-faith research must remain limited to the minimum proof required. In particular:

- use accounts, projects, imagery, and infrastructure you own or are authorized to test
- stop after confirming the issue and do not access, modify, or retain another person's data
- do not run denial-of-service, load, destructive, bulk-download, credential-stuffing, social-engineering, phishing, or physical-security tests
- do not bypass imagery anonymization to identify people or vehicles
- do not test third-party systems such as hosting providers, GeoServer infrastructure, AI providers, storage systems, or identity providers without their explicit permission
- do not leave persistent access, create avoidable operational cost, or interrupt production services
- comply with applicable law and the terms of the systems you test

If testing could affect production availability, privacy, stored imagery, AI results, geospatial publication, or data integrity, ask for authorization through the private reporting channel before proceeding.

## What to Expect

The maintainers aim to:

- acknowledge a complete report within 3 business days
- provide an initial severity assessment within 7 business days
- send a progress update at least every 14 days while remediation is active
- coordinate disclosure after affected services and users can be protected

These are response goals, not guarantees. Complex ecosystem or third-party issues may take longer. Reporters should not disclose an unresolved issue publicly without a coordinated disclosure decision.

When appropriate, the project will publish a GitHub security advisory, request a CVE, credit the reporter with permission, and document the fixed versions. Reports involving active exploitation or exposed secrets may require immediate containment before a full response is available.

## Public Release Gate

Before this repository becomes public, its owners must:

1. enable GitHub private vulnerability reporting
2. verify the private report flow with a non-maintainer account
3. confirm that this policy is visible from the repository Security page
4. assign incident roles and complete a tabletop exercise using the incident response runbook
5. require the secret-scanning and test checks on the protected release branch
6. complete the automated and owner-reviewed public-content audit for the exact public revision and full history

Operational handling is documented in [docs/security/incident-response.md](docs/security/incident-response.md). Secret rotation and repository scanning are documented in [docs/security/secret-management.md](docs/security/secret-management.md).
