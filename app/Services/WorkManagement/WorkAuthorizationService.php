<?php

declare(strict_types=1);

namespace App\Services\WorkManagement;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WorkAuthorizationService
{
    public const PERMISSIONS = [
        'office.executive.view' => ['Dirección', 'Ver panel ejecutivo'],
        'office.dashboard.view' => ['Dirección', 'Ver panel administrativo'],
        'office.manage' => ['Administración', 'Gestionar oficinas'],
        'office.users.view' => ['Administración', 'Ver equipo'],
        'office.users.manage' => ['Administración', 'Gestionar usuarios'],
        'office.roles.manage' => ['Administración', 'Gestionar roles'],
        'crm.view' => ['Clientes', 'Ver cartera comercial, contratos y prospectos'],
        'crm.manage' => ['Clientes', 'Gestionar prospectos, contratos e interacciones'],
        'training.view' => ['Capacitación', 'Ver capacitación del equipo'],
        'training.manage' => ['Capacitación', 'Registrar cursos, certificaciones y necesidades'],
        'office.companies.view' => ['Administración', 'Ver empresas'],
        'office.companies.assign' => ['Administración', 'Asignar empresas'],
        'office.workload.view' => ['Operación', 'Ver carga de trabajo'],
        'office.audit.view' => ['Auditoría', 'Ver auditoría'],
        'office.users.invite' => ['Administración', 'Invitar usuarios'],
        'office.users.deactivate' => ['Administración', 'Desactivar y reactivar usuarios'],
        'office.companies.create' => ['Administración', 'Crear empresas'],
        'office.companies.update' => ['Administración', 'Editar y mover empresas entre oficinas'],
        'office.settings.manage' => ['Administración', 'Gestionar configuración de oficina'],
        'office.security.view' => ['Seguridad', 'Ver seguridad de la oficina'],
        'office.security.manage' => ['Seguridad', 'Gestionar 2FA y sesiones'],
        'roles.assign_high_privilege' => ['Administración', 'Asignar roles de dirección o administración'],
        'subscription.manage' => ['Cuenta', 'Gestionar plan y suscripción'],
        'operations.dashboard.view' => ['Operación', 'Ver panel de operaciones'],
        'operations.incidents.manage' => ['Operación', 'Registrar y resolver incidencias'],
        'reviews.reassign' => ['Revisión', 'Reasignar revisores'],
        'office.assignments.request_change' => ['Administración', 'Solicitar cambios de asignación'],
        'supervision.dashboard.view' => ['Supervisión', 'Ver panel de supervisión contable'],
        'supervision.fiscal.approve' => ['Supervisión', 'Aprobar la revisión fiscal del período'],
        'supervision.periods.close' => ['Supervisión', 'Cerrar períodos aprobados por supervisión'],
        'communications.send' => ['Comunicación', 'Enviar mensajes y solicitudes a equipo y clientes'],
        'accountant.dashboard.view' => ['Contador encargado', 'Ver panel del contador encargado'],
        'assistant.dashboard.view' => ['Auxiliar contable', 'Ver panel del auxiliar contable'],
        'fiscal.dashboard.view' => ['Fiscal', 'Ver panel del encargado fiscal'],
        'fiscal.obligations.generate' => ['Fiscal', 'Generar obligaciones desde la configuración fiscal'],
        'fiscal.obligations.prepare' => ['Fiscal', 'Preparar obligaciones fiscales'],
        'fiscal.obligations.submit' => ['Fiscal', 'Enviar obligaciones fiscales a revisión'],
        'fiscal.obligations.review' => ['Fiscal', 'Aprobar o devolver obligaciones fiscales'],
        'fiscal.obligations.mark_filed' => ['Fiscal', 'Registrar la presentación de obligaciones con evidencia'],
        'payables.dashboard.view' => ['Cuentas por pagar', 'Ver panel de cuentas por pagar'],
        'payables.payment_proposals.create' => ['Cuentas por pagar', 'Preparar propuestas de pago'],
        'payables.payment_proposals.submit' => ['Cuentas por pagar', 'Enviar propuestas a aprobación y a Tesorería'],
        'payables.payment_proposals.approve' => ['Cuentas por pagar', 'Aprobar propuestas de pago'],
        'payables.advances.apply' => ['Cuentas por pagar', 'Aplicar anticipos y pagos sin aplicar'],
        'payables.disputes.manage' => ['Cuentas por pagar', 'Abrir y resolver disputas con proveedores'],
        'payables.incidents.manage' => ['Cuentas por pagar', 'Resolver incidencias de CxP'],
        'payables.reports.view' => ['Cuentas por pagar', 'Ver y exportar reportes de CxP'],
        'payables.payment.unapply' => ['Cuentas por pagar', 'Desaplicar pagos de facturas'],
        'payables.adjustments.create' => ['Cuentas por pagar', 'Solicitar ajustes de CxP'],
        'payables.adjustments.approve' => ['Cuentas por pagar', 'Aprobar ajustes de CxP'],
        'payables.write_off' => ['Cuentas por pagar', 'Solicitar condonaciones de proveedores'],
        'payables.credit_override' => ['Cuentas por pagar', 'Autorizar excepciones de crédito con proveedores'],
        'receivables.dashboard.view' => ['Cuentas por cobrar', 'Ver panel de cuentas por cobrar'],
        'receivables.collections.create' => ['Cuentas por cobrar', 'Registrar gestiones de cobranza'],
        'receivables.promises.manage' => ['Cuentas por cobrar', 'Registrar y cancelar promesas de pago'],
        'receivables.receipts.apply' => ['Cuentas por cobrar', 'Aplicar cobros a documentos'],
        'receivables.statements.send' => ['Cuentas por cobrar', 'Enviar estados de cuenta y recordatorios'],
        'receivables.disputes.manage' => ['Cuentas por cobrar', 'Abrir y resolver disputas'],
        'receivables.incidents.manage' => ['Cuentas por cobrar', 'Resolver incidencias de cartera'],
        'receivables.reports.view' => ['Cuentas por cobrar', 'Ver y exportar reportes de cartera'],
        'receivables.receipt.unapply' => ['Cuentas por cobrar', 'Revertir aplicaciones de cobros'],
        'receivables.adjustment.create' => ['Cuentas por cobrar', 'Crear ajustes de cartera'],
        'receivables.adjustment.approve' => ['Cuentas por cobrar', 'Aprobar ajustes de cartera'],
        'receivables.write_off' => ['Cuentas por cobrar', 'Castigar cuentas incobrables'],
        'receivables.credit_override' => ['Cuentas por cobrar', 'Autorizar excepciones de crédito'],
        'audit.dashboard.view' => ['Auditoría', 'Ver panel de auditoría'],
        'audit.engagements.view' => ['Auditoría', 'Ver auditorías'],
        'audit.engagements.create' => ['Auditoría', 'Crear y asignar auditorías'],
        'audit.engagements.manage' => ['Auditoría', 'Avanzar etapas de sus auditorías'],
        'audit.plans.view' => ['Auditoría', 'Ver planes de auditoría'],
        'audit.plans.manage' => ['Auditoría', 'Preparar y aprobar planes de auditoría'],
        'audit.risks.view' => ['Auditoría', 'Ver riesgos de auditoría'],
        'audit.risks.manage' => ['Auditoría', 'Registrar riesgos de auditoría'],
        'audit.controls.view' => ['Auditoría', 'Ver controles'],
        'audit.controls.assess' => ['Auditoría', 'Registrar y evaluar controles'],
        'audit.procedures.view' => ['Auditoría', 'Ver procedimientos'],
        'audit.procedures.execute' => ['Auditoría', 'Ejecutar procedimientos'],
        'audit.samples.view' => ['Auditoría', 'Ver muestras'],
        'audit.samples.create' => ['Auditoría', 'Generar muestras'],
        'audit.samples.confirm' => ['Auditoría', 'Confirmar y congelar muestras'],
        'audit.evidence.view' => ['Auditoría', 'Ver evidencia de auditoría'],
        'audit.evidence.request' => ['Auditoría', 'Solicitar evidencia'],
        'audit.evidence.review' => ['Auditoría', 'Revisar evidencia'],
        'audit.working_papers.view' => ['Auditoría', 'Ver papeles de trabajo'],
        'audit.working_papers.manage' => ['Auditoría', 'Preparar y revisar papeles de trabajo'],
        'audit.findings.view' => ['Auditoría', 'Ver hallazgos'],
        'audit.findings.create' => ['Auditoría', 'Crear hallazgos'],
        'audit.findings.update' => ['Auditoría', 'Documentar y emitir hallazgos'],
        'audit.findings.verify' => ['Auditoría', 'Verificar correcciones de hallazgos'],
        'audit.findings.close' => ['Auditoría', 'Cerrar hallazgos verificados'],
        'audit.accounting_review.view' => ['Auditoría', 'Revisión contable de auditoría (solo lectura)'],
        'audit.reconciliations.view' => ['Auditoría', 'Ver conciliaciones para auditoría'],
        'audit.period_close.view' => ['Auditoría', 'Ver cierres y reaperturas'],
        'audit.documents.view' => ['Auditoría', 'Ver documentos soporte'],
        'audit.traceability.view' => ['Auditoría', 'Ver trazabilidad de operaciones'],
        'audit.system_events.view' => ['Auditoría', 'Ver registro de auditoría del sistema'],
        'audit.sensitive_changes.view' => ['Auditoría', 'Ver cambios sensibles'],
        'audit.reports.view' => ['Auditoría', 'Ver informes de auditoría'],
        'audit.reports.create' => ['Auditoría', 'Preparar informes de auditoría'],
        'audit.reports.issue' => ['Auditoría', 'Emitir informes de auditoría aprobados'],
        'audit.activity.view' => ['Auditoría', 'Ver actividad de auditoría'],
        'audit.findings.accept_risk' => ['Auditoría', 'Aceptar el riesgo de un hallazgo (administración)'],
        'audit.reports.final_approve' => ['Auditoría', 'Aprobación final de informes de auditoría'],
        'audit.external_access.manage' => ['Auditoría', 'Gestionar accesos de auditores externos'],
        'audit.sensitive_documents.view' => ['Auditoría', 'Ver documentos sensibles en auditoría'],
        'audit.payroll_sensitive.view' => ['Auditoría', 'Ver datos salariales en auditoría'],
        'payroll.dashboard.view' => ['Nómina', 'Ver panel de nómina'],
        'payroll.employees.manage' => ['Nómina', 'Registrar y editar empleados'],
        'payroll.employees.view_salary' => ['Nómina', 'Ver salarios, DPI y cuentas bancarias completas'],
        'payroll.novelties.manage' => ['Nómina', 'Registrar y revisar novedades'],
        'payroll.calculate' => ['Nómina', 'Abrir períodos y calcular nómina'],
        'payroll.submit' => ['Nómina', 'Enviar nómina a revisión'],
        'payroll.approve' => ['Nómina', 'Aprobar o devolver nóminas'],
        'payroll.payments.prepare' => ['Nómina', 'Enviar órdenes de pago a Tesorería'],
        'payroll.loans.manage' => ['Nómina', 'Registrar anticipos y préstamos a empleados'],
        'payroll.concepts.manage' => ['Nómina', 'Gestionar conceptos y cuentas de nómina'],
        'payroll.incidents.manage' => ['Nómina', 'Resolver incidencias de nómina'],
        'payroll.reports.view' => ['Nómina', 'Ver y exportar reportes de nómina'],
        'treasury.dashboard.view' => ['Tesorería', 'Ver panel de tesorería'],
        'treasury.bank_transactions.import' => ['Tesorería', 'Importar estados de cuenta'],
        'treasury.bank_transactions.match' => ['Tesorería', 'Conciliar y clasificar movimientos bancarios'],
        'treasury.reconciliation.prepare' => ['Tesorería', 'Preparar conciliaciones bancarias'],
        'treasury.reconciliation.approve' => ['Tesorería', 'Aprobar conciliaciones bancarias'],
        'treasury.receipts.create' => ['Tesorería', 'Registrar y aplicar cobros'],
        'treasury.payments.prepare' => ['Tesorería', 'Preparar pagos'],
        'treasury.payments.approve' => ['Tesorería', 'Aprobar pagos'],
        'treasury.payments.execute' => ['Tesorería', 'Registrar la ejecución de pagos con evidencia'],
        'treasury.transfers.create' => ['Tesorería', 'Registrar transferencias entre cuentas'],
        'treasury.cash.count' => ['Tesorería', 'Registrar arqueos de caja'],
        'treasury.cash.adjust' => ['Tesorería', 'Autorizar ajustes por diferencias de caja'],
        'reviews.submit_override' => ['Revisión', 'Enviar a revisión con bloqueos (motivo auditado)'],
        'reviews.first_level_approve' => ['Revisión', 'Aprobar revisión de primer nivel'],
        'reviews.upper_approve' => ['Revisión', 'Aprobar revisión superior (segundo nivel)'],
        'periods.request_close' => ['Contabilidad', 'Solicitar cierre de período'],
        'periods.request_reopen' => ['Contabilidad', 'Solicitar reapertura de período'],
        'tasks.view' => ['Tareas', 'Ver tareas propias'],
        'tasks.view_all' => ['Tareas', 'Ver todas las tareas'],
        'tasks.create' => ['Tareas', 'Crear tareas'],
        'tasks.assign' => ['Tareas', 'Asignar tareas'],
        'tasks.reassign' => ['Tareas', 'Reasignar tareas'],
        'tasks.start' => ['Tareas', 'Iniciar tareas'],
        'tasks.submit' => ['Tareas', 'Enviar a revisión'],
        'tasks.review' => ['Tareas', 'Revisar tareas'],
        'tasks.request_changes' => ['Tareas', 'Solicitar cambios'],
        'tasks.approve' => ['Tareas', 'Aprobar tareas'],
        'tasks.complete' => ['Tareas', 'Completar tareas'],
        'tasks.cancel' => ['Tareas', 'Cancelar tareas'],
        'tasks.manage_templates' => ['Tareas', 'Gestionar plantillas'],
        'reviews.view' => ['Revisión', 'Ver revisiones'],
        'reviews.submit' => ['Revisión', 'Enviar empresa a revisión'],
        'reviews.start' => ['Revisión', 'Iniciar revisión'],
        'reviews.request_changes' => ['Revisión', 'Solicitar cambios de revisión'],
        'reviews.approve' => ['Revisión', 'Aprobar revisión'],
        'sales.view' => ['Ventas', 'Ver ventas y cartera'],
        'sales.manage' => ['Ventas', 'Crear ventas, clientes y cobros'],
        'sales.process' => ['Ventas', 'Emitir ventas'],
        'sales.void' => ['Ventas', 'Anular ventas y cobros'],
        'sales.export' => ['Ventas', 'Exportar ventas'],
        'purchases.view' => ['Compras', 'Ver compras y proveedores'],
        'purchases.manage' => ['Compras', 'Crear compras, proveedores y pagos'],
        'purchases.approve' => ['Compras', 'Aprobar compras'],
        'purchases.void' => ['Compras', 'Anular compras y pagos'],
        'purchases.export' => ['Compras', 'Exportar compras'],
        'banks.view' => ['Bancos', 'Ver bancos y conciliaciones'],
        'banks.manage' => ['Bancos', 'Registrar cuentas y movimientos bancarios'],
        'banks.export' => ['Bancos', 'Exportar bancos'],
        'fiscal.view' => ['Fiscal', 'Ver configuración fiscal'],
        'fiscal.manage' => ['Fiscal', 'Gestionar configuración fiscal'],
        'vat_books.view' => ['Fiscal', 'Ver Libros IVA'],
        'vat_books.export' => ['Fiscal', 'Exportar Libros IVA'],
        'vat_books.close' => ['Fiscal', 'Cerrar Libros IVA'],
        'fel.view' => ['FEL', 'Ver documentos FEL'],
        'fel.manage' => ['FEL', 'Crear y editar documentos FEL'],
        'fel.certify' => ['FEL', 'Certificar documentos FEL'],
        'fel.cancel' => ['FEL', 'Anular documentos FEL'],
        'fel.accounting' => ['FEL', 'Enviar FEL a contabilidad'],
        'fel.settings' => ['FEL', 'Gestionar emisor FEL'],
        'journal.view' => ['Contabilidad', 'Ver libro diario'],
        'journal.create' => ['Contabilidad', 'Crear asientos'],
        'journal.attach_support' => ['Contabilidad', 'Adjuntar soportes a asientos'],
        'journal.submit' => ['Contabilidad', 'Enviar asientos a revisión'],
        'journal.review' => ['Contabilidad', 'Revisar asientos'],
        'journal.approve' => ['Contabilidad', 'Aprobar asientos'],
        'journal.post' => ['Contabilidad', 'Contabilizar asientos'],
        'journal.reject' => ['Contabilidad', 'Rechazar asientos'],
        'journal.reverse' => ['Contabilidad', 'Reversar asientos'],
        'journal.void' => ['Contabilidad', 'Descartar asientos'],
        'journal.import' => ['Contabilidad', 'Importar asientos'],
        'ledger.view' => ['Contabilidad', 'Ver libro mayor'],
        'trial_balance.view' => ['Contabilidad', 'Ver balance de comprobación'],
        'accounts.manage' => ['Contabilidad', 'Gestionar catálogo contable'],
        'periods.manage' => ['Contabilidad', 'Gestionar períodos y cierres'],
        'documents.view' => ['Documentos', 'Ver documentos'],
        'documents.manage' => ['Documentos', 'Adjuntar y retirar documentos'],
        'reports.view' => ['Reportes', 'Ver reportes'],
        'reports.export' => ['Reportes', 'Exportar reportes'],
        'operations.view' => ['Operación', 'Ver registros operativos'],
        'operations.manage' => ['Operación', 'Gestionar registros operativos'],
        'client.portal.view' => ['Cliente', 'Acceder portal cliente'],
        'client.dashboard.view' => ['Portal cliente', 'Ver inicio del portal'],
        'client.company.view' => ['Portal cliente', 'Ver datos de la empresa'],
        'client.company.change_request' => ['Portal cliente', 'Solicitar cambios de datos de la empresa'],
        'client.pending.view' => ['Portal cliente', 'Ver pendientes'],
        'client.calendar.view' => ['Portal cliente', 'Ver calendario'],
        'client.documents.view' => ['Portal cliente', 'Ver documentos'],
        'client.documents.upload' => ['Portal cliente', 'Entregar documentos'],
        'client.documents.replace' => ['Portal cliente', 'Reemplazar documentos'],
        'client.document_requests.view' => ['Portal cliente', 'Ver solicitudes de documentos'],
        'client.document_requests.respond' => ['Portal cliente', 'Responder solicitudes'],
        'client.approvals.view' => ['Portal cliente', 'Ver autorizaciones'],
        'client.approvals.respond' => ['Portal cliente', 'Responder autorizaciones'],
        'client.obligations.view' => ['Portal cliente', 'Ver obligaciones'],
        'client.fel.view' => ['Portal cliente', 'Ver facturación FEL'],
        'client.reports.view' => ['Portal cliente', 'Ver reportes publicados'],
        'client.reports.download' => ['Portal cliente', 'Descargar reportes publicados'],
        'client.history.view' => ['Portal cliente', 'Ver historial'],
        'client.messages.view' => ['Portal cliente', 'Ver mensajes'],
        'client.messages.send' => ['Portal cliente', 'Enviar mensajes'],
        'client.luzma.use' => ['Portal cliente', 'Usar Luzma en el portal'],
        'client.reminders.create' => ['Portal cliente', 'Crear recordatorios'],
        'client.payroll.view' => ['Portal cliente', 'Ver solicitudes de nómina'],
        'client.payroll.respond' => ['Portal cliente', 'Responder solicitudes de nómina'],
        'client.fiscal.view' => ['Portal cliente', 'Ver información fiscal'],
        'client.fel.documents.download' => ['Portal cliente', 'Descargar documentos FEL'],
        'client.financial_reports.view' => ['Portal cliente', 'Ver estados financieros'],
        'client.bank_documents.upload' => ['Portal cliente', 'Entregar estados de cuenta bancarios'],
        'integrations.api.manage' => ['Integraciones', 'Gestionar llaves de API'],
        'integrations.webhooks.manage' => ['Integraciones', 'Gestionar webhooks'],
        'integrations.logs.view' => ['Integraciones', 'Ver uso de la API y entregas de webhooks'],
        'client_portal.view' => ['Portal de clientes (oficina)', 'Ver solicitudes y entregas de clientes'],
        'client_portal.requests.manage' => ['Portal de clientes (oficina)', 'Solicitar documentos e información a clientes'],
        'client_portal.documents.review' => ['Portal de clientes (oficina)', 'Revisar documentos de clientes'],
        'client_portal.approvals.request' => ['Portal de clientes (oficina)', 'Solicitar autorizaciones a clientes'],
        'client_portal.reports.publish' => ['Portal de clientes (oficina)', 'Aprobar y publicar reportes al cliente'],
        'client_portal.messages.manage' => ['Portal de clientes (oficina)', 'Conversar con clientes'],
        'client_portal.company_data.review' => ['Portal de clientes (oficina)', 'Validar cambios de datos de clientes'],
        'luzma.use' => ['Luzma', 'Usar Luzma'],
        'luzma.global.view' => ['Luzma', 'Abrir el centro de conversación de Luzma'],
        'luzma.contextual.use' => ['Luzma', 'Preguntar a Luzma dentro de cada módulo'],
        'luzma.insights.view' => ['Luzma', 'Ver alertas e insights de Luzma'],
        'luzma.reminders.view' => ['Luzma', 'Ver recordatorios de Luzma'],
        'luzma.reminders.create' => ['Luzma', 'Crear recordatorios con Luzma'],
        'luzma.actions.prepare' => ['Luzma', 'Preparar acciones con Luzma'],
        'luzma.actions.execute' => ['Luzma', 'Confirmar acciones preparadas por Luzma'],
        'luzma.history.view' => ['Luzma', 'Ver historial de conversaciones'],
        'luzma.admin.view' => ['Luzma', 'Ver estado técnico de Luzma'],
    ];

