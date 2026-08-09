<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\User;
use App\Notifications\SupplierLimitExceededNotification;
use Illuminate\Database\Eloquent\Collection;

class SupplierLedgerService
{
    /**
     * Record a credit transaction (supplier owes company money).
     */
    public function recordCredit(
        Supplier $supplier,
        float $amount,
        ?int $documentId = null,
        ?string $description = null,
        ?string $referenceNo = null
    ): SupplierLedger {
        $latestBalance = $supplier->getCurrentBalance();
        $newBalance = $latestBalance + $amount;

        return SupplierLedger::create([
            'supplier_code' => $supplier->code,
            'document_id' => $documentId,
            'transaction_type' => 'credit',
            'amount' => $amount,
            'balance_after' => $newBalance,
            'description' => $description,
            'reference_no' => $referenceNo,
        ])->tap(function () use ($supplier, $newBalance) {
            $supplier->notifyIfThresholdExceeded($newBalance);
        });
    }

    /**
     * Record a debit transaction (company makes payment to supplier).
     */
    public function recordDebit(
        Supplier $supplier,
        float $amount,
        ?int $documentId = null,
        ?string $description = null,
        ?string $referenceNo = null
    ): SupplierLedger {
        $latestBalance = $supplier->getCurrentBalance();
        $newBalance = max(0, $latestBalance - $amount);

        return SupplierLedger::create([
            'supplier_code' => $supplier->code,
            'document_id' => $documentId,
            'transaction_type' => 'debit',
            'amount' => $amount,
            'balance_after' => $newBalance,
            'description' => $description,
            'reference_no' => $referenceNo,
        ]);
    }

    /**
     * Get ledger history for a supplier.
     */
    public function getSupplierLedger(Supplier $supplier, ?int $limit = null): Collection
    {
        $query = $supplier->ledgers()->orderBy('created_at', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Get ledger summary for a supplier.
     */
    public function getLedgerSummary(Supplier $supplier): array
    {
        $ledgers = $supplier->ledgers()->get();

        $totalCredit = $ledgers->where('transaction_type', 'credit')->sum('amount');
        $totalDebit = $ledgers->where('transaction_type', 'debit')->sum('amount');
        $currentBalance = $supplier->getCurrentBalance();

        return [
            'total_credit' => $totalCredit,
            'total_debit' => $totalDebit,
            'current_balance' => $currentBalance,
            'opening_balance' => $supplier->opening_balance,
        ];
    }

    /**
     * Get ledger entries within a date range.
     */
    public function getLedgerByDateRange(Supplier $supplier, string $startDate, string $endDate): Collection
    {
        return $supplier->ledgers()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Recalculate and fix ledger balances (if data gets corrupted).
     */
    public function recalculateLedgerBalances(Supplier $supplier): void
    {
        $balance = (float) $supplier->opening_balance;
        $ledgers = $supplier->ledgers()->orderBy('created_at', 'asc')->get();

        foreach ($ledgers as $ledger) {
            if ($ledger->transaction_type === 'credit') {
                $balance += (float) $ledger->amount;
            } else {
                $balance -= (float) $ledger->amount;
            }

            $ledger->update(['balance_after' => max(0, $balance)]);
        }

        // Update supplier's total_owing to match the final balance
        $supplier->update(['total_owing' => max(0, $balance)]);
    }




    /**
     * Export ledger as array for Excel/CSV.
     */
    public function exportLedger(Supplier $supplier): array
    {
        $ledgers = $this->getSupplierLedger($supplier);

        return $ledgers->map(function ($ledger) {
            return [
                'তারিখ' => $ledger->created_at->format('Y-m-d H:i'),
                'লেনদেনের ধরন' => $ledger->transaction_type === 'credit' ? 'বকেয়া' : 'পেমেন্ট',
                'পেমেন্ট মাধ্যম' => $ledger->payment_method,
                'টাকার পরিমাণ' => $ledger->amount,
                'বকেয়া' => $ledger->balance_after,
                'বিবরণ' => $ledger->description,
                'রেফারেন্স' => $ledger->reference_no,
            ];
        })->toArray();
    }
}
