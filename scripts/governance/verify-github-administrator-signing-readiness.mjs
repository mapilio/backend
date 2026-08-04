#!/usr/bin/env node

import { readFile } from 'node:fs/promises';
import { access } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const repository = 'mapilio/backend';
const branch = 'main';
const apiVersionHeader = 'X-GitHub-Api-Version: 2022-11-28';
const commandTimeoutMs = 30_000;
const matchedStatuses = new Set(['SAFE_DEFERRED', 'SIGNING_STATE_MATCHED', 'ENFORCED_STATE_MATCHED']);

export function parseGlobalCodeowners(content) {
    const handles = [];
    let globalRuleCount = 0;

    for (const rawLine of String(content).split(/\r?\n/)) {
        const line = rawLine.replace(/#.*$/, '').trim();
        if (!line) continue;

        const fields = line.split(/\s+/);
        if (fields[0] !== '*') continue;
        globalRuleCount += 1;

        if (fields.length < 2 || fields.slice(1).some((handle) => !/^@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/.test(handle))) {
            throw new Error('Malformed global CODEOWNERS rule.');
        }
        handles.push(...fields.slice(1));
    }

    if (globalRuleCount === 0 || handles.length === 0) throw new Error('A global CODEOWNERS rule is required.');
    if (globalRuleCount > 1) throw new Error('Multiple global CODEOWNERS rules are not supported.');
    return [...new Set(handles)];
}

function requireBoolean(value, field) {
    if (typeof value !== 'boolean') throw new Error(`Malformed ${field}.`);
    return value;
}

export function parsePermission(value, expectedLogin) {
    if (!value || typeof value !== 'object' || typeof value.permission !== 'string' || typeof value.role_name !== 'string' || !value.user || typeof value.user !== 'object' || typeof value.user.login !== 'string' || value.user.login.length === 0) {
        throw new Error('Malformed collaborator permission response.');
    }
    if (typeof expectedLogin !== 'string' || value.user.login.toLowerCase() !== expectedLogin.toLowerCase()) {
        throw new Error('Collaborator permission response does not match the requested global CODEOWNER.');
    }
    return { admin: value.permission === 'admin' || value.role_name === 'admin' };
}

export function parsePermissions(values, expectedLogins) {
    if (!Array.isArray(values) || !Array.isArray(expectedLogins) || values.length !== expectedLogins.length) {
        throw new Error('Malformed collaborator permission responses.');
    }
    return values.map((value, index) => parsePermission(value, expectedLogins[index]));
}

export function parseProtection(protection, requiredSignatures) {
    if (!protection || typeof protection !== 'object' || !protection.enforce_admins || !requiredSignatures || typeof requiredSignatures !== 'object') {
        throw new Error('Malformed branch protection response.');
    }
    return {
        enforceAdmins: requireBoolean(protection.enforce_admins.enabled, 'administrator enforcement'),
        requiredSignatures: requireBoolean(requiredSignatures.enabled, 'required signatures'),
    };
}

export function evaluateLiveState({ codeowners, ownerPermissions, protection, requiredSignatures, expect }) {
    if (!['deferred', 'signing', 'enforced'].includes(expect)) throw new Error('Expectation must be deferred, signing, or enforced.');
    const handles = parseGlobalCodeowners(codeowners);
    const permissions = parsePermissions(ownerPermissions, handles.map((handle) => handle.slice(1)));
    const controls = parseProtection(protection, requiredSignatures);
    const globalCodeownerAdminCount = permissions.filter(({ admin }) => admin).length;
    const unsafePartialActivation = globalCodeownerAdminCount < 2 && (controls.requiredSignatures || controls.enforceAdmins);

    if (unsafePartialActivation) {
        return { status: 'STATE_MISMATCH', globalCodeownerAdminCount, codeownerCount: handles.length, ...controls, unsafePartialActivation: true, errors: ['Live signing or administrator enforcement is enabled with fewer than two global CODEOWNER administrators.'] };
    }

    const errors = [];
    if (expect === 'deferred') {
        if (globalCodeownerAdminCount < 1) errors.push('At least one global CODEOWNER administrator is required while controls are deferred.');
        if (controls.requiredSignatures) errors.push('Required signatures must remain disabled in deferred mode.');
        if (controls.enforceAdmins) errors.push('Administrator enforcement must remain disabled in deferred mode.');
        return {
            status: errors.length > 0 ? 'STATE_MISMATCH' : 'SAFE_DEFERRED',
            globalCodeownerAdminCount, codeownerCount: handles.length, ...controls, unsafePartialActivation: false, errors,
        };
    }

    if (globalCodeownerAdminCount < 2) errors.push(`At least two global CODEOWNER administrators are required in ${expect} mode.`);
    if (!controls.requiredSignatures) errors.push(`Required signatures must be enabled in ${expect} mode.`);
    if (expect === 'signing' && controls.enforceAdmins) errors.push('Administrator enforcement must remain disabled in signing mode.');
    if (expect === 'enforced' && !controls.enforceAdmins) errors.push('Administrator enforcement must be enabled in enforced mode.');
    return {
        status: errors.length > 0 ? 'STATE_MISMATCH' : (expect === 'signing' ? 'SIGNING_STATE_MATCHED' : 'ENFORCED_STATE_MATCHED'),
        globalCodeownerAdminCount, codeownerCount: handles.length, ...controls, unsafePartialActivation: false, errors,
    };
}

export function exitCodeForResult(result) {
    return result && Array.isArray(result.errors) && result.errors.length === 0 && matchedStatuses.has(result.status) ? 0 : 1;
}

export function formatPublicSummary(result) {
    const lines = [
        `STATUS=${result.status}`,
        `CODEOWNER_COUNT=${result.codeownerCount}`,
        `GLOBAL_CODEOWNER_ADMIN_COUNT=${result.globalCodeownerAdminCount}`,
        `REQUIRED_SIGNATURES=${result.requiredSignatures}`,
        `ENFORCE_ADMINS=${result.enforceAdmins}`,
        'GOVERNANCE_READINESS_VALIDATED=false',
        `ERROR_COUNT=${result.errors.length}`,
    ];
    return `${lines.join('\n')}\n`;
}

function execGh(args) {
    return new Promise((resolvePromise, reject) => {
        const child = spawn('gh', args, { stdio: ['ignore', 'pipe', 'ignore'] });
        let stdout = '';
        let settled = false;
        const finish = (callback) => {
            if (settled) return;
            settled = true;
            clearTimeout(timeout);
            callback();
        };
        const timeout = setTimeout(() => {
            child.kill('SIGKILL');
            finish(() => reject(new Error('GitHub API command failed.')));
        }, commandTimeoutMs);
        child.stdout.on('data', (chunk) => { stdout += chunk; });
        child.on('error', () => finish(() => reject(new Error('GitHub API command failed.'))));
        child.on('close', (code) => finish(() => code === 0 ? resolvePromise(stdout) : reject(new Error('GitHub API command failed.'))));
    });
}

function ghApiArgs(endpoint) {
    return ['api', endpoint, '--header', apiVersionHeader];
}

async function readCodeowners(root) {
    for (const candidate of ['.github/CODEOWNERS', 'CODEOWNERS', 'docs/CODEOWNERS']) {
        const path = resolve(root, candidate);
        try { await access(path); return readFile(path, 'utf8'); } catch { /* try next sanctioned location */ }
    }
    throw new Error('CODEOWNERS file is missing.');
}

export async function collectLiveState({ root, expect, runCommand = execGh }) {
    const codeowners = await readCodeowners(root);
    const handles = parseGlobalCodeowners(codeowners);
    const [protectionJson, signaturesJson, ...permissionJson] = await Promise.all([
        runCommand(ghApiArgs(`repos/${repository}/branches/${branch}/protection`)),
        runCommand(ghApiArgs(`repos/${repository}/branches/${branch}/protection/required_signatures`)),
        ...handles.map((handle) => runCommand(ghApiArgs(`repos/${repository}/collaborators/${handle.slice(1)}/permission`))),
    ]);
    return evaluateLiveState({ codeowners, ownerPermissions: permissionJson.map((response) => JSON.parse(response)), protection: JSON.parse(protectionJson), requiredSignatures: JSON.parse(signaturesJson), expect });
}

export function parseArgs(argv) {
    if (argv.length === 1 && (argv[0] === '--help' || argv[0] === '-h')) return { help: true };
    if (argv.length !== 1 || !/^--expect=(deferred|signing|enforced)$/.test(argv[0])) throw new Error('Usage: --expect=deferred|signing|enforced');
    const expect = argv[0].slice('--expect='.length);
    return { expect };
}

export async function main(argv = process.argv.slice(2)) {
    try {
        const options = parseArgs(argv);
        if (options.help) {
            console.log('Usage: node scripts/governance/verify-github-administrator-signing-readiness.mjs --expect=deferred|signing|enforced');
            console.log('Read-only live-state check for global CODEOWNER administrator count and main signing controls.');
            console.log('A matched state does not validate governance readiness or approval.');
            return 0;
        }
        const result = await collectLiveState({ root: resolve(fileURLToPath(new URL('.', import.meta.url)), '../..'), expect: options.expect });
        process.stdout.write(formatPublicSummary(result));
        const exitCode = exitCodeForResult(result);
        if (exitCode !== 0) process.stderr.write('Live-state verification failed closed.\n');
        return exitCode;
    } catch {
        process.stderr.write('Live-state verification failed closed.\n');
        return 1;
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) process.exitCode = await main();
