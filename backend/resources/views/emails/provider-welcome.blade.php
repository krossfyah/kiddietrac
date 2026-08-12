@php
  $primary = $primaryColor ?? '#081C41';
  $accent  = $accentColor ?? '#2EA9AC';
  // Editable narrative blocks (agency admins can rewrite these). The controller
  // fills them via ProviderWelcomeTemplate; fall back to safe empties.
  $intro       = $intro       ?? '';
  $careMessage = $careMessage ?? '';
  $expectIntro = $expectIntro ?? '';
  $closing     = $closing     ?? '';
  // Rich-editor blocks may be HTML; plain-text defaults are not. If a block has
  // no tags, escape it + convert newlines; otherwise trust the (server-sanitised) HTML.
  $blk = function ($t) { $t = (string) $t; return strip_tags($t) === $t ? nl2br(e($t)) : $t; };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<title>Welcome to {{ $providerName }}</title>
</head>
<body style="margin:0;padding:0;background:#EEF1F6;font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0B1A33;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">A warm welcome from {{ $providerName }} — meet your child's provider and what to expect.</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF1F6;">
  <tr><td align="center" style="padding:24px 14px;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

      {{-- Hero banner with the agency logo incorporated (white badge, enlarged) --}}
      <tr><td style="background:linear-gradient(135deg,{{ $primary }} 0%,{{ $accent }} 135%);border-radius:16px 16px 0 0;padding:30px 34px 30px;">
        @if(!empty($agencyLogoUrl))
          <div style="background:#ffffff;border-radius:14px;display:inline-block;padding:12px 18px;margin-bottom:20px;box-shadow:0 6px 18px rgba(0,0,0,.18);">
            <img src="{{ $agencyLogoUrl }}" alt="{{ $agencyName }}" style="height:48px;max-height:48px;display:block;">
          </div>
        @else
          <div style="color:#ffffff;font-size:23px;font-weight:800;margin-bottom:18px;letter-spacing:-0.3px;">{{ $agencyName }}</div>
        @endif
        <div style="color:#CFE9EA;font-size:12px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;">A warm welcome to our family</div>
        <div style="color:#ffffff;font-size:28px;font-weight:800;line-height:1.2;margin-top:8px;">Welcome to<br>{{ $providerName }} 🌿</div>
        <div style="color:#E9F5F5;font-size:15px;margin-top:14px;line-height:1.6;">{!! $blk($intro) !!}</div>
      </td></tr>

      {{-- Body card --}}
      <tr><td style="background:#ffffff;border-radius:0 0 16px 16px;padding:8px 34px 30px;box-shadow:0 1px 4px rgba(8,28,65,.06);">

        {{-- Provider profile --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0 8px;">
          <tr>
            <td width="88" valign="top" style="padding-right:16px;">
              @if(!empty($providerPhotoUrl))
                <img src="{{ $providerPhotoUrl }}" alt="{{ $providerName }}" width="76" height="76" style="width:76px;height:76px;border-radius:50%;object-fit:cover;border:3px solid {{ $accent }};display:block;">
              @else
                <div style="width:76px;height:76px;border-radius:50%;background:{{ $accent }};color:#fff;font-size:34px;text-align:center;line-height:76px;">👩</div>
              @endif
            </td>
            <td valign="top">
              <div style="font-size:19px;font-weight:800;color:{{ $primary }};">{{ $providerName }}</div>
              <div style="font-size:13px;font-weight:700;color:{{ $accent }};text-transform:uppercase;letter-spacing:.5px;margin-top:2px;">Your child's provider</div>
              <div style="font-size:13px;color:#5B6B85;margin-top:4px;">with {{ $agencyName }}</div>
            </td>
          </tr>
        </table>

        {{-- Bio --}}
        <div style="background:#F6F8FB;border-left:4px solid {{ $accent }};border-radius:8px;padding:16px 18px;margin:14px 0 6px;">
          <div style="font-size:11.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#7A8AA3;margin-bottom:6px;">A little about me</div>
          <div style="font-size:14.5px;color:#2A3D5F;line-height:1.6;">{!! nl2br(e($providerBio)) !!}</div>
        </div>

        {{-- How we'll nurture --}}
        <h2 style="font-size:16px;color:{{ $primary }};font-weight:800;margin:26px 0 8px;">🌱 How we'll care for {{ $childName ?: 'your little one' }} this term</h2>
        <div style="font-size:14.5px;color:#2A3D5F;line-height:1.7;margin:0 0 8px;">{!! $blk($careMessage) !!}</div>

        {{-- What to expect / daily logging --}}
        <h2 style="font-size:16px;color:{{ $primary }};font-weight:800;margin:24px 0 8px;">📱 Staying connected — what to expect</h2>
        <div style="font-size:14.5px;color:#2A3D5F;line-height:1.65;margin:0 0 10px;">{!! $blk($expectIntro) !!}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 6px;">
          @foreach([['✅','Sign-in & sign-out times, so you always know they arrived safely'],['🍽️','Meals & snacks — what they ate and how much'],['😴','Naps — when they slept and woke'],['🎨','Activities, learning moments & photos throughout the day'],['💛','Health notes, and instant alerts for anything important']] as $row)
          <tr>
            <td width="30" valign="top" style="font-size:18px;padding:5px 0;">{{ $row[0] }}</td>
            <td valign="top" style="font-size:14px;color:#2A3D5F;padding:5px 0;line-height:1.5;">{{ $row[1] }}</td>
          </tr>
          @endforeach
        </table>

        {{-- CTA --}}
        <div style="text-align:center;margin:22px 0 6px;">
          <a href="{{ $portalUrl }}" style="display:inline-block;background:{{ $primary }};color:#ffffff;text-decoration:none;font-weight:800;font-size:15px;padding:14px 30px;border-radius:9px;">Open your parent app →</a>
        </div>

        {{-- Contacts & escalation --}}
        <h2 style="font-size:16px;color:{{ $primary }};font-weight:800;margin:26px 0 10px;">☎️ Who to contact</h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #E4E8EF;border-radius:10px;overflow:hidden;">
          <tr style="background:#F6F8FB;">
            <td style="padding:12px 16px;font-size:13px;font-weight:800;color:{{ $primary }};border-bottom:1px solid #E4E8EF;">Day-to-day — your provider</td>
          </tr>
          <tr><td style="padding:12px 16px;font-size:14px;color:#2A3D5F;border-bottom:1px solid #E4E8EF;">
            <strong>{{ $providerName }}</strong><br>
            @if($providerPhone)📞 <a href="tel:{{ $providerPhone }}" style="color:{{ $accent }};text-decoration:none;">{{ $providerPhone }}</a><br>@endif
            @if($providerEmail)✉️ <a href="mailto:{{ $providerEmail }}" style="color:{{ $accent }};text-decoration:none;">{{ $providerEmail }}</a>@endif
            @if(!empty($providerAddress))<br>📍 <span style="color:#5B6B85;">{!! nl2br(e($providerAddress)) !!}</span>@endif
          </td></tr>
          <tr style="background:#F6F8FB;">
            <td style="padding:12px 16px;font-size:13px;font-weight:800;color:{{ $primary }};border-bottom:1px solid #E4E8EF;">Need to escalate? — the agency</td>
          </tr>
          <tr><td style="padding:12px 16px;font-size:14px;color:#2A3D5F;">
            <strong>{{ $agencyName }}</strong>@if(!empty($agencyOwnerName))<br><span style="color:#5B6B85;">Owner / Director: {{ $agencyOwnerName }}</span>@endif<br>
            @if($agencyPhone)📞 <a href="tel:{{ $agencyPhone }}" style="color:{{ $accent }};text-decoration:none;">{{ $agencyPhone }}</a><br>@endif
            @if($agencyEmail)✉️ <a href="mailto:{{ $agencyEmail }}" style="color:{{ $accent }};text-decoration:none;">{{ $agencyEmail }}</a>@endif
            @if(!empty($websiteUrl))<br>🌐 <a href="{{ $websiteUrl }}" style="color:{{ $accent }};text-decoration:none;">{{ preg_replace('#^https?://#', '', rtrim($websiteUrl, '/')) }}</a>@endif
            @if(!empty($agencyAddress))<br>📍 <span style="color:#5B6B85;">{!! nl2br(e($agencyAddress)) !!}</span>@endif
          </td></tr>
        </table>
        <p style="font-size:12.5px;color:#7A8AA3;line-height:1.6;margin:10px 2px 0;">
          <strong style="color:#5B6B85;">Escalation path:</strong> for everyday questions, reach your provider first. If a concern is urgent or unresolved, contact {{ $agencyName }} directly. In a medical emergency, always call 911 first, then notify your provider and the agency.
        </p>

        <div style="font-size:15px;color:#2A3D5F;line-height:1.65;margin:24px 0 4px;">{!! $blk($closing) !!}</div>
        <p style="font-size:15px;color:{{ $primary }};font-weight:800;margin:0;">Warmly,<br>{{ $providerName }} &amp; the {{ $agencyName }} team</p>

      </td></tr>

      {{-- Footer --}}
      <tr><td style="padding:20px 8px 6px;color:#8693A8;font-size:12px;line-height:1.7;text-align:center;">
        Sent by {{ $agencyName }} via KiddieTrac.<br>
        @if(!empty($websiteUrl))<a href="{{ $websiteUrl }}" style="color:{{ $accent }};text-decoration:none;">{{ preg_replace('#^https?://#', '', rtrim($websiteUrl, '/')) }}</a> · @endif
        @if($privacyUrl)<a href="{{ $privacyUrl }}" style="color:{{ $accent }};text-decoration:none;">Privacy Policy</a> · @endif
        @if($termsUrl)<a href="{{ $termsUrl }}" style="color:{{ $accent }};text-decoration:none;">Terms of Use</a>@endif
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
