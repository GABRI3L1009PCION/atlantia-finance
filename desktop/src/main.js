'use strict';

/**
 * Atlantia Finance for desktop. Online it shows the web app itself (same screens, same session). In the background it
 * keeps an encrypted copy of the person's companies; when the server or the connection fails it switches to the
 * offline screens, where that copy can be consulted and new sales, purchases and journal entries captured. Back online
 * those captures are uploaded and become drafts for review.
 */
const { app, BrowserWindow, Menu, Notification, dialog, ipcMain, safeStorage, session, shell } = require('electron');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { Connectivity } = require('./connectivity');
const { OfflineStore } = require('./offline-store');
const { ServerClient } = require('./server');
const { Vault } = require('./vault');

const SERVER_URL = (process.env.ATLANTIA_URL || 'https://finance.atlantiasystems.com').replace(/\/$/, '');
const SERVER_ORIGIN = new URL(SERVER_URL).origin;
const OFFLINE_PAGE = path.join(__dirname, 'offline', 'offline.html');
const CHECK_EVERY_MS = 20 * 1000;
const SNAPSHOT_EVERY_MS = 30 * 60 * 1000;

let window = null;
let store = null;
let server = null;
const connectivity = new Connectivity({ failuresToOffline: 2 });
const state = { lastSnapshotAt: null, lastUpload: null, lastError: null, syncing: false };

if (!app.requestSingleInstanceLock()) {
    app.quit();
}

function platform() {
    return process.platform === 'win32' ? 'windows' : process.platform === 'darwin' ? 'mac' : 'linux';
}

/** A stable id for this computer, kept in the app's own folder (it identifies the device, it is not a secret). */
function deviceUuid() {
    const file = path.join(app.getPath('userData'), 'device.json');
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8')).device_uuid;
    } catch {
        const id = crypto.randomUUID();
        fs.mkdirSync(path.dirname(file), { recursive: true });
        fs.writeFileSync(file, JSON.stringify({ device_uuid: id }));

        return id;
    }
}

function device() {
    return { device_uuid: deviceUuid(), name: os.hostname().slice(0, 120), platform: platform(), app_version: app.getVersion() };
}

function onOfflinePage() {
    return window && !window.isDestroyed() && window.webContents.getURL().startsWith('file://');
}

function publicState() {
    const owner = store.owner();

    return {
        online: connectivity.online,
        server: SERVER_URL,
        owner,
        pending: store.captures().length,
        lastUpload: state.lastUpload,
        lastError: state.lastError,
        version: app.getVersion(),
    };
}

function sendState() {
    if (onOfflinePage()) {
        window.webContents.send('offline:status', publicState());
    }
}

/** Downloads a fresh copy of the person's companies when they are signed in. */
async function refreshSnapshot() {
    if (state.syncing || !connectivity.online) {
        return;
    }
    state.syncing = true;
    try {
        const registered = await server.registerDevice(device());
        if (registered.status === 'revoked') {
            store.wipe();
            dialog.showMessageBox(window, { type: 'warning', title: 'Equipo desconectado', message: 'Este equipo fue desconectado de Atlantia Finance. Se borró su copia sin conexión.' });

            return;
        }
        if (registered.status !== 'ok') {
            return;
        }
        const snapshot = await server.snapshot(device().device_uuid);
        if (snapshot.status === 'revoked') {
            store.wipe();

            return;
        }
        if (snapshot.status === 'ok') {
            const saved = store.saveSnapshot(snapshot.data);
            if (saved.saved) {
                state.lastSnapshotAt = snapshot.data.generated_at;
                state.lastError = null;
            } else {
                state.lastError = 'Hay capturas sin subir de ' + saved.owner.user.name + '. Que esa persona inicie sesión para subirlas antes de cambiar de usuario.';
            }
        }
    } catch (error) {
        state.lastError = 'No se pudo actualizar la copia sin conexión.';
    } finally {
        state.syncing = false;
    }
}

/** Uploads what was captured offline; each capture becomes a draft on the server or is kept there with its reason. */
async function uploadCaptures() {
    const pending = store.captures();
    if (pending.length === 0) {
        return;
    }
    const registered = await server.registerDevice(device());
    if (registered.status !== 'ok') {
        if (registered.status === 'signed_out') {
            state.lastError = 'Inicia sesión para subir las ' + pending.length + ' captura(s) pendientes.';
        }

        return;
    }
    const response = await server.uploadCaptures(device().device_uuid, pending.map(({ company_name, ...capture }) => capture));
    if (response.status !== 'ok') {
        state.lastError = response.message || 'No se pudieron subir las capturas.';

        return;
    }
    const results = response.data.results;
    store.acknowledge(results);
    const drafts = results.filter((result) => result.status === 'draft_created').length;
    const failed = results.filter((result) => result.status === 'failed').length;
    state.lastUpload = { at: new Date().toISOString(), drafts, failed };
    if (Notification.isSupported()) {
        new Notification({
            title: 'Capturas sin conexión subidas',
            body: drafts + ' quedaron como borradores para revisar' + (failed ? ' y ' + failed + ' necesitan corrección en Atlantia Finance.' : '.'),
        }).show();
    }
}

async function goOnline() {
    await uploadCaptures();
    if (onOfflinePage()) {
        window.loadURL(SERVER_URL);
    }
    refreshSnapshot();
}

