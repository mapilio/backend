import assert from 'node:assert/strict';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { collectLiveState, evaluateLiveState, exitCodeForResult, formatPublicSummary, parseGlobalCodeowners, parsePermission, parsePermissions, parseProtection, parseArgs } from './verify-github-administrator-signing-readiness.mjs';

const codeowners = '* @alpha @beta @alpha\n/docs @gamma';
const protection = (enforceAdmins) => ({ enforce_admins: { enabled: enforceAdmins } });
const signatures = (enabled) => ({ enabled });

test('parses, deduplicates, and rejects global CODEOWNERS rules', () => {
    assert.deepEqual(parseGlobalCodeowners(codeowners), ['@alpha', '@beta']);
    assert.throws(() => parseGlobalCodeowners('/docs @alpha\n*'), /global CODEOWNERS/);
    assert.throws(() => parseGlobalCodeowners('* alpha'), /Malformed/);
    assert.throws(() => parseGlobalCodeowners('* @alpha\n* @beta'), /Multiple global/);
});

const ownerPermissions = (adminCount) => ['alpha', 'beta'].map((login, index) => ({
    permission: index < adminCount ? 'admin' : 'push',
    role_name: index < adminCount ? 'admin' : 'write',
    user: { login },
}));

test('matches safely deferred state with one global CODEOWNER administrator', () => {
    const result = evaluateLiveState({ codeowners, ownerPermissions: ownerPermissions(1), protection: protection(false), requiredSignatures: signatures(false), expect: 'deferred' });
    assert.equal(result.status, 'SAFE_DEFERRED');
    assert.equal(result.errors.length, 0);
    assert.equal(exitCodeForResult(result), 0);
});

test('rejects deferred state with no administrators', () => {
    const result = evaluateLiveState({ codeowners, ownerPermissions: ownerPermissions(0), protection: protection(false), requiredSignatures: signatures(false), expect: 'deferred' });
    assert.equal(result.status, 'STATE_MISMATCH');
    assert.equal(result.errors.length, 1);
    assert.equal(exitCodeForResult(result), 1);
});

test('matches signing state with two global CODEOWNER administrators', () => {
    const result = evaluateLiveState({ codeowners, ownerPermissions: ownerPermissions(2), protection: protection(false), requiredSignatures: signatures(true), expect: 'signing' });
    assert.equal(result.status, 'SIGNING_STATE_MATCHED');
    assert.equal(result.errors.length, 0);
    assert.equal(exitCodeForResult(result), 0);
});

test('matches enforced state with two global CODEOWNER administrators', () => {
    const result = evaluateLiveState({ codeowners, ownerPermissions: ownerPermissions(2), protection: protection(true), requiredSignatures: signatures(true), expect: 'enforced' });
    assert.equal(result.status, 'ENFORCED_STATE_MATCHED');
    assert.equal(result.errors.length, 0);
    assert.equal(exitCodeForResult(result), 0);
});

test('rejects unsafe partial activation', () => {
    const result = evaluateLiveState({ codeowners, ownerPermissions: ownerPermissions(1), protection: protection(true), requiredSignatures: signatures(false), expect: 'deferred' });
    assert.equal(result.unsafePartialActivation, true);
    assert.equal(result.status, 'STATE_MISMATCH');
    assert.equal(result.errors.length, 1);
    assert.equal(exitCodeForResult(result), 1);
});

test('rejects signing and enforced state mismatches', () => {
    const signingMismatch = evaluateLiveState({ codeowners, ownerPermissions: ownerPermissions(2), protection: protection(true), requiredSignatures: signatures(true), expect: 'signing' });
    const enforcedMismatch = evaluateLiveState({ codeowners, ownerPermissions: ownerPermissions(2), protection: protection(false), requiredSignatures: signatures(true), expect: 'enforced' });
    assert.equal(signingMismatch.status, 'STATE_MISMATCH');
    assert.equal(enforcedMismatch.status, 'STATE_MISMATCH');
    assert.equal(exitCodeForResult(signingMismatch), 1);
    assert.equal(exitCodeForResult(enforcedMismatch), 1);
});