    /** Luzma capabilities of every internal role; each tool still checks the permission of its source module. */
    public const LUZMA_PERMISSIONS = ['luzma.use', 'luzma.global.view', 'luzma.contextual.use', 'luzma.insights.view', 'luzma.reminders.view', 'luzma.reminders.create', 'luzma.actions.prepare', 'luzma.actions.execute', 'luzma.history.view'];

    /** Audit work of the auditor role; special permissions (accept risk, final approval, external access, sensitive data) stay out. */
    public const AUDITOR_PERMISSIONS = ['audit.dashboard.view', 'audit.engagements.view', 'audit.engagements.manage', 'audit.plans.view', 'audit.plans.manage', 'audit.risks.view', 'audit.risks.manage', 'audit.controls.view', 'audit.controls.assess', 'audit.procedures.view', 'audit.procedures.execute', 'audit.samples.view', 'audit.samples.create', 'audit.samples.confirm', 'audit.evidence.view', 'audit.evidence.request', 'audit.evidence.review', 'audit.working_papers.view', 'audit.working_papers.manage', 'audit.findings.view', 'audit.findings.create', 'audit.findings.update', 'audit.findings.verify', 'audit.findings.close', 'audit.accounting_review.view', 'audit.reconciliations.view', 'audit.period_close.view', 'audit.documents.view', 'audit.traceability.view', 'audit.system_events.view', 'audit.sensitive_changes.view', 'audit.reports.view', 'audit.reports.create', 'audit.reports.issue', 'audit.activity.view'];

