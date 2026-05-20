<!doctype html>
<html><head><meta charset="utf-8"><title>Statement</title>
<style>
  body { font-family: 'DejaVu Sans', sans-serif; color: #1F2937; font-size: 10pt; }
  .header { border-bottom: 2px solid #1F6080; padding-bottom: 10px; margin-bottom: 16px; }
  .header h1 { color: #1F6080; margin: 0; font-size: 20pt; }
  .meta { font-size: 9.5pt; color: #6B7280; }
  table { width: 100%; border-collapse: collapse; margin-top: 14px; }
  th, td { padding: 6px 8px; border-bottom: 1px solid #E5E7EB; font-size: 9pt; }
  th { background: #F9FAFB; text-align: left; color: #6B7280; text-transform: uppercase; font-size: 8pt; }
  td.amount { text-align: right; font-variant-numeric: tabular-nums; }
  .credit { color: #047857; }
  .debit { color: #B91C1C; }
  .summary { background: #F0F9FF; padding: 14px; border-radius: 6px; margin: 14px 0; }
  .balance { font-size: 16pt; font-weight: bold; color: #1F6080; }
</style></head>
<body>
  <div class="header">
    <h1>Account Statement</h1>
    <div class="meta">{{ $agency->name ?? 'KiddieTrac' }} · {{ $family->family_name }}</div>
    <div class="meta">Generated {{ now()->format('F j, Y') }}</div>
  </div>
  <div class="summary">
    <div>Outstanding balance: <span class="balance">${{ number_format((float) $balance, 2) }}</span></div>
    <div class="meta" style="margin-top:4px;">
      Invoiced: ${{ number_format((float) $totals['total_invoiced'], 2) }} ·
      Paid: ${{ number_format((float) $totals['total_paid'], 2) }} ·
      Refunded: ${{ number_format((float) $totals['total_refunded'], 2) }}
    </div>
  </div>
  <table>
    <thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="amount">Debit</th><th class="amount">Credit</th><th class="amount">Balance</th></tr></thead>
    <tbody>
      @forelse ($rows as $r)
        <tr>
          <td>{{ \Carbon\Carbon::parse($r['date'])->format('M j, Y') }}</td>
          <td style="text-transform:capitalize;">{{ $r['type'] }}</td>
          <td>{{ $r['description'] }}</td>
          <td class="amount debit">{{ $r['debit'] ? '$' . number_format((float) $r['debit'], 2) : '' }}</td>
          <td class="amount credit">{{ $r['credit'] ? '$' . number_format((float) $r['credit'], 2) : '' }}</td>
          <td class="amount"><strong>${{ number_format((float) $r['running_balance'], 2) }}</strong></td>
        </tr>
      @empty
        <tr><td colspan="6" style="text-align:center; color:#9CA3AF;">No transactions on file.</td></tr>
      @endforelse
    </tbody>
  </table>
</body></html>
