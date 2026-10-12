export const EXECUTIVE_HOME = '/office/executive-dashboard';
export const OFFICE_ADMIN_HOME = '/office/dashboard';
export const OPERATIONS_HOME = '/operations/dashboard';
export const SUPERVISOR_HOME = '/supervisor/dashboard';
export const ACCOUNTANT_HOME = '/accountant/dashboard';
export const ASSISTANT_HOME = '/assistant/dashboard';
export const TAX_HOME = '/tax/dashboard';
export const TREASURY_HOME = '/treasury/dashboard';
export const RECEIVABLES_HOME = '/receivables/dashboard';
export const PAYABLES_HOME = '/payables/dashboard';
export const PAYROLL_HOME = '/payroll/dashboard';
export const AUDITOR_HOME = '/work/audit';
export const CLIENT_HOME = '/client';

export type FirmWorkspace =
    | 'executive'
    | 'office-admin'
    | 'operations'
    | 'supervisor'
    | 'accountant'
    | 'assistant'
    | 'tax'
    | 'treasury'
    | 'receivables'
    | 'payables'
    | 'payroll'
    | 'auditor'
    | 'client';

const officeAdminScreens = [
    OFFICE_ADMIN_HOME,
    '/office/users',
    '/office/team',
    '/office/team-workload',
    '/office/assignments',
    '/office/companies',
    '/office/unassigned-tasks',
    '/office/audit',
    '/office/security',
    '/office/settings',
];

const sharedFirmPrefixes = [
    '/office/',
    '/work/',
    '/audit/',
    '/billing/',
    '/treasury/',
    '/receivables/',
    '/payables/',
    '/payroll/',
    '/library/',
    '/support/',
    '/settings/',
];

/** Screens shared by every panel that keep the user's own menu. */
const sharedFirmScreens = [
    '/accounting',
    '/communication',
    '/luzma',
    '/library',
    '/support',
    '/settings',
];

/**
 * Which firm-level navigation a screen belongs to. The executive and office administration panels keep their own menu
 * on their own screens; shared screens (offices, roles, closings, audit, client list) follow the user's home panel.
 * Inside a specific client company the company menu takes over (null).
 */
export function firmWorkspace(
    currentPath: string,
    homePath: string | undefined,
    pageCompany: unknown,
): FirmWorkspace | null {
    // The external client portal always shows the client menu, never an office one.
    if (
        currentPath === CLIENT_HOME ||
        currentPath.startsWith(CLIENT_HOME + '/')
    ) {
        return 'client';
    }

    if (currentPath === EXECUTIVE_HOME) {
        return 'executive';
    }

    if (officeAdminScreens.includes(currentPath)) {
        return 'office-admin';
    }

    if (currentPath.startsWith('/operations/')) {
        return 'operations';
    }

    if (currentPath.startsWith('/supervisor/')) {
        return 'supervisor';
    }

    if (currentPath.startsWith('/accountant/')) {
        return 'accountant';
    }

    if (currentPath.startsWith('/assistant/')) {
        return 'assistant';
    }

    if (currentPath.startsWith('/tax/')) {
        return 'tax';
    }

    // Approvers from other panels open receivables adjustments with their own menu.
    if (
        currentPath.startsWith('/receivables/') &&
        homePath === RECEIVABLES_HOME
    ) {
        return 'receivables';
    }

    // Approvers from other panels open payment proposals and payables adjustments with their own menu.
    if (currentPath.startsWith('/payables/') && homePath === PAYABLES_HOME) {
        return 'payables';
    }

    // The audit desk keeps its own menu for auditors; other roles open audit screens with theirs.
    if (
        (currentPath === AUDITOR_HOME ||
            currentPath.startsWith(AUDITOR_HOME + '/')) &&
        homePath === AUDITOR_HOME
    ) {
        return 'auditor';
    }

    // Approvers from other panels review payrolls with their own menu.
    if (currentPath.startsWith('/payroll/') && homePath === PAYROLL_HOME) {
        return 'payroll';
    }

    // Approvers from other panels open treasury approvals with their own menu.
    if (currentPath.startsWith('/treasury/') && homePath === TREASURY_HOME) {
        return 'treasury';
    }

    if (pageCompany) {
        return null;
    }

    const isSharedFirmScreen =
        sharedFirmScreens.includes(currentPath) ||
        sharedFirmPrefixes.some((prefix) => currentPath.startsWith(prefix));

    if (!isSharedFirmScreen) {
        return null;
    }

    if (homePath === EXECUTIVE_HOME) {
        return 'executive';
    }

    if (homePath === OPERATIONS_HOME) {
        return 'operations';
    }

    if (homePath === SUPERVISOR_HOME) {
        return 'supervisor';
    }

    if (homePath === ACCOUNTANT_HOME) {
        return 'accountant';
    }

    if (homePath === ASSISTANT_HOME) {
        return 'assistant';
    }

    if (homePath === TAX_HOME) {
        return 'tax';
    }

    if (homePath === TREASURY_HOME) {
        return 'treasury';
    }

    if (homePath === RECEIVABLES_HOME) {
        return 'receivables';
    }

    if (homePath === PAYABLES_HOME) {
        return 'payables';
    }

    if (homePath === PAYROLL_HOME) {
        return 'payroll';
    }

    if (homePath === AUDITOR_HOME) {
        return 'auditor';
    }

    return homePath === OFFICE_ADMIN_HOME ? 'office-admin' : null;
}

/** Keeps the selected branch (and period) when moving between firm-level screens. */
export function withOfficeScope(
    href: string,
    officeId: string | null,
    period: string | null = null,
): string {
    if (!officeId && !period) {
        return href;
    }

    const [path, hash] = href.split('#');
    const params = new URLSearchParams();

    if (officeId) {
        params.set('office_id', officeId);
    }

    if (period) {
        params.set('period', period);
    }

    return `${path}${path.includes('?') ? '&' : '?'}${params.toString()}${hash ? `#${hash}` : ''}`;
}