    /** Capabilities of external client users; each company narrows them further by the contact role. */
    public const CLIENT_PERMISSIONS = ['client.dashboard.view', 'client.company.view', 'client.company.change_request', 'client.pending.view', 'client.calendar.view', 'client.documents.view', 'client.documents.upload', 'client.documents.replace', 'client.document_requests.view', 'client.document_requests.respond', 'client.approvals.view', 'client.approvals.respond', 'client.obligations.view', 'client.fel.view', 'client.reports.view', 'client.reports.download', 'client.history.view', 'client.messages.view', 'client.messages.send', 'client.luzma.use', 'client.reminders.create', 'client.payroll.view', 'client.payroll.respond', 'client.fiscal.view', 'client.fel.documents.download', 'client.financial_reports.view', 'client.bank_documents.upload'];

    public const ROLE_PRESETS = [
        'owner_director' => [
            'name' => 'Director / Socio',
            'description' => 'Supervisa la firma, oficinas, equipo, cumplimiento y resultados consolidados.',
            'permissions' => ['*'],
        ],
        'office_admin' => [
            'name' => 'Administrador de Oficina',
            'description' => 'Administra estructura, oficinas, usuarios, roles, empresas y configuración.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'integrations.api.manage', 'integrations.webhooks.manage', 'integrations.logs.view', 'client_portal.view', 'client_portal.company_data.review', 'audit.dashboard.view', 'audit.engagements.view', 'audit.engagements.create', 'audit.external_access.manage', 'vat_books.view', 'vat_books.export', 'office.dashboard.view', 'office.manage', 'office.users.view', 'office.users.manage', 'office.roles.manage', 'office.companies.view', 'office.companies.assign', 'office.workload.view', 'office.audit.view', 'office.users.invite', 'office.users.deactivate', 'office.companies.create', 'office.companies.update', 'office.settings.manage', 'office.security.view', 'office.security.manage', 'training.view', 'training.manage', 'crm.view', 'crm.manage', 'tasks.view_all', 'tasks.create', 'tasks.assign', 'tasks.reassign', 'tasks.cancel', 'tasks.manage_templates', 'reviews.view', 'sales.view', 'sales.export', 'purchases.view', 'purchases.export', 'banks.view', 'banks.export', 'fiscal.view', 'fel.view', 'fel.settings', 'journal.view', 'ledger.view', 'trial_balance.view', 'documents.view', 'reports.view', 'reports.export', 'operations.view'],
        ],
        'accounting_manager' => [
            'name' => 'Gerente Contable',
            'description' => 'Dirige producción contable, distribución de carga, revisiones y cierres.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'client_portal.view', 'audit.dashboard.view', 'audit.engagements.view', 'audit.engagements.create', 'audit.findings.accept_risk', 'payroll.approve', 'payroll.dashboard.view', 'payroll.reports.view', 'payables.payment_proposals.approve', 'payables.adjustments.approve', 'receivables.adjustment.approve', 'treasury.payments.approve', 'treasury.reconciliation.approve', 'reviews.submit_override', 'operations.dashboard.view', 'operations.incidents.manage', 'reviews.reassign', 'office.assignments.request_change', 'training.view', 'vat_books.view', 'vat_books.export', 'office.companies.view', 'office.workload.view', 'tasks.view', 'tasks.view_all', 'tasks.create', 'tasks.assign', 'tasks.reassign', 'tasks.cancel', 'tasks.manage_templates', 'reviews.view', 'reviews.start', 'reviews.request_changes', 'reviews.approve', 'sales.view', 'purchases.view', 'banks.view', 'fiscal.view', 'fel.view', 'journal.view', 'journal.review', 'ledger.view', 'trial_balance.view', 'documents.view', 'reports.view', 'reports.export', 'operations.view'],
        ],
        'general_accountant' => [
            'name' => 'Contador General',
            'description' => 'Supervisa contabilidad, revisa asientos, valida balances y aprueba revisión.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'client_portal.view', 'client_portal.reports.publish', 'client_portal.approvals.request', 'client_portal.company_data.review', 'audit.findings.accept_risk', 'payroll.approve', 'payroll.dashboard.view', 'payroll.reports.view', 'payables.payment_proposals.approve', 'payables.adjustments.approve', 'payables.write_off', 'receivables.adjustment.approve', 'receivables.write_off', 'treasury.payments.approve', 'treasury.reconciliation.approve', 'fiscal.obligations.review', 'fiscal.obligations.mark_filed', 'reviews.upper_approve', 'reviews.submit_override', 'vat_books.view', 'vat_books.export', 'vat_books.close', 'tasks.view', 'tasks.view_all', 'tasks.review', 'tasks.request_changes', 'tasks.approve', 'reviews.view', 'reviews.start', 'reviews.request_changes', 'reviews.approve', 'sales.view', 'purchases.view', 'banks.view', 'fiscal.view', 'fel.view', 'journal.view', 'journal.create', 'journal.attach_support', 'journal.submit', 'journal.review', 'journal.approve', 'journal.post', 'journal.reject', 'journal.import', 'ledger.view', 'trial_balance.view', 'periods.manage', 'documents.view', 'documents.manage', 'reports.view', 'reports.export', 'operations.view'],
        ],
        'accounting_supervisor' => [
            'name' => 'Supervisor Contable',
            'description' => 'Supervisa auxiliares, borradores, observaciones y trabajo contable por equipo.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'client_portal.view', 'client_portal.requests.manage', 'client_portal.documents.review', 'client_portal.messages.manage', 'treasury.payments.approve', 'treasury.reconciliation.approve', 'fiscal.obligations.review', 'fiscal.obligations.mark_filed', 'supervision.dashboard.view', 'supervision.fiscal.approve', 'supervision.periods.close', 'communications.send', 'operations.incidents.manage', 'reviews.approve', 'reviews.reassign', 'vat_books.view', 'vat_books.export', 'vat_books.close', 'office.audit.view', 'office.workload.view', 'tasks.view', 'tasks.view_all', 'tasks.create', 'tasks.assign', 'tasks.reassign', 'tasks.review', 'tasks.request_changes', 'tasks.approve', 'reviews.view', 'reviews.start', 'reviews.request_changes', 'sales.view', 'purchases.view', 'banks.view', 'fiscal.view', 'fel.view', 'journal.view', 'journal.create', 'journal.attach_support', 'journal.submit', 'journal.review', 'journal.reject', 'ledger.view', 'trial_balance.view', 'documents.view', 'documents.manage', 'reports.view', 'operations.view', 'operations.manage'],
        ],
        'accountant_in_charge' => [
            'name' => 'Contador Encargado',
            'description' => 'Responsable directo de empresas asignadas y revisión operativa.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'client_portal.view', 'client_portal.requests.manage', 'client_portal.documents.review', 'client_portal.approvals.request', 'client_portal.reports.publish', 'client_portal.messages.manage', 'client_portal.company_data.review', 'payroll.approve', 'payroll.dashboard.view', 'payroll.reports.view', 'payables.payment_proposals.approve', 'payables.adjustments.create', 'payables.payment.unapply', 'receivables.adjustment.create', 'receivables.receipt.unapply', 'treasury.payments.approve', 'treasury.reconciliation.approve', 'fiscal.obligations.review', 'fiscal.obligations.mark_filed', 'accountant.dashboard.view', 'reviews.start', 'reviews.request_changes', 'reviews.first_level_approve', 'periods.request_close', 'periods.request_reopen', 'communications.send', 'operations.incidents.manage', 'journal.approve', 'vat_books.export', 'reports.export', 'vat_books.view', 'tasks.view', 'tasks.create', 'tasks.assign', 'tasks.review', 'tasks.request_changes', 'tasks.approve', 'reviews.view', 'reviews.submit', 'sales.view', 'sales.manage', 'purchases.view', 'purchases.manage', 'banks.view', 'banks.manage', 'fiscal.view', 'fel.view', 'fel.manage', 'journal.view', 'journal.create', 'journal.attach_support', 'journal.submit', 'journal.review', 'journal.reject', 'ledger.view', 'trial_balance.view', 'documents.view', 'documents.manage', 'reports.view', 'operations.view', 'operations.manage'],
        ],
        'accounting_assistant' => [
            'name' => 'Auxiliar Contable',
            'description' => 'Ejecuta operación contable y prepara empresas para revisión.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'client_portal.view', 'client_portal.requests.manage', 'client_portal.documents.review', 'client_portal.messages.manage', 'assistant.dashboard.view', 'communications.send', 'vat_books.view', 'tasks.view', 'tasks.start', 'tasks.submit', 'tasks.complete', 'reviews.submit', 'sales.view', 'sales.manage', 'purchases.view', 'purchases.manage', 'banks.view', 'banks.manage', 'fiscal.view', 'fel.view', 'fel.manage', 'journal.view', 'journal.create', 'journal.attach_support', 'journal.submit', 'ledger.view', 'trial_balance.view', 'documents.view', 'documents.manage', 'operations.view', 'operations.manage'],
        ],
        'tax_specialist' => [
            'name' => 'Encargado Fiscal',
            'description' => 'Gestiona obligaciones tributarias, libros IVA, FEL y documentos fiscales.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'client_portal.view', 'client_portal.requests.manage', 'client_portal.documents.review', 'client_portal.approvals.request', 'client_portal.messages.manage', 'fiscal.dashboard.view', 'fiscal.obligations.generate', 'fiscal.obligations.prepare', 'fiscal.obligations.submit', 'communications.send', 'vat_books.view', 'vat_books.export', 'tasks.view', 'tasks.start', 'tasks.submit', 'tasks.complete', 'sales.view', 'purchases.view', 'fiscal.view', 'fel.view', 'fel.manage', 'fel.certify', 'fel.accounting', 'journal.view', 'journal.create', 'journal.attach_support', 'journal.submit', 'ledger.view', 'trial_balance.view', 'documents.view', 'documents.manage', 'reports.view', 'operations.view', 'operations.manage'],
        ],
        'treasury' => [
            'name' => 'Tesorería',
            'description' => 'Gestiona bancos, conciliaciones, pagos, cobros y flujo financiero.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'client_portal.view', 'client_portal.approvals.request', 'client_portal.messages.manage', 'treasury.dashboard.view', 'treasury.bank_transactions.import', 'treasury.bank_transactions.match', 'treasury.reconciliation.prepare', 'treasury.receipts.create', 'treasury.payments.prepare', 'treasury.payments.execute', 'treasury.transfers.create', 'treasury.cash.count', 'communications.send', 'tasks.view', 'tasks.start', 'tasks.submit', 'tasks.complete', 'sales.view', 'sales.manage', 'purchases.view', 'purchases.manage', 'banks.view', 'banks.manage', 'banks.export', 'journal.view', 'journal.create', 'journal.attach_support', 'journal.submit', 'ledger.view', 'trial_balance.view', 'documents.view', 'documents.manage', 'reports.view', 'operations.view', 'operations.manage'],
        ],
        'accounts_receivable' => [
            'name' => 'Cuentas por Cobrar',
            'description' => 'Gestiona clientes, facturas, cobros, cartera vencida y estados de cuenta.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'receivables.dashboard.view', 'receivables.collections.create', 'receivables.promises.manage', 'receivables.receipts.apply', 'receivables.statements.send', 'receivables.disputes.manage', 'receivables.incidents.manage', 'receivables.reports.view', 'communications.send', 'tasks.view', 'tasks.start', 'tasks.submit', 'tasks.complete', 'sales.view', 'sales.manage', 'sales.process', 'sales.export', 'banks.view', 'journal.view', 'journal.create', 'journal.attach_support', 'journal.submit', 'ledger.view', 'trial_balance.view', 'documents.view', 'documents.manage', 'reports.view', 'operations.view', 'operations.manage'],
        ],
        'accounts_payable' => [
            'name' => 'Cuentas por Pagar',
            'description' => 'Gestiona proveedores, compras, pagos, vencimientos y cuentas por pagar.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'payables.dashboard.view', 'payables.payment_proposals.create', 'payables.payment_proposals.submit', 'payables.advances.apply', 'payables.disputes.manage', 'payables.incidents.manage', 'payables.reports.view', 'communications.send', 'tasks.view', 'tasks.start', 'tasks.submit', 'tasks.complete', 'purchases.view', 'purchases.manage', 'purchases.approve', 'purchases.export', 'banks.view', 'journal.view', 'journal.create', 'journal.attach_support', 'journal.submit', 'ledger.view', 'trial_balance.view', 'documents.view', 'documents.manage', 'reports.view', 'operations.view', 'operations.manage'],
        ],
        'payroll' => [
            'name' => 'Nómina',
            'description' => 'Gestiona nómina, revisiones y obligaciones laborales.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'client_portal.view', 'client_portal.requests.manage', 'client_portal.approvals.request', 'client_portal.messages.manage', 'payroll.dashboard.view', 'payroll.employees.manage', 'payroll.employees.view_salary', 'payroll.novelties.manage', 'payroll.calculate', 'payroll.submit', 'payroll.payments.prepare', 'payroll.loans.manage', 'payroll.concepts.manage', 'payroll.incidents.manage', 'payroll.reports.view', 'communications.send', 'tasks.view', 'tasks.start', 'tasks.submit', 'tasks.complete', 'journal.view', 'journal.create', 'journal.attach_support', 'journal.submit', 'ledger.view', 'trial_balance.view', 'documents.view', 'documents.manage', 'reports.view', 'operations.view', 'operations.manage'],
        ],
        'auditor' => [
            'name' => 'Auditor',
            'description' => 'Verifica información con acceso principalmente de lectura.',
            'permissions' => [...self::LUZMA_PERMISSIONS, ...self::AUDITOR_PERMISSIONS, 'vat_books.view', 'vat_books.export', 'tasks.view', 'reviews.view', 'office.audit.view', 'sales.view', 'sales.export', 'purchases.view', 'purchases.export', 'banks.view', 'banks.export', 'fiscal.view', 'fel.view', 'journal.view', 'ledger.view', 'trial_balance.view', 'documents.view', 'reports.view', 'reports.export', 'operations.view'],
        ],
        'client_user' => [
            'name' => 'Cliente Externo',
            'description' => 'Accede únicamente al portal del cliente y solicitudes autorizadas.',
            'permissions' => ['client.portal.view', ...self::CLIENT_PERMISSIONS, 'luzma.use', 'luzma.contextual.use', 'luzma.reminders.view', 'luzma.reminders.create'],
        ],
        'read_only' => [
            'name' => 'Solo Lectura',
            'description' => 'Consulta información autorizada sin modificarla.',
            'permissions' => [...self::LUZMA_PERMISSIONS, 'vat_books.view', 'tasks.view', 'reviews.view', 'sales.view', 'purchases.view', 'banks.view', 'fiscal.view', 'fel.view', 'journal.view', 'ledger.view', 'trial_balance.view', 'documents.view', 'reports.view', 'operations.view'],
        ],
    ];

