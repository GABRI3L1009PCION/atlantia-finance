import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import ControlCenterLayout from '@/layouts/control-center-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'service-hours':
            case name === 'error':
            case name === 'work/certificate':
            case name === 'legal/show':
            case name === 'legal/accept':
            case name === 'quote/request':
                return null;
            case name === 'billing/checkout':
            case name === 'billing/plans':
            case name === 'billing/embedded-checkout':
                return null;
            case name === 'billing/details':
                return AuthLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('control-center/'):
                return ControlCenterLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
