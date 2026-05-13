@include('emails.layout', ['title' => 'Payment received', 'preheader' => 'Your payment to ' . $centreName . ' was received.', 'appUrl' => $appUrl ?? 'https://app.kiddietrac.com', 'content' => '
<h1>Thanks, ' . e($recipientName) . '!</h1>

<p>' . e($centreName) . ' has received your payment for invoice <strong>' . e($invoiceNumber) . '</strong>.</p>

<table class="totals">
<tr><td>Amount received</td><td style="text-align: right;"><strong>$' . number_format($amount, 2) . '</strong></td></tr>
<tr><td>Payment method</td><td style="text-align: right;">' . e(str_replace("_", " ", ucwords($method, "_"))) . '</td></tr>
' . ($fullyPaid
    ? '<tr class="total-row"><td colspan="2" style="text-align: center; color: #10B981; font-weight: 700;">✓ Invoice fully paid</td></tr>'
    : '<tr class="total-row"><td>Remaining balance</td><td style="text-align: right;">$' . number_format($remainingBalance, 2) . '</td></tr>'
) . '
</table>

<p><a href="' . e($appUrl ?? 'https://app.kiddietrac.com') . '" class="btn">View payment history</a></p>
'])
