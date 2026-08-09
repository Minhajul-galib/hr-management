<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Voucher - {{ $document->doc_no }}</title>
    <style>
        @page {
            size: 8.27in 11.69in;
            margin: 14mm 10mm 14mm 10mm;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #111827;
        }

        /* ── Header ── */
        .header {
            text-align: center;
            margin-bottom: 10px;
        }
        .logo {
            height: 30px;
            display: block;
            margin: 0 auto 4px auto;
        }
        .header-date {
            font-size: 11px;
            color: #374151;
            margin-bottom: 6px;
        }
        .title-bar {
            text-transform: uppercase;
            font-weight: 600;
            font-size: 11px;
            padding: 4px 8px;
            background: #e5e7eb;
            text-align: center;
            display: block;
            width: 97.9%;
        }

        /* ── Meta box (top-right) ── */
        .meta-wrap {
            width: 100%;
            margin-top: 8px;
            margin-bottom: 8px;
        }
        .meta-wrap td {
            /* transparent cell to push meta-box right */
        }
        .meta-box {
            border-collapse: collapse;
            float: right;
        }
        .meta-box td {
            font-size: 11px;
            border: 1px solid #9ca3af;
            padding: 3px 6px;
        }
        .meta-box .label {
            font-weight: 600;
            width: 110px;
        }
        .meta-box .val {
            text-align: right;
            width: 90px;
        }
        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }

        /* ── Main table ── */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            clear: both;
        }
        .main-table th,
        .main-table td {
            border: 1px solid #9ca3af;
            padding: 4px 6px;
            vertical-align: middle;
        }
        .main-table thead th {
            background: #f3f4f6;
            font-weight: 600;
            text-align: center;
        }
        .main-table td.center { text-align: center; }
        .main-table td.right  { text-align: right; }
        .main-table td.nowrap { white-space: nowrap; }
        .main-table .total-row td {
            font-weight: 700;
        }

        /* fixed column widths to match VoucherView */
        .col-sl       { width: 4%; }
        .col-bill     { width: 8%; }
        .col-date     { width: 10%; }
        .col-supplier { width: 22%; }
        .col-part     { width: 22%; }
        .col-amount   { width: 12%; }
        .col-unit     { width: 22%; }

        /* ── Amount in words ── */
        .amount-words {
            margin-top: 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        /* ── Signature row ── */
        .sign-row {
            position: fixed;
            bottom: 12mm;
            left: 0;
            right: 0;
            width: 100%;
            border-collapse: collapse;
            border: none
        }
        .sign-row td {
            border: none;
        }
        .sign-col {
            width: 16.6667%;
            font-size: 10px;
            padding: 36px 4px 4px 4px;
            text-align: center;
            vertical-align: top;
        }
        .sign-label {
            font-weight: 600;
            display: block;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .sign-sub {
            font-weight: 400;
            display: block;
            font-size: 9px;
        }

        /* push content above fixed footer */
        .content {
            padding-bottom: 28mm;
        }
    </style>
</head>
<body>
<div class="content">

    <!-- Header -->
    <div class="header">
        <img src="{{ public_path('images/clifton-group-logo.png') }}" alt="Clifton Group Logo" class="logo">
        <div class="header-date">DATE.............. PERIOD:&nbsp;&nbsp;&nbsp;FROM .............. TO ..............</div>
        <span class="title-bar">CHEQUE / CREDIT VOUCHERS FOR APPROVAL</span>
    </div>

    <!-- Meta box floated right -->
    <div class="clearfix">
        <table class="meta-box">
            <tr>
                <td class="label">DOCUMENT NO</td>
                <td class="val">{{ $document->doc_no }}</td>
            </tr>
            <tr>
                <td class="label">DATE</td>
                <td class="val">{{ \Carbon\Carbon::parse($document->doc_date)->format('n/j/Y') }}</td>
            </tr>
        </table>
    </div>

    <!-- Main table -->
    <table class="main-table">
        <thead>
            <tr>
                <th class="col-sl">SL NO</th>
                <th class="col-bill">BILL NO</th>
                <th class="col-date">BILL DATE</th>
                <th class="col-supplier">NAME OF SUPPLIERS</th>
                <th class="col-part">PARTICULARS</th>
                <th class="col-amount">AMOUNT (TK)</th>
                <th class="col-unit">UNIT</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document->items as $item)
            <tr>
                <td class="center">{{ $item->sl_no }}</td>
                <td class="center nowrap">{{ $item->bill_no ?: '' }}</td>
                <td class="center nowrap">{{ $item->bill_date ? \Carbon\Carbon::parse($item->bill_date)->format('n/j/Y') : '' }}</td>
                <td>{{ $item->supplier->name ?? '' }}</td>
                <td>{{ $item->particular->name ?? '' }}</td>
                <td class="right nowrap">{{ number_format((float)$item->amount, 2) }}</td>
                <td class="center">{{ $item->unit->short_form ?? '' }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="5">TOTAL TAKA</td>
                <td class="right nowrap">{{ number_format((float)$document->total_amount, 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <!-- Amount in words -->
    <div class="amount-words">
        <strong>TOTAL:</strong> {{ $document->amount_in_words ?: '' }}
    </div>

</div>

<!-- Signature row -->
<table class="sign-row">
    <tr>
        <td class="sign-col">
            <hr>
            <span class="sign-label">PREPARED BY</span>
            <span class="sign-sub">CHECKED BY</span>
        </td>
        <td class="sign-col">
            <hr>
            <span class="sign-label">AGM (PURCHASE)</span>
            <span class="sign-sub">CHECKED BY</span>
        </td>
        <td class="sign-col">
            <hr>
            <span class="sign-label">SR. MANAGER (A/C)</span>
            <span class="sign-sub">CHECKED BY</span>
        </td>
        <td class="sign-col">
            <hr>
            <span class="sign-label">DGM (A/C)</span>
        </td>
        <td class="sign-col">
            <hr>
            <span class="sign-label">GM (A/C)</span>
        </td>
        <td class="sign-col">
            <hr>
            <span class="sign-label">DIRECTOR/MD/CHAIRMAN</span>
            <span class="sign-sub">APPROVED BY</span>
        </td>
    </tr>
</table>

</body>
</html>