    private static ?string $definitionHash = null;

    /** @var array<int, true> Organizations already synced during this request. */
    private array $synced = [];

    public function __construct(private AccountingService $accounting) {}

    /** @return array<string, mixed> */
    public function ensureDefaults(Organization $organization): array
    {
        $this->syncDefaults($organization);

        return [
            'roles' => DB::table('workspace_roles')->where('organization_id', $organization->id)->whereNull('deleted_at')->orderBy('name')->get(),
            'permissions' => DB::table('workspace_permissions')->orderBy('group')->orderBy('name')->get(),
        ];
    }

    /**
     * Writes the permission catalog and the system presets only when they changed. Every panel request calls this, and
     * a full sync costs ~1,400 queries, so a fingerprint of the definitions plus the persisted state of the system
     * roles and owners decides whether anything has to be written (a tampered or missing preset still gets restored).
     */
    private function syncDefaults(Organization $organization): void
    {
        $key = 'workspace_defaults:'.$organization->id;
        if (isset($this->synced[$organization->id]) || Cache::get($key) === $this->defaultsFingerprint($organization)) {
            $this->synced[$organization->id] = true;

            return;
        }

        $this->writeDefaults($organization);
        Cache::put($key, $this->defaultsFingerprint($organization), now()->addDay());
        $this->synced[$organization->id] = true;
    }

