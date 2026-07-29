#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const defaultPolicyPath = resolve(repositoryRoot, 'scripts/security/public-content-policy.json');
const riskyExtensions = new Set([
    '7z', 'avi', 'backup', 'bak', 'cer', 'crt', 'db', 'doc', 'docx', 'dump', 'gif', 'gz', 'heic', 'jpeg',
    'jpg', 'jks', 'kdbx', 'key', 'log', 'mov', 'mp4', 'p12', 'pdf', 'pem', 'pfx', 'png', 'sql', 'sqlite',
    'tar', 'tgz', 'tif', 'tiff', 'webp', 'xls', 'xlsx', 'zip',
]);

const sha256 = (value) => createHash('sha256').update(value).digest('hex');

export function validatePolicy(policy) {
    const arrayFields = [
        'allowedTrackedArtifactPaths',
        'prohibitedTokenSha256',
        'reviewedHistoryExceptions',
        'thirdPartyMetadataPaths',
        'vendoredArtifactPaths',
    ];

    if (!policy.approvedMapilioHosts || Array.isArray(policy.approvedMapilioHosts)
        || typeof policy.approvedMapilioHosts !== 'object') {
        throw new Error('approvedMapilioHosts must be an object.');
    }

    for (const field of arrayFields) {
        if (!Array.isArray(policy[field])) {
            throw new Error(`${field} must be an array.`);
        }
    }

    for (const fingerprint of policy.prohibitedTokenSha256) {
        if (!/^[a-f0-9]{64}$/.test(fingerprint)) {
            throw new Error('Every prohibited identifier fingerprint must be a lowercase SHA-256 value.');
        }
    }

    const exceptionKeys = new Set();

    for (const exception of policy.reviewedHistoryExceptions) {
        if (!/^[a-f0-9]{40}$/.test(exception.commit ?? '')
            || !/^[a-f0-9]{64}$/.test(exception.fingerprint ?? '')
            || typeof exception.category !== 'string'
            || typeof exception.path !== 'string'
            || typeof exception.reason !== 'string'
            || exception.reason.trim().length < 20) {
            throw new Error('Every history exception requires category, full commit, path, fingerprint, and public reason.');
        }

        const key = [exception.category, exception.commit, exception.path, exception.fingerprint].join('|');

        if (exceptionKeys.has(key)) {
            throw new Error('History exceptions must be unique.');
        }

        exceptionKeys.add(key);
    }
}

function lineNumberAt(text, index) {
    return text.slice(0, index).split('\n').length;
}

function isDocumentationIpv4(parts) {
    return (parts[0] === 192 && parts[1] === 0 && parts[2] === 2)
        || (parts[0] === 198 && parts[1] === 51 && parts[2] === 100)
        || (parts[0] === 203 && parts[1] === 0 && parts[2] === 113);
}

function isNonPublicIpv4(value) {
    const parts = value.split('.').map(Number);

    if (parts.length !== 4 || parts.some((part) => !Number.isInteger(part) || part < 0 || part > 255)) {
        return false;
    }

    if (value === '0.0.0.0' || value === '127.0.0.1' || isDocumentationIpv4(parts)) {
        return false;
    }

    return parts[0] === 0
        || parts[0] === 10
        || parts[0] === 127
        || (parts[0] === 100 && parts[1] >= 64 && parts[1] <= 127)
        || (parts[0] === 169 && parts[1] === 254)
        || (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31)
        || (parts[0] === 192 && parts[1] === 168)
        || parts[0] >= 224;
}

function isSyntheticEmail(local, domain, suffix) {
    const normalizedDomain = domain.toLowerCase();

    return normalizedDomain === 'example.com'
        || normalizedDomain.endsWith('.example')
        || normalizedDomain.endsWith('.test')
        || normalizedDomain.endsWith('.invalid')
        || /^\d+x\.(?:json|png|svg)$/.test(normalizedDomain)
        || (local.toLowerCase() === 'support' && normalizedDomain === 'github.com')
        || (local.toLowerCase() === 'git' && normalizedDomain === 'github.com' && suffix === ':');
}

