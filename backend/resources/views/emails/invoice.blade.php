@php
$linesHtml = '';
foreach ($lineItems as $line) {
    $linesHtml .= '<tr><td>' . e($line['description']) . '</td><td style="text-align: right;">$' . number_format($line['net_amount'] ?? $line['amount'], 2) . '</td></tr>';
}
@endphp

@include('emails.layout', ['title' => 'Invoice — ' . $invoiceNumber, 'preheader' => 'Invoice ' . $invoiceNumber . ' from ' . $centreName . ' — $' . number_format($totalDue, 2) . ' due ' . $dueDate, 'appUrl' => $appUrl, 'content' => '
<h1>Hi ' . e($recipientName) . ',</h1>

<p>Your monthly invoice from <strong>' . e($centreName) . '</strong> is ready.</p>

<table class="totals">
' . $linesHtml . '
<tr class="total-row">
  <td>Total due</td>
  <td style="text-align: right;">$' . number_format($totalDue, 2) . '</td>
</tr>
</table>

<p><strong>Due:</strong> ' . e($dueDate) . '</p>

<p><a href="' . e($appUrl) . '" class="btn">View invoice in Kiddietrac</a></p>

<p style="font-size: 13px; color: #6B7280;">Contact your centre directly for payment instructions.</p>
'])
