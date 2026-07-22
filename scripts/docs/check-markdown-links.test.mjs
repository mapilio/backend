import test from 'node:test';
import assert from 'node:assert/strict';
import { findMarkdownLinkIssues, parseMarkdownLinks } from './check-markdown-links.mjs';

const check = (contents, paths = ['docs/read me.md', 'docs/nested/item.md', 'images/photo one.png']) => findMarkdownLinkIssues({ markdownFiles: { 'docs/index.md': contents }, trackedPaths: paths });

test('accepts links, images, nested paths, spaces, queries, fragments, and balanced parentheses', () => {
    assert.deepEqual(check('[item](nested/item.md?x=1#part) ![photo](<../images/photo one.png>) [x](nested/(draft).md) [quoted](<file "draft" (one).md>)', ['docs/nested/item.md', 'docs/nested/(draft).md', 'images/photo one.png', 'docs/file "draft" (one).md']), []);
});

test('accepts URL-encoded and escaped spaces', () => {
    assert.deepEqual(check('[a](read%20me.md "title") [b](<read me.md> \'title\') [c](read\\%20me.md)', ['docs/read me.md']), []);
    assert.equal(check('[c](read\\%20me.md)', ['docs/read%20me.md']).length, 1);
});

test('reports missing, case mismatch, and repository escape with source and line', () => {
    const issues = check('[x](missing.md)\n[x](Nested/item.md)\n[x](../../secret.md)', ['docs/nested/item.md']);
    assert.deepEqual(issues.map(({ line, message }) => [line, message]), [[1, "missing target 'docs/missing.md'"], [2, "case mismatch for 'docs/Nested/item.md'"], [3, 'repository escape']]);
});

test('reports malformed percent encoding', () => assert.match(check('[x](bad%2.md)')[0].message, /malformed percent encoding/));

test('ignores fenced code, inline code, external schemes, protocol-relative, and fragments', () => {
    const source = '`[x](missing.md)`\n```\n[x](missing.md)\n```\n~~~md\n[x](missing.md)\n~~~~\n[http](https://example.test) [mail](mailto:a@b.test) [other](ftp://example.test) [host](//example.test) [frag](#x)';
    assert.deepEqual(check(source), []);
});

test('keeps fence state synchronized when a same-marker line has info text', () => {
    const source = '```\n[x](missing-one.md)\n```not-a-close\n[x](missing-two.md)\n```\n[x](missing-three.md)';
    assert.deepEqual(check(source).map(({ line, destination }) => [line, destination]), [[6, 'missing-three.md']]);
});

test('keeps CRLF fence state synchronized', () => {
    const source = '```js\r\n[x](hidden.md)\r\n```\r\n[x](missing.md)\r\n';
    assert.deepEqual(check(source).map(({ line, destination }) => [line, destination]), [[4, 'missing.md']]);
});

test('does not open a backtick fence when its info string contains a backtick', () => {
    assert.deepEqual(check('```info` [x](missing.md)'), [{ source: 'docs/index.md', line: 1, destination: 'missing.md', message: "missing target 'docs/missing.md'" }]);
});

test('validates reference definitions and avoids code literals', () => {
    assert.deepEqual(check('[good]: <read%20me.md>\n[bad]: missing.md\n`[literal]: missing.md`').map(({ line }) => line), [2]);
});

test('returns deterministic source, line, destination ordering', () => {
    const issues = findMarkdownLinkIssues({ markdownFiles: { 'z.md': '[x](z.md)\n[x](missing.md)', 'a.md': '[x](b.md)' }, trackedPaths: ['a.md', 'z.md'] });
    assert.deepEqual(issues.map(({ source, line, destination }) => `${source}:${line}:${destination}`), ['a.md:1:b.md', 'z.md:2:missing.md']);
});

test('parses only links outside inline code', () => assert.equal(parseMarkdownLinks('`[x](a.md)` [y](a.md)').length, 1));

