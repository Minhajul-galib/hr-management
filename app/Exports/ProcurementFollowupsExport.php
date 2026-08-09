<?php

namespace App\Exports;

use App\Models\ProcurementFollowup;
use Illuminate\Support\Arr;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProcurementFollowupsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private array $filters = [])
    {
    }

    public function query()
    {
        $q = ProcurementFollowup::query();

        $fy = Arr::get($this->filters, 'fiscal_year');
        if (!empty($fy)) {
            $q->where('fiscal_year', (int) $fy);
        }

        $bookingType = Arr::get($this->filters, 'booking_type');
        if (!empty($bookingType)) {
            $q->whereRaw('LOWER(booking_type) = ?', [strtolower((string) $bookingType)]);
        }

        $status = Arr::get($this->filters, 'status');
        if (!empty($status)) {
            $status = strtolower((string) $status);
            if ($status === 'received') {
                $q->where('balance_qty', '<=', 0);
            } elseif ($status === 'pending') {
                $q->where('received_qty', '<=', 0)->where('balance_qty', '>', 0);
            } elseif ($status === 'partial') {
                $q->where('received_qty', '>', 0)->where('balance_qty', '>', 0);
            }
        }

        $suppliers = Arr::get($this->filters, 'supplier', []);
        if (is_array($suppliers) && count(array_filter($suppliers))) {
            $q->whereIn('supplier', array_filter($suppliers));
        }

        $teams = Arr::get($this->filters, 'merchant_team', []);
        if (is_array($teams) && count(array_filter($teams))) {
            $q->whereIn('merchant_team', array_filter($teams));
        }

        return $q->orderByDesc('pi_received_date');
    }

    public function headings(): array
    {
        return [
            'supplier',
            'merchant_team',
            'yarn_count',
            'booking_type',
            'fiber',
            'order_qty_kg',
            'pi_number',
            'pi_received_date',
            'lc_number',
            'lc_issue_date',
            'lc_factory',
            'commercial_invoice',
            'bl_qty_ctn',
            'org_document_date',
            'ex_factory_date',
            'etd',
            'eta',
            'received_qty',
            'received_date',
            'balance_qty',
            'remark',
            'fiscal_year',
            'import_batch',
        ];
    }

    public function map($row): array
    {
        return [
            $row->supplier,
            $row->merchant_team,
            $row->yarn_count,
            $row->booking_type,
            $row->fiber,
            $row->order_qty_kg,
            $row->pi_number,
            optional($row->pi_received_date)->format('Y-m-d'),
            $row->lc_number,
            optional($row->lc_issue_date)->format('Y-m-d'),
            $row->lc_factory,
            $row->commercial_invoice,
            $row->bl_qty_ctn,
            optional($row->org_document_date)->format('Y-m-d'),
            optional($row->ex_factory_date)->format('Y-m-d'),
            optional($row->etd)->format('Y-m-d'),
            optional($row->eta)->format('Y-m-d'),
            $row->received_qty,
            optional($row->received_date)->format('Y-m-d'),
            $row->balance_qty,
            $row->remark,
            $row->fiscal_year,
            $row->import_batch,
        ];
    }
}

