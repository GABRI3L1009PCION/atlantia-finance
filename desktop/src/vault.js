'use strict';

/**
 * Encrypted offline storage. The data (the snapshot of the person's companies and the captures waiting to be uploaded)
 * is sealed with AES-256-GCM using a random key; that key is itself protected by the operating system (DPAPI on
 * Windows, the Keychain on Mac) through Electron's safeStorage, so the files are useless if copied to another computer.
 */
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const VERSION = 1;
const TAG_LENGTH = 16;

class Vault {
    /**
     * @param {string} directory where the sealed files live
     * @param {{ encrypt(text: string): Buffer, decrypt(data: Buffer): string, available(): boolean }} keyProtector
     */
    constructor(directory, keyProtector) {
        this.directory = directory;
        this.keyProtector = keyProtector;
        this.key = null;
    }

    loadKey() {
        if (this.key) {
            return this.key;
        }
        if (!this.keyProtector.available()) {
            throw new Error('El sistema operativo no permite cifrar la copia sin conexión.');
        }
        fs.mkdirSync(this.directory, { recursive: true });
        const keyFile = path.join(this.directory, 'vault.key');
        if (fs.existsSync(keyFile)) {
            this.key = Buffer.from(this.keyProtector.decrypt(fs.readFileSync(keyFile)), 'base64');
        } else {
            this.key = crypto.randomBytes(32);
            fs.writeFileSync(keyFile, this.keyProtector.encrypt(this.key.toString('base64')), { mode: 0o600 });
        }

        return this.key;
    }

    write(name, value) {
        const key = this.loadKey();
        const iv = crypto.randomBytes(12);
        const cipher = crypto.createCipheriv('aes-256-gcm', key, iv, { authTagLength: TAG_LENGTH });
        const body = Buffer.concat([cipher.update(JSON.stringify(value), 'utf8'), cipher.final()]);
        const sealed = Buffer.concat([Buffer.from([VERSION]), iv, cipher.getAuthTag(), body]);
        const file = this.file(name);
        const temporary = file + '.tmp';
        fs.writeFileSync(temporary, sealed, { mode: 0o600 });
        fs.renameSync(temporary, file);
    }

    read(name, fallback = null) {
        const file = this.file(name);
        if (!fs.existsSync(file)) {
            return fallback;
        }
        const sealed = fs.readFileSync(file);
        if (sealed[0] !== VERSION) {
            throw new Error('La copia sin conexión tiene un formato desconocido.');
        }
        const decipher = crypto.createDecipheriv('aes-256-gcm', this.loadKey(), sealed.subarray(1, 13), {
            authTagLength: TAG_LENGTH,
        });
        decipher.setAuthTag(sealed.subarray(13, 13 + TAG_LENGTH));

        return JSON.parse(Buffer.concat([decipher.update(sealed.subarray(13 + TAG_LENGTH)), decipher.final()]).toString('utf8'));
    }

    remove(name) {
        fs.rmSync(this.file(name), { force: true });
    }

    /** Erases every sealed file and the key: used when the device is disconnected from the server. */
    wipe() {
        fs.rmSync(this.directory, { recursive: true, force: true });
        this.key = null;
    }

    file(name) {
        if (!/^[a-z0-9-]+$/.test(name)) {
            throw new Error('Nombre de archivo no válido.');
        }

        return path.join(this.directory, name + '.bin');
    }
}

module.exports = { Vault };
