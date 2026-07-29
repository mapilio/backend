import { constants as fsConstants } from 'node:fs';
import { lstat, open, realpath } from 'node:fs/promises';
import { isAbsolute, relative, resolve, sep } from 'node:path';
import { TextDecoder } from 'node:util';
import { fileURLToPath } from 'node:url';

export const MAX_INPUT_BYTES = 1024 * 1024;
export const MAX_JSON_DEPTH = 32;

const decoder = new TextDecoder('utf-8', { fatal: true });

function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function validateCoordinates(coordinates) {
    if (!Array.isArray(coordinates) || coordinates.length !== 2) {
        return 'Point coordinates must contain exactly longitude and latitude.';
    }

    const [longitude, latitude] = coordinates;
    if (!Number.isFinite(longitude) || !Number.isFinite(latitude)) {
        return 'Point coordinates must be finite numbers.';
    }
    if (longitude < -180 || longitude > 180 || latitude < -90 || latitude > 90) {
        return 'Point coordinates are outside the allowed ranges.';
    }

    return null;
}

function validateGeometry(geometry) {
    if (!isRecord(geometry) || geometry.type !== 'Point') {
        return 'Feature geometry must be a Point.';
    }

    return validateCoordinates(geometry.coordinates);
}

function validateFeature(feature) {
    if (!isRecord(feature) || feature.type !== 'Feature') {
        return 'FeatureCollection members must be Features.';
    }
    if (!isRecord(feature.properties)) {
        return 'Feature properties must be an object.';
    }

    return validateGeometry(feature.geometry);
}

function findExcessiveDepth(value) {
    const stack = [{ value, depth: 0 }];
    while (stack.length > 0) {
        const current = stack.pop();
        if (current.depth > MAX_JSON_DEPTH) {
            return true;
        }
        if (current.value !== null && typeof current.value === 'object') {
            for (const child of Object.values(current.value)) {
                stack.push({ value: child, depth: current.depth + 1 });
            }
        }
    }
    return false;
}

/** Return null for valid input, or a concise safe-to-display reason. */
export function validateGeoJson(value) {
    if (findExcessiveDepth(value)) {
        return 'JSON exceeds the maximum supported depth.';
    }
    if (!isRecord(value) || !['Point', 'Feature', 'FeatureCollection'].includes(value.type)) {
        return 'Unsupported top-level GeoJSON type.';
    }

    if (value.type === 'Point') {
        return validateCoordinates(value.coordinates);
    }
    if (value.type === 'Feature') {
        return validateFeature(value);
    }
    if (!Array.isArray(value.features)) {
        return 'FeatureCollection features must be an array.';
    }
    for (const feature of value.features) {
        const error = validateFeature(feature);
        if (error !== null) {
            return error;
        }
    }
    return null;
}

function isWithinRoot(rootPath, candidatePath) {
    const relativePath = relative(rootPath, candidatePath);
    return relativePath !== ''
        && relativePath !== '..'
        && !relativePath.startsWith(`..${sep}`)
        && !isAbsolute(relativePath);
}

async function resolveFixturePath(filePath) {
    if (typeof filePath !== 'string' || filePath.length === 0) {
        return null;
    }

    try {
        const rootPath = await realpath(process.cwd());
        const candidatePath = resolve(rootPath, filePath);
        if (!isWithinRoot(rootPath, candidatePath)) {
            return null;
        }

        const resolvedPath = await realpath(candidatePath);
        return resolvedPath === candidatePath && isWithinRoot(rootPath, resolvedPath)
            ? candidatePath
            : null;
    } catch {
        return null;
    }
}

async function readRegularFile(filePath) {
    const safePath = await resolveFixturePath(filePath);
    if (safePath === null) {
        throw new Error('invalid fixture path');
    }

    let handle;
    try {
        handle = await open(safePath, fsConstants.O_RDONLY | (fsConstants.O_NOFOLLOW ?? 0));
        const descriptor = await handle.stat();
        const pathDescriptor = await lstat(safePath);
        const openedPath = await realpath(`/proc/self/fd/${handle.fd}`).catch(() => null);
        const currentPath = await realpath(safePath).catch(() => null);
        if (!descriptor.isFile()
            || !pathDescriptor.isFile()
            || descriptor.dev !== pathDescriptor.dev
            || descriptor.ino !== pathDescriptor.ino
            || openedPath !== null && openedPath !== safePath
            || currentPath !== safePath) {
            throw new Error('not a regular file');
        }
        if (descriptor.size > MAX_INPUT_BYTES) {
            throw new Error('input is too large');
        }

        const chunks = [];
        let total = 0;
        while (total <= MAX_INPUT_BYTES) {
            const chunk = Buffer.alloc(Math.min(64 * 1024, MAX_INPUT_BYTES + 1 - total));
            const { bytesRead } = await handle.read(chunk, 0, chunk.length, null);
            if (bytesRead === 0) {
                return Buffer.concat(chunks, total);
            }
            chunks.push(chunk.subarray(0, bytesRead));
            total += bytesRead;
            if (total > MAX_INPUT_BYTES) {
                throw new Error('input is too large');
            }
        }
    } finally {
        await handle?.close();
    }
    throw new Error('input is too large');
}

export async function validateGeoJsonFile(filePath) {
    let bytes;
    try {
        bytes = await readRegularFile(filePath);
    } catch {
        return { ok: false, message: 'Unable to read the input file.' };
    }

    let value;
    try {
        value = JSON.parse(decoder.decode(bytes));
    } catch {
        return { ok: false, message: 'Malformed JSON.' };
    }

    const message = validateGeoJson(value);
    return message === null ? { ok: true } : { ok: false, message };
}

export async function main(args = process.argv.slice(2)) {
    if (args.length !== 1) {
        console.error('Usage: npm run validate:geojson -- <file>');
        return 2;
    }

    const result = await validateGeoJsonFile(args[0]);
    if (result.ok) {
        console.log('GeoJSON is valid.');
        return 0;
    }
    console.error(`GeoJSON validation failed: ${result.message}`);
    return 1;
}

const invokedPath = process.argv[1];
if (invokedPath && fileURLToPath(import.meta.url) === resolve(invokedPath)) {
    process.exitCode = await main();
}
