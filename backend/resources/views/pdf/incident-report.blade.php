{{--
  The incident as a document — for a parent, a file, or a licensing visit.

  On the AGENCY's branding, not KiddieTrac's: this is the provider's record and it
  is what leaves the building. Dompdf renders it, so the layout is tables and
  simple CSS throughout — no flex, no grid, no custom properties, none of which it
  supports. Getting clever here produces a PDF that looks fine in a browser preview
  and collapses in the actual file.

  It is deliberately plain. An incident report is read under pressure, sometimes by
  somebody deciding whether a centre handled a child properly, and every element on
  it should be a fact rather than a flourish.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Incident report {{ $incident->id }}</title>
<style>
  @page { margin: 34px 40px 56px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1E293B; line-height: 1.5; }

  .band { width: 100%; border-collapse: collapse; border-bottom: 2.5px solid #081C41; padding-bottom: 8px; }
  .band td { vertical-align: middle; }
  .logo { height: 42px; }
  .provider { font-size: 15px; font-weight: bold; color: #081C41; }
  .provider-sub { font-size: 10px; color: #64748B; }
  .doctype { font-size: 10px; letter-spacing: 1.4px; color: #B3261E; font-weight: bold; text-align: right; }
  .docref  { font-size: 9.5px; color: #64748B; text-align: right; }

  h1 { font-size: 19px; color: #081C41; margin: 16px 0 2px; }
  .sub { font-size: 10.5px; color: #64748B; margin: 0 0 14px; }

  .sec { font-size: 9.5px; letter-spacing: 1.1px; text-transform: uppercase; color: #1F6FB2;
         font-weight: bold; margin: 16px 0 5px; border-bottom: 1px solid #E2E8F0; padding-bottom: 3px; }

  table.kv { width: 100%; border-collapse: collapse; }
  table.kv td { padding: 3.5px 0; vertical-align: top; font-size: 11px; }
  table.kv td.k { color: #64748B; width: 132px; }
  table.kv td.v { color: #0F172A; }

  .prose { font-size: 11px; color: #1E293B; white-space: pre-wrap; margin: 2px 0 0; }

  .flag { background: #FBEAE8; border-left: 3px solid #B3261E; color: #7A1710;
          padding: 7px 10px; font-size: 10.5px; margin: 8px 0; font-weight: bold; }

  table.notes { width: 100%; border-collapse: collapse; margin-top: 4px; }
  table.notes th { text-align: left; font-size: 9px; letter-spacing: .6px; text-transform: uppercase;
                   color: #64748B; border-bottom: 1px solid #E2E8F0; padding: 4px 6px 4px 0; font-weight: bold; }
  table.notes td { font-size: 10.5px; padding: 5px 6px 5px 0; border-bottom: 1px solid #F1F5F9;
                   vertical-align: top; color: #334155; }

  .sign { margin-top: 24px; }
  .sign td { padding-top: 26px; font-size: 10px; color: #64748B; }
  .rule { border-bottom: 1px solid #94A3B8; height: 1px; }

  .foot { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 8.5px; color: #94A3B8;
          border-top: 1px solid #E2E8F0; padding-top: 5px; }
</style>
</head>
<body>

@php
  $childName = trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? ''));
  $recName   = $recorder ? trim(($recorder->first_name ?? '') . ' ' . ($recorder->last_name ?? '')) : '—';
  $providerName = $agency->name ?? ($centre->name ?? 'Childcare provider');
  $tz = \App\Support\AgencyTime::tz((int) ($agency->id ?? 0)) ?: 'America/Toronto';
  /* WALL CLOCK — a human typed it, there is no zone in it, so it prints as stored. */
  $fmt = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('j M Y, g:i A') : '—';
  /* INSTANT — the server wrote it with app.timezone=UTC, so it is CONVERTED to the
     agency's zone. Printing these raw put the whole handling timeline four hours out. */
  $fmtAt = fn ($t) => $t
      ? \Illuminate\Support\Carbon::parse($t, 'UTC')->setTimezone($tz)->format('j M Y, g:i A')
      : '—';
  /* Who is this copy for? 'staff' (default) is the full record; 'parent' is the
     copy filed to the family's documents, which must not carry staff-internal
     remarks. Set by IncidentReportFiler; every other caller gets 'staff'. */
  $forParent = ($audience ?? 'staff') === 'parent';

  $interactions = collect($notes)->where('kind', 'interaction');
  $plainNotes   = collect($notes)->where('kind', '!=', 'interaction');

  if ($forParent) {
      /* Contact WITH THE FAMILY only. "We called you at 4pm" is about them and
         belongs on their copy; "spoke to the educator about supervision" does not. */
      $interactions = $interactions->filter(
          fn ($n) => in_array((string) ($n->contact_with ?? ''), ['parent', 'guardian', 'family'], true)
      );
      /* The family's own words stay — they wrote them. Everything else on the
         note thread is internal. */
      $plainNotes = $plainNotes->filter(
          fn ($n) => in_array((string) ($n->kind ?? ''), ['parent_feedback', 'meeting_request'], true)
      );
  }
@endphp

<table class="band">
  <tr>
    <td style="width:60%;">
      @if($logo)
        <img src="{{ $logo }}" class="logo" alt="{{ $providerName }}">
      @else
        <div class="provider">{{ $providerName }}</div>
      @endif
      @if($logo)
        <div class="provider" style="margin-top:5px;">{{ $providerName }}</div>
      @endif
      @if(!empty($centre->name) && ($centre->name !== ($agency->name ?? null)))
        <div class="provider-sub">{{ $centre->name }}</div>
      @endif
    </td>
    <td style="width:40%;">
      <div class="doctype">INCIDENT REPORT</div>
      <div class="docref">Reference INC-{{ str_pad((string) $incident->id, 5, '0', STR_PAD_LEFT) }}</div>
      <div class="docref">Generated {{ now()->format('j M Y, g:i A') }}</div>
    </td>
  </tr>
</table>

<h1>{{ $childName ?: 'Child' }}</h1>
<p class="sub">{{ ucwords(str_replace('_', ' ', (string) $incident->incident_type)) }}
   &middot; severity {{ $incident->severity ?: 'not set' }}
   &middot; {{ $fmt($incident->occurred_at) }}</p>

@if($incident->is_serious_occurrence)
  <div class="flag">SERIOUS OCCURRENCE — CCEYA reporting obligations may apply.</div>
@endif

<div class="sec">The incident</div>
<table class="kv">
  <tr><td class="k">Child</td><td class="v">{{ $childName ?: '—' }}</td></tr>
  <tr><td class="k">Occurred</td><td class="v">{{ $fmt($incident->occurred_at) }}</td></tr>
  <tr><td class="k">Location</td><td class="v">{{ $incident->location ?: '—' }}</td></tr>
  <tr><td class="k">Type</td><td class="v">{{ ucwords(str_replace('_', ' ', (string) $incident->incident_type)) }}</td></tr>
  <tr><td class="k">Severity</td><td class="v">{{ ucfirst((string) ($incident->severity ?: 'not set')) }}</td></tr>
  <tr><td class="k">First aid given</td><td class="v">{{ $incident->first_aid_administered ? 'Yes' : 'No' }}</td></tr>
  <tr><td class="k">Recorded by</td><td class="v">{{ $recName }}</td></tr>
  <tr><td class="k">Reported</td><td class="v">{{ $fmtAt($incident->created_at) }}</td></tr>
</table>

<div class="sec">What happened</div>
<div class="prose">{{ trim((string) $incident->description) ?: '(no description recorded)' }}</div>

<div class="sec">Action taken</div>
<div class="prose">{{ trim((string) $incident->action_taken) ?: '(none recorded)' }}</div>

@if(trim((string) ($incident->follow_up_required ?? '')) !== '')
  <div class="sec">Follow-up</div>
  <div class="prose">{{ $incident->follow_up_required }}</div>
@endif

<div class="sec">How this was handled</div>
<table class="kv">
  <tr><td class="k">Status</td><td class="v">{{ ucwords(str_replace('_', ' ', (string) $incident->status)) }}</td></tr>
  <tr><td class="k">Submitted</td><td class="v">{{ $fmtAt($incident->submitted_at) }}</td></tr>
  <tr><td class="k">Reviewed</td><td class="v">{{ $fmtAt($incident->reviewed_at ?? $incident->director_reviewed_at) }}</td></tr>
  <tr><td class="k">Parent notified</td><td class="v">{{ $fmtAt($incident->parent_notified_at) }}</td></tr>
  <tr><td class="k">Acknowledged</td><td class="v">{{ $fmtAt($incident->acknowledged_at ?? $incident->parent_acknowledged_at) }}</td></tr>
  <tr><td class="k">Closed</td><td class="v">{{ $fmtAt($incident->closed_at) }}</td></tr>
</table>

@if($interactions->count())
  <div class="sec">Contact with family and staff</div>
  <table class="notes">
    <tr><th style="width:88px;">When</th><th style="width:96px;">With</th><th style="width:70px;">How</th><th>What was discussed</th></tr>
    @foreach($interactions as $n)
      <tr>
        <td>{{ $fmtAt($n->created_at) }}</td>
        <td>{{ $n->contact_name ?: ucfirst((string) ($n->contact_with ?: '—')) }}</td>
        <td>{{ ucfirst(str_replace('_', ' ', (string) ($n->contact_method ?: '—'))) }}</td>
        <td>{{ $n->note }}</td>
      </tr>
    @endforeach
  </table>
@endif

@if($forParent)
  <div style="font-size:9.5px; color:#94A3B8; margin-top:16px; line-height:1.45;">
    This is your copy of the report. Notes our staff keep for internal follow-up are
    not included; ask us if you would like to know more about how this was handled.
  </div>
@endif

@if($plainNotes->count())
  <div class="sec">{{ $forParent ? 'Your comments on the record' : 'Notes on the record' }}</div>
  <table class="notes">
    <tr><th style="width:88px;">When</th><th style="width:120px;">Who</th><th>Note</th></tr>
    @foreach($plainNotes as $n)
      <tr>
        <td>{{ $fmtAt($n->created_at) }}</td>
        <td>{{ $n->author_name ?: '—' }}</td>
        <td>{{ $n->note }}</td>
      </tr>
    @endforeach
  </table>
@endif

{{-- Two attestations: the account of what happened, and the sign-off that it was
     handled. Captured in the app, stored with the record, printed together. --}}
<div class="sec">Signatures</div>
<table style="width:100%; border-collapse:collapse;">
  <tr>
    {{-- Who filed it --}}
    <td style="width:48%; vertical-align:bottom; padding:0 14px 0 0;">
      @if($incident->recorder_signature_data)
        {{-- Dompdf embeds a base64 data: URI inline; no remote fetch involved. --}}
        <img src="{{ $incident->recorder_signature_data }}"
             style="max-width:215px; max-height:68px;" alt="Signature">
        <div style="border-top:1px solid #0F172A; margin-top:2px; padding-top:3px; font-size:10px; color:#64748B;">
          <strong style="color:#0F172A;">{{ $incident->recorder_signed_name
              ?: trim(($recorder->first_name ?? '') . ' ' . ($recorder->last_name ?? '')) }}</strong><br>
          Recorded this report &middot; {{ $fmtAt($incident->recorder_signed_at) }}
        </div>
      @else
        <div style="border-top:1px solid #CBD5E1; padding-top:3px; font-size:10px; color:#64748B;">
          <strong style="color:#0F172A;">{{ trim(($recorder->first_name ?? '') . ' ' . ($recorder->last_name ?? '')) ?: 'Staff member' }}</strong><br>
          Recorded this report &middot; filed before signing was required
        </div>
      @endif
    </td>

    {{-- Who closed it --}}
    <td style="width:48%; vertical-align:bottom; padding:0 0 0 14px;">
      @if($incident->closer_signature_data)
        <img src="{{ $incident->closer_signature_data }}"
             style="max-width:215px; max-height:68px;" alt="Signature">
        <div style="border-top:1px solid #0F172A; margin-top:2px; padding-top:3px; font-size:10px; color:#64748B;">
          <strong style="color:#0F172A;">{{ $incident->closer_signed_name ?: 'Director' }}</strong><br>
          Reviewed and closed &middot; {{ $fmtAt($incident->closer_signed_at) }}
        </div>
      @else
        <div style="border-top:1px solid #CBD5E1; padding-top:3px; font-size:10px; color:#64748B;">
          <strong style="color:#0F172A;">Not yet closed</strong><br>
          This incident is still open &middot; status
          {{ ucwords(str_replace('_', ' ', (string) $incident->status)) }}
        </div>
      @endif
    </td>
  </tr>
</table>

<div style="font-size:9.5px; color:#94A3B8; margin-top:7px; line-height:1.45;">
  Signatures above were captured electronically in KiddieTrac and are stored with this record.
</div>

{{-- Room for a wet signature: this gets printed and put in a file. --}}
<table class="sign" style="width:100%;">
  <tr>
    <td style="width:46%;"><div class="rule"></div>Signature — reviewing director</td>
    <td style="width:8%;"></td>
    <td style="width:46%;"><div class="rule"></div>Date</td>
  </tr>
</table>

<div class="foot">
  {{ $providerName }} &middot; Incident report INC-{{ str_pad((string) $incident->id, 5, '0', STR_PAD_LEFT) }}
  &middot; All times {{ $tz }} &middot; Confidential — contains personal information about a child.
</div>

</body>
</html>
