@php
  $bodyHtml = '<h1>Hi ' . e($recipientName ?: 'there') . ',</h1>';
  foreach (preg_split('/\n{2,}/', trim($bodyText)) as $para) {
      $para = nl2br(e($para));
      $bodyHtml .= '<p>' . $para . '</p>';
  }
  if (!empty($ctaUrl)) {
      $bodyHtml .= '<p style="margin-top:20px;"><a href="' . e($ctaUrl) . '" class="btn">' . e($ctaLabel ?: 'Continue') . '</a></p>';
  }
@endphp
@include('emails.layout', [
    'title'     => $subjectLine,
    'preheader' => mb_substr($bodyText, 0, 120),
    'appUrl'    => $appUrl,
    'content'   => $bodyHtml,
])