function addMatches(findings, text, pattern, category, context, predicate = () => true) {
    for (const match of text.matchAll(pattern)) {
        const value = match[0];

        if (!predicate(value, match)) {
            continue;
        }

        findings.push({
            category,
            commit: context.commit,
            fingerprint: sha256(value.toLowerCase()),
            line: context.lineOffset + lineNumberAt(text, match.index ?? 0) - 1,
            path: context.path,
            source: context.source,
        });
    }
}

export function auditText(text, context, policy) {
    const findings = [];
    const normalizedContext = {
        commit: context.commit ?? null,
        ignorePersonalEmails: context.ignorePersonalEmails ?? false,
        lineOffset: context.lineOffset ?? 1,
        path: context.path,
        source: context.source ?? 'candidate',
    };

    addMatches(
        findings,
        text,
        /\b(?:\d{1,3}\.){3}\d{1,3}\b/g,
        'private-network-ip',
        normalizedContext,
        isNonPublicIpv4,
    );
    addMatches(
        findings,
        text,
        /\b(?:f[cd][0-9a-f]{0,2}|fe[89ab][0-9a-f]?):(?:[0-9a-f]{0,4}:){1,6}[0-9a-f]{0,4}\b/gi,
        'private-network-ip',
        normalizedContext,
    );
    addMatches(
        findings,
        text,
        /(?:\/(?:Users|home)\/[^\s"'<>]+|\/var\/www(?:\/[^\s"'<>]+)?|[A-Za-z]:\\Users\\[^\s"'<>]+)/g,
        'local-absolute-path',
        normalizedContext,
    );
    addMatches(
        findings,
        text,
        /\b(?:[a-z0-9-]+\.)+(?:internal|intranet|lan|local|private|corp|home)\b/gi,
        'private-hostname',
        normalizedContext,
    );

    for (const match of text.matchAll(/\b(?:https?|postgres(?:ql)?|mysql|redis|amqps?):\/\/[^\s"'<>]+/gi)) {
        let hostname;

        try {
            hostname = new URL(match[0]).hostname.toLowerCase();
        } catch {
            continue;
        }

        if (!hostname.includes('.') && hostname !== 'localhost' && hostname !== '[::1]') {
            findings.push({
                category: 'private-hostname',
                commit: normalizedContext.commit,
                fingerprint: sha256(hostname),
                line: normalizedContext.lineOffset + lineNumberAt(text, match.index ?? 0) - 1,
                path: normalizedContext.path,
                source: normalizedContext.source,
            });
        }
    }

    for (const match of text.matchAll(/\b([A-Z0-9._%+-]+)@([A-Z0-9.-]+\.[A-Z]{2,})\b/gi)) {
        const suffix = text[(match.index ?? 0) + match[0].length] ?? '';

        if (normalizedContext.ignorePersonalEmails || isSyntheticEmail(match[1], match[2], suffix)) {
            continue;
        }

        findings.push({
            category: 'personal-email',
            commit: normalizedContext.commit,
            fingerprint: sha256(match[0].toLowerCase()),
            line: normalizedContext.lineOffset + lineNumberAt(text, match.index ?? 0) - 1,
            path: normalizedContext.path,
            source: normalizedContext.source,
        });
    }

    const approvedMapilioHosts = new Set(Object.keys(policy.approvedMapilioHosts));

    addMatches(
        findings,
        text,
        /\b(?:[a-z0-9-]+\.)*mapilio\.com\b/gi,
        'unapproved-mapilio-hostname',
        normalizedContext,
        (value) => !approvedMapilioHosts.has(value.toLowerCase()),
    );

    const prohibitedHashes = new Set(policy.prohibitedTokenSha256);

    addMatches(
        findings,
        text,
        /\b[a-z][a-z0-9_-]{3,}\b/gi,
        'prohibited-identifier',
        normalizedContext,
        (value) => prohibitedHashes.has(sha256(value.toLowerCase())),
    );

    return findings;
}

export function auditPath(path, context, policy) {
    if (policy.allowedTrackedArtifactPaths.includes(path)) {
        return [];
    }

    const basename = path.split('/').at(-1) ?? path;
    const extension = basename.includes('.') ? basename.split('.').at(-1)?.toLowerCase() : '';
    const findings = [];

    if (basename === '.env' || basename.startsWith('.env.')) {
        findings.push({ category: 'environment-file', path, ...context, fingerprint: sha256(path), line: 1 });
    }

    if (extension && riskyExtensions.has(extension)) {
        findings.push({ category: 'risky-artifact', path, ...context, fingerprint: sha256(path), line: 1 });
    }

    return findings;
}

function isThirdPartyMetadataPath(path, policy) {
    return policy.thirdPartyMetadataPaths.includes(path);
}

function isVendoredArtifactPath(path, policy) {
    return policy.vendoredArtifactPaths.includes(path);
}

function deduplicate(findings) {
    return [...new Map(findings.map((finding) => [
        [finding.category, finding.commit, finding.path, finding.line, finding.fingerprint].join('|'),
        finding,
    ])).values()];
}

function applyHistoryExceptions(findings, policy) {
    const exceptionKeys = new Set(policy.reviewedHistoryExceptions.map((exception) => [
        exception.category,
        exception.commit,
        exception.path,
        exception.fingerprint,
    ].join('|')));

    const matchedExceptionKeys = new Set();
    const remainingFindings = findings.filter((finding) => {
        const key = [
            finding.category,
            finding.commit,
            finding.path,
            finding.fingerprint,
        ].join('|');

        if (exceptionKeys.has(key)) {
            matchedExceptionKeys.add(key);
            return false;
        }

        return true;
    });

    for (const exception of policy.reviewedHistoryExceptions) {
        const key = [
            exception.category,
            exception.commit,
            exception.path,
            exception.fingerprint,
        ].join('|');

        if (!matchedExceptionKeys.has(key)) {
            remainingFindings.push({
                category: 'stale-history-exception',
                commit: exception.commit,
                fingerprint: sha256(key),
                line: 1,
                path: exception.path,
                source: 'history',
            });
        }
    }

    return remainingFindings;
}

function candidateFiles() {
    const output = execFileSync(
        'git',
        ['ls-files', '--cached', '--others', '--exclude-standard', '-z'],
        { cwd: repositoryRoot },
    ).toString();

    return output.split('\0').filter(Boolean);
}

function scanCandidate(policy) {
    const findings = [];
    let scannedFiles = 0;

    for (const path of candidateFiles()) {
        const pathContext = { commit: null, source: 'candidate' };
        findings.push(...auditPath(path, pathContext, policy));

        if (isVendoredArtifactPath(path, policy)) {
            continue;
        }

        const buffer = readFileSync(resolve(repositoryRoot, path));

        if (buffer.includes(0)) {
            findings.push({
                category: 'binary-content',
                commit: null,
                fingerprint: sha256(path),
                line: 1,
                path,
                source: 'candidate',
            });
            continue;
        }

        scannedFiles += 1;
        findings.push(...auditText(buffer.toString('utf8'), {
            ignorePersonalEmails: isThirdPartyMetadataPath(path, policy),
            path,
        }, policy));
    }

    return { findings: deduplicate(findings), scannedFiles };
}

function decodeDiffPath(value) {
    if (value === '/dev/null') {
        return null;
    }

    const unquoted = value.startsWith('"') ? JSON.parse(value) : value;

    return unquoted.startsWith('b/') ? unquoted.slice(2) : unquoted;
}

export function isAuditableHistoryDiffLine(line) {
    return line.startsWith('+') && !line.startsWith('+++');
}

function scanHistory(policy) {
    const output = execFileSync(
        'git',
        [
            'log', '--all', '--no-renames', '--no-ext-diff', '--text', '-p',
            '--format=__MAPILIO_COMMIT__%H%n__MAPILIO_MESSAGE_START__%n%B%n__MAPILIO_MESSAGE_END__',
        ],
        { cwd: repositoryRoot, maxBuffer: 128 * 1024 * 1024 },
    ).toString();
    const findings = [];
    let commit = null;
    let currentPath = null;
    let oldPath = null;
    let inMessage = false;
    let logicalLine = 0;
    let oldLine = 0;
    let newLine = 0;
    const auditedPaths = new Set();

    for (const line of output.split('\n')) {
        if (line.startsWith('__MAPILIO_COMMIT__')) {
            commit = line.slice('__MAPILIO_COMMIT__'.length);
            currentPath = null;
            oldPath = null;
            logicalLine = 0;
            continue;
        }

        if (line === '__MAPILIO_MESSAGE_START__') {
            inMessage = true;
            logicalLine = 0;
            continue;
        }

        if (line === '__MAPILIO_MESSAGE_END__') {
            inMessage = false;
            continue;
        }

        if (inMessage) {
            logicalLine += 1;
            findings.push(...auditText(line, {
                commit,
                lineOffset: logicalLine,
                path: '<commit-message>',
                source: 'history',
            }, policy));
            continue;
        }

        if (line.startsWith('--- ')) {
            oldPath = decodeDiffPath(line.slice(4));
            continue;
        }

        if (line.startsWith('+++ ')) {
            currentPath = decodeDiffPath(line.slice(4)) ?? oldPath;
            logicalLine = 0;

            if (currentPath) {
                const pathKey = `${commit}|${currentPath}`;

                if (!auditedPaths.has(pathKey)) {
                    findings.push(...auditPath(currentPath, { commit, source: 'history' }, policy));
                    auditedPaths.add(pathKey);
                }
            }
            continue;
        }

        const hunk = line.match(/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/);

        if (hunk) {
            oldLine = Number(hunk[1]);
            newLine = Number(hunk[2]);
            continue;
        }

        if (!currentPath || isVendoredArtifactPath(currentPath, policy)) {
            continue;
        }

        if (isAuditableHistoryDiffLine(line)) {
            findings.push(...auditText(line.slice(1), {
                commit,
                ignorePersonalEmails: isThirdPartyMetadataPath(currentPath, policy),
                lineOffset: newLine,
                path: currentPath,
                source: 'history',
            }, policy));
            newLine += 1;
            continue;
        }

        if (line.startsWith('-') && !line.startsWith('---')) {
            oldLine += 1;
            continue;
        }

        if (line.startsWith(' ')) {
            oldLine += 1;
            newLine += 1;
        }
    }

    return applyHistoryExceptions(deduplicate(findings), policy);
}

export function formatFindings(findings, showLocations = false) {
    const counts = new Map();

    for (const finding of findings) {
        counts.set(finding.category, (counts.get(finding.category) ?? 0) + 1);
    }

    const lines = [...counts.entries()]
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([category, count]) => `${category}: ${count}`);

    if (showLocations) {
        for (const finding of findings) {
            const revision = finding.commit ? ` at ${finding.commit.slice(0, 12)}` : '';
            lines.push(`${finding.category}: ${finding.path}:${finding.line}${revision}`);
        }
    }

    return lines.join('\n');
}

function parseArguments(argv) {
    const scopeArgument = argv.find((argument) => argument.startsWith('--scope='));
    const scope = scopeArgument ? scopeArgument.split('=', 2)[1] : 'all';

    if (!['all', 'candidate', 'history'].includes(scope)) {
        throw new Error('Scope must be all, candidate, or history.');
    }

    return { scope, showLocations: argv.includes('--show-locations') };
}

function run() {
    const { scope, showLocations } = parseArguments(process.argv.slice(2));
    const policy = JSON.parse(readFileSync(defaultPolicyPath, 'utf8'));
    validatePolicy(policy);
    let findings = [];
    let scannedFiles = 0;

    if (scope === 'all' || scope === 'candidate') {
        const candidate = scanCandidate(policy);
        findings.push(...candidate.findings);
        scannedFiles = candidate.scannedFiles;
    }

    if (scope === 'all' || scope === 'history') {
        findings.push(...scanHistory(policy));
    }

    findings = deduplicate(findings);

    if (findings.length > 0) {
        console.error(`Public-content audit failed with ${findings.length} redacted finding(s).`);
        console.error(formatFindings(findings, showLocations));
        console.error('No matched value was printed. Use --show-locations only in a trusted local environment.');
        process.exitCode = 1;
        return;
    }

    const candidateSummary = scannedFiles > 0 ? ` and ${scannedFiles} candidate text file(s)` : '';
    console.log(`Public-content audit passed for scope ${scope}${candidateSummary}.`);
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
    run();
}
