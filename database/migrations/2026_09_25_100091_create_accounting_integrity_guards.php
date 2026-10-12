<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accounting invariants enforced by PostgreSQL itself, independent of application code:
 *
 * - Journal lines can only change while their entry is a draft.
 * - Posted entries are immutable; the only allowed transition is posted -> reversed.
 * - An entry can only be posted when it has at least two lines and debits equal credits.
 * - Entries cannot be created or posted in a closed (or reopening) period or outside its dates.
 * - Voided/reversed/rejected entries are terminal, and non-draft entries can never be deleted.
 * - The audit log is append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION atlantia_guard_journal_lines() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                entry_status text;
                entry_id bigint := CASE WHEN TG_OP = 'DELETE' THEN OLD.journal_entry_id ELSE NEW.journal_entry_id END;
            BEGIN
                SELECT status INTO entry_status FROM journal_entries WHERE id = entry_id;
                IF entry_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'Journal lines of a % entry are immutable', COALESCE(entry_status, 'missing')
                        USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.journal_entry_id <> OLD.journal_entry_id THEN
                    RAISE EXCEPTION 'Journal lines cannot move between entries' USING ERRCODE = '23514';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER journal_lines_guard
            BEFORE INSERT OR UPDATE OR DELETE ON journal_lines
            FOR EACH ROW EXECUTE FUNCTION atlantia_guard_journal_lines();

            CREATE OR REPLACE FUNCTION atlantia_assert_open_period(period_id bigint, entry_date date) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                period record;
            BEGIN
                SELECT status, closed_at, starts_on, ends_on INTO period FROM accounting_periods WHERE id = period_id;
                IF NOT FOUND
                    OR period.closed_at IS NOT NULL
                    OR period.status IN ('closed', 'reopen_requested', 'reopen_approved')
                    OR entry_date < period.starts_on
                    OR entry_date > period.ends_on THEN
                    RAISE EXCEPTION 'Accounting period % does not accept entries dated %', period_id, entry_date
                        USING ERRCODE = '23514';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION atlantia_guard_journal_entries() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                line_count integer;
                total_debits bigint;
                total_credits bigint;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'Only draft journal entries can be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'Journal entries must be created as drafts' USING ERRCODE = '23514';
                    END IF;
                    PERFORM atlantia_assert_open_period(NEW.accounting_period_id, NEW.entry_date);
                    RETURN NEW;
                END IF;

                IF OLD.status IN ('reversed', 'void', 'rejected') THEN
                    RAISE EXCEPTION 'A % journal entry is final', OLD.status USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'posted' THEN
                    IF NEW.status NOT IN ('posted', 'reversed')
                        OR (to_jsonb(NEW) - 'status' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'updated_at') THEN
                        RAISE EXCEPTION 'Posted journal entries are immutable; register a reversal instead' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.status = 'reversed' THEN
                    RAISE EXCEPTION 'Only posted journal entries can be reversed' USING ERRCODE = '23514';
                END IF;

                IF NEW.accounting_period_id <> OLD.accounting_period_id OR NEW.entry_date <> OLD.entry_date OR NEW.status = 'posted' THEN
                    PERFORM atlantia_assert_open_period(NEW.accounting_period_id, NEW.entry_date);
                END IF;

                IF NEW.status = 'posted' THEN
                    SELECT count(*), COALESCE(sum(debit_cents), 0), COALESCE(sum(credit_cents), 0)
                        INTO line_count, total_debits, total_credits
                        FROM journal_lines WHERE journal_entry_id = NEW.id;
                    IF line_count < 2 OR total_debits <= 0 OR total_debits <> total_credits THEN
                        RAISE EXCEPTION 'Journal entry % is not balanced (lines %, debits %, credits %)', NEW.id, line_count, total_debits, total_credits
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER journal_entries_guard
            BEFORE INSERT OR UPDATE OR DELETE ON journal_entries
            FOR EACH ROW EXECUTE FUNCTION atlantia_guard_journal_entries();

            CREATE OR REPLACE FUNCTION atlantia_audit_logs_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only' USING ERRCODE = '42501';
            END;
            $$;

            CREATE TRIGGER audit_logs_append_only
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION atlantia_audit_logs_append_only();

            CREATE TRIGGER audit_logs_no_truncate
            BEFORE TRUNCATE ON audit_logs
            FOR EACH STATEMENT EXECUTE FUNCTION atlantia_audit_logs_append_only();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
            DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs;
            DROP TRIGGER IF EXISTS journal_entries_guard ON journal_entries;
            DROP TRIGGER IF EXISTS journal_lines_guard ON journal_lines;
            DROP FUNCTION IF EXISTS atlantia_audit_logs_append_only();
            DROP FUNCTION IF EXISTS atlantia_guard_journal_entries();
            DROP FUNCTION IF EXISTS atlantia_assert_open_period(bigint, date);
            DROP FUNCTION IF EXISTS atlantia_guard_journal_lines();
            SQL);
    }
};
