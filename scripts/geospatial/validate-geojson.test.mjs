import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, relative } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import {
    MAX_INPUT_BYTES,
    MAX_JSON_DEPTH,
    validateGeoJson,
    validateGeoJsonFile,
} from './validate-geojson.mjs';

const script = fileURLToPath(new URL('./validate-geojson.mjs', import.meta.url));

const point = { type: 'Point', coordinates: [29, 41] };
const feature = { type: 'Feature', properties: {}, geometry: point };
const temporaryDirectories = [];
const repositoryRelative = (path) => relative(process.cwd(), path);

async function makeTemporaryDirectory(prefix) {
    const directory = await mkdtemp(join(process.cwd(), prefix));
    temporaryDirectories.push(directory);
    return directory;
}

test.after(async () => {
    await Promise.all(temporaryDirectories.map((directory) => rm(directory, { force: true, recursive: true })));
});

function runCli(...args) {
    return new Promise((resolve) => {
        const child = spawn(process.execPath, [script, ...args], { encoding: 'utf8' });
        let stdout = '';
        let stderr = '';
        child.stdout.on('data', (chunk) => { stdout += chunk; });
        child.stderr.on('data', (chunk) => { stderr += chunk; });
        child.on('close', (code) => resolve({ code, stdout, stderr }));
    });
}

test('accepts Point, Feature, and FeatureCollection', () => {
    assert.equal(validateGeoJson(point), null);
    assert.equal(validateGeoJson(feature), null);
    assert.equal(validateGeoJson({ type: 'FeatureCollection', features: [feature] }), null);
});

test('rejects malformed, unsupported, and non-Point geometries', () => {
    assert.equal(validateGeoJson({ type: 'LineString', coordinates: [] }), 'Unsupported top-level GeoJSON type.');
    assert.equal(validateGeoJson({ type: 'Feature', properties: {}, geometry: { type: 'Polygon' } }), 'Feature geometry must be a Point.');
    assert.equal(validateGeoJson({ type: 'Feature', properties: {}, geometry: null }), 'Feature geometry must be a Point.');
});

test('rejects invalid coordinates, including swapped and non-finite values', () => {
    for (const coordinates of [[41], [29, 41, 1], ['29', 41], [29, null], [29, Infinity], [181, 41], [29, 91], [91, 181]]) {
        assert.notEqual(validateGeoJson({ type: 'Point', coordinates }), null);
    }
    assert.equal(validateGeoJson({ type: 'Point', coordinates: [41, 29] }), null);
});

test('rejects invalid properties and collections', () => {
    assert.notEqual(validateGeoJson({ type: 'Feature', properties: null, geometry: point }), null);
    assert.notEqual(validateGeoJson({ type: 'Feature', properties: [], geometry: point }), null);
    assert.notEqual(validateGeoJson({ type: 'FeatureCollection', features: [{ ...feature, properties: null }] }), null);
    assert.notEqual(validateGeoJson({ type: 'FeatureCollection', features: [point] }), null);
    assert.notEqual(validateGeoJson({ type: 'FeatureCollection', features: {} }), null);
});

test('rejects excessive depth before structural validation', () => {
    let value = point;
    for (let i = 0; i < MAX_JSON_DEPTH + 1; i += 1) value = [value];
    assert.equal(validateGeoJson(value), 'JSON exceeds the maximum supported depth.');
});

test('accepts root depth 32 and rejects root depth 33', () => {
    const featureAtDepth = (propertiesDepth) => {
        let properties = {};
        for (let depth = 1; depth < propertiesDepth; depth += 1) {
            properties = { nested: properties };
        }
        return { type: 'Feature', properties, geometry: point };
    };

    assert.equal(validateGeoJson(featureAtDepth(MAX_JSON_DEPTH)), null);
    assert.equal(validateGeoJson(featureAtDepth(MAX_JSON_DEPTH + 1)), 'JSON exceeds the maximum supported depth.');
});

test('file validation normalizes malformed JSON, overflow, UTF-8, and size failures', async () => {
    const directory = await makeTemporaryDirectory('.geojson-validator-');
    const malformed = join(directory, 'malformed.json');
    const overflow = join(directory, 'overflow.json');
    const invalidUtf8 = join(directory, 'invalid-utf8.json');
    const exactSize = join(directory, 'exact-size.json');
    const large = join(directory, 'large.json');
    await writeFile(malformed, '{');
    await writeFile(overflow, '{"type":"Point","coordinates":[1e999,0]}');
    await writeFile(invalidUtf8, Buffer.from([0x7b, 0x22, 0x78, 0x22, 0x3a, 0xff, 0x7d]));
    const validJson = JSON.stringify(point);
    await writeFile(exactSize, `${validJson}${' '.repeat(MAX_INPUT_BYTES - Buffer.byteLength(validJson))}`);
    await writeFile(large, Buffer.alloc(MAX_INPUT_BYTES + 1, 0x20));
    assert.deepEqual(await validateGeoJsonFile(malformed), { ok: false, message: 'Malformed JSON.' });
    assert.notEqual((await validateGeoJsonFile(repositoryRelative(overflow))).ok, true);
    assert.deepEqual(await validateGeoJsonFile(repositoryRelative(invalidUtf8)), { ok: false, message: 'Malformed JSON.' });
    assert.deepEqual(await validateGeoJsonFile(exactSize), { ok: true });
    assert.deepEqual(await validateGeoJsonFile(repositoryRelative(large)), { ok: false, message: 'Unable to read the input file.' });
});

test('CLI requires exactly one argument and never echoes paths or fixture contents', async () => {
    const noArgs = await runCli();
    const twoArgs = await runCli('one', 'two');
    assert.equal(noArgs.code, 2);
    assert.equal(twoArgs.code, 2);
    const directory = await makeTemporaryDirectory('.sentinel-');
    const sentinel = 'SECRET_SENTINEL_FIXTURE_CONTENT';
    const file = join(directory, 'sentinel.json');
    await writeFile(file, `${sentinel}{`);
    const result = await runCli(repositoryRelative(file));
    assert.equal(result.code, 1);
    assert.doesNotMatch(`${result.stdout}${result.stderr}`, new RegExp(sentinel));
    assert.doesNotMatch(`${result.stdout}${result.stderr}`, new RegExp(directory.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
});

test('CLI rejects directories and symlinks', async () => {
    const directory = await makeTemporaryDirectory('.geojson-files-');
    const childDirectory = join(directory, 'directory');
    const target = join(directory, 'target.json');
    const link = join(directory, 'link.json');
    await mkdir(childDirectory);
    await writeFile(target, JSON.stringify(point));
    await symlink(target, link);
    assert.equal((await runCli(repositoryRelative(childDirectory))).code, 1);
    assert.equal((await runCli(repositoryRelative(link))).code, 1);
});

test('rejects absolute and traversal paths outside the repository boundary', async () => {
    const outsideDirectory = await mkdtemp(join(tmpdir(), 'geojson-outside-'));
    const outsideFile = join(outsideDirectory, 'outside.json');
    await writeFile(outsideFile, JSON.stringify(point));

    assert.deepEqual(await validateGeoJsonFile(outsideFile), {
        ok: false,
        message: 'Unable to read the input file.',
    });
    assert.deepEqual(await validateGeoJsonFile(relative(process.cwd(), outsideFile)), {
        ok: false,
        message: 'Unable to read the input file.',
    });
    await rm(outsideDirectory, { force: true, recursive: true });
});
