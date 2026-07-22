import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const specificationPath = resolve(repositoryRoot, 'docs/api/openapi-v1.json');

const operationMethods = new Set(['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace']);

const escapePointerToken = (token) => String(token).replaceAll('~', '~0').replaceAll('/', '~1');

const hasOwn = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
const RESOLUTION_FAILED = Symbol('resolution failed');

/**
 * Return coverage problems for operation request/response media types whose
 * schemas are documented without an inline example value.
 */
export function findExampleCoverageIssues(specification) {
    const issues = [];

    for (const [path, pathItem] of Object.entries(specification.paths ?? {})) {
        for (const [method, operation] of Object.entries(pathItem ?? {})) {
            if (!operationMethods.has(method) || !operation || typeof operation !== 'object') continue;

            const operationPointer = `/paths/${escapePointerToken(path)}/${method}`;
            const requestBodyPointer = `${operationPointer}/requestBody`;
            const requestBody = resolveLocalReference(specification, operation.requestBody, requestBodyPointer, issues);
            inspectContent(requestBody?.content, `${requestBodyPointer}/content`, issues, specification);

            for (const [status, response] of Object.entries(operation.responses ?? {})) {
                const responsePointer = `${operationPointer}/responses/${escapePointerToken(status)}`;
                const resolvedResponse = resolveLocalReference(specification, response, responsePointer, issues);
                inspectContent(resolvedResponse?.content, `${responsePointer}/content`, issues, specification);
            }
        }
    }

    return issues;
}

export function validateExampleCoverage(specification) {
    const issues = findExampleCoverageIssues(specification);

    if (issues.length > 0) {
        const error = new Error(`OpenAPI example coverage failed:\n${issues.map((issue) => `- ${issue.pointer}: ${issue.message}`).join('\n')}`);
        error.issues = issues;
        throw error;
    }

    return true;
}

function resolveLocalReference(specification, value, pointer, issues) {
    let current = value;
    const seen = new Set();

    while (current && typeof current === 'object' && hasOwn(current, '$ref')) {
        const reference = current.$ref;
        if (typeof reference !== 'string') {
            issues.push({ pointer: `${pointer}/$ref`, message: 'reference must be a local JSON Pointer string' });
            return RESOLUTION_FAILED;
        }

        if (reference !== '#' && !reference.startsWith('#/')) {
            issues.push({ pointer: `${pointer}/$ref`, message: 'external references are not supported by this deterministic offline coverage gate' });
            return RESOLUTION_FAILED;
        }

        if (seen.has(reference)) {
            issues.push({ pointer: `${pointer}/$ref`, message: `cyclic local reference detected: ${reference}` });
            return RESOLUTION_FAILED;
        }
        seen.add(reference);

        const target = resolveJsonPointer(specification, reference);
        if (target === undefined) {
            issues.push({ pointer: `${pointer}/$ref`, message: `local reference does not resolve: ${reference}` });
            return RESOLUTION_FAILED;
        }
        current = target;
    }

    return current;
}

function resolveJsonPointer(value, reference) {
    if (reference === '#') return value;

    let tokens;
    try {
        tokens = reference.slice(2).split('/').map((token) => token.replaceAll('~1', '/').replaceAll('~0', '~'));
    } catch {
        return undefined;
    }

    let current = value;
    for (const token of tokens) {
        if (!current || (typeof current !== 'object' && !Array.isArray(current)) || !hasOwn(current, token)) return undefined;
        current = current[token];
    }
    return current;
}

function inspectContent(content, contentPointer, issues, specification) {
    for (const [mediaType, mediaObject] of Object.entries(content ?? {})) {
        if (!mediaObject || typeof mediaObject !== 'object' || !hasOwn(mediaObject, 'schema')) continue;

        const mediaPointer = `${contentPointer}/${escapePointerToken(mediaType)}`;
        if (hasOwn(mediaObject, 'example')) continue;

        if (!hasOwn(mediaObject, 'examples')) {
            issues.push({ pointer: mediaPointer, message: 'schema has no inline example or examples value' });
            continue;
        }

        const examples = mediaObject.examples;
        if (!examples || typeof examples !== 'object' || Array.isArray(examples) || Object.keys(examples).length === 0) {
            issues.push({ pointer: `${mediaPointer}/examples`, message: 'examples must be a non-empty map with value entries' });
            continue;
        }

        for (const [name, example] of Object.entries(examples)) {
            const examplePointer = `${mediaPointer}/examples/${escapePointerToken(name)}`;
            const resolvedExample = resolveLocalReference(specification, example, examplePointer, issues);
            if (resolvedExample === RESOLUTION_FAILED) continue;
            if (!resolvedExample || typeof resolvedExample !== 'object') {
                issues.push({ pointer: examplePointer, message: 'example entry must resolve to an Example Object with a value' });
                continue;
            }

            // externalValue is intentionally forbidden: coverage must stay repository-visible and offline.
            if (hasOwn(resolvedExample, 'externalValue')) {
                issues.push({ pointer: examplePointer, message: 'externalValue is not supported; examples must be repository-visible, synthetic, and schema-valid offline' });
            } else if (!hasOwn(resolvedExample, 'value')) {
                issues.push({
                    pointer: examplePointer,
                    message: 'example entry must resolve to an Example Object with a value',
                });
            }
        }
    }
}

async function runCli() {
    const specification = JSON.parse(await readFile(specificationPath, 'utf8'));
    validateExampleCoverage(specification);
    console.log('OpenAPI example coverage passed.');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    runCli().catch((error) => {
        console.error(JSON.stringify({ error: error.message, issues: error.issues ?? [] }, null, 2));
        process.exitCode = 1;
    });
}
