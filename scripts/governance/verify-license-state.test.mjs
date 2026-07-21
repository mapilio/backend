import assert from 'node:assert/strict';
import test from 'node:test';

import { expectedLicenseState, validateLicenseState } from './verify-license-state.mjs';

const validState = () => ({
    composerPackage: { license: expectedLicenseState.composer },
    nodePackage: { license: expectedLicenseState.npm, private: true },
    openApi: {
        info: {
            license: {
                name: expectedLicenseState.openApiName,
                url: expectedLicenseState.openApiUrl,
            },
        },
    },
    rootEntries: ['README.md', 'composer.json', 'package.json'],
});

test('accepts the explicit pending project-license state', () => {
    assert.deepEqual(validateLicenseState(validState()), []);
});

test('rejects an unsupported Composer license claim', () => {
    const state = validState();
    state.composerPackage.license = 'MIT';

    assert.match(validateLicenseState(state)[0], /composer\.json license/);
});

test('rejects a publishable or licensed npm root package', () => {
    const publishable = validState();
    publishable.nodePackage['private'] = false;
    const licensed = validState();
    licensed.nodePackage.license = 'MIT';

    assert.match(validateLicenseState(publishable)[0], /must remain private/);
    assert.match(validateLicenseState(licensed)[0], /package\.json license/);
});

test('rejects a changed OpenAPI license status or governance link', () => {
    const named = validState();
    named.openApi.info.license.name = 'MIT';
    const linked = validState();
    linked.openApi.info.license.url = 'https://example.test/license';

    assert.match(validateLicenseState(named)[0], /OpenAPI license name/);
    assert.match(validateLicenseState(linked)[0], /OpenAPI license URL/);
});

test('rejects root license and copying files case-insensitively', () => {
    const state = validState();
    state.rootEntries.push('LICENSE.md', 'copying');
    const errors = validateLicenseState(state);

    assert.equal(errors.length, 1);
    assert.match(errors[0], /LICENSE\.md, copying/);
});

test('does not classify third-party notices as the project license', () => {
    const state = validState();
    state.rootEntries.push('THIRD_PARTY_LICENSES.txt', 'NOTICE');

    assert.deepEqual(validateLicenseState(state), []);
});
