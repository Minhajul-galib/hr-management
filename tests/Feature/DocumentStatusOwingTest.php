<?php

namespace Tests\Feature;

use App\Http\Controllers\DocumentController;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\DocumentStatus;
use App\Models\Particular;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class DocumentStatusOwingTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_head_approved_creates_ledger_and_updates_supplier_due(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $unit = Unit::create([
            'unit_code' => 'U001',
            'name' => 'Main Unit',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $particular = Particular::create([
            'name' => 'General',
            'code' => 'GEN',
            'status' => 'active',
        ]);

        $supplier = Supplier::create([
            'code' => 'SUP0001',
            'name' => 'Test Supplier',
            'type_id' => null,
            'opening_balance' => 0,
            'total_owing' => 0,
            'status' => 'active',
        ]);

        $pendingStatusId = optional(DocumentStatus::where('slug', 'purchase_head_pending')->first())->id;

        $document = Document::create([
            'doc_no' => '1',
            'doc_date' => now()->toDateString(),
            'amount_in_words' => 'One hundred',
            'prepared_by' => $user->id,
            'current_status_id' => $pendingStatusId,
        ]);

        DocumentItem::create([
            'document_id' => $document->id,
            'sl_no' => 1,
            'bill_no' => 'B-1',
            'bill_date' => now()->toDateString(),
            'supplier_code' => $supplier->code,
            'particular_code' => $particular->code,
            'item_description' => 'Item A',
            'amount' => 60,
            'unit_id' => $unit->id,
            'ac_code' => 'AC1',
        ]);
        DocumentItem::create([
            'document_id' => $document->id,
            'sl_no' => 2,
            'bill_no' => 'B-2',
            'bill_date' => now()->toDateString(),
            'supplier_code' => $supplier->code,
            'particular_code' => $particular->code,
            'item_description' => 'Item B',
            'amount' => 40,
            'unit_id' => $unit->id,
            'ac_code' => 'AC2',
        ]);

        $controller = new DocumentController();
        $controller->check(new Request(), $document);

        $this->assertEquals(2, SupplierLedger::where('document_id', $document->id)->where('transaction_type', 'credit')->count());
        $supplier->refresh();
        $this->assertEquals(100.0, (float) $supplier->total_owing);

        // Calling check again should not duplicate
        $controller->check(new Request(), $document);
        $this->assertEquals(2, SupplierLedger::where('document_id', $document->id)->where('transaction_type', 'credit')->count());
        $supplier->refresh();
        $this->assertEquals(100.0, (float) $supplier->total_owing);
    }
}
