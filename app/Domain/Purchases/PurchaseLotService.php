<?php

namespace App\Domain\Purchases;

use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseLot;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\UnitContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseLotService
{
    public function create(int $supplierId, array $rows, string $date, ?string $notes, User $actor): PurchaseLot
    {
        abort_unless($actor->can('purchase.create'), 403);
        Validator::make(['rows' => $rows, 'date' => $date, 'notes' => $notes], ['rows' => 'required|array|min:1|max:500', 'rows.*.product_variant_id' => 'required|integer', 'rows.*.unit_cost' => 'required|numeric|min:0|max:999999999', 'rows.*.pending_reason' => 'nullable|string|max:1000', 'date' => 'required|date', 'notes' => 'nullable|string|max:2000'])->validate();
        $unit = app(UnitContext::class)->requireCurrent($actor);

        return DB::transaction(function () use ($supplierId, $rows, $date, $notes, $actor, $unit) {
            Supplier::where('is_active', true)->findOrFail($supplierId);
            $lot = PurchaseLot::create(['lot_no' => 'LOT-'.BusinessClock::today()->format('Ymd').'-'.strtoupper(Str::random(8)), 'supplier_id' => $supplierId, 'business_unit_id' => $unit->id, 'order_date' => $date, 'notes' => $notes, 'created_by' => $actor->id]);
            if (! $rows) {
                throw ValidationException::withMessages(['rows' => 'Add at least one phone.']);
            }
            $seen = [];
            foreach ($rows as $i => $row) {
                $variant = ProductVariant::with('product')->where('is_active', true)->findOrFail($row['product_variant_id']);
                if ($variant->product->product_type !== ProductType::Phone) {
                    throw ValidationException::withMessages(["rows.$i.product_variant_id" => 'Choose a phone variant.']);
                }
                $imei = trim($row['expected_imei'] ?? '') ?: null;
                if ($imei && (! preg_match('/^\d{15}$/', $imei) || isset($seen[$imei]))) {
                    throw ValidationException::withMessages(["rows.$i.expected_imei" => 'Enter a unique 15 digit IMEI.']);
                }
                if ($imei) {
                    $seen[$imei] = true;
                }
                $lot->lines()->create(['product_variant_id' => $variant->id, 'expected_imei' => $imei, 'unit_cost' => $row['unit_cost'], 'pending_reason' => $row['pending_reason'] ?? null]);
            }
            AuditLogger::record('purchase_lot.created', $lot, [], ['count' => count($rows)], null, $unit->id, $actor);

            return $lot;
        });
    }

    public function receive(PurchaseLot $lot, array $items, string $paid, string $method, string $key, User $actor)
    {
        abort_unless($actor->can('purchase.approve'), 403);
        app(UnitContext::class)->authorize($actor, $lot->business_unit_id);
        Validator::make(['items' => $items, 'paid' => $paid, 'method' => $method], ['items' => 'required|array|max:500', 'items.*.imei' => 'required|digits:15|distinct', 'items.*.battery_health' => 'nullable|integer|min:0|max:100', 'paid' => 'required|numeric|min:0|max:999999999', 'method' => ['required', Rule::enum(PaymentMethod::class)]])->validate();

        return DB::transaction(function () use ($lot, $items, $paid, $method, $key, $actor) {
            $lot = PurchaseLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if ($old = Purchase::where('idempotency_key', $key)->first()) {
                abort_unless($old->business_unit_id === $lot->business_unit_id && $old->reference === $lot->lot_no, 403);

                return $old;
            }
            if (! $items) {
                throw ValidationException::withMessages(['receipt' => 'Select pending phones to receive.']);
            }
            $lines = [];
            $selected = [];
            foreach ($items as $id => $item) {
                $line = $lot->lines()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($line->status !== 'PENDING') {
                    throw ValidationException::withMessages(['receipt' => 'Only pending phones can be received.']);
                }
                $imei = trim($item['imei'] ?? '');
                if ($line->expected_imei && $line->expected_imei !== $imei) {
                    throw ValidationException::withMessages(['receipt' => 'Scanned IMEI does not match the ordered phone.']);
                }
                $lines[] = ['product_variant_id' => $line->product_variant_id, 'line_type' => 'PHONE', 'quantity' => 1, 'unit_cost' => $line->unit_cost, 'imei_1' => $imei, 'serial_payload' => ['battery_health' => $item['battery_health'] ?? null], 'notes' => $lot->lot_no];
                $selected[] = $line;
            }
            $service = app(PurchaseService::class);
            $purchase = $service->create(['supplier_id' => $lot->supplier_id, 'business_unit_id' => $lot->business_unit_id, 'purchase_date' => BusinessClock::today()->toDateString(), 'paid_amount' => $paid, 'payment_method' => $method, 'reference' => $lot->lot_no, 'idempotency_key' => $key], $lines, $actor);
            $purchase = $service->receive($purchase, $actor);
            foreach ($selected as $i => $line) {
                $line->update(['status' => 'RECEIVED', 'actual_imei' => $lines[$i]['imei_1'], 'purchase_id' => $purchase->id, 'received_at' => BusinessClock::utcNow()]);
            }
            AuditLogger::record('purchase_lot.received', $lot, [], ['purchase_id' => $purchase->id, 'lines' => array_keys($items)], null, $lot->business_unit_id, $actor);

            return $purchase;
        });
    }

    public function cancel(PurchaseLot $lot, array $ids, string $reason, User $actor): void
    {
        abort_unless($actor->can('purchase.approve'), 403);
        app(UnitContext::class)->authorize($actor, $lot->business_unit_id);
        if (! trim($reason) || strlen($reason) > 1000 || ! $ids) {
            throw ValidationException::withMessages(['cancelReason' => 'Select pending phones and enter a cancellation reason.']);
        }
        DB::transaction(function () use ($lot, $ids, $reason, $actor) {
            PurchaseLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            foreach (array_unique($ids) as $id) {
                $line = $lot->lines()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($line->status !== 'PENDING') {
                    throw ValidationException::withMessages(['cancelReason' => 'Received or cancelled phones cannot be cancelled.']);
                } $line->update(['status' => 'CANCELLED', 'cancellation_reason' => $reason]);
            }
            AuditLogger::record('purchase_lot.cancelled',$lot,[],['lines' => $ids, 'reason' => $reason],null,$lot->business_unit_id,$actor);
        });
    }
}
