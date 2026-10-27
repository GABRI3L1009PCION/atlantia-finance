'use strict';

/**
 * Decides when the app is offline. One failed check is not enough (a slow answer, a brief Wi-Fi drop): it takes
 * `failuresToOffline` consecutive failures to switch to offline mode, and one successful check to come back.
 */
class Connectivity {
    constructor({ failuresToOffline = 2 } = {}) {
        this.failuresToOffline = failuresToOffline;
        this.failures = 0;
        this.online = true;
        this.listeners = [];
    }

    onChange(listener) {
        this.listeners.push(listener);
    }

    /** Records the result of a health check and returns whether the state changed. */
    record(reachable) {
        const before = this.online;
        if (reachable) {
            this.failures = 0;
            this.online = true;
        } else {
            this.failures += 1;
            if (this.failures >= this.failuresToOffline) {
                this.online = false;
            }
        }
        if (before !== this.online) {
            this.listeners.forEach((listener) => listener(this.online));

            return true;
        }

        return false;
    }
}

/** Whether a snapshot is still usable: older copies are locked so stale or stolen data is not read forever. */
function snapshotUsable(snapshot, now = new Date()) {
    if (!snapshot || !snapshot.generated_at) {
        return false;
    }
    const age = (now.getTime() - new Date(snapshot.generated_at).getTime()) / 86400000;

    return age <= (snapshot.max_offline_days || 15);
}

module.exports = { Connectivity, snapshotUsable };