    private function defaultsFingerprint(Organization $organization): string
    {
        $roles = DB::table('workspace_roles')->where('workspace_roles.organization_id', $organization->id)->where('is_system', true)
            ->selectRaw("CAST(COUNT(*) AS BIGINT) as total, CAST(COUNT(*) FILTER (WHERE status = 'active' AND deactivated_at IS NULL) AS BIGINT) as active")->first();
        $grants = DB::table('workspace_role_permissions')->join('workspace_roles', 'workspace_roles.id', '=', 'workspace_role_permissions.workspace_role_id')
            ->where('workspace_roles.organization_id', $organization->id)->where('workspace_roles.is_system', true)->count();
        $owners = DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->orderBy('user_id')->pluck('user_id')->implode(',');
        $ownerGrants = DB::table('user_role_assignments')->join('workspace_roles', 'workspace_roles.id', '=', 'user_role_assignments.workspace_role_id')
            ->where('user_role_assignments.organization_id', $organization->id)->where('workspace_roles.code', 'owner_director')->where('user_role_assignments.status', 'active')->count();

        return md5(implode('|', [self::$definitionHash ??= md5(json_encode([self::PERMISSIONS, self::ROLE_PRESETS], JSON_THROW_ON_ERROR)),
            DB::table('workspace_permissions')->count(), $roles?->total, $roles?->active, $grants, $owners, $ownerGrants]));
    }

