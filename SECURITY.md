# Security Policy

Mapilio handles account data, street-level imagery, geospatial data, and service-to-service integrations. Please report suspected vulnerabilities privately and avoid including secrets, personal data, unblurred imagery, or production records in a report.

## Supported Versions

The modern backend has not reached its first stable public release. Until versioned releases are published, security fixes are made on the latest commit of the `main` branch only. Legacy PyroCMS deployments and other Mapilio repositories have separate release lifecycles.

## Reporting a Vulnerability

Use the repository's **Security** tab and select **Report a vulnerability**. This opens a private GitHub security advisory visible only to the reporter and repository maintainers.

Do not report a vulnerability through a public issue, discussion, pull request, commit message, or social-media post. If **Report a vulnerability** is unavailable, stop and do not publish the details. Private vulnerability reporting is enabled, and the public Security page renders this policy and the reporting action. Maintainers still verify the complete submission, notification, private reply, and closure flow from a non-maintainer account as an operational release gate.

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

## Operational Release Gate

The repository is public, private vulnerability reporting is enabled, and the public Security page renders this policy and the reporting action. Before a stable release or production cutover, its owners must:

1. verify private submission, primary/backup notification, private reply, and non-public closure with a non-maintainer account
2. assign incident roles and complete a tabletop exercise using the incident response runbook
3. retain the required secret-scanning, quality, and migration checks on the protected release branch
4. complete the automated and owner-reviewed public-content audit for the exact release revision and full history

Use the sanitized [private reporting verification procedure](docs/security/private-vulnerability-reporting-verification.md) for the remaining human gate. Operational handling is documented in [docs/security/incident-response.md](docs/security/incident-response.md). Secret rotation and repository scanning are documented in [docs/security/secret-management.md](docs/security/secret-management.md).
