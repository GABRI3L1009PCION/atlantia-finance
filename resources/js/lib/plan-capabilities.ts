export type PlanFeature =
    | 'advanced_reports'
    | 'approval_workflows'
    | 'audit_log'
    | 'automatic_backups'
    | 'basic_accounting'
    | 'basic_invoicing'
    | 'fel'
    | 'initial_training'
    | 'inventory'
    | 'obligation_management'
    | 'ocr'
    | 'payroll'
    | 'roles_and_permissions'
    | 'task_management';

export type PlanModule =
    | 'activos-fijos'
    | 'auditoria'
    | 'caja'
    | 'cierre-periodo'
    | 'conciliacion'
    | 'configuracion'
    | 'cotizaciones'
    | 'documentos'
    | 'estados-cuenta'
    | 'fel'
    | 'impuestos'
    | 'inventario'
    | 'inventario-fisico'
    | 'kardex'
    | 'libros-iva'
    | 'nomina'
    | 'normativa'
    | 'notas-credito'
    | 'ordenes-compra'
    | 'portal-cliente'
    | 'presupuestos'
    | 'proyectos'
    | 'recordatorios'
    | 'usuarios-roles'
    | 'reglas';

const moduleFeatures: Record<PlanModule, PlanFeature> = {
    conciliacion: 'advanced_reports',
    reglas: 'advanced_reports',
    caja: 'advanced_reports',
    inventario: 'inventory',
    kardex: 'inventory',
    'inventario-fisico': 'inventory',
    'activos-fijos': 'advanced_reports',
    impuestos: 'advanced_reports',
    nomina: 'payroll',
    proyectos: 'advanced_reports',
    presupuestos: 'advanced_reports',
    normativa: 'advanced_reports',
    documentos: 'ocr',
    cotizaciones: 'approval_workflows',
    fel: 'fel',
    'portal-cliente': 'advanced_reports',
    'notas-credito': 'approval_workflows',
    'ordenes-compra': 'approval_workflows',
    'libros-iva': 'advanced_reports',
    'cierre-periodo': 'approval_workflows',
    'estados-cuenta': 'obligation_management',
    recordatorios: 'task_management',
    auditoria: 'audit_log',
    'usuarios-roles': 'roles_and_permissions',
    configuracion: 'basic_accounting',
};

export function hasPlanFeature(
    features: readonly string[],
    feature: PlanFeature,
): boolean {
    return features.includes(feature);
}

export function hasPlanModule(
    features: readonly string[],
    module: string,
): module is PlanModule {
    return (
        module in moduleFeatures &&
        hasPlanFeature(features, moduleFeatures[module as PlanModule])
    );
}
