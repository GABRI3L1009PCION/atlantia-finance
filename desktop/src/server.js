'use strict';

/**
 * Talks to the Atlantia server with the same signed-in session as the embedded web pages: the fetch function and the
 * cookies come from Electron's session, so there is no separate login for the desktop app.
 */
class ServerClient {
    /**
     * @param {string} baseUrl
     * @param {(url: string, init?: object) => Promise<Response>} fetchFn session-aware fetch
     * @param {() => Promise<string|null>} xsrfToken reads the XSRF-TOKEN cookie of the session
     */
    constructor(baseUrl, fetchFn, xsrfToken) {
        this.baseUrl = baseUrl.replace(/\/$/, '');
        this.fetchFn = fetchFn;
        this.xsrfToken = xsrfToken;
    }

    /** Whether the server answers its health route. */
    async reachable(timeoutMs = 8000) {
        try {
            const response = await this.fetchFn(this.baseUrl + '/up', { method: 'GET', cache: 'no-store', signal: AbortSignal.timeout(timeoutMs) });

            return response.ok;
        } catch {
            return false;
        }
    }

    async registerDevice(device) {
        return this.request('POST', '/desktop/devices', device);
    }

    async snapshot(deviceUuid) {
        return this.request('GET', '/desktop/snapshot?device_uuid=' + encodeURIComponent(deviceUuid));
    }

    async uploadCaptures(deviceUuid, captures) {
        return this.request('POST', '/desktop/captures', { device_uuid: deviceUuid, captures });
    }

    /**
     * @returns {Promise<{status: 'ok', data: any} | {status: 'signed_out'|'revoked'|'error', message?: string}>}
     */
    async request(method, path, body) {
        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (method !== 'GET') {
            headers['Content-Type'] = 'application/json';
            const token = await this.xsrfToken();
            if (token) {
                headers['X-XSRF-TOKEN'] = token;
            }
        }
        let response;
        try {
            response = await this.fetchFn(this.baseUrl + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), redirect: 'manual', signal: AbortSignal.timeout(60000) });
        } catch (error) {
            return { status: 'error', message: 'No se pudo conectar con el servidor.' };
        }
        if (response.status === 401 || response.status === 419 || (response.status >= 300 && response.status < 400)) {
            return { status: 'signed_out' };
        }
        if (response.status === 410) {
            return { status: 'revoked' };
        }
        if (!response.ok) {
            let message = 'El servidor respondió con un error (' + response.status + ').';
            try {
                const data = await response.json();
                message = data.message || message;
            } catch {
                // The body was not JSON; keep the generic message.
            }

            return { status: 'error', message };
        }

        return { status: 'ok', data: await response.json() };
    }
}

module.exports = { ServerClient };
