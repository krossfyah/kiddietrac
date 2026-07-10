@php
  $bodyHtml  = '<h1>You&rsquo;re on the list 🎉</h1>';
  $bodyHtml .= '<p>Hi '.e($recipientName ?: 'there').', thanks for subscribing to <strong>KiddieTrac</strong>. We&rsquo;ll send occasional product updates, childcare-management tips, and CWELCC / compliance news — no spam, and you can leave any time.</p>';
  $bodyHtml .= '<p style="margin-top:20px;"><a href="https://www.kiddietrac.com" class="btn">Explore KiddieTrac</a></p>';
  $bodyHtml .= '<p style="margin-top:26px;font-size:13px;color:#8693A8;line-height:1.7;">'
      .'You received this because '.e($recipientName ? '' : '').'someone subscribed this email address at kiddietrac.com. '
      .'Not you, or changed your mind? <a href="'.e($unsubscribeUrl).'"><strong>Unsubscribe instantly</strong></a>.<br>'
      .'By subscribing you agree to our <a href="'.e($privacyUrl).'">Privacy Policy</a> and <a href="'.e($termsUrl).'">Terms of Use</a>.'
      .'</p>';
@endphp
@include('emails.layout', [
    'title'     => 'You are subscribed to KiddieTrac updates',
    'preheader' => 'Thanks for subscribing to KiddieTrac — product updates, tips and compliance news.',
    'appUrl'    => $appUrl,
    'content'   => $bodyHtml,
])