    private function writeDefaults(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            foreach (self::PERMISSIONS as $code => [$group, $name]) {
                DB::table('workspace_permissions')->updateOrInsert(
                    ['code' => $code],
                    ['name' => $name, 'group' => $group, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            foreach (self::ROLE_PRESETS as $code => $preset) {
                DB::table('workspace_roles')->updateOrInsert(
                    ['organization_id' => $organization->id, 'code' => $code],
                    [
                        'name' => $preset['name'],
                        'description' => $preset['description'],
                        'is_system' => true,
                        'status' => 'active',
                        'deactivated_at' => null,
                        'deactivated_by' => null,
                        'metadata' => json_encode(['preset' => true], JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );

                $roleId = (int) DB::table('workspace_roles')
                    ->where('workspace_roles.organization_id', $organization->id)
                    ->where('code', $code)
                    ->value('id');

                $permissionIds = $preset['permissions'] === ['*']
                    ? DB::table('workspace_permissions')->pluck('id')
                    : DB::table('workspace_permissions')->whereIn('code', $preset['permissions'])->pluck('id');

                foreach ($permissionIds as $permissionId) {
                    DB::table('workspace_role_permissions')->updateOrInsert([
                        'workspace_role_id' => $roleId,
                        'workspace_permission_id' => $permissionId,
                    ]);
                }

                // System presets are the source of truth: permissions dropped from a preset are revoked.
                DB::table('workspace_role_permissions')
                    ->where('workspace_role_id', $roleId)
                    ->whereNotIn('workspace_permission_id', $permissionIds)
                    ->delete();
            }

            $ownerRoleId = (int) DB::table('workspace_roles')
                ->where('workspace_roles.organization_id', $organization->id)
                ->where('code', 'owner_director')
                ->value('id');

            $ownerIds = DB::table('organization_user')
                ->where('organization_user.organization_id', $organization->id)
                ->where('role', 'owner')
                ->where('status', 'active')
                ->pluck('user_id');

            foreach ($ownerIds as $ownerId) {
                DB::table('user_role_assignments')->updateOrInsert(
                    [
                        'organization_id' => $organization->id,
                        'user_id' => $ownerId,
                        'workspace_role_id' => $ownerRoleId,
                        'status' => 'active',
                    ],
                    ['starts_at' => now(), 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });
    }

    public function primaryRole(Organization $organization, User $user): string
    {
        $role = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.user_id', $user->id)
            ->where('ura.status', 'active')
            ->where('role.status', 'active')
            ->whereNull('ura.deleted_at')
            ->orderByRaw("CASE WHEN role.code = 'owner_director' THEN 1 ELSE 0 END DESC")
            ->orderByDesc('ura.id')
            ->value('role.code');

        if (is_string($role) && $role !== '') {
            return $role;
        }

        return $this->organizationRole($organization, $user) === 'owner' ? 'owner_director' : 'accounting_assistant';
    }

    /** Luzma capabilities that only read; every other permission is an action. */
    private const READ_ONLY_LUZMA = ['luzma.use', 'luzma.global.view', 'luzma.contextual.use', 'luzma.insights.view', 'luzma.reminders.view', 'luzma.history.view'];

    /**
     * The Director / Socio only supervises: once the firm has delegated administration to an active
     * firm-wide Administrador de Oficina (not tied to a single branch), a director who is not that administrator keeps view and export permissions but no actions.
     * Without a delegated administrator the director stays unrestricted so a new firm can be set up.
     */
    public function isExecutiveReadOnly(Organization $organization, User $user): bool
    {
        if ($this->primaryRole($organization, $user) !== 'owner_director') {
            return false;
        }

        $firmAdministrators = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.status', 'active')
            ->whereNull('ura.deleted_at')
            ->whereNull('ura.office_id')
            ->where('role.code', 'office_admin')
            ->where('role.status', 'active')
            ->pluck('ura.user_id');

        return $firmAdministrators->isNotEmpty() && ! $firmAdministrators->contains($user->id);
    }

    private function isReadPermission(string $permission): bool
    {
        return (bool) preg_match('/\.(view|view_all|export)$/', $permission) || in_array($permission, self::READ_ONLY_LUZMA, true);
    }

    public function can(Organization $organization, User $user, string $permission, ?int $officeId = null): bool
    {
        if (! $this->isReadPermission($permission) && $this->isExecutiveReadOnly($organization, $user)) {
            return false;
        }

        if ($this->organizationRole($organization, $user) === 'owner') {
            return true;
        }

        $hasPersistedPermission = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->join('workspace_role_permissions as rp', 'rp.workspace_role_id', '=', 'role.id')
            ->join('workspace_permissions as permission', 'permission.id', '=', 'rp.workspace_permission_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.user_id', $user->id)
            ->where('ura.status', 'active')
            ->where('role.status', 'active')
            ->whereNull('ura.deleted_at')
            ->where('permission.code', $permission)
            ->when($officeId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('ura.office_id')->orWhere('ura.office_id', $officeId)))
            ->exists();

        if ($hasPersistedPermission) {
            return true;
        }

        $fallbackPermissions = self::ROLE_PRESETS[$this->primaryRole($organization, $user)]['permissions'] ?? [];

        return $fallbackPermissions === ['*'] || in_array($permission, $fallbackPermissions, true);
    }

    public function dashboardPathFor(Organization $organization, User $user): string
    {
        return match ($this->primaryRole($organization, $user)) {
            'owner_director' => '/office/executive-dashboard',
            'office_admin' => '/office/dashboard',
            'accounting_manager' => '/operations/dashboard',
            'general_accountant' => '/work/accounting-review',
            'accounting_supervisor' => '/supervisor/dashboard',
            'accountant_in_charge' => '/accountant/dashboard',
            'accounting_assistant' => $this->hasActiveRole($organization, $user, 'accounting_assistant') ? '/assistant/dashboard' : '/work/dashboard',
            'tax_specialist' => $this->hasActiveRole($organization, $user, 'tax_specialist') ? '/tax/dashboard' : '/work/tax',
            'treasury' => $this->hasActiveRole($organization, $user, 'treasury') ? '/treasury/dashboard' : '/work/treasury',
            'accounts_receivable' => $this->hasActiveRole($organization, $user, 'accounts_receivable') ? '/receivables/dashboard' : '/work/receivables',
            'accounts_payable' => $this->hasActiveRole($organization, $user, 'accounts_payable') ? '/payables/dashboard' : '/work/payables',
            'payroll' => $this->hasActiveRole($organization, $user, 'payroll') ? '/payroll/dashboard' : '/work/payroll',
            'auditor' => $this->hasActiveRole($organization, $user, 'auditor') ? '/work/audit' : '/audit/dashboard',
            'client_user' => '/client',
            default => '/work/dashboard',
        };
    }

    /** The role is really assigned (not the fallback used for members without roles). */
    private function hasActiveRole(Organization $organization, User $user, string $roleCode): bool
    {
        return DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.user_id', $user->id)
            ->where('ura.status', 'active')
            ->whereNull('ura.deleted_at')
            ->where('role.code', $roleCode)
            ->exists();
    }

    private function organizationRole(Organization $organization, User $user): string
    {
        if ($organization->relationLoaded('pivot') && $organization->pivot->role) {
            return (string) $organization->pivot->role;
        }

        return (string) (DB::table('organization_user')
            ->where('organization_user.organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role') ?? 'member');
    }

    /**
     * Offices where the user holds a permission: null means organization-wide (owners, or a role assigned without office),
     * otherwise the offices of the office-scoped role assignments that grant it.
     *
     * @return list<int>|null
     */
    public function officesWithPermission(Organization $organization, User $user, string $permission): ?array
    {
        if (! $this->isReadPermission($permission) && $this->isExecutiveReadOnly($organization, $user)) {
            return [];
        }

        if ($this->organizationRole($organization, $user) === 'owner') {
            return null;
        }

        $grants = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->join('workspace_role_permissions as rp', 'rp.workspace_role_id', '=', 'role.id')
            ->join('workspace_permissions as permission', 'permission.id', '=', 'rp.workspace_permission_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.user_id', $user->id)
            ->where('ura.status', 'active')
            ->where('role.status', 'active')
            ->whereNull('ura.deleted_at')
            ->where('permission.code', $permission)
            ->pluck('ura.office_id');

        if ($grants->isEmpty()) {
            return $this->can($organization, $user, $permission) ? $this->memberOfficeIds($organization->id, $user->id) : [];
        }

        if ($grants->contains(null)) {
            return null;
        }

        return array_values($grants->map(fn ($officeId): int => (int) $officeId)->unique()->all());
    }

    /** Roles that grant direction or administration powers can only be handed out by the owner or an explicitly trusted admin. */
    public function isHighPrivilegeRole(Organization $organization, string $roleCode): bool
    {
        if (in_array($roleCode, ['owner_director', 'office_admin'], true)) {
            return true;
        }

        return DB::table('workspace_roles as role')
            ->join('workspace_role_permissions as rp', 'rp.workspace_role_id', '=', 'role.id')
            ->join('workspace_permissions as permission', 'permission.id', '=', 'rp.workspace_permission_id')
            ->where('role.organization_id', $organization->id)
            ->where('role.code', $roleCode)
            ->whereIn('permission.code', ['office.roles.manage', 'roles.assign_high_privilege', 'office.executive.view', 'subscription.manage'])
            ->exists();
    }

    /** @return list<int> */
    private function memberOfficeIds(int $organizationId, int $userId): array
    {
        return array_values(DB::table('office_user')
            ->join('offices', 'offices.id', '=', 'office_user.office_id')
            ->where('offices.organization_id', $organizationId)
            ->where('office_user.user_id', $userId)
            ->where('office_user.status', 'active')
            ->whereNull('offices.deleted_at')
            ->pluck('offices.id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Gives the user a role in an office (or organization-wide) and ends the roles it replaces there, keeping the history.
     * Nobody can hand out direction or administration roles without the high-privilege permission, and the last
     * active director cannot lose that role.
     */
    public function assignRole(Organization $organization, User $actor, int $userId, string $roleCode, ?int $officeId = null): int
    {
        $this->ensureDefaults($organization);
        $roleId = (int) DB::table('workspace_roles')
            ->where('workspace_roles.organization_id', $organization->id)
            ->where('code', $roleCode)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->value('id');

        abort_unless($roleId > 0, 422);
        abort_unless(DB::table('organization_user')->where('organization_user.organization_id', $organization->id)->where('user_id', $userId)->where('status', 'active')->exists(), 422);
        if ($officeId !== null) {
            abort_unless(DB::table('offices')->where('offices.organization_id', $organization->id)->where('id', $officeId)->where('status', 'active')->whereNull('deleted_at')->exists(), 422);
        }
        abort_if($this->isHighPrivilegeRole($organization, $roleCode) && ! $this->can($organization, $actor, 'roles.assign_high_privilege'), 403, 'No puedes otorgar roles de dirección o administración.');

        $replaced = DB::table('user_role_assignments as ura')
            ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
            ->where('ura.organization_id', $organization->id)
            ->where('ura.user_id', $userId)
            ->where('ura.status', 'active')
            ->whereNull('ura.deleted_at')
            ->where('ura.workspace_role_id', '!=', $roleId)
            ->when($officeId === null, fn ($query) => $query->whereNull('ura.office_id'), fn ($query) => $query->where('ura.office_id', $officeId))
            ->get(['ura.id', 'role.code']);

        if ($replaced->contains('code', 'owner_director')) {
            abort_unless($this->can($organization, $actor, 'roles.assign_high_privilege'), 403, 'No puedes retirar el rol de dirección.');
            $otherDirectors = DB::table('user_role_assignments as ura')
                ->join('workspace_roles as role', 'role.id', '=', 'ura.workspace_role_id')
                ->where('ura.organization_id', $organization->id)
                ->where('role.code', 'owner_director')
                ->where('ura.status', 'active')
                ->whereNull('ura.deleted_at')
                ->where('ura.user_id', '!=', $userId)
                ->exists();
            abort_unless($otherDirectors, 422, 'La firma debe conservar al menos un director activo.');
        }

        DB::table('user_role_assignments')->whereIn('id', $replaced->pluck('id'))->update(['status' => 'inactive', 'ends_at' => now(), 'updated_at' => now()]);

        $assignmentId = DB::table('user_role_assignments')->insertGetId([
            'organization_id' => $organization->id,
            'office_id' => $officeId,
            'user_id' => $userId,
            'workspace_role_id' => $roleId,
            'assigned_by' => $actor->id,
            'status' => 'active',
            'starts_at' => now(),
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accounting->audit($organization, $actor, 'user.role_changed', 'user_role_assignments', $assignmentId, [
            'user_id' => $userId,
            'role' => $roleCode,
            'office_id' => $officeId,
            'replaced_roles' => $replaced->pluck('code')->values()->all(),
        ], $officeId);

        return $assignmentId;
    }
}
