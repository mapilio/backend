import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';

import {
    auditPath,
    auditText,
    formatFindings,
    isAuditableHistoryDiffLine,
    validatePolicy,
} from './audit-public-content.mjs';

const prohibitedIdentifier = ['legacy', 'owner', 'name'].join('-');
const policy = {
    allowedTrackedArtifactPaths: ['.env.example'],
    approvedMapilioHosts: {
        'mapilio.com': 'public',
        'end.mapilio.com': 'public API',
    },
    prohibitedTokenSha256: [
        '3638d513a174f5b79faddbf3a9ee27f953d1a77142de1d5eeb32834706a54b7e',
    ],
    reviewedHistoryExceptions: [],
    thirdPartyMetadataPaths: [],
    vendoredArtifactPaths: [],
};

const categories = (text) => auditText(text, { path: 'fixture.txt' }, policy)
    .map((finding) => finding.category);
const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

test('accepts reserved examples, loopback, documentation networks, and approved public hosts', () => {
    assert.deepEqual(categories([
        'person@example.test',
        'sprites@2x.json',
        'http://127.0.0.1:8000',
        '192.0.2.10',
        '198.51.100.20',
        '203.0.113.30',
        'https://end.mapilio.com',
    ].join('\n')), []);
});

test('finds non-public network addresses without storing them as dotted literals in the test', () => {
    const privateIpv4 = [10, 20, 30, 40].join('.');
    const uniqueLocalIpv6 = ['fd12', '3456', '789a', '', '1'].join(':');

    assert.deepEqual(categories(`${privateIpv4}\n${uniqueLocalIpv6}`), [
        'private-network-ip',
        'private-network-ip',
    ]);
});

test('finds personal emails, private hosts, local paths, and unapproved platform hosts', () => {
    const personalEmail = ['person', 'company.example.org'].join('@');
    const privateHost = ['database', 'internal'].join('.');
    const serviceUrl = ['redis:', '', 'cache-service'].join('/');
    const localPath = ['', 'Users', 'developer', 'project'].join('/');
    const unapprovedHost = ['private', 'mapilio', 'com'].join('.');

    assert.deepEqual(categories([personalEmail, privateHost, serviceUrl, localPath, unapprovedHost].join('\n')), [
        'local-absolute-path',
        'private-hostname',
        'private-hostname',
        'personal-email',
        'unapproved-mapilio-hostname',
    ]);
});

test('finds a prohibited identifier by hash without exposing it in policy output', () => {
    policy.prohibitedTokenSha256 = [
        createHash('sha256').update(prohibitedIdentifier).digest('hex'),
    ];

    assert.deepEqual(categories(prohibitedIdentifier), ['prohibited-identifier']);
});

test('rejects risky artifacts while allowing the public environment template', () => {
    assert.deepEqual(auditPath('.env.example', { source: 'candidate' }, policy), []);
    assert.equal(auditPath('.env.production', { source: 'candidate' }, policy)[0].category, 'environment-file');
    assert.equal(auditPath(['backup', 'sql'].join('.'), { source: 'candidate' }, policy)[0].category, 'risky-artifact');
});

test('redacted output contains categories and locations but never matched content', () => {
    const sensitiveValue = ['person', 'company.example.org'].join('@');
    const findings = auditText(sensitiveValue, { path: 'fixture.txt' }, policy);
    const output = formatFindings(findings, true);

    assert.match(output, /personal-email: 1/);
    assert.match(output, /fixture\.txt:1/);
    assert.doesNotMatch(output, new RegExp(escapeRegExp(sensitiveValue)));
});

test('history scans introductions without assigning removed content to its deletion commit', () => {
    assert.equal(isAuditableHistoryDiffLine('+introduced content'), true);
    assert.equal(isAuditableHistoryDiffLine('-removed content'), false);
    assert.equal(isAuditableHistoryDiffLine(' unchanged content'), false);
    assert.equal(isAuditableHistoryDiffLine('+++ b/example.txt'), false);
});

test('policy validation rejects incomplete reviewed history exceptions', () => {
    assert.doesNotThrow(() => validatePolicy(policy));
    assert.throws(() => validatePolicy({
        ...policy,
        reviewedHistoryExceptions: [{ category: 'private-network-ip' }],
    }), /Every history exception requires/);
});
