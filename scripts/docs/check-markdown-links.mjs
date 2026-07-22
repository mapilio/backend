import { readFile } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const execFileAsync = promisify(execFile);
const MARKDOWN_FILE = /\.(?:md|markdown)$/i;
const SCHEME = /^[A-Za-z][A-Za-z0-9+.-]*:/;
const ESCAPABLE = new Set('\\!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~'.split(''));

function compareCodeUnits(left, right) {
    return left < right ? -1 : left > right ? 1 : 0;
}

function isEscaped(text, index) {
    let slashes = 0;
    for (let cursor = index - 1; cursor >= 0 && text[cursor] === '\\'; cursor -= 1) slashes += 1;
    return slashes % 2 === 1;
}

function markerRun(line, start) {
    const marker = line[start];
    let end = start;
    while (line[end] === marker) end += 1;
    return { marker, length: end - start, end };
}

function fenceOpening(line) {
    const match = line.match(/^ {0,3}(`{3,}|~{3,})([^\n]*)/);
    if (!match) return null;
    if (match[1][0] === '`' && match[2].includes('`')) return null;
    return { marker: match[1][0], length: match[1].length };
}

function fenceClosing(line, fence) {
    const indentation = line.match(/^ {0,3}/)[0].length;
    if (line[indentation] !== fence.marker) return false;
    const run = markerRun(line, indentation);
    return run.length >= fence.length && /^[ \t]*(?:\r?\n|$)/.test(line.slice(run.end));
}

function findInlineCodeClose(line, start, length) {
    const delimiter = '`'.repeat(length);
    for (let index = start; index < line.length; index += 1) {
        if (line[index] !== '`' || isEscaped(line, index)) continue;
        const run = markerRun(line, index);
        if (run.length === length && line.slice(index, run.end) === delimiter) return index;
        index = run.end - 1;
    }
    return -1;
}

function maskInlineCode(line) {
    const output = [...line];
    for (let index = 0; index < line.length; index += 1) {
        if (line[index] !== '`' || isEscaped(line, index)) continue;
        const run = markerRun(line, index);
        const close = findInlineCodeClose(line, run.end, run.length);
        if (close < 0) continue;
        for (let cursor = index; cursor < close + run.length; cursor += 1) if (output[cursor] !== '\n') output[cursor] = ' ';
        index = close + run.length - 1;
    }
    return output.join('');
}

function maskCode(source) {
    const lines = source.split(/(?<=\n)/);
    let fence = null;
    const masked = [];
    for (const line of lines) {
        if (fence) {
            masked.push(line.replace(/[^\n]/g, ' '));
            if (fenceClosing(line, fence)) fence = null;
            continue;
        }
        const opening = fenceOpening(line);
        if (opening) {
            fence = opening;
            masked.push(line.replace(/[^\n]/g, ' '));
            continue;
        }
        masked.push(maskInlineCode(line));
    }
    return masked.join('');
}

function parseDestination(text, start) {
    let index = start;
    while (/\s/.test(text[index] ?? '')) index += 1;
    let destinationStart = index;
    let destinationEnd;
    if (text[index] === '<') {
        destinationStart = ++index;
        while (index < text.length && text[index] !== '\n' && text[index] !== '\r' && (text[index] !== '>' || isEscaped(text, index))) {
            if (text[index] === '<' && !isEscaped(text, index)) return null;
            index += 1;
        }
        if (text[index] !== '>') return null;
        destinationEnd = index++;
    } else {
        const begin = index;
        let depth = 0;
        while (index < text.length) {
            const char = text[index];
            if (isEscaped(text, index)) { index += 1; continue; }
            if (char === '(') depth += 1;
            else if (char === ')') {
                if (depth === 0) { destinationEnd = index; break; }
                depth -= 1;
            } else if (/\s/.test(char) && depth === 0) { destinationEnd = index; break; }
            index += 1;
        }
        if (destinationEnd === undefined || destinationEnd === begin) return null;
    }
    while (/\s/.test(text[index] ?? '')) index += 1;
    if (text[index] !== ')') {
        const quote = text[index];
        if (!['"', "'", '('].includes(quote)) return null;
        const closing = quote === '(' ? ')' : quote;
        index += 1;
        while (index < text.length && (text[index] !== closing || isEscaped(text, index))) index += 1;
        if (text[index] !== closing) return null;
        index += 1;
        while (/\s/.test(text[index] ?? '')) index += 1;
    }
    return { start: destinationStart, end: destinationEnd, close: index };
}

function findClosingBracket(text, start) {
    let depth = 1;
    for (let index = start + 1; index < text.length; index += 1) {
        if (isEscaped(text, index)) continue;
        if (text[index] === '[') depth += 1;
        if (text[index] === ']' && --depth === 0) return index;
    }
    return -1;
}

function lineNumber(source, index) { return source.slice(0, index).split('\n').length; }

function referenceDefinitions(source, masked) {
    const links = [];
    let offset = 0;
    const lines = masked.split(/(?<=\n)/);
    for (let lineIndex = 0; lineIndex < lines.length; lineIndex += 1) {
        const line = lines[lineIndex];
        const indentation = line.match(/^ {0,3}/)[0].length;
        if (line[indentation] !== '[') { offset += line.length; continue; }
        const close = findClosingBracket(line, indentation);
        if (close < 0 || line[close + 1] !== ':') { offset += line.length; continue; }
        let destinationOffset = close + 2;
        while (/[ \t]/.test(line[destinationOffset] ?? '')) destinationOffset += 1;
        let destinationLine = line;
        let destinationLineOffset = destinationOffset;
        let destinationBase = offset;
        const lineContentEnd = (value) => value.replace(/[\r\n]+$/, '').length;
        if (destinationOffset >= lineContentEnd(line)) {
            const next = lines[lineIndex + 1];
            if (next === undefined) { offset += line.length; continue; }
            const nextIndentation = next.match(/^ */)[0].length;
            const nextContentEnd = lineContentEnd(next);
            if (nextIndentation > 3 || next[nextIndentation] === '\t' || nextIndentation >= nextContentEnd) { offset += line.length; continue; }
            destinationLine = next;
            destinationLineOffset = nextIndentation;
            destinationBase = offset + line.length;
        }
        const destinationTextEnd = lineContentEnd(destinationLine);
        const destinationText = destinationLine.slice(destinationLineOffset, destinationTextEnd);
        const parsed = parseDestination(`${destinationText})`, 0);
        if (parsed && parsed.close === destinationText.length) {
            links.push({ destination: source.slice(destinationBase + destinationLineOffset + parsed.start, destinationBase + destinationLineOffset + parsed.end), line: lineNumber(source, offset + indentation) });
        }
        offset += line.length;
    }
    return links;
}

export function parseMarkdownLinks(source) {
    const masked = maskCode(source);
    const links = [];
    for (let index = 0; index < masked.length; index += 1) {
        if (masked[index] !== '[' || isEscaped(masked, index)) continue;
        const close = findClosingBracket(masked, index);
        if (close < 0) continue;
        const parsed = masked[close + 1] === '(' ? parseDestination(masked, close + 2) : null;
        if (parsed && masked[parsed.close] === ')') links.push({ destination: source.slice(parsed.start, parsed.end), line: lineNumber(source, index) });
        index = close;
    }
    return links.concat(referenceDefinitions(source, masked));
}

function unescapeDestination(destination) {
    let result = '';
    for (let index = 0; index < destination.length; index += 1) {
        result += destination[index] === '\\' && ESCAPABLE.has(destination[index + 1]) ? destination[++index] : destination[index];
    }
    return result;
}

function splitSuffix(destination) {
    for (let index = 0; index < destination.length; index += 1) {
        if ((destination[index] === '?' || destination[index] === '#') && !isEscaped(destination, index)) return [destination.slice(0, index), destination.slice(index)];
    }
    return [destination, ''];
}

function classifyTarget(source, line, rawDestination, trackedPaths, symlinkPaths) {
    let destination = unescapeDestination(rawDestination.trim());
    if (!destination || destination.startsWith('#') || destination.startsWith('//') || SCHEME.test(destination)) return null;
    const [rawPath] = splitSuffix(destination);
    let decoded;
    try { decoded = decodeURIComponent(rawPath); }
    catch { return { source, line, destination, message: 'malformed percent encoding' }; }
    if (!decoded) return null;
    const sourceDir = path.posix.dirname(source);
    const resolved = path.posix.normalize(path.posix.join(sourceDir, decoded));
    if (decoded.startsWith('/') || resolved === '..' || resolved.startsWith('../')) return { source, line, destination, message: 'repository escape' };
    const exact = new Set(trackedPaths);
    const symlinks = new Set(symlinkPaths);
    const directories = new Set();
    for (const tracked of exact) {
        let current = path.posix.dirname(tracked);
        while (current !== '.') { directories.add(current); current = path.posix.dirname(current); }
    }
    if (symlinks.has(resolved)) return { source, line, destination, message: `tracked symlink target '${resolved}'` };
    if (exact.has(resolved) || directories.has(resolved)) return null;
    const candidates = [...exact, ...directories];
    if (candidates.some((candidate) => candidate.toLowerCase() === resolved.toLowerCase())) return { source, line, destination, message: `case mismatch for '${resolved}'` };
    return { source, line, destination, message: `missing target '${resolved}'` };
}

export function findMarkdownLinkIssues({ markdownFiles, trackedPaths, symlinkPaths = [], symlinkInputPaths = [] }) {
    const symlinks = new Set(symlinkPaths);
    const symlinkInputs = new Set(symlinkInputPaths);
    const issues = [...symlinkInputs].sort(compareCodeUnits).map((source) => ({ source, line: 1, destination: source, message: 'tracked symlink Markdown input' }));
    for (const [source, contents] of Object.entries(markdownFiles).sort(([a], [b]) => compareCodeUnits(a, b))) {
        if (symlinkInputs.has(source)) continue;
        for (const link of parseMarkdownLinks(contents)) {
            const issue = classifyTarget(source, link.line, link.destination, trackedPaths, symlinks);
            if (issue) issues.push(issue);
        }
    }
    return issues.sort((a, b) => compareCodeUnits(a.source, b.source) || a.line - b.line || compareCodeUnits(a.destination, b.destination) || compareCodeUnits(a.message, b.message));
}

export function formatIssues(issues) {
    const lines = issues.map((issue) => `${issue.source}:${issue.line}: ${issue.message} (${issue.destination})`);
    return [...lines, `Found ${issues.length} invalid relative Markdown link${issues.length === 1 ? '' : 's'}.`].join('\n');
}

async function trackedEntries() {
    const { stdout } = await execFileAsync('git', ['ls-files', '-s', '-z'], { encoding: 'utf8' });
    return stdout.split('\0').filter(Boolean).map((entry) => {
        const separator = entry.indexOf('\t');
        const metadata = entry.slice(0, separator).split(' ');
        return { mode: metadata[0], path: entry.slice(separator + 1) };
    });
}

export async function run() {
    const entries = await trackedEntries();
    const tracked = entries.map(({ path: trackedPath }) => trackedPath);
    const symlinkPaths = entries.filter(({ mode }) => mode === '120000').map(({ path: trackedPath }) => trackedPath);
    const symlinkInputPaths = entries.filter(({ path: trackedPath, mode }) => MARKDOWN_FILE.test(trackedPath) && !trackedPath.startsWith('public/docs/api/') && mode === '120000').map(({ path: trackedPath }) => trackedPath);
    const inputs = entries.filter(({ path: trackedPath, mode }) => MARKDOWN_FILE.test(trackedPath) && !trackedPath.startsWith('public/docs/api/') && mode !== '120000');
    const markdownFiles = Object.fromEntries(await Promise.all(inputs.map(async ({ path: trackedPath }) => [trackedPath, await readFile(trackedPath, 'utf8')])));
    const issues = findMarkdownLinkIssues({ markdownFiles, trackedPaths: tracked, symlinkPaths, symlinkInputPaths });
    if (issues.length) { console.error(formatIssues(issues)); return 1; }
    console.log(`Checked ${inputs.length} tracked Markdown files; no invalid relative links found.`);
    return 0;
}

if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url))) process.exitCode = await run();
