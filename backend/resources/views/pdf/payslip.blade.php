@php
    /**
     * Payslip PDF. Every value comes from the frozen payslip snapshot — nothing is
     * recomputed. Rendered by PayslipService via the existing dompdf pattern.
     *
     * @var \App\Models\Hr\HrPayslip $payslip
     */
    $employee = $payslip->employee;
    $tenant   = $tenant ?? null;

    $company  = $tenant->name ?? config('app.name');
    $brand    = $tenant->branding_color ?? '#7C3AED';
    $logo     = $tenant->logo_url ?? null;
    $period   = \Illuminate\Support\Carbon::create($payslip->payslip_year, $payslip->payslip_month, 1)->format('F Y');
    $money    = fn ($v) => '₹' . number_format((float) $v, 2);

    $bd         = $payslip->breakdown ?? [];
    $earnings   = $bd['earnings']   ?? [];
    $benefits   = $bd['benefits']   ?? [];
    $deductions = $bd['deductions'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $payslip->payslip_number }} — Payslip</title>
    <style>
        @page { margin: 34px 40px 56px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1f2937; line-height: 1.5; }
        .head { border-bottom: 3px solid {{ $brand }}; padding-bottom: 12px; margin-bottom: 6px; }
        .head td { vertical-align: middle; }
        .company { font-size: 19px; font-weight: bold; color: {{ $brand }}; }
        .muted { color: #6b7280; }
        .meta { text-align: right; font-size: 10.5px; }
        .title { text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 1px; color: {{ $brand }};
                 text-transform: uppercase; margin: 14px 0 4px; }
        .sub { text-align: center; font-size: 10.5px; color: #6b7280; margin-bottom: 16px; }
        table.kv { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.kv td { padding: 6px 9px; border: 1px solid #e5e7eb; font-size: 10.5px; }
        table.kv td.k { background: #f9fafb; font-weight: bold; width: 18%; color: #374151; }
        table.sal { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.sal th { background: {{ $brand }}; color: #fff; text-align: left; padding: 7px 10px; font-size: 11px; }
        table.sal td { padding: 6px 10px; border-bottom: 1px solid #eef2f7; font-size: 10.5px; }
        table.sal td.amt { text-align: right; }
        .sec { width: 49%; vertical-align: top; }
        .subtot td { border-top: 2px solid #e5e7eb; font-weight: bold; background: #f9fafb; }
        .summary { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .summary td { padding: 8px 12px; border: 1px solid #e5e7eb; font-size: 11.5px; }
        .summary td.k { background: #f9fafb; font-weight: bold; }
        .summary td.net { background: {{ $brand }}; color: #fff; font-weight: bold; font-size: 13px; }
        .footer { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 9px; color: #9ca3af;
                  border-top: 1px solid #e5e7eb; padding-top: 6px; text-align: center; }
    </style>
</head>
<body>

<table class="head" width="100%">
    <tr>
        <td>
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $company }}" style="max-height:44px;">
            @endif
            <div class="company">{{ $company }}</div>
        </td>
        <td class="meta">
            <div><strong>Payslip No.</strong> {{ $payslip->payslip_number }}</div>
            <div class="muted">Pay Period: {{ $period }}</div>
            @if ($payslip->generated_at)
                <div class="muted">Generated: {{ \Illuminate\Support\Carbon::parse($payslip->generated_at)->format('d M Y') }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="title">Salary Slip</div>
<div class="sub">for the month of {{ $period }}</div>

<table class="kv">
    <tr>
        <td class="k">Employee</td><td>{{ $employee->name ?? '—' }}</td>
        <td class="k">Emp. Code</td><td>{{ $employee->employee_code ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">Department</td><td>{{ $employee->department ?? '—' }}</td>
        <td class="k">Designation</td><td>{{ $employee->designation ?? '—' }}</td>
    </tr>
</table>

<table width="100%"><tr>
    {{-- Earnings --}}
    <td class="sec" style="padding-right:8px;">
        <table class="sal">
            <thead><tr><th>Earnings</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
                @forelse ($earnings as $row)
                    <tr><td>{{ $row['name'] }}</td><td class="amt">{{ $money($row['amount']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">—</td></tr>
                @endforelse
                @foreach ($benefits as $row)
                    <tr><td>{{ $row['name'] }} <span class="muted">(benefit)</span></td><td class="amt">{{ $money($row['amount']) }}</td></tr>
                @endforeach
                <tr class="subtot"><td>Gross Earnings</td><td class="amt">{{ $money($payslip->gross_salary) }}</td></tr>
                @if ((float) $payslip->total_benefits > 0)
                    <tr class="subtot"><td>Employer Benefits</td><td class="amt">{{ $money($payslip->total_benefits) }}</td></tr>
                @endif
            </tbody>
        </table>
    </td>
    {{-- Deductions --}}
    <td class="sec" style="padding-left:8px;">
        <table class="sal">
            <thead><tr><th>Deductions</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
                @forelse ($deductions as $row)
                    <tr><td>{{ $row['name'] }}</td><td class="amt">{{ $money($row['amount']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">—</td></tr>
                @endforelse
                <tr class="subtot"><td>Total Deductions</td><td class="amt">{{ $money($payslip->total_deductions) }}</td></tr>
            </tbody>
        </table>
    </td>
</tr></table>

<table class="summary">
    <tr>
        <td class="k">Gross Salary</td><td style="text-align:right;">{{ $money($payslip->gross_salary) }}</td>
        <td class="k">Total Deductions</td><td style="text-align:right;">{{ $money($payslip->total_deductions) }}</td>
    </tr>
    <tr>
        <td class="net" colspan="3">Net Salary</td>
        <td class="net" style="text-align:right;">{{ $money($payslip->net_salary) }}</td>
    </tr>
</table>

<p class="muted" style="font-size:9.5px; margin-top:14px;">
    This is a system-generated salary statement and does not require a signature. Attendance-based adjustments are not
    applied — they will be sourced from SangoeTrack once integrated.
</p>

<div class="footer">
    {{ $company }} &middot; {{ $payslip->payslip_number }} &middot; System-generated payslip &middot;
    Generated {{ $payslip->generated_at ? \Illuminate\Support\Carbon::parse($payslip->generated_at)->format('d M Y') : now()->format('d M Y') }}
</div>

</body>
</html>
