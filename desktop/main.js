'use strict';

/**
 * BCIS desktop shell. It opens http(s)://<SERVER_URL>/ in one window and does
 * nothing else: no business logic, no Node access for the page, no local data.
 * Every business operation happens on the Laravel server (INV-25).
 */

const path = require('node:path');
const { app, BrowserWindow, Menu, session } = require('electron');
const { resolveServerUrl } = require('./src/config');
const { windowOptions, secureWebPreferences, isAllowedNavigation } = require('./src/security');

const OFFLINE_PAGE = path.join(__dirname, 'offline', 'offline.html');
const ERR_ABORTED = -3; // user navigated away or a redirect replaced the load

let mainWindow = null;
let serverUrl = null;
let serverOrigin = null;

function configFiles() {
    const files = [path.join(app.getPath('userData'), 'config.json')];
    if (app.isPackaged) {
        files.push(path.join(process.resourcesPath, 'config.json'));
    } else {
        files.push(path.join(__dirname, 'config.json'));
    }
    return files;
}

function offlinePageUrl() {
    // The local page gets the target URL as a query string so Retry knows where to go.
    const url = new URL(`file:///${OFFLINE_PAGE.replace(/\\/g, '/')}`);
    if (serverUrl) {
        url.searchParams.set('url', serverUrl);
    } else {
        url.searchParams.set('invalid', '1');
    }
    return url.href;
}

function showOffline(webContents) {
    webContents.loadURL(offlinePageUrl());
}

function loadServer(webContents) {
    if (serverUrl === null) {
        showOffline(webContents);
        return;
    }
    webContents.loadURL(serverUrl);
}

/** FR-SHL-004: lock every window (including pop-ups) to the server origin. */
function guardWebContents(webContents) {
    webContents.on('will-navigate', (event, url) => {
        if (!isAllowedNavigation(url, serverOrigin, offlinePageUrl())) {
            event.preventDefault();
        }
    });

    webContents.on('will-redirect', (event, url) => {
        if (!isAllowedNavigation(url, serverOrigin, offlinePageUrl())) {
            event.preventDefault();
        }
    });

    // New windows (for example a print view) are allowed only for the same origin
    // and get the same locked-down settings. Everything else is refused.
    webContents.setWindowOpenHandler(({ url }) => {
        if (serverOrigin !== null && isAllowedNavigation(url, serverOrigin)) {
            return {
                action: 'allow',
                overrideBrowserWindowOptions: {
                    autoHideMenuBar: true,
                    webPreferences: secureWebPreferences({ isPackaged: app.isPackaged }),
                },
            };
        }
        return { action: 'deny' };
    });

    // FR-SHL-005: if the server cannot be reached, show the local page.
    // Nothing is cached or queued for later.
    webContents.on('did-fail-load', (_event, errorCode, _description, validatedURL, isMainFrame) => {
        if (!isMainFrame || errorCode === ERR_ABORTED || validatedURL.startsWith('file:')) {
            return;
        }
        showOffline(webContents);
    });
}

function hardenSession() {
    const shellSession = session.fromPartition('bcis-shell');

    // The system web app needs no camera, microphone, location or similar access.
    shellSession.setPermissionRequestHandler((_webContents, _permission, callback) => callback(false));
    shellSession.setPermissionCheckHandler(() => false);

    // FR-SHL-006: downloads (PDF, XLSX) always go through a Save As dialog.
    shellSession.on('will-download', (_event, item) => {
        item.setSaveDialogOptions({
            title: 'Save file',
            defaultPath: path.join(app.getPath('downloads'), item.getFilename()),
        });
    });
}

function createWindow() {
    mainWindow = new BrowserWindow(windowOptions({ isPackaged: app.isPackaged }));
    mainWindow.once('ready-to-show', () => mainWindow.show());
    mainWindow.on('closed', () => {
        mainWindow = null;
    });

    loadServer(mainWindow.webContents);
}

const gotLock = app.requestSingleInstanceLock();

if (!gotLock) {
    // FR-SHL-007: a second launch only brings the first window forward.
    app.quit();
} else {
    app.on('second-instance', () => {
        if (mainWindow) {
            if (mainWindow.isMinimized()) {
                mainWindow.restore();
            }
            mainWindow.focus();
        }
    });

    app.on('web-contents-created', (_event, webContents) => guardWebContents(webContents));

    app.whenReady().then(() => {
        const resolved = resolveServerUrl({ files: configFiles() });
        serverUrl = resolved.serverUrl;
        serverOrigin = serverUrl ? new URL(serverUrl).origin : null;

        if (app.isPackaged) {
            Menu.setApplicationMenu(null);
        }

        hardenSession();
        createWindow();

        app.on('activate', () => {
            if (BrowserWindow.getAllWindows().length === 0) {
                createWindow();
            }
        });
    });

    app.on('window-all-closed', () => {
        app.quit();
    });
}
