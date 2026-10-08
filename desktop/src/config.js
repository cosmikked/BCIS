'use strict';

/**
 * Where the shell opens. FR-SHL-001: the server URL comes from a config file or
 * an environment setting, so pointing a PC at another server needs no rebuild.
 *
 * Order, first valid value wins:
 *   1. BCIS_SERVER_URL environment variable
 *   2. config.json in the user's app-data folder (per PC, survives updates)
 *   3. config.json installed next to the app (resources/config.json)
 *   4. config.json bundled in the source tree (development default)
 */

const fs = require('node:fs');

const ENV_NAME = 'BCIS_SERVER_URL';

/**
 * Accept only http(s) URLs. Returns the URL with a trailing slash, or null.
 * Credentials inside the URL are refused so none can be stored in a config file.
 * @param {unknown} value
 * @returns {string|null}
 */
function normalizeServerUrl(value) {
    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    let url;
    try {
        url = new URL(value.trim());
    } catch {
        return null;
    }

    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return null;
    }

    // Text check on the normalized URL: "scheme://user:secret@host" is refused.
    if (/^[a-z]+:\/\/[^/?#]*@/i.test(url.href)) {
        return null;
    }

    url.hash = '';
    return url.href.endsWith('/') ? url.href : `${url.href}/`;
}

/**
 * @param {string} file
 * @param {{ readFileSync: (path: string, encoding: 'utf8') => string }} [fileSystem]
 * @returns {string|null}
 */
function readServerUrlFromFile(file, fileSystem = fs) {
    try {
        const parsed = JSON.parse(fileSystem.readFileSync(file, 'utf8'));
        return normalizeServerUrl(parsed.serverUrl);
    } catch {
        return null;
    }
}

/**
 * @param {{ env?: Record<string, string|undefined>, files?: string[], fileSystem?: object }} options
 * @returns {{ serverUrl: string|null, source: string|null }}
 */
function resolveServerUrl({ env = process.env, files = [], fileSystem = fs } = {}) {
    const fromEnv = normalizeServerUrl(env[ENV_NAME]);
    if (fromEnv !== null) {
        return { serverUrl: fromEnv, source: `env:${ENV_NAME}` };
    }

    for (const file of files) {
        const fromFile = readServerUrlFromFile(file, fileSystem);
        if (fromFile !== null) {
            return { serverUrl: fromFile, source: file };
        }
    }

    return { serverUrl: null, source: null };
}

module.exports = { ENV_NAME, normalizeServerUrl, readServerUrlFromFile, resolveServerUrl };
