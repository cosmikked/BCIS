'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { normalizeServerUrl, resolveServerUrl } = require('../src/config');

function fakeFs(files) {
    return {
        readFileSync(file) {
            if (!(file in files)) {
                throw new Error('ENOENT');
            }
            return files[file];
        },
    };
}

test('normalizes http and https URLs with a trailing slash', () => {
    assert.equal(normalizeServerUrl('http://192.168.1.10:8000'), 'http://192.168.1.10:8000/');
    assert.equal(normalizeServerUrl(' https://bcis.lan/app '), 'https://bcis.lan/app/');
});

test('rejects anything that is not a plain http(s) URL', () => {
    for (const bad of [undefined, null, 42, '', '   ', 'bcis.lan', 'ftp://bcis.lan/', 'file:///C:/x', 'javascript:alert(1)', 'http://user:pw@bcis.lan/']) {
        assert.equal(normalizeServerUrl(bad), null, String(bad));
    }
});

// FR-SHL-001: changing the setting points the shell elsewhere without a rebuild.
test('the environment variable wins over config files', () => {
    const result = resolveServerUrl({
        env: { BCIS_SERVER_URL: 'http://10.0.0.2:8000' },
        files: ['a.json'],
        fileSystem: fakeFs({ 'a.json': '{"serverUrl":"http://10.0.0.9:8000"}' }),
    });
    assert.deepEqual(result, { serverUrl: 'http://10.0.0.2:8000/', source: 'env:BCIS_SERVER_URL' });
});

test('files are tried in order and invalid ones are skipped', () => {
    const result = resolveServerUrl({
        env: {},
        files: ['missing.json', 'broken.json', 'badurl.json', 'good.json', 'later.json'],
        fileSystem: fakeFs({
            'broken.json': '{not json',
            'badurl.json': '{"serverUrl":"ftp://x"}',
            'good.json': '{"serverUrl":"http://10.0.0.5:8000"}',
            'later.json': '{"serverUrl":"http://10.0.0.6:8000"}',
        }),
    });
    assert.deepEqual(result, { serverUrl: 'http://10.0.0.5:8000/', source: 'good.json' });
});

test('returns null when nothing valid is configured', () => {
    assert.deepEqual(resolveServerUrl({ env: {}, files: ['x.json'], fileSystem: fakeFs({}) }), { serverUrl: null, source: null });
});
