<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Suppliers Export</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .filters {
            margin-bottom: 20px;
            font-size: 10px;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Suppliers Report</h1>
        <p>Generated on: {{ $exportDate }}</p>
    </div>

    @if(!empty($filters))
        <div class="filters">
            <strong>Filters Applied:</strong>
            @if(!empty($filters['search']))<br>Search: {{ $filters['search'] }}@endif
            @if(!empty($filters['status']))<br>Status: {{ ucfirst($filters['status']) }}@endif
            @if(!empty($filters['type_id']))<br>Type ID: {{ $filters['type_id'] }}@endif
            @if(!empty($filters['particular_id']))<br>Particular ID: {{ $filters['particular_id'] }}@endif
            @if(!empty($filters['trash']))<br>Showing: Trashed Items@endif
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>Contact Person</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Address</th>
                <th>BIN Number</th>
                <th class="text-right">Opening Balance</th>
                <th class="text-right">Total Owing</th>
                <th>Avg Rating</th>
                <th>Total Evaluations</th>
                <th>Verified</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($suppliers)
                <tr>
                    <td colspan="15" class="text-center">No suppliers found</td>
                </tr>
            @empty
                @foreach($suppliers as $supplier)
                    <tr>
                        <td>{{ $supplier->code }}</td>
                        <td>{{ $supplier->name }}</td>
                        <td>{{ $supplier->type ? $supplier->type->name : 'N/A' }}</td>
                        <td>{{ $supplier->contact_person ?? 'N/A' }}</td>
                        <td>{{ $supplier->email ?? 'N/A' }}</td>
                        <td>{{ $supplier->phone_number ?? 'N/A' }}</td>
                        <td>{{ $supplier->address ?? 'N/A' }}</td>
                        <td>{{ $supplier->bin_number ?? 'N/A' }}</td>
                        <td class="text-right">{{ number_format($supplier->opening_balance, 2) }}</td>
                        <td class="text-right">{{ number_format($supplier->total_owing, 2) }}</td>
                        <td>{{ $supplier->avg_rating ?? 'N/A' }}</td>
                        <td>{{ $supplier->total_evaluations ?? 0 }}</td>
                        <td class="text-center">{{ $supplier->is_verified ? 'Yes' : 'No' }}</td>
                        <td>
                            <span style="color: {{ $supplier->status == 'active' ? '#28a745' : '#dc3545' }}; font-weight: bold;">
                                {{ ucfirst($supplier->status) }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p>Total Suppliers: {{ $suppliers->count() }}</p>
        <p>Page {{ request('page', 1) }} of 1</p>
    </div>
</body>
</html>
