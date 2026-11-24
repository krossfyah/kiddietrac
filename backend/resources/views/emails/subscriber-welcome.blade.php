@php
  $webinar = ! empty($webinar);
  if ($webinar) {
      /* The webinar waitlist (2026-10-22): they asked to be invited to a session, so say
         that, and what happens next. The site promises the first sessions; no dates exist
         yet, so none are given. */
      $bodyHtml  = '<h1>You&rsquo;re on the webinar list 🎥</h1>';
      $bodyHtml .= '<p>Hi '.e($recipientName ?: 'there').', thanks for signing up for <strong>KiddieTrac</strong> webinars &mdash; free live sessions for childcare owners, directors and educators.</p>';
      $bodyHtml .= '<p><strong>What happens next:</strong> when the first sessions are scheduled, we&rsquo;ll email you the topics, dates and a link to save your seat. Topics include CWELCC, billing, Safe Arrival, working with families, and planning.</p>';
      $bodyHtml .= '<p>Between sessions we may also send the occasional product update or compliance note &mdash; no spam, and you can leave any time.</p>';
      $bodyHtml .= '<p style="margin-top:20px;"><a href="https://www.kiddietrac.com/webinars" class="btn">See the webinars</a></p>';
  } else {
      $bodyHtml  = '<h1>You&rsquo;re on the list 🎉</h1>';
      $bodyHtml .= '<p>Hi '.e($recipientName ?: 'there').', thanks for subscribing to <strong>KiddieTrac</strong>. We&rsquo;ll send occasional product updates, childcare-management tips, and CWELCC / compliance news — no spam, and you can leave any time.</p>';
      $bodyHtml .= '<p style="margin-top:20px;"><a href="https://www.kiddietrac.com" class="btn">Explore KiddieTrac</a></p>';
  }
  // Now on Google Play (2026-10-25)
  $bodyHtml .= '<div style="margin-top:24px;">' . \App\Support\PlayBadge::card() . '</div>';
  $bodyHtml .= '<p style="margin-top:26px;font-size:13px;color:#8693A8;line-height:1.7;">'
      .'You received this because '.e($recipientName ? '' : '').'someone subscribed this email address at kiddietrac.com. '
      .'Not you, or changed your mind? <a href="'.e($unsubscribeUrl).'"><strong>Unsubscribe instantly</strong></a>.<br>'
      .'By subscribing you agree to our <a href="'.e($privacyUrl).'">Privacy Policy</a> and <a href="'.e($termsUrl).'">Terms of Use</a>.'
      .'</p>';
@endphp
@include('emails.layout', [
    'title'     => $webinar ? 'You are on the KiddieTrac webinar list' : 'You are subscribed to KiddieTrac updates',
    'preheader' => $webinar ? 'We will email your invitation when the first KiddieTrac webinars are scheduled.' : 'Thanks for subscribing to KiddieTrac — product updates, tips and compliance news.',
    // Not from a childcare centre: this person is not a customer of one.
    'footerLine' => 'This email was sent by <strong style="color:inherit;">KiddieTrac</strong> because this address signed up at kiddietrac.com.',
    'appUrl'    => $appUrl,
    'content'   => $bodyHtml,
])