test('exit-code helper fails closed for malformed nominal results', () => {
    assert.equal(exitCodeForResult({ status: 'SAFE_DEFERRED', errors: ['mismatch'] }), 1);
    assert.equal(exitCodeForResult({ status: 'UNRECOGNIZED', errors: [] }), 1);
    assert.equal(exitCodeForResult(null), 1);
});

test('rejects signing state missing required signatures', () => {
    const result = evaluateLiveState({ codeowners, ownerPermissions: ownerPermissions(2), protection: protection(false), requiredSignatures: signatures(false), expect: 'signing' });
    assert.equal(result.status, 'STATE_MISMATCH');
    assert.equal(result.errors.length, 1);
});

test('fails closed on malformed protection or permissions', () => {
    assert.throws(() => parseProtection({}, signatures(false)), /Malformed/);
    assert.throws(() => parsePermission({ permission: 'admin', role_name: 'admin' }, 'x'), /Malformed/);
    assert.throws(() => parsePermission({ permission: 'admin', role_name: 'admin', user: { login: 'other' } }, 'x'), /does not match/);
    assert.throws(() => parsePermissions([{ permission: 'push', role_name: 'write', user: { login: 'x' } }, {}], ['x', 'y']), /Malformed/);
    assert.deepEqual(parsePermissions([
        { permission: 'admin', role_name: 'write', user: { login: 'x' } },
        { permission: 'push', role_name: 'admin', user: { login: 'Y' } },
    ], ['X', 'y']), [{ admin: true }, { admin: true }]);
});

test('public output contains aggregate values only', () => {
    const output = formatPublicSummary(evaluateLiveState({ codeowners, ownerPermissions: ownerPermissions(1), protection: protection(false), requiredSignatures: signatures(false), expect: 'deferred' }));
    assert.doesNotMatch(output, /@alpha|@beta|gamma/);
    assert.match(output, /GLOBAL_CODEOWNER_ADMIN_COUNT=1/);
    assert.match(output, /GOVERNANCE_READINESS_VALIDATED=false/);
    assert.match(output, /STATUS=SAFE_DEFERRED/);
});

test('collects live-shaped data through a fake command runner without network access', async () => {
    const root = await mkdtemp(join(tmpdir(), 'mapilio-governance-'));

    try {
        await mkdir(join(root, '.github'));
        await writeFile(join(root, '.github', 'CODEOWNERS'), '* @alpha @beta\n', 'utf8');

        const calls = [];
        const responses = [
            JSON.stringify({ enforce_admins: { enabled: false } }),
            JSON.stringify({ enabled: false }),
            JSON.stringify({ permission: 'admin', role_name: 'admin', user: { login: 'alpha' } }),
            JSON.stringify({ permission: 'push', role_name: 'write', user: { login: 'beta' } }),
        ];
        const result = await collectLiveState({
            root,
            expect: 'deferred',
            runCommand: async (args) => { calls.push(args); return responses.shift(); },
        });
        assert.equal(result.globalCodeownerAdminCount, 1);
        assert.equal(calls.length, 4);
        assert.deepEqual(calls.slice(2).map((args) => args[1]), [
            'repos/mapilio/backend/collaborators/alpha/permission',
            'repos/mapilio/backend/collaborators/beta/permission',
        ]);
        assert.ok(!calls.some((args) => args[1]?.includes('/collaborators?')));
        assert.ok(calls.every((args) => args.includes('X-GitHub-Api-Version: 2022-11-28')));
    } finally {
        await rm(root, { recursive: true, force: true });
    }
});

test('rejects unknown and duplicate CLI arguments', () => {
    assert.deepEqual(parseArgs(['--expect=deferred']), { expect: 'deferred' });
    assert.deepEqual(parseArgs(['--expect=signing']), { expect: 'signing' });
    assert.deepEqual(parseArgs(['--expect=enforced']), { expect: 'enforced' });
    assert.throws(() => parseArgs(['--expect=active']), /Usage/);
    assert.throws(() => parseArgs(['--expect=deferred', '--expect=deferred']), /Usage/);
    assert.throws(() => parseArgs(['--expect=deferred', '--unknown']), /Usage/);
    assert.throws(() => parseArgs(['--help', '--expect=deferred']), /Usage/);
});
