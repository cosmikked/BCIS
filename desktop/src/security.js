'use strict';

/**
 * Window security settings and the navigation rule (FR-SHL-002, FR-SHL-004).
 * Kept free of Electron imports so plain Node tests can inspect them.
 */

/**
 * @param {{ isPackaged: boolean }} options
 */
function secureWebPreferences({ isPackaged }) {
    return {
        contextIsolation: true,
        nodeIntegration: false,
        sandbox: true,
        webSecurity: true,
        // No preload on purpose: the page gets no Node and no file access.
        allowRunningInsecureContent: false,
        nodeIntegrationInSubFrames: false,
        nodeIntegrationInWorker: false,
        webviewTag: false,
        experimentalFeatures: false,
        // DevTools only while developing (FR-SHL-006).
        devTools: !isPackaged,
        // In-memory session: no business pages, cookies or cache survive on this PC
        // after the app closes (FR-SHL-005). Staff sign in again each launch.
        partition: 'bcis-shell',
    };
}

/**
 * @param {{ isPackaged: boolean }} options
 */
function windowOptions({ isPackaged }) {
    return {
        width: 1366,
        height: 768,
        minWidth: 1280,
        minHeight: 720,
        show: false,
        title: 'BCIS',
        backgroundColor: '#F6F8FB',
        autoHideMenuBar: true,
        webPreferences: secureWebPreferences({ isPackaged }),
    };
}

/**
 * True when `target` is inside the one allowed web origin (scheme + host + port),
 * or is the local "cannot reach server" page that ships inside the app.
 *
 * Parsing with URL (not string prefix checks) defeats tricks such as
 * http://server.lan@evil.example/ or http://server.lan.evil.example/.
 *
 * @param {string} target
 * @param {string} serverOrigin e.g. "http://192.168.1.10:8000"
 * @param {string} [offlinePageUrl] file URL of the local error page
 */
function isAllowedNavigation(target, serverOrigin, offlinePageUrl) {
    let url;
    try {
        url = new URL(target);
    } catch {
        return false;
    }

    if (url.protocol === 'file:') {
        if (!offlinePageUrl) {
            return false;
        }
        // Only the one local page, ignoring its query string and hash.
        const offline = new URL(offlinePageUrl);
        return url.pathname === offline.pathname;
    }

    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return false;
    }

    if (/^[a-z]+:\/\/[^/?#]*@/i.test(url.href)) {
        return false;
    }

    return url.origin === serverOrigin;
}

module.exports = { secureWebPreferences, windowOptions, isAllowedNavigation };
