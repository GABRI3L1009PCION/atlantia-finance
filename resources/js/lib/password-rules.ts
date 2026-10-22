/** What a new password must have. The server enforces the same rule; this only shows it live. */
export const PASSWORD_MIN_LENGTH = 12;

export type PasswordCheck = { key: string; label: string; ok: boolean };

export function passwordChecks(password: string): PasswordCheck[] {
    return [
        {
            key: 'length',
            label: `${PASSWORD_MIN_LENGTH} caracteres o más`,
            ok: password.length >= PASSWORD_MIN_LENGTH,
        },
        {
            key: 'case',
            label: 'Mayúscula y minúscula',
            ok: /\p{Ll}/u.test(password) && /\p{Lu}/u.test(password),
        },
        { key: 'number', label: 'Un número', ok: /\d/.test(password) },
        {
            key: 'symbol',
            label: 'Un símbolo (!@#$%…)',
            ok: /[^\p{L}\p{N}\s]/u.test(password),
        },
    ];
}

export function meetsPasswordRules(password: string): boolean {
    return passwordChecks(password).every((check) => check.ok);
}