function goOffline() {
    if (!onOfflinePage()) {
        window.loadFile(OFFLINE_PAGE);
    }
}

async function check() {
    const reachable = await server.reachable();
    connectivity.record(reachable);
    sendState();
}

function createWindow() {
    window = new BrowserWindow({
        width: 1360,
        height: 860,
        minWidth: 980,
        minHeight: 640,
        title: 'Atlantia Finance',
        backgroundColor: '#520e26',
        webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, sandbox: true, nodeIntegration: false },
    });

    // Only Atlantia's own pages open inside the app; any other link opens in the browser.
    window.webContents.setWindowOpenHandler(({ url }) => {
        if (url.startsWith('https://') || url.startsWith('http://')) {
            shell.openExternal(url);
        }

        return { action: 'deny' };
    });
    window.webContents.on('will-navigate', (event, url) => {
        if (!url.startsWith(SERVER_ORIGIN) && !url.startsWith('file://')) {
            event.preventDefault();
            shell.openExternal(url);
        }
    });
    window.webContents.on('did-fail-load', (_event, code, _description, url, isMainFrame) => {
        // -3 is an aborted navigation (a redirect, a new page); anything else on the main frame means the server is not there.
        if (isMainFrame && code !== -3 && url.startsWith(SERVER_ORIGIN)) {
            connectivity.record(false);
            connectivity.record(false);
        }
    });
    window.webContents.on('did-finish-load', () => {
        const url = window.webContents.getURL();
        if (url.startsWith(SERVER_ORIGIN) && !/\/(login|register|forgot-password|two-factor)/.test(url)) {
            refreshSnapshot();
        }
        sendState();
    });

    server.reachable().then((reachable) => {
        connectivity.record(reachable);
        if (reachable) {
            window.loadURL(SERVER_URL);
        } else {
            connectivity.record(false);
            window.loadFile(OFFLINE_PAGE);
        }
    });
}

/** The offline screens are the only ones allowed to read the offline copy. */
function fromOfflinePage(event) {
    return event.senderFrame && event.senderFrame.url.startsWith('file://');
}

function registerIpc() {
    ipcMain.handle('offline:state', (event) => (fromOfflinePage(event) ? publicState() : null));
    ipcMain.handle('offline:snapshot', (event) => (fromOfflinePage(event) ? store.snapshot() : null));
    ipcMain.handle('offline:captures', (event) => (fromOfflinePage(event) ? store.captures() : []));
    ipcMain.handle('offline:add-capture', (event, input) => {
        if (!fromOfflinePage(event)) {
            return { saved: false, errors: ['No permitido.'] };
        }
        const result = store.addCapture(input);
        sendState();

        return result;
    });
    ipcMain.handle('offline:remove-capture', (event, id) => {
        if (fromOfflinePage(event)) {
            store.removeCapture(String(id));
            sendState();
        }

        return true;
    });
    ipcMain.handle('offline:retry', async (event) => {
        if (!fromOfflinePage(event)) {
            return false;
        }
        const reachable = await server.reachable();
        connectivity.record(reachable);
        sendState();

        return reachable;
    });
}

app.on('second-instance', () => {
    if (window) {
        if (window.isMinimized()) {
            window.restore();
        }
        window.focus();
    }
});

app.whenReady().then(() => {
    const keyProtector = {
        available: () => safeStorage.isEncryptionAvailable(),
        encrypt: (text) => safeStorage.encryptString(text),
        decrypt: (data) => safeStorage.decryptString(data),
    };
    store = new OfflineStore(new Vault(path.join(app.getPath('userData'), 'offline'), keyProtector));
    server = new ServerClient(
        SERVER_URL,
        (url, init) => session.defaultSession.fetch(url, init),
        async () => {
            const [cookie] = await session.defaultSession.cookies.get({ url: SERVER_URL, name: 'XSRF-TOKEN' });

            return cookie ? decodeURIComponent(cookie.value) : null;
        },
    );
    connectivity.onChange((online) => (online ? goOnline() : goOffline()));

    Menu.setApplicationMenu(Menu.buildFromTemplate([
        ...(process.platform === 'darwin' ? [{ role: 'appMenu' }] : []),
        { label: 'Archivo', submenu: [{ label: 'Inicio de Atlantia', click: () => (connectivity.online ? window.loadURL(SERVER_URL) : window.loadFile(OFFLINE_PAGE)) }, { type: 'separator' }, { role: 'quit', label: 'Salir' }] },
        { label: 'Editar', submenu: [{ role: 'undo', label: 'Deshacer' }, { role: 'redo', label: 'Rehacer' }, { type: 'separator' }, { role: 'cut', label: 'Cortar' }, { role: 'copy', label: 'Copiar' }, { role: 'paste', label: 'Pegar' }, { role: 'selectAll', label: 'Seleccionar todo' }] },
        { label: 'Ver', submenu: [{ role: 'reload', label: 'Recargar' }, { role: 'resetZoom', label: 'Tamaño normal' }, { role: 'zoomIn', label: 'Acercar' }, { role: 'zoomOut', label: 'Alejar' }, { type: 'separator' }, { role: 'togglefullscreen', label: 'Pantalla completa' }] },
    ]));

    registerIpc();
    createWindow();
    setInterval(check, CHECK_EVERY_MS);
    setInterval(refreshSnapshot, SNAPSHOT_EVERY_MS);
});

app.on('window-all-closed', () => app.quit());