test('does not let an unequal longer backtick run close inline code', () => {
    assert.equal(parseMarkdownLinks('``[hidden](missing.md)```').length, 1);
});

test('splits query and fragment before decoding the path', () => {
    assert.deepEqual(check('[x](name%3F.md?bad%2) [y](name%23.md#bad%ZZ)', ['docs/name?.md', 'docs/name#.md']), []);
});

test('normalizes escaped external URLs and fragments before classification', () => {
    const source = '[external](https\\:\\/\\/example.test) [fragment](\\#section) [encoded](https%3A//example.md)';
    assert.deepEqual(check(source, ['docs/https:/example.md']), []);
});

test('unescapes escapable punctuation that remains in a path', () => {
    assert.deepEqual(check('[x](punct\\!\\$\\&\\\'\\(\\)\\*\\+\\,\\-\\.\\/\\:\\;\\<\\=\\>\\@\\[\\]\\^\\_\\`\\{\\|\\}\\~\\\\.md)', ['docs/punct!$&\'()*+,-./:;<=>@[]^_`{|}~\\.md']), []);
});

test('scans reference definitions with escaped labels and accurate lines', () => {
    assert.deepEqual(check('[good\\]]: read%20me.md\n[bad\\]]: missing.md').map(({ line }) => line), [2]);
});

test('scans a bounded one-line reference destination continuation', () => {
    const issues = check('[good]:\r\n   read%20me.md\n[bad]:\n  missing.md\n[title]:\n  read%20me.md\n  "optional title"', ['docs/read me.md']);
    assert.deepEqual(issues.map(({ line, destination }) => [line, destination]), [[3, 'missing.md']]);
});

test('rejects line breaks inside angle-bracket destinations', () => {
    assert.deepEqual(check('[lf](<bad\npath.md>) [crlf](<bad\r\npath.md>)'), []);
    assert.deepEqual(parseMarkdownLinks('[lf](<bad\npath.md>) [crlf](<bad\r\npath.md>)'), []);
});

test('allows escaped angle closing brackets in destinations', () => {
    assert.deepEqual(check('[x](<file\\>.md>)', ['docs/file>.md']), []);
});

test('rejects unescaped angle opening brackets and accepts escaped angle brackets', () => {
    assert.deepEqual(parseMarkdownLinks('[bad](<file<bad>.md>) [good](<file\\<and\\>.md>)').map(({ destination }) => destination), ['file\\<and\\>.md']);
    assert.deepEqual(check('[good](<file\\<and\\>.md>)', ['docs/file<and>.md']), []);
});

test('does not treat a four-space next line as a reference continuation', () => {
    const issues = check('[good]:\n    missing.md', ['docs/missing.md']);
    assert.deepEqual(issues, []);
});

test('does not treat tab-indented next lines as reference continuations', () => {
    assert.deepEqual(check('[tab]:\n\tmissing.md\n[spaces-tab]:\n   \tmissing.md', ['docs/missing.md']), []);
});

test('rejects symlink inputs and link targets without following them', () => {
    const issues = findMarkdownLinkIssues({
        markdownFiles: { 'docs/link.md': '[x](alias.md)' },
        trackedPaths: ['docs/link.md', 'docs/symlink.md', 'docs/alias.md'],
        symlinkPaths: ['docs/symlink.md', 'docs/alias.md'],
        symlinkInputPaths: ['docs/symlink.md'],
    });
    assert.deepEqual(issues.map(({ source, line, message }) => [source, line, message]), [
        ['docs/link.md', 1, "tracked symlink target 'docs/alias.md'"],
        ['docs/symlink.md', 1, 'tracked symlink Markdown input'],
    ]);
});

test('sorts mixed-case paths deterministically by code unit', () => {
    const issues = findMarkdownLinkIssues({ markdownFiles: { 'a.md': '[x](missing-a)', 'B.md': '[x](missing-b)' }, trackedPaths: ['a.md', 'B.md'] });
    assert.deepEqual(issues.map(({ source }) => source), ['B.md', 'a.md']);
});
