cd /root/development/supplier-management
cat > create_bill_controller.sh << 'EOF'
#!/bin/bash
cd /root/development/supplier-management
cat > app/Http/Controllers/BillController.php << 'INNEREOF'
<?php

namespace App\Http\Controllers;

use App\Models\DocumentItem;
use App\Models\Document;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BillController extends Controller
{
    public function index(Request )
    {
         = DocumentItem::with(['document', 'supplier', 'particular', 'unit'])
            ->orderBy('created_at', 'desc');

        if (->filled('search')) {
             = ->search;
            ->where(function () use () {
                ->where('bill_no', 'like', "%{}%")
                  ->orWhere('item_description', 'like', "%{}%")
                  ->orWhereHas('document', function () use () {
                      ->where('doc_no', 'like', "%{}%");
                  });
            });
        }

        if (->filled('supplier_id')) {
             = Supplier::find(->supplier_id);
            if () {
                ->where('supplier_code', ->code);
            }
        }

        if (->filled('unit_id')) {
            ->where('unit_id', ->unit_id);
        }

        if (->filled('bill_no')) {
            ->where('bill_no', 'like', "%{->bill_no}%");
        }

        if (->filled('date_from')) {
            ->whereDate('bill_date', '>=', ->date_from);
        }
        if (->filled('date_to')) {
            ->whereDate('bill_date', '<=', ->date_to);
        }

         = ->paginate(50);
         = DocumentItem::sum('amount');

        return Inertia::render('Bills/Index', [
            'bills' => ,
            'suppliers' => Supplier::select('id', 'name', 'code')->orderBy('name')->get(),
            'units' => Unit::select('id', 'name', 'unit_code')->where('is_active', true)->orderBy('name')->get(),
            'filters' => ->only(['search', 'supplier_id', 'unit_id', 'bill_no', 'date_from', 'date_to']),
            'summary' => [
                'total_amount' => ,
            ],
        ]);
    }
}
INNEREOF
ls -la app/Http/Controllers/BillController.php
EOF
chmod +x create_bill_controller.sh
wsl -d Ubuntu -e bash -c "cd /root/development/supplier-management && ./create_bill_controller.sh"
