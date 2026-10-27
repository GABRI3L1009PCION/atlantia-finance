'use strict';

/**
 * Bridge between the offline screens and the main process. The web pages get it too, but the main process only
 * answers requests coming from the offline screens.
 */
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('atlantiaDesktop', {
    isDesktop: true,
    state: () => ipcRenderer.invoke('offline:state'),
    snapshot: () => ipcRenderer.invoke('offline:snapshot'),
    captures: () => ipcRenderer.invoke('offline:captures'),
    addCapture: (capture) => ipcRenderer.invoke('offline:add-capture', capture),
    removeCapture: (id) => ipcRenderer.invoke('offline:remove-capture', id),
    retry: () => ipcRenderer.invoke('offline:retry'),
    onStatus: (listener) => ipcRenderer.on('offline:status', (_event, status) => listener(status)),
});
