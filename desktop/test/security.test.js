'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { windowOptions, secureWebPreferences, isAllowedNavigation } = require('../src/security');

const ORIGIN = 'http://192.168.1.10:8000';
const OFFLINE = 'file:///C:/Program%20Files/BCIS/resources/app/offline/offline.html';

// FR-SHL-002: a test inspects the window options.
test('window options use the required secure flags', () => {
    for (const isPackaged of [true, false]) {
        const prefs = windowOptions({ isPackaged }).webPreferences;
        assert.equal(prefs.contextIsolation, true);
        assert.equal(prefs.nodeIntegration, false);
        assert.equal(prefs.sandbox, true);
        assert.equal(prefs.webSecurity, true);
        assert.equal(prefs.nodeIntegrationInSubFrames, false);
        assert.equal(prefs.webviewTag, false);
        assert.equal(prefs.allowRunningInsecureContent, false);
    }
});

test('window options define no preload script', () => {
    const prefs = windowOptions({ isPackaged: true }).webPreferences;
    assert.equal('preload' in prefs, false);
});

test('main.js does not mention a preload and passes its options through the secure helpers', () => {
    const source = fs.readFileSync(path.join(__dirname, '..', 'main.js'), 'utf8');
    assert.doesNotMatch(source, /preload/i);
    assert.doesNotMatch(source, /nodeIntegration\s*:\s*true/);
    assert.doesNotMatch(source, /contextIsolation\s*:\s*false/);
    assert.doesNotMatch(source, /webSecurity\s*:\s*false/);
    assert.match(source, /windowOptions\(/);
    assert.match(source, /requestSingleInstanceLock/);
    assert.match(source, /setWindowOpenHandler/);
    assert.match(source, /will-navigate/);
});

// FR-SHL-006: DevTools disabled in production.
test('DevTools are off when packaged and on in development', () => {
    assert.equal(secureWebPreferences({ isPackaged: true }).devTools, false);
    assert.equal(secureWebPreferences({ isPackaged: false }).devTools, true);
});

test('the session is in-memory (no persist: prefix)', () => {
    const { partition } = secureWebPreferences({ isPackaged: true });
    assert.ok(partition && !partition.startsWith('persist:'));
});

// FR-SHL-004: navigation locked to the server origin.
test('same-origin pages are allowed', () => {
    assert.equal(isAllowedNavigation('http://192.168.1.10:8000/', ORIGIN, OFFLINE), true);
    assert.equal(isAllowedNavigation('http://192.168.1.10:8000/payments/create?x=1#top', ORIGIN, OFFLINE), true);
});

test('other origins are blocked', () => {
    const blocked = [
        'https://example.com/',
        'http://192.168.1.11:8000/',
        'http://192.168.1.10:8001/',
        'https://192.168.1.10:8000/',
        'http://192.168.1.10.evil.example:8000/',
        'http://192.168.1.10:8000@evil.example/',
        'http://user:secret@192.168.1.10:8000/',
        'javascript:alert(1)',
        'data:text/html,<h1>x</h1>',
        'ftp://192.168.1.10/',
        'file:///C:/Windows/System32/drivers/etc/hosts',
        'not a url',
        '',
    ];
    for (const target of blocked) {
        assert.equal(isAllowedNavigation(target, ORIGIN, OFFLINE), false, target);
    }
});

test('only the local offline page is allowed from file:', () => {
    assert.equal(isAllowedNavigation(`${OFFLINE}?url=http%3A%2F%2F192.168.1.10%3A8000%2F`, ORIGIN, OFFLINE), true);
    assert.equal(isAllowedNavigation('file:///C:/Program%20Files/BCIS/resources/app/main.js', ORIGIN, OFFLINE), false);
    assert.equal(isAllowedNavigation(OFFLINE, ORIGIN), false);
});
