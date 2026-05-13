<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $title ?? 'Kiddietrac' }}</title>
<style>
  /* ── Reset / baseline ── */
  body, html { margin: 0; padding: 0; }
  body { background: #F4F6FA; font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #0B1A33; line-height: 1.55; -webkit-font-smoothing: antialiased; }
  table { border-collapse: collapse; }
  img { display: block; border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }

  /* ── Layout ── */
  .wrap { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
  .card { background: #FFFFFF; border-radius: 14px; padding: 32px; box-shadow: 0 1px 4px rgba(8, 28, 65, .06); border: 1px solid #E4E8EF; }

  /* ── Header ── */
  .header { padding-bottom: 18px; margin-bottom: 22px; border-bottom: 1px solid #E4E8EF; }
  .logo-img { height: 32px; max-height: 32px; }
  .logo-fallback { font-size: 22px; font-weight: 800; color: #081C41; letter-spacing: -0.5px; }

  /* ── Typography ── */
  h1 { font-size: 26px; line-height: 1.2; margin: 0 0 12px; color: #081C41; font-weight: 800; letter-spacing: -0.4px; }
  h2 { font-size: 17px; line-height: 1.3; margin: 24px 0 8px; color: #081C41; font-weight: 700; }
  p { margin: 0 0 14px; font-size: 15px; color: #2A3D5F; }
  strong { color: #0B1A33; }
  a { color: #2EA9AC; text-decoration: none; }

  /* ── Button (bulletproof-ish) ── */
  .btn { display: inline-block; background: #081C41; color: #FFFFFF !important; padding: 13px 28px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 15px; margin: 16px 0; letter-spacing: -0.005em; }
  .btn:hover { background: #142A57; }

  /* ── Credentials box ── */
  .creds { background: #EEF1F8; border-left: 3px solid #3BBBBE; padding: 16px 18px; border-radius: 6px; font-family: 'SF Mono', 'Menlo', 'Consolas', monospace; font-size: 14px; margin: 16px 0; color: #0B1A33; }
  .creds strong { color: #081C41; font-family: 'Plus Jakarta Sans', sans-serif; }

  /* ── Totals table ── */
  .totals { width: 100%; border-collapse: collapse; margin: 16px 0; }
  .totals td { padding: 10px 0; border-bottom: 1px solid #E4E8EF; color: #2A3D5F; font-size: 15px; }
  .totals .total-row td { padding-top: 14px; font-size: 18px; font-weight: 800; color: #081C41; border-bottom: none; }

  /* ── Footer ── */
  .footer { margin-top: 24px; padding-top: 16px; border-top: 1px solid #E4E8EF; color: #8693A8; font-size: 12px; line-height: 1.6; }
  .footer a { color: #2EA9AC; }
  .preheader { display: none; max-height: 0; overflow: hidden; mso-hide: all; visibility: hidden; opacity: 0; color: transparent; height: 0; width: 0; }

  /* ── Dark mode ── */
  @media (prefers-color-scheme: dark) {
    body { background: #0A1224; color: #E1E5EE; }
    .card { background: #142A57; border-color: #1F3568; box-shadow: 0 1px 4px rgba(0, 0, 0, .3); }
    .header { border-bottom-color: #1F3568; }
    h1, h2 { color: #FFFFFF; }
    p, .totals td { color: #C8D0E0; }
    strong, .totals .total-row td { color: #FFFFFF; }
    .creds { background: #1F3568; color: #E1E5EE; }
    .creds strong { color: #5BCDD0; }
    .footer { border-top-color: #1F3568; color: #8693A8; }
    .logo-fallback { color: #5BCDD0; }
  }

  /* ── Mobile ── */
  @media (max-width: 480px) {
    .wrap { padding: 12px 8px; }
    .card { padding: 24px 18px; border-radius: 10px; }
    h1 { font-size: 22px; }
    .btn { display: block; text-align: center; padding: 14px 20px; }
  }
</style>
</head>
<body>
<span class="preheader">{{ $preheader ?? $title ?? 'A message from Kiddietrac' }}</span>
<div class="wrap">
<div class="card">
  <div class="header">
    <img src="https://app.kiddietrac.com/logo-wordmark@2x.png" alt="KiddieTrac" height="32" class="logo-img" style="height:32px;max-height:32px;color:#081C41;font-size:22px;font-weight:800;letter-spacing:-0.5px;">
  </div>

  {!! $slot ?? $content ?? '' !!}

  <div class="footer">
    This email was sent by <strong style="color:inherit;">Kiddietrac</strong> on behalf of your childcare centre.<br>
    Visit <a href="{{ $appUrl ?? 'https://app.kiddietrac.com' }}">app.kiddietrac.com</a> to manage your account.
  </div>
</div>
</div>
</body>
</html>
