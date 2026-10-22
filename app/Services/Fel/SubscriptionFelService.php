<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Models\Payment;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FiscalConfigurationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionFelService
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private FelDocumentService $documents, private AccountingService $accounting, private FiscalConfigurationService $fiscal) {}

    public function generate(int $paymentId): void
    {
        $issuer = app(FelIssuerSettings::class)->platformCompanyUuid();
        if (! is_string($issuer) || $issuer === '') {
            DB::table('fel_subscription_requests')->where('payment_id', $paymentId)->whereNull('document_id')
                ->update(['status' => 'needs_configuration', 'error_code' => 'issuer_not_configured', 'updated_at' => now()]);

            return;
        }
        DB::transaction(function () use ($paymentId, $issuer): void {
            $company = DB::table('companies')->where('public_id', $issuer)->firstOrFail();
            $this->accounting->lock($company->id);
            $request = DB::table('fel_subscription_requests')->where('payment_id', $paymentId)->lockForUpdate()->firstOrFail();
            if ($request->document_id !== null) {
                return;
            }
            $payment = Payment::with('organization')->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'succeeded' || $payment->currency_code !== 'GTQ' || $payment->paid_at === null) {
                throw new RuntimeException('El pago no está confirmado en GTQ.');
            }
            $office = DB::table('offices')->where('id', $company->office_id)->firstOrFail();
            $ownerId = DB::table('organization_user')->where('organization_user.organization_id', $office->organization_id)
                ->where('role', 'owner')->where('status', 'active')->value('user_id');
            $owner = User::query()->where('id', $ownerId)->firstOrFail();
            $organization = $owner->organizations()->whereKey($office->organization_id)->firstOrFail();
            $this->accounting->company($organization, $owner, $issuer, true);
            $buyer = $payment->organization;
            $billing = $payment->billing_snapshot;
            if (! is_array($billing)) {
                $contact = $buyer->users()->wherePivot('role', 'owner')->wherePivot('status', 'active')->firstOrFail();
                $billing = ['name' => $buyer->legal_name ?? $buyer->name, 'nit' => $buyer->tax_identifier,
                    'address' => $buyer->offices()->where('is_primary', true)->value('address_line_1'), 'email' => $contact->email];
            }
            if (empty($billing['nit']) || empty($billing['address']) || empty($billing['name']) || empty($billing['email'])) {
                throw new RuntimeException('Completa el NIT y dirección fiscal de la organización cliente.');
            }
            $customerId = DB::table('sales_customers')->where('company_id', $company->id)
                ->where('tax_id', $billing['nit'])->value('id');
            if (! $customerId) {
                $customerId = DB::table('sales_customers')->insertGetId(['company_id' => $company->id,
                    'name' => $billing['name'], 'tax_id' => $billing['nit'],
                    'email' => $billing['email'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $date = $payment->paid_at->timezone('America/Guatemala')->toDateString();
            $profile = $this->fiscal->ensureDefaults($company->id);
            $rate = $profile->vat_regime === 'general' ? (int) $this->fiscal->activeRule($company->id, 'IVA_GENERAL', $date)->rate_basis_points : 0;
            $total = $this->accounting->cents($payment->amount);
            $base = intdiv($total * 10000 + intdiv(10000 + $rate, 2), 10000 + $rate);
            $price = intdiv($base, 100).'.'.str_pad((string) ($base % 100), 2, '0', STR_PAD_LEFT);
            $planName = (string) ($billing['plan_name'] ?? 'Atlantia Finance');
            $periodLabel = ($billing['billing_period'] ?? 'monthly') === 'annual' ? 'Anual' : 'Mensual';
            $paidAt = $payment->paid_at->timezone('America/Guatemala');
            $doc = $this->documents->save($organization, $owner, $company, [
                'issued_on' => $date, 'currency' => 'GTQ', 'document_type' => 'FACT', 'customer_id' => $customerId,
                'observations' => 'Servicios correspondientes a '.self::MONTHS[$paidAt->month - 1].' de '.$paidAt->year.".\nPlan ".$planName.' · '.$periodLabel."\nForma de pago: tarjeta de crédito o débito (Recurrente).",
                'receiver_name' => $billing['name'], 'receiver_nit' => $billing['nit'],
                'receiver_address' => $billing['address'], 'receiver_email' => $billing['email'], 'intent' => 'draft',
                'lines' => [['item' => 'Suscripción '.mb_strtolower($periodLabel === 'Anual' ? 'anual' : 'mensual').' Atlantia Finance', 'description' => 'Plan '.$planName
                    .' · '.$periodLabel.' · pago '.$payment->public_id,
                    'classification' => 'service', 'quantity' => '1', 'price' => $price, 'discount' => '0']],
            ]);
            if ($doc->total_cents !== $total) {
                throw new RuntimeException('El total fiscal no coincide con el pago confirmado.');
            }
            $doc->forceFill(['payment_id' => $payment->id])->save();
            $this->documents->requestCertification($doc, $owner->id);
            DB::table('fel_subscription_requests')->where('payment_id', $paymentId)->update([
                'document_id' => $doc->id, 'status' => 'generated', 'error_code' => null, 'updated_at' => now(),
            ]);
        });
    }
}
