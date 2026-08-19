@php
  // Transactional: a receipt for something the reader just did. No pitch, and deliberately
  // no unsubscribe link — there is nothing left to unsubscribe from, and offering one would
  // imply the request had not been honoured.
  $byAdmin = ($actor ?? 'self') === 'admin';

  $bodyHtml  = '<h1>You&rsquo;ve been unsubscribed</h1>';
  $bodyHtml .= '<p>'.($recipientName ? 'Hi '.e($recipientName).', ' : '').'this is a confirmation that '
      .'<strong>'.e($recipientEmail).'</strong> has been removed from KiddieTrac marketing emails'
      .($byAdmin ? ' at your request' : '').'.</p>';
  $bodyHtml .= '<p>You won&rsquo;t hear from us again unless you subscribe. Anything to do with an '
      .'account you already hold &mdash; invoices, your child&rsquo;s day, security notices &mdash; is separate '
      .'and will keep arriving as normal.</p>';
  $bodyHtml .= '<p style="margin-top:22px;font-size:13px;color:#8693A8;line-height:1.7;">'
      .'Unsubscribed by mistake? You can join again any time at '
      .'<a href="'.e($resubscribeUrl).'">kiddietrac.com</a>.<br>'
      .'See our <a href="'.e($privacyUrl).'">Privacy Policy</a> for how we handle your details, or write to '
      .'<a href="mailto:privacy@kiddietrac.com">privacy@kiddietrac.com</a>.'
      .'</p>';
@endphp
@include('emails.layout', [
    'title'     => 'You have been unsubscribed from KiddieTrac',
    'preheader' => 'Confirming that '.$recipientEmail.' has been removed from KiddieTrac marketing emails.',
    'appUrl'    => $appUrl,
    'content'   => $bodyHtml,
])
