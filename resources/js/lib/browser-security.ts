import { http, router } from '@inertiajs/react';

/**
 * Two protections the page needs before any other module runs (import this first in app.tsx):
 *
 * - The security policy only accepts <style> tags that carry the page's nonce. Libraries (toasts, dialogs, the 2FA code
 *   input) inject their own <style> tags, so every style tag added to the document receives the nonce.
 * - The CSRF token travels in the page, never in a cookie scripts can read: it starts from the csrf-token meta tag, is
 *   refreshed from the csrfToken prop on every visit (signing in rotates it) and goes out as X-CSRF-TOKEN.
 */
const hasDocument = typeof document !== 'undefined';

const styleNonce = hasDocument
    ? (document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')
          ?.nonce ?? '')
    : '';

let token = hasDocument
    ? (document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
          ?.content ?? '')
    : '';

if (hasDocument && styleNonce !== '') {
    const withNonce = <T extends Node>(node: T): T => {
        if (node instanceof HTMLStyleElement && !node.nonce) {
            node.setAttribute('nonce', styleNonce);
        }

        return node;
    };
    const appendChild = Node.prototype.appendChild;
    const insertBefore = Node.prototype.insertBefore;
    Node.prototype.appendChild = function <T extends Node>(node: T): T {
        return appendChild.call(this, withNonce(node)) as T;
    };
    Node.prototype.insertBefore = function <T extends Node>(
        node: T,
        child: Node | null,
    ): T {
        return insertBefore.call(this, withNonce(node), child) as T;
    };
}

if (hasDocument) {
    router.on('navigate', (event) => {
        const next = event.detail.page.props.csrfToken;

        if (typeof next === 'string' && next !== '') {
            token = next;
        }
    });
    http.onRequest((config) => ({
        ...config,
        headers: { ...config.headers, 'X-CSRF-TOKEN': token },
    }));
}

/** The current CSRF token, for requests made with fetch. */
export function csrfToken(): string {
    return token;
}
