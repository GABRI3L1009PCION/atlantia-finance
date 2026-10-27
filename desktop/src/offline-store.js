'use strict';

/**
 * The offline copy of one person: their snapshot and the captures they made while offline, both sealed in the vault.
 * The app remembers who signed in last on this computer, because without connection nobody can sign in again.
 */
const crypto = require('node:crypto');
const { validate } = require('./captures');
const { snapshotUsable } = require('./connectivity');

class OfflineStore {
    constructor(vault) {
        this.vault = vault;
    }

    owner() {
        return this.vault.read('owner', null);
    }

    saveSnapshot(snapshot) {
        const owner = { user: snapshot.user, organization: snapshot.organization };
        const previous = this.owner();
        // Another person signed in on this computer: the previous person's copy and captures are not theirs to see.
        if (previous && (previous.user.id !== owner.user.id || previous.organization.id !== owner.organization.id)) {
            const pending = this.captures();
            if (pending.length > 0) {
                return { saved: false, reason: 'pending_other_user', owner: previous };
            }
            this.vault.remove('snapshot');
        }
        this.vault.write('owner', owner);
        this.vault.write('snapshot', snapshot);

        return { saved: true };
    }

    /** The snapshot, or why it cannot be shown. */
    snapshot(now = new Date()) {
        const snapshot = this.vault.read('snapshot', null);
        if (!snapshot) {
            return { status: 'missing' };
        }
        if (!snapshotUsable(snapshot, now)) {
            return { status: 'expired', generated_at: snapshot.generated_at, max_offline_days: snapshot.max_offline_days };
        }

        return { status: 'ok', snapshot };
    }

    captures() {
        return this.vault.read('captures', []);
    }

    /** Validates and keeps a capture until it can be uploaded. */
    addCapture(input, now = new Date()) {
        const current = this.snapshot(now);
        if (current.status !== 'ok') {
            return { saved: false, errors: ['La copia sin conexión no está disponible.'] };
        }
        const company = current.snapshot.companies.find((item) => item.public_id === input.company);
        const capture = { id: crypto.randomUUID(), company: input.company, company_name: company ? company.name : '', kind: input.kind, captured_at: now.toISOString(), payload: input.payload };
        const errors = validate(capture, company);
        if (errors.length > 0) {
            return { saved: false, errors };
        }
        this.vault.write('captures', [...this.captures(), capture]);

        return { saved: true, capture };
    }

    removeCapture(id) {
        this.vault.write('captures', this.captures().filter((capture) => capture.id !== id));
    }

    /** Drops the captures the server already handled (as a draft or kept with an error for review). */
    acknowledge(results) {
        const handled = new Set(results.filter((result) => result.status === 'draft_created' || result.status === 'failed').map((result) => result.id));
        this.vault.write('captures', this.captures().filter((capture) => !handled.has(capture.id)));

        return handled.size;
    }

    wipe() {
        this.vault.wipe();
    }
}

module.exports = { OfflineStore };
