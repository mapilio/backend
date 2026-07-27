import { createHash } from 'node:crypto';
import { copyFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const specificationPath = resolve(repositoryRoot, 'docs/api/openapi-v1.json');
const outputDirectory = resolve(repositoryRoot, 'public/docs/api');
const runtimeSource = resolve(repositoryRoot, 'resources/docs/redoc.standalone.js');
const runtimeTarget = resolve(outputDirectory, 'redoc.standalone.js');
const runtimeLicenseSource = resolve(repositoryRoot, 'resources/docs/redoc.standalone.js.LICENSE.txt');
const runtimeLicenseTarget = resolve(outputDirectory, 'redoc.standalone.js.LICENSE.txt');
const packageLicenseSource = resolve(repositoryRoot, 'resources/docs/redoc.LICENSE');
const packageLicenseTarget = resolve(outputDirectory, 'redoc.LICENSE');
const htmlPath = resolve(outputDirectory, 'index.html');
const reviewedRuntimeSha256 = '1320f442151c57c447d3b70c7ffc6c4f86d08464020fe34c8cc5d3164e9944f0';
const reviewedLicenseSha256 = '469cc94b600aac09643f70e167cd1f66f24301ebb546532fad5db7c60f7b30d0';
const reviewedPackageLicenseSha256 = 'd3026d549cf68ab7355bcfa85877bf8f845b3334a7efbfdc63936432fb34ff0e';

const assertReviewedBytes = (label, bytes, expectedSha256) => {
    const actualSha256 = createHash('sha256').update(bytes).digest('hex');
    if (actualSha256 !== expectedSha256) {
        throw new Error(`Reviewed ${label} checksum mismatch.`);
    }
};

const [specificationSource, runtime, runtimeLicense, packageLicense] = await Promise.all([
    readFile(specificationPath, 'utf8'),
    readFile(runtimeSource),
    readFile(runtimeLicenseSource),
    readFile(packageLicenseSource),
]);
assertReviewedBytes('Redoc runtime', runtime, reviewedRuntimeSha256);
assertReviewedBytes('Redoc license notice', runtimeLicense, reviewedLicenseSha256);
assertReviewedBytes('Redoc package license', packageLicense, reviewedPackageLicenseSha256);
const specification = JSON.parse(specificationSource);
const integrity = `sha384-${createHash('sha384').update(runtime).digest('base64')}`;
const options = {
    jsonSampleExpandLevel: 3,
    nativeScrollbars: true,
    requiredPropsFirst: true,
    schemaExpansionLevel: 1,
    sideNavStyle: 'path-first',
    sortPropsAlphabetically: true,
    theme: {
        colors: {
            error: { main: '#b42335' },
            primary: { main: '#087f6d' },
            success: { main: '#237a45' },
            text: { primary: '#17212b' },
            warning: { main: '#a86400' },
        },
        rightPanel: { backgroundColor: '#18272d' },
        sidebar: {
            activeTextColor: '#087f6d',
            backgroundColor: '#f4f7f6',
            textColor: '#243530',
        },
        typography: {
            code: { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' },
            fontFamily: 'ui-sans-serif, system-ui, sans-serif',
            headings: {
                fontFamily: 'ui-sans-serif, system-ui, sans-serif',
                fontWeight: '650',
            },
        },
    },
};

const serializeForInlineScript = (value) => JSON.stringify(value)
    .replace(/<\//g, '<\\/')
    .replace(/\u2028/g, '\\u2028')
    .replace(/\u2029/g, '\\u2029');

const html = `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; font-src 'self'; connect-src 'none'; object-src 'none'; base-uri 'none'">
  <meta name="description" content="Versioned Mapilio backend API reference generated from the OpenAPI contract.">
  <title>Mapilio API Reference</title>
  <style>body { margin: 0; padding: 0; }</style>
</head>
<body>
  <div id="redoc"></div>
  <script src="./redoc.standalone.js" integrity="${integrity}"></script>
  <script>
    const specification = ${serializeForInlineScript(specification)};
    const options = ${serializeForInlineScript(options)};
    Redoc.init(specification, options, document.getElementById('redoc'));
  </script>
</body>
</html>
`;

await mkdir(outputDirectory, { recursive: true });
await Promise.all([
    writeFile(htmlPath, html),
    copyFile(runtimeSource, runtimeTarget),
    copyFile(runtimeLicenseSource, runtimeLicenseTarget),
    copyFile(packageLicenseSource, packageLicenseTarget),
]);

console.log(`Generated ${htmlPath}`);
