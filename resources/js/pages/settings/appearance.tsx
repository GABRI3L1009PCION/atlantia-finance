import { Head } from '@inertiajs/react';
import { Monitor } from 'lucide-react';
import AppearanceTabs from '@/components/appearance-tabs';
import { SettingsCard } from '@/components/settings-card';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Apariencia" />

            <SettingsCard
                icon={Monitor}
                title="Apariencia"
                description="Personaliza cómo se ve la aplicación en este dispositivo."
            >
                <AppearanceTabs />
            </SettingsCard>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Configuración de apariencia',
            href: editAppearance(),
        },
    ],
};
