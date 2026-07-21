#!/usr/bin/env node

import { readFile, readdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(scriptDirectory, '../..');

export const expectedLicenseState = Object.freeze({
    composer: 'proprietary',
    npm: 'UNLICENSED',
    openApiName: 'License not yet selected',
    openApiUrl: 'https://github.com/mapilio/backend/blob/main/docs/governance/public-release-decisions.md',
});

const rootLicenseFilePattern = /^(?:license|copying)(?:\.|$)/i;

export function validateLicenseState({ composerPackage, nodePackage, openApi, rootEntries }) {
    const errors = [];

    if (composerPackage.license !== expectedLicenseState.composer) {
        errors.push(`composer.json license must be ${expectedLicenseState.composer} while project terms are pending.`);
    }

    if (nodePackage['private'] !== true) {
        errors.push('package.json must remain private while project terms are pending.');
    }

    if (nodePackage.license !== expectedLicenseState.npm) {
        errors.push(`package.json license must be ${expectedLicenseState.npm} while project terms are pending.`);
    }

    if (openApi.info?.license?.name !== expectedLicenseState.openApiName) {
        errors.push('OpenAPI license name must state that the project license is not yet selected.');
    }

    if (openApi.info?.license?.url !== expectedLicenseState.openApiUrl) {
        errors.push('OpenAPI license URL must point to the public-release governance decision.');
    }

    const rootLicenseFiles = rootEntries.filter((entry) => rootLicenseFilePattern.test(entry));

    if (rootLicenseFiles.length > 0) {
        errors.push(`Root license file is not allowed while project terms are pending: ${rootLicenseFiles.join(', ')}`);
    }

    return errors;
}

async function readJson(path) {
    return JSON.parse(await readFile(path, 'utf8'));
}

async function main() {
    const [composerPackage, nodePackage, openApi, rootEntries] = await Promise.all([
        readJson(resolve(repositoryRoot, 'composer.json')),
        readJson(resolve(repositoryRoot, 'package.json')),
        readJson(resolve(repositoryRoot, 'docs/api/openapi-v1.json')),
        readdir(repositoryRoot),
    ]);
    const errors = validateLicenseState({ composerPackage, nodePackage, openApi, rootEntries });

    if (errors.length > 0) {
        console.error('Pending project-license state verification failed:');

        for (const error of errors) {
            console.error(`- ${error}`);
        }

        process.exitCode = 1;
        return;
    }

    console.log('Pending project-license state is consistent across package metadata, OpenAPI, and root files.');
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    await main();
}
