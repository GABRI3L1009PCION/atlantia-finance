'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { validate, cents } = require('../src/captures');
const { Connectivity, snapshotUsable } = require('../src/connectivity');
const { OfflineStore } = require('../src/offline-store');
const { ServerClient } = require('../src/server');
const { Vault } = require('../src/vault');

/** Stands in for Electron's safeStorage: a reversible transform that marks the data as protected. */
const protector = {
    available: () => true,
    encrypt: (text) => Buffer.from('protected:' + text),
    decrypt: (data) => data.toString().replace(/^protected:/, ''),
};

function tempVault() {
    return new Vault(fs.mkdtempSync(path.join(os.tmpdir(), 'atlantia-vault-')), protector);
}

function snapshot(overrides = {}) {
    return {
        generated_at: new Date().toISOString(), max_offline_days: 15,
        user: { id: 7, name: 'Ana López' }, organization: { id: 3, name: 'Hermanos Demo' },
        companies: [{ public_id: 'c-1', name: 'El Progreso', can: { sale: true, purchase: true, journal: false }, accounts: [], customers: [{ id: 5, name: 'Finca' }] }],
        ...overrides,
    };
}

test('the vault seals data so the file never holds it in clear and reads it back', () => {
    const vault = tempVault();
    vault.write('snapshot', { name: 'Distribuidora El Progreso', nit: '8754321-4' });

    const raw = fs.readFileSync(path.join(vault.directory, 'snapshot.bin'));
    assert.equal(raw.includes(Buffer.from('El Progreso')), false);
    assert.deepEqual(vault.read('snapshot'), { name: 'Distribuidora El Progreso', nit: '8754321-4' });
    assert.equal(fs.readFileSync(path.join(vault.directory, 'vault.key')).toString().startsWith('protected:'), true);
});

test('a tampered file is rejected instead of read', () => {
    const vault = tempVault();
    vault.write('captures', [{ id: 1 }]);
    const file = path.join(vault.directory, 'captures.bin');
    const data = fs.readFileSync(file);
    data[data.length - 1] ^= 0xff;
    fs.writeFileSync(file, data);

    assert.throws(() => vault.read('captures'));
});

test('wiping removes the copy and the key', () => {
    const vault = tempVault();
    vault.write('snapshot', { a: 1 });
    vault.wipe();

    assert.equal(fs.existsSync(vault.directory), false);
});

test('captures are checked before they are saved', () => {
    const company = { name: 'El Progreso', can: { sale: true, purchase: true, journal: true } };
    assert.deepEqual(validate({ kind: 'sale', payload: { date: '2026-10-27', customer_id: 5, lines: [{ description: 'Fertilizante', quantity: 2, unit_price: '5600.00' }] } }, company), []);
    assert.ok(validate({ kind: 'journal', payload: { date: '2026-10-27', description: 'Luz', lines: [{ account_id: 1, debit: '450.00' }, { account_id: 2, credit: '400.00' }] } }, company)
        .some((error) => error.includes('no cuadra')));
    assert.deepEqual(validate({ kind: 'journal', payload: { date: '2026-10-27', description: 'Luz', lines: [{ account_id: 1, debit: '450', credit: '0' }, { account_id: 2, debit: '0', credit: '450.00' }] } }, company), []);
    assert.ok(validate({ kind: 'purchase', payload: { date: '2026-10-27', supplier_id: 1, lines: [{ description: 'Flete', quantity: 1, unit_price: '100' }] } }, company)
        .some((error) => error.includes('cuenta')));
    assert.deepEqual(validate({ kind: 'journal', payload: {} }, { name: 'X', can: { journal: false } }), ['No tienes permiso para registrar este movimiento en X.']);
    assert.equal(cents('1,000.00'), null);
    assert.equal(cents('12.5'), 1250);
});

test('the app goes offline after two failed checks and comes back after one success', () => {
    const connectivity = new Connectivity({ failuresToOffline: 2 });
    const changes = [];
    connectivity.onChange((online) => changes.push(online));

    connectivity.record(false);
    assert.equal(connectivity.online, true);
    connectivity.record(false);
    assert.equal(connectivity.online, false);
    connectivity.record(true);
    assert.deepEqual(changes, [false, true]);
});

test('a copy older than the allowed days is locked', () => {
    const old = snapshot({ generated_at: new Date(Date.now() - 16 * 86400000).toISOString() });
    assert.equal(snapshotUsable(old), false);
    assert.equal(snapshotUsable(snapshot()), true);
    assert.equal(new OfflineStore(tempVault()).snapshot().status, 'missing');
});

test('the store keeps captures until the server handles them, and never mixes two people', () => {
    const store = new OfflineStore(tempVault());
    assert.deepEqual(store.saveSnapshot(snapshot()), { saved: true });

    const sale = store.addCapture({ company: 'c-1', kind: 'sale', payload: { date: '2026-10-27', customer_id: 5, lines: [{ description: 'Abono', quantity: 1, unit_price: '100' }] } });
    assert.equal(sale.saved, true);
    assert.equal(store.addCapture({ company: 'c-1', kind: 'journal', payload: {} }).saved, false);
    assert.equal(store.captures().length, 1);

    // Another person signs in while a capture is pending: the copy is not replaced.
    assert.equal(store.saveSnapshot(snapshot({ user: { id: 9, name: 'Otro' } })).saved, false);
    assert.equal(store.acknowledge([{ id: sale.capture.id, status: 'draft_created' }]), 1);
    assert.equal(store.captures().length, 0);
    assert.equal(store.saveSnapshot(snapshot({ user: { id: 9, name: 'Otro' } })).saved, true);
    assert.equal(store.owner().user.id, 9);
});

test('the server client sends the session token on writes and understands revoked and signed-out answers', async () => {
    const calls = [];
    const answers = [
        { status: 200, ok: true, json: async () => ({ device_uuid: 'd' }) },
        { status: 410, ok: false, json: async () => ({}) },
        { status: 302, ok: false, json: async () => ({}) },
    ];
    const client = new ServerClient('https://finance.example.test/', async (url, init) => { calls.push([url, init]); return answers.shift(); }, async () => 'token-123');

    assert.equal((await client.registerDevice({ device_uuid: 'd' })).status, 'ok');
    assert.equal(calls[0][0], 'https://finance.example.test/desktop/devices');
    assert.equal(calls[0][1].headers['X-XSRF-TOKEN'], 'token-123');
    assert.equal((await client.snapshot('d')).status, 'revoked');
    assert.equal((await client.uploadCaptures('d', [])).status, 'signed_out');
    assert.equal(await new ServerClient('https://x.test', async () => { throw new Error('down'); }, async () => null).reachable(), false);
});
