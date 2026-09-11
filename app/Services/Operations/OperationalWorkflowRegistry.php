<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OperationalWorkflowRegistry
{
    /**
     * @return array{
     *   feature: string,
     *   initial: string,
     *   period_guard: bool,
     *   transitions: array<string, list<string>>,
     *   privileged: list<string>,
     *   reasons: list<string>
     * }
     */
    public function definition(string $module): array
    {
        $definition = $this->definitions()[$module] ?? null;
        if ($definition === null) {
            throw new NotFoundHttpException('El módulo operativo no existe.');
        }

        return $definition;
    }

    /** @return list<string> */
    public function modules(): array
    {
        return array_keys($this->definitions());
    }

    /** @return array<string, array{feature: string, initial: string, period_guard: bool, transitions: array<string, list<string>>, privileged: list<string>, reasons: list<string>}> */
    private function definitions(): array
    {
        $review = [
            'draft' => ['pending_review', 'cancelled'],
            'pending_review' => ['approved', 'rejected', 'draft'],
            'approved' => ['processing', 'cancelled'],
            'processing' => ['completed', 'failed'],
            'failed' => ['processing', 'cancelled'],
            'completed' => ['closed'],
        ];
        $approval = ['approved', 'completed', 'closed', 'reopened', 'issued', 'paid', 'posted', 'published'];
        $reasons = ['cancelled', 'rejected', 'reopened', 'ignored', 'disposed', 'superseded'];

        return [
            'conciliacion' => $this->workflow('advanced_reports', true, [
                'draft' => ['in_progress', 'cancelled'], 'in_progress' => ['reviewed', 'blocked', 'cancelled'],
                'blocked' => ['in_progress'], 'reviewed' => ['closed', 'in_progress'],
            ], ['reviewed', 'closed'], $reasons),
            'reglas' => $this->workflow('advanced_reports', false, [
                'draft' => ['testing', 'cancelled'], 'testing' => ['active', 'draft'],
                'active' => ['inactive'], 'inactive' => ['active', 'cancelled'],
            ], ['active'], $reasons),
            'caja' => $this->workflow('advanced_reports', true, [
                'draft' => ['open', 'cancelled'], 'open' => ['closing'], 'closing' => ['closed', 'open'],
            ], ['closed'], $reasons),
            'inventario' => $this->workflow('inventory', true, [
                'draft' => ['pending_review', 'cancelled'], 'pending_review' => ['approved', 'rejected'],
                'approved' => ['posted'], 'posted' => ['reversed'],
            ], ['approved', 'posted', 'reversed'], $reasons),
            'kardex' => $this->workflow('inventory', true, [
                'draft' => ['review', 'cancelled'], 'review' => ['posted', 'draft'],
                'posted' => ['reversed'],
            ], ['posted', 'reversed'], $reasons),
            'inventario-fisico' => $this->workflow('inventory', true, [
                'draft' => ['counting', 'cancelled'], 'counting' => ['review'], 'review' => ['adjustment', 'counting'],
                'adjustment' => ['closed'],
            ], ['adjustment', 'closed'], $reasons),
            'activos-fijos' => $this->workflow('advanced_reports', true, [
                'draft' => ['active', 'cancelled'], 'active' => ['maintenance', 'transferred', 'disposed'],
                'maintenance' => ['active'], 'transferred' => ['active'],
            ], ['active', 'disposed'], $reasons),
            'nomina' => $this->workflow('payroll', true, [
                'draft' => ['calculated', 'cancelled'], 'calculated' => ['review', 'draft'],
                'review' => ['approved', 'calculated'], 'approved' => ['paid'], 'paid' => ['closed'],
            ], ['approved', 'paid', 'closed'], $reasons),
            'proyectos' => $this->workflow('advanced_reports', false, [
                'draft' => ['active', 'cancelled'], 'active' => ['paused', 'completed', 'cancelled'],
                'paused' => ['active', 'cancelled'], 'completed' => ['closed'],
            ], ['active', 'closed'], $reasons),
            'presupuestos' => $this->workflow('advanced_reports', true, [
                'draft' => ['review', 'cancelled'], 'review' => ['approved', 'draft'],
                'approved' => ['active'], 'active' => ['closed', 'review'],
            ], ['approved', 'active', 'closed'], $reasons),
            'normativa' => $this->workflow('advanced_reports', false, [
                'draft' => ['review', 'archived'], 'review' => ['approved', 'draft'],
                'approved' => ['published'], 'published' => ['superseded', 'archived'],
            ], ['approved', 'published', 'superseded'], $reasons),
            'documentos' => $this->workflow('ocr', false, [
                'draft' => ['review', 'archived'], 'review' => ['published', 'draft'], 'published' => ['archived'],
            ], ['published'], $reasons),
            'cotizaciones' => $this->workflow('approval_workflows', false, [
                'draft' => ['pending_review', 'sent', 'cancelled'], 'pending_review' => ['approved', 'rejected'],
                'approved' => ['sent'], 'sent' => ['accepted', 'rejected', 'expired'], 'accepted' => ['converted'],
            ], ['approved', 'converted'], $reasons),
            'portal-cliente' => $this->workflow('advanced_reports', false, [
                'pending' => ['in_review', 'cancelled'], 'in_review' => ['received', 'pending'], 'received' => ['completed'],
            ], ['completed'], $reasons, 'pending'),
            'notas-credito' => $this->workflow('approval_workflows', true, [
                'draft' => ['review', 'cancelled'], 'review' => ['approved', 'rejected'],
                'approved' => ['issued'], 'issued' => ['cancelled'],
            ], ['approved', 'issued', 'cancelled'], $reasons),
            'ordenes-compra' => $this->workflow('approval_workflows', true, [
                'draft' => ['review', 'cancelled'], 'review' => ['approved', 'rejected'],
                'approved' => ['issued'], 'issued' => ['partially_received', 'received', 'cancelled'],
                'partially_received' => ['received', 'cancelled'],
            ], ['approved', 'issued', 'received'], $reasons),
            'libros-iva' => $this->workflow('advanced_reports', true, [
                'draft' => ['review'], 'review' => ['reconciled', 'draft'], 'reconciled' => ['closed'],
            ], ['reconciled', 'closed'], $reasons),
            'cierre-periodo' => $this->workflow('approval_workflows', true, [
                'draft' => ['validation'], 'validation' => ['blocked', 'ready'], 'blocked' => ['validation'],
                'ready' => ['closed'], 'closed' => ['reopened'], 'reopened' => ['validation'],
            ], ['ready', 'closed', 'reopened'], $reasons),
            'estados-cuenta' => $this->workflow('obligation_management', false, [
                'generated' => ['sent', 'archived'], 'sent' => ['archived'],
            ], ['sent'], $reasons, 'generated'),
            'recordatorios' => $this->workflow('task_management', false, [
                'pending' => ['snoozed', 'completed', 'cancelled'], 'snoozed' => ['pending', 'completed', 'cancelled'],
            ], ['completed'], $reasons, 'pending'),
            'auditoria' => $this->workflow('audit_log', false, [
                'open' => ['reviewed'], 'reviewed' => ['closed'],
            ], ['closed'], $reasons, 'open'),
            'usuarios-roles' => $this->workflow('roles_and_permissions', false, [
                'pending' => ['active', 'revoked'], 'active' => ['revoked'], 'revoked' => ['active'],
            ], ['active', 'revoked'], $reasons, 'pending'),
            'configuracion' => $this->workflow('basic_accounting', false, [
                'draft' => ['active'], 'active' => ['draft'],
            ], ['active'], $reasons),
            'reportes' => $this->workflow('advanced_reports', false, [
                'draft' => ['generating', 'cancelled'], 'generating' => ['completed', 'failed'], 'failed' => ['generating'],
            ], ['completed'], $reasons),
            'impuestos' => $this->workflow('advanced_reports', true, $review, $approval, $reasons),
        ];
    }

    /**
     * @param  array<string, list<string>>  $transitions
     * @param  list<string>  $privileged
     * @param  list<string>  $reasons
     * @return array{feature: string, initial: string, period_guard: bool, transitions: array<string, list<string>>, privileged: list<string>, reasons: list<string>}
     */
    private function workflow(string $feature, bool $periodGuard, array $transitions, array $privileged, array $reasons, string $initial = 'draft'): array
    {
        return [
            'feature' => $feature,
            'initial' => $initial,
            'period_guard' => $periodGuard,
            'transitions' => $transitions,
            'privileged' => $privileged,
            'reasons' => $reasons,
        ];
    }
}
