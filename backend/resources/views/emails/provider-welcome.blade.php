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
{{-- DARK MODE.

     The email declared `color-scheme: light` and then set backgrounds and text
     colours on DIFFERENT elements — `background:#ffffff` on the card <td>, and
     `color:#2A3D5F` on the divs inside it. Mail clients that force a dark theme
     invert what they can see: the white card went near-black, the navy text
     stayed navy, and the body of the email became dark-on-dark.

     Two fixes, because no single one covers every client:

     1. We now declare `light dark` and ship an actual dark palette below. Apple
        Mail, iOS, Outlook and Outlook.com honour that and use OUR colours rather
        than inverting ours.
     2. Every background below is now paired with a colour on the SAME element.
        Gmail ignores the media query and inverts regardless, but it inverts an
        element's background and text together — so a paired declaration survives
        it, and an unpaired one is what broke.

     Both are needed: the media query alone leaves Gmail broken, and the pairing
     alone gives Apple Mail a washed-out auto-invert instead of a designed one. --}}
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>Welcome to {{ $providerName }}</title>
<style>
  :root { color-scheme: light dark; supported-color-schemes: light dark; }

  @media (prefers-color-scheme: dark) {
    /* The page and the card it sits on. */
    .kt-page, .kt-page td { background:#0F1523 !important; }
    .kt-card { background:#182131 !important; color:#D6DEEC !important; }

    /* Body copy, headings and the quieter greys. Headings used the brand navy,
       which is near-black and unreadable on a dark card. */
    .kt-text { color:#D6DEEC !important; }
    .kt-head { color:#EAF1FF !important; }
    .kt-muted { color:#93A0B8 !important; }
    .kt-foot, .kt-foot td { color:#8494AC !important; }

    /* Panels: the bio quote and the contacts table. */
    .kt-panel { background:#1E2939 !important; color:#D6DEEC !important; }
    .kt-panel-head { background:#232F42 !important; color:#EAF1FF !important; }
    .kt-bordered { border-color:#2C3A50 !important; }
    .kt-bordered td { border-color:#2C3A50 !important; }

    /* The logo panel is the ONE thing that must not follow the dark palette: it
       holds the original logo, which is artwork drawn on white. Invert the panel
       and the logo keeps its own white rectangle while the panel goes dark, so
       the file's edges show as a bright box. Pinned white, with the outline
       darkened just enough to still read against the dark page. */
    .kt-logobar, .kt-logobar td { background:#FFFFFF !important; }
    .kt-logobar { border-color:#C6D0DE !important; }

  }

  /* Outlook.com rewrites colours and prefixes the body, so it needs its own
     selectors — [data-ogsc] for text, [data-ogsb] for backgrounds. */
  [data-ogsc] .kt-page, [data-ogsb] .kt-page { background:#0F1523 !important; }
  [data-ogsc] .kt-card, [data-ogsb] .kt-card { background:#182131 !important; color:#D6DEEC !important; }
  [data-ogsc] .kt-text { color:#D6DEEC !important; }
  [data-ogsc] .kt-head { color:#EAF1FF !important; }
  [data-ogsc] .kt-muted, [data-ogsc] .kt-foot { color:#93A0B8 !important; }
  [data-ogsc] .kt-panel, [data-ogsb] .kt-panel { background:#1E2939 !important; color:#D6DEEC !important; }
  [data-ogsc] .kt-panel-head, [data-ogsb] .kt-panel-head { background:#232F42 !important; color:#EAF1FF !important; }
  [data-ogsc] .kt-logobar, [data-ogsb] .kt-logobar { background:#FFFFFF !important; border-color:#C6D0DE !important; }
  [data-ogsc] .kt-logobar td, [data-ogsb] .kt-logobar td { background:#FFFFFF !important; }
</style>
</head>
<body style="margin:0;padding:0;background:#EEF1F6;font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0B1A33;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">A warm welcome from {{ $providerName }} — meet your child's provider and what to expect.</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="kt-page" style="background:#EEF1F6;color:#0B1A33;">
  <tr><td align="center" class="kt-page" style="padding:24px 14px;background:#EEF1F6;color:#0B1A33;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

      {{-- Logo panel — a white, outlined band sitting ABOVE the gradient.

           The logo has been through both other options and neither held up. On a
           white badge floating inside the banner it read as a sticker; straight
           onto the gradient it needed its background cut away, and a cut-out is
           only ever as good as the file — soft edges, drop shadows and anti-aliased
           type all leave a halo on a dark ground.

           Giving it its own white band sidesteps the problem rather than fighting
           it: the ORIGINAL logo is used, untouched, on the white it was drawn for,
           and the outline stops the band from bleeding into the page behind it.
           It also generalises — any logo an agency uploads, light or dark, sits
           correctly on white, which is not true of the gradient. --}}
      @if(!empty($agencyLogoPanelUrl))
      <tr><td align="center" class="kt-logobar" style="background:#FFFFFF;border:1px solid #D7DEE8;border-bottom:none;border-radius:16px 16px 0 0;padding:22px 24px 20px;">
        <img src="{{ $agencyLogoPanelUrl }}" alt="{{ $agencyName }}" width="286" style="width:286px;max-width:100%;height:auto;display:block;margin:0 auto;border:0;outline:none;text-decoration:none;">
      </td></tr>
      @endif

      {{-- Hero banner. Square on top when the logo panel is above it, so the two
           read as one card rather than two stacked ones. --}}
      <tr><td style="background:linear-gradient(135deg,{{ $primary }} 0%,{{ $accent }} 135%);border-radius:{{ !empty($agencyLogoPanelUrl) ? '0' : '16px 16px 0 0' }};padding:30px 34px 30px;">
        @if(empty($agencyLogoPanelUrl))
          <div style="color:#ffffff;font-size:23px;font-weight:800;margin-bottom:18px;letter-spacing:-0.3px;">{{ $agencyName }}</div>
        @endif
        <div style="color:#CFE9EA;font-size:12px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;">A warm welcome to our family</div>
        <div style="color:#ffffff;font-size:28px;font-weight:800;line-height:1.2;margin-top:8px;">Welcome to<br>{{ $providerName }} 🌿</div>
        <div style="color:#E9F5F5;font-size:15px;margin-top:14px;line-height:1.6;">{!! $blk($intro) !!}</div>
      </td></tr>

      {{-- Body card --}}
      <tr><td class="kt-card" style="background:#ffffff;color:#2A3D5F;border-radius:0 0 16px 16px;padding:8px 34px 30px;box-shadow:0 1px 4px rgba(8,28,65,.06);">

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
              <div class="kt-head" style="font-size:19px;font-weight:800;color:{{ $primary }};">{{ $providerName }}</div>
              <div style="font-size:13px;font-weight:700;color:{{ $accent }};text-transform:uppercase;letter-spacing:.5px;margin-top:2px;">Your child's provider</div>
              <div class="kt-muted" style="font-size:13px;color:#5B6B85;margin-top:4px;">with {{ $agencyName }}</div>
            </td>
          </tr>
        </table>

        {{-- Bio --}}
        <div class="kt-panel" style="background:#F6F8FB;color:#2A3D5F;border-left:4px solid {{ $accent }};border-radius:8px;padding:16px 18px;margin:14px 0 6px;">
          <div class="kt-muted" style="font-size:11.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#7A8AA3;margin-bottom:6px;">A little about me</div>
          <div class="kt-text" style="font-size:14.5px;color:#2A3D5F;line-height:1.6;">{!! nl2br(e($providerBio)) !!}</div>
        </div>

        {{-- How we'll nurture --}}
        <h2 class="kt-head" style="font-size:16px;color:{{ $primary }};font-weight:800;margin:26px 0 8px;">🌱 How we'll care for {{ $childName ?: 'your little one' }} this term</h2>
        <div class="kt-text" style="font-size:14.5px;color:#2A3D5F;line-height:1.7;margin:0 0 8px;">{!! $blk($careMessage) !!}</div>

        {{-- What to expect / daily logging --}}
        <h2 class="kt-head" style="font-size:16px;color:{{ $primary }};font-weight:800;margin:24px 0 8px;">📱 Staying connected — what to expect</h2>
        <div class="kt-text" style="font-size:14.5px;color:#2A3D5F;line-height:1.65;margin:0 0 10px;">{!! $blk($expectIntro) !!}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 6px;">
          @foreach([['✅','Sign-in & sign-out times, so you always know they arrived safely'],['🍽️','Meals & snacks — what they ate and how much'],['😴','Naps — when they slept and woke'],['🎨','Activities, learning moments & photos throughout the day'],['💛','Health notes, and instant alerts for anything important']] as $row)
          <tr>
            <td width="30" valign="top" style="font-size:18px;padding:5px 0;">{{ $row[0] }}</td>
            <td valign="top" class="kt-text" style="font-size:14px;color:#2A3D5F;padding:5px 0;line-height:1.5;">{{ $row[1] }}</td>
          </tr>
          @endforeach
        </table>

        {{-- CTA --}}
        <div style="text-align:center;margin:22px 0 6px;">
          <a href="{{ $portalUrl }}" style="display:inline-block;background:{{ $primary }};color:#ffffff;text-decoration:none;font-weight:800;font-size:15px;padding:14px 30px;border-radius:9px;">Open your parent app →</a>
        </div>

        {{-- Contacts & escalation --}}
        <h2 class="kt-head" style="font-size:16px;color:{{ $primary }};font-weight:800;margin:26px 0 10px;">☎️ Who to contact</h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="kt-bordered" style="border:1px solid #E4E8EF;border-radius:10px;overflow:hidden;">
          <tr class="kt-panel-head" style="background:#F6F8FB;color:#0B1A33;">
            <td class="kt-panel-head kt-bordered" style="padding:12px 16px;font-size:13px;font-weight:800;color:{{ $primary }};background:#F6F8FB;border-bottom:1px solid #E4E8EF;">Day-to-day — your provider</td>
          </tr>
          <tr><td class="kt-card kt-bordered" style="padding:12px 16px;font-size:14px;color:#2A3D5F;background:#ffffff;border-bottom:1px solid #E4E8EF;">
            <strong>{{ $providerName }}</strong><br>
            @if($providerPhone)📞 <a href="tel:{{ $providerPhone }}" style="color:{{ $accent }};text-decoration:none;">{{ $providerPhone }}</a><br>@endif
            @if($providerEmail)✉️ <a href="mailto:{{ $providerEmail }}" style="color:{{ $accent }};text-decoration:none;">{{ $providerEmail }}</a>@endif
            @if(!empty($providerAddress))<br>📍 <span class="kt-muted" style="color:#5B6B85;">{!! nl2br(e($providerAddress)) !!}</span>@endif
          </td></tr>
          <tr class="kt-panel-head" style="background:#F6F8FB;color:#0B1A33;">
            <td class="kt-panel-head kt-bordered" style="padding:12px 16px;font-size:13px;font-weight:800;color:{{ $primary }};background:#F6F8FB;border-bottom:1px solid #E4E8EF;">Need to escalate? — the agency</td>
          </tr>
          <tr><td class="kt-card" style="padding:12px 16px;font-size:14px;color:#2A3D5F;background:#ffffff;">
            <strong>{{ $agencyName }}</strong>@if(!empty($agencyOwnerName))<br><span class="kt-muted" style="color:#5B6B85;">Owner / Director: {{ $agencyOwnerName }}</span>@endif<br>
            @if($agencyPhone)📞 <a href="tel:{{ $agencyPhone }}" style="color:{{ $accent }};text-decoration:none;">{{ $agencyPhone }}</a><br>@endif
            @if($agencyEmail)✉️ <a href="mailto:{{ $agencyEmail }}" style="color:{{ $accent }};text-decoration:none;">{{ $agencyEmail }}</a>@endif
            @if(!empty($websiteUrl))<br>🌐 <a href="{{ $websiteUrl }}" style="color:{{ $accent }};text-decoration:none;">{{ preg_replace('#^https?://#', '', rtrim($websiteUrl, '/')) }}</a>@endif
            @if(!empty($agencyAddress))<br>📍 <span class="kt-muted" style="color:#5B6B85;">{!! nl2br(e($agencyAddress)) !!}</span>@endif
          </td></tr>
        </table>
        <p class="kt-muted" style="font-size:12.5px;color:#7A8AA3;line-height:1.6;margin:10px 2px 0;">
          <strong class="kt-text" style="color:#5B6B85;">Escalation path:</strong> for everyday questions, reach your provider first. If a concern is urgent or unresolved, contact {{ $agencyName }} directly. In a medical emergency, always call 911 first, then notify your provider and the agency.
        </p>

        <div class="kt-text" style="font-size:15px;color:#2A3D5F;line-height:1.65;margin:24px 0 4px;">{!! $blk($closing) !!}</div>
        <p class="kt-head" style="font-size:15px;color:{{ $primary }};font-weight:800;margin:0;">Warmly,<br>{{ $providerName }} &amp; the {{ $agencyName }} team</p>

      </td></tr>

      {{-- Footer --}}
      <tr><td class="kt-foot" style="padding:20px 8px 6px;color:#8693A8;background:#EEF1F6;font-size:12px;line-height:1.7;text-align:center;">
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
