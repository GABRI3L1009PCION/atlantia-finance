<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes the foreign keys that tenant queries filter on. Every Row Level Security policy compares organization_id, so
 * without these indexes PostgreSQL scans whole tables once a firm has history.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'account_reconciliation_items' => ['company_id'],
        'api_tokens' => ['user_id'],
        'audit_access_grants' => ['company_id', 'organization_id'],
        'audit_control_assessments' => ['company_id', 'organization_id'],
        'audit_controls' => ['organization_id'],
        'audit_engagement_members' => ['company_id', 'organization_id', 'user_id'],
        'audit_finding_responses' => ['company_id', 'organization_id'],
        'audit_plans' => ['company_id', 'organization_id'],
        'audit_procedures' => ['company_id', 'organization_id'],
        'audit_programs' => ['company_id', 'organization_id'],
        'audit_reports' => ['company_id', 'organization_id'],
        'audit_risks' => ['company_id', 'organization_id'],
        'audit_sample_items' => ['company_id', 'organization_id'],
        'audit_samples' => ['company_id', 'organization_id'],
        'audit_working_papers' => ['company_id', 'organization_id'],
        'bank_checkbooks' => ['company_id'],
        'bank_reconciliations' => ['company_id', 'organization_id'],
        'bank_transfers' => ['organization_id'],
        'cash_counts' => ['company_id', 'organization_id'],
        'client_company_access' => ['organization_id', 'user_id'],
        'client_conversations' => ['organization_id'],
        'client_document_versions' => ['company_id', 'organization_id'],
        'client_documents' => ['organization_id'],
        'client_messages' => ['company_id', 'organization_id'],
        'collection_reminders' => ['organization_id'],
        'communications' => ['company_id'],
        'company_data_change_requests' => ['organization_id'],
        'company_user' => ['user_id'],
        'crm_contracts' => ['company_id'],
        'crm_interactions' => ['company_id', 'user_id'],
        'crm_invoices' => ['company_id'],
        'fiscal_obligation_checks' => ['organization_id'],
        'job_runs' => ['organization_id'],
        'login_attempts' => ['user_id'],
        'luzma_actions' => ['company_id', 'organization_id'],
        'luzma_conversations' => ['company_id', 'organization_id'],
        'luzma_messages' => ['organization_id'],
        'luzma_reminder_deliveries' => ['organization_id'],
        'luzma_reminders' => ['company_id'],
        'normative_sections' => ['organization_id'],
        'notification_preferences' => ['company_id'],
        'office_user' => ['user_id'],
        'operational_record_events' => ['user_id'],
        'payable_adjustments' => ['organization_id'],
        'payable_disputes' => ['organization_id'],
        'payment_approvals' => ['company_id', 'organization_id'],
        'payment_instruction_items' => ['company_id', 'organization_id'],
        'payment_instructions' => ['company_id'],
        'payment_proposal_items' => ['company_id', 'organization_id'],
        'payments' => ['subscription_id'],
        'payroll_account_mappings' => ['organization_id'],
        'payroll_concepts' => ['organization_id'],
        'payroll_employees' => ['organization_id'],
        'payroll_loans' => ['company_id', 'organization_id'],
        'payroll_novelties' => ['company_id', 'organization_id'],
        'payroll_parameters' => ['organization_id'],
        'payroll_payment_orders' => ['company_id', 'organization_id'],
        'payroll_periods' => ['organization_id'],
        'payroll_receipts' => ['company_id', 'organization_id'],
        'payroll_result_lines' => ['company_id', 'organization_id'],
        'payroll_results' => ['company_id', 'organization_id'],
        'published_reports' => ['organization_id'],
        'purchase_credit_notes' => ['company_id', 'organization_id'],
        'receivable_adjustments' => ['organization_id'],
        'receivable_disputes' => ['organization_id'],
        'review_observations' => ['company_id'],
        'saas_credits' => ['organization_id'],
        'saas_invoices' => ['subscription_id'],
        'sales_credit_notes' => ['organization_id'],
        'supplier_advance_applications' => ['company_id', 'organization_id'],
        'supplier_advances' => ['organization_id'],
        'support_access_requests' => ['organization_id'],
        'support_ticket_messages' => ['organization_id'],
        'support_tickets' => ['organization_id'],
        'system_incident_organizations' => ['organization_id'],
        'task_comments' => ['user_id'],
        'training_certifications' => ['user_id'],
        'training_enrollments' => ['user_id'],
        'treasury_receipts' => ['company_id'],
        'user_role_assignments' => ['user_id'],
        'webhook_deliveries' => ['company_id', 'organization_id'],
        'work_notifications' => ['user_id'],
        'work_task_dependencies' => ['organization_id'],
        'work_task_waits' => ['organization_id'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement('CREATE INDEX IF NOT EXISTS '.$table.'_'.$column.'_fk_index ON '.$table.' ('.$column.')');
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement('DROP INDEX IF EXISTS '.$table.'_'.$column.'_fk_index');
            }
        }
    }
};
