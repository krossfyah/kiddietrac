"""Pages for what buyers search for, and two free tools (2026-09-30).

Anthony chose these from the SEO plan: landing pages that answer the searches childcare
buyers make, and calculators other sites link to.

    /childcare-software-canada   "childcare management software Canada"
    /cwelcc-billing-software     "CWELCC billing software"
    /daycare-software-ontario    "daycare software Ontario"
    /ratio-calculator            Ontario staff-to-child ratios (centres + licensed home child care)
    /cwelcc-calculator           Ontario parent fee under the $22/day cap

FACTS, and where they come from (checked 2026-09-30):
  - Centre ratios and group sizes, home child care limits: ontario.ca/page/child-care-rules-ontario
    (O. Reg. 137/15 under the Child Care and Early Years Act, 2014).
  - CWELCC: parent fees for eligible children under 6 capped at $22/day since January 1,
    2025; programs already below $22 stay at the fee they charged on December 31, 2024.
  - Product claims are features that exist in the portal today. No invented statistics,
    customers or claims about submitting to government.
"""

LINK = 'style="color:var(--teal);font-weight:800"'


def card(icon, title, body):
    return ('<div class="card"><div style="font-size:32px;margin-bottom:8px">%s</div>'
            '<h3 style="font-size:18px;font-weight:900;color:var(--dark);margin-bottom:6px">%s</h3>'
            '<p style="color:var(--gray);font-size:14.5px;line-height:1.65">%s</p></div>') % (icon, title, body)


def ticks(title, items):
    return ('<div class="card" style="margin-top:26px;padding:28px"><h2 style="font-size:22px;font-weight:900;color:var(--dark);margin-bottom:14px">%s</h2>'
            '<ul style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px 26px;font-size:14.5px;color:var(--dark);list-style:none;padding:0">%s</ul></div>') % (
        title, ''.join('<li style="display:flex;gap:10px"><span style="color:var(--green);font-weight:900">✓</span><span>%s</span></li>' % i for i in items))


def prose(title, paras):
    return ('<div style="max-width:780px;margin:36px auto 0"><h2 style="font-size:24px;font-weight:900;color:var(--dark);margin-bottom:12px">%s</h2>%s</div>') % (
        title, ''.join('<p style="color:var(--dark);font-size:15.5px;line-height:1.75;margin-bottom:12px">%s</p>' % p for p in paras))


def faq(items):
    return ('<div style="max-width:780px;margin:40px auto 0"><h2 style="font-size:24px;font-weight:900;color:var(--dark);margin-bottom:14px">Questions we hear</h2>%s</div>') % ''.join(
        '<details class="card" style="margin-bottom:10px;padding:16px 20px"><summary style="font-weight:800;color:var(--dark);cursor:pointer;font-size:15.5px">%s</summary>'
        '<p style="color:var(--gray);font-size:14.5px;line-height:1.7;margin-top:10px">%s</p></details>' % (q, a) for q, a in items)


def go(pid, label):
    return '<a href="/%s" onclick="showPage(\'%s\');return false;" %s>%s</a>' % (pid, pid, LINK, label)


def cta():
    return ('<div style="text-align:center;margin-top:44px"><button class="btn btn-secondary" onclick="showPage(\'contact\')" style="font-size:16px;padding:14px 28px">Book a demo →</button>'
            ' <button class="btn btn-secondary" onclick="showPage(\'pricing\')" style="font-size:16px;padding:14px 28px;margin-left:8px">See pricing</button>'
            '<div style="font-size:13px;color:var(--gray);margin-top:10px">Free 14-day trial · No credit card required · Free migration</div></div>')


def page(pid, label, h1, sub, body, img=None, alt=''):
    strip = ('<div class="k7-strip"><img src="/images/life/%s-1600.jpg" alt="%s" loading="lazy" decoding="async"></div>' % (img, alt)) if img else ''
    return ('<div class="page" id="page-%s">\n<div class="hero-band"><div class="container"><div class="section-label" style="color:var(--green-lt)">%s</div>'
            '<h1 class="section-title" style="color:white">%s</h1><p class="section-sub" style="color:rgba(255,255,255,.82);margin:0 auto">%s</p></div></div>\n%s'
            '<section class="section"><div class="container" style="max-width:1000px">%s</div></section>\n</div>\n') % (pid, label, h1, sub, strip, body)


CANADA = page(
    'childcare-software-canada', 'For Canadian child care',
    'Childcare management software built for Canada',
    'Attendance, billing, families and compliance in one place, in Canadian dollars, with your province’s fee reductions built into every invoice.',
    '<div class="cards-grid cards-3">'
    + card('🍁', 'Fee reductions on every invoice', 'Record each child’s fee reduction or subsidy once, whether it is CWELCC in Ontario or your own province’s program, and every invoice shows the full fee, the reduction and what the family pays.')
    + card('🧾', 'Billing in CAD, and USD too', 'Invoices, payment schedules, receipts and year-end childcare expense receipts in Canadian dollars, with USD for programs that also operate in the United States.')
    + card('💬', 'Families in their own language', 'The parent app shows daily reports, photos, messages and invoices, and parents can use it in English, French, Spanish or Hindi.')
    + '</div>'
    + ticks('Everything a Canadian program runs on', [
        'Check-in and check-out, including parent QR check-in at the door',
        'Safe Arrival alerts when a child has not arrived',
        'Live room counts against your staff-to-child ratios',
        'Daily reports, photos and two-way messages with families',
        'Immunization records, incidents and inspection checklists',
        'Staff time clock, schedules and payroll documents',
        'Observations and report cards linked to your provincial learning framework',
        'Split billing for separated parents, each with their own receipts',
        'Online forms and e-signatures for enrolment',
        'QuickBooks sync for your bookkeeper',
    ])
    + prose('Province by province', [
        'KiddieTrac is used by licensed centres, home child care agencies and independent providers. The parts that differ between provinces are built in as settings, not workarounds:',
        '<b>Learning frameworks.</b> Link observations and report cards to Ontario’s <i>How Does Learning Happen?</i> and ELECT, the British Columbia Early Learning Framework, Alberta’s Flight, Quebec’s <i>Accueillir la petite enfance</i>, or your own.',
        '<b>Fee reductions and subsidies.</b> Set up your province’s reduced parent fee or a family’s subsidy per child, and invoices deduct it automatically. Ontario programs get CWELCC handled end to end: see ' + go('cwelcc-billing-software', 'CWELCC billing') + '.',
        '<b>Your data.</b> KiddieTrac is hosted in North America with encryption, two-factor sign-in and a full audit log. If your organization needs data stored in Canada, talk to us before you sign up.',
    ])
    + faq([
        ('Can any province use KiddieTrac?', 'Yes. Centres, home child care agencies and independent providers anywhere in Canada use the same platform; provincial differences such as learning frameworks and fee reductions are settings.'),
        ('Can we move from our current software?', 'Yes. We import children, families and staff from a spreadsheet or an export from your current system, and migration is free on every plan.'),
        ('Does it work for home child care agencies?', 'Yes. Agencies manage their providers, home visitors file their visit reports, and providers run attendance, billing and parent updates from their phone.'),
        ('Is there a free trial?', 'Yes, 14 days with no credit card. ' + go('contact', 'Book a demo') + ' and we will set it up with you.'),
    ])
    + cta(), 'chalk-outdoors', 'An educator drawing with chalk outdoors with a toddler')

CWELCC = page(
    'cwelcc-billing-software', 'CWELCC for Ontario programs',
    'CWELCC billing software for Ontario child care',
    'Apply the fee reduction on every invoice, keep parent fees within Ontario’s $22-a-day cap, and keep the records your reporting is built from.',
    '<div class="cards-grid cards-3">'
    + card('📉', 'The reduction on every invoice', 'Mark each child’s CWELCC eligibility once. Every invoice shows the full fee, the CWELCC reduction and what the family pays, so parents can see the saving.')
    + card('📊', 'Records for your reporting', 'Enrolment, attendance and fees by child and by period, ready to export when your service system manager asks for them.')
    + card('🧾', 'Receipts parents can use', 'Families see every invoice and payment in the parent app, and each parent gets a year-end childcare expense receipt, even when the bill is split.')
    + '</div>'
    + prose('How the $22-a-day cap works', [
        'Since January 1, 2025, parent fees for CWELCC-eligible children under six in participating Ontario programs have been capped at $22 a day. A program that was already charging less keeps the lower fee it charged on December 31, 2024.',
        'Try the numbers for your own program with the free ' + go('cwelcc-calculator', 'CWELCC fee calculator') + ', or read ' + '<a href="/blog/cwelcc-explained-ontario" %s>CWELCC explained for Ontario operators</a>.' % LINK,
    ])
    + ticks('What KiddieTrac does for CWELCC programs', [
        'Eligibility per child, with the reduction applied automatically',
        'Invoices that show full fee, reduction and parent fee',
        'Families under and over six billed correctly side by side',
        'Provincial and municipal fee subsidies recorded per child',
        'Attendance and enrolment exports for your reporting',
        'Split family billing, each parent paying their share',
        'Year-end childcare expense receipts for every parent',
        'A free printable CWELCC compliance checklist',
    ])
    + faq([
        ('What is CWELCC?', 'The Canada-Wide Early Learning and Child Care system: federal and provincial funding that lowers parent fees for children under six in participating licensed programs.'),
        ('Does KiddieTrac submit our CWELCC claims?', 'No. KiddieTrac keeps the enrolment, attendance and fee records your reports are built from and exports them; you submit through your service system manager’s own process.'),
        ('Do we need a separate system for families not in CWELCC?', 'No. Children over six and programs or families outside CWELCC are billed in the same place, at their own rates.'),
        ('Where can I check the rules?', 'Your service system manager (your municipality or district services board) sets your funding and reporting. Ontario’s own summary is at <a href="https://www.ontario.ca/page/child-care-rules-ontario" rel="noopener" target="_blank" %s>ontario.ca</a>.' % LINK),
    ])
    + '<div style="text-align:center;margin-top:30px">' + go('checklist', 'Get the free CWELCC compliance checklist →') + '</div>'
    + cta(), 'classroom-tables', 'Children working at tables in a bright classroom')

ONTARIO = page(
    'daycare-software-ontario', 'For Ontario child care',
    'Daycare software for Ontario centres and home child care agencies',
    'Built around how Ontario child care works: Child Care and Early Years Act ratios, CWELCC fees, How Does Learning Happen? and the records a licensing visit asks for.',
    '<div class="cards-grid cards-3">'
    + card('👩‍🏫', 'Ratios you can see', 'Live counts in every room against Ontario’s staff-to-child ratios, so you know before a room goes over. Check a room with the free ' + go('ratio-calculator', 'ratio calculator') + '.')
    + card('📁', 'Records ready for licensing', 'Attendance, immunizations, incidents, staff records and an inspection checklist, kept in one place instead of binders.')
    + card('🌱', 'How Does Learning Happen? built in', 'Link observations and report cards to belonging, well-being, engagement and expression, and see which areas each child has had least of.')
    + '</div>'
    + ticks('For centres, agencies and home providers', [
        'CWELCC reductions applied on every invoice',
        'Parent QR check-in and Safe Arrival alerts',
        'Room placement, waitlists and transitions',
        'Home visitor reports and Ontario home child care inspection forms',
        'Staff time clock, schedules and payroll documents',
        'Daily reports, photos and messages for families, in English or French',
        'Online enrolment forms with e-signatures',
        'Holiday and closure calendars across every location',
    ])
    + prose('One platform from one home to many centres', [
        'A home child care agency manages its providers and home visitors; a provider runs attendance, billing and parent updates from a phone; a multi-site operator sees every centre from one login. See ' + go('home-daycare', 'home daycare software') + ', ' + go('centre', 'centre software') + ' and ' + go('multi-site', 'multi-site management') + '.',
    ])
    + faq([
        ('Does KiddieTrac know Ontario’s ratios?', 'Yes. Rooms show live counts against the Child Care and Early Years Act ratios for infant, toddler, preschool, kindergarten and school-age groups.'),
        ('Is it available in French?', 'Yes. Families can use the parent app in French, and this website is available in French too.'),
        ('Can a home child care agency use it?', 'Yes. Agencies, home visitors and providers each have their own view, including home-visit reports and Ontario’s home child care inspection forms.'),
        ('Where is our data stored?', 'KiddieTrac is hosted in North America with encryption, two-factor sign-in and a full audit log. If you need data stored in Canada, talk to us before you sign up.'),
    ])
    + cta(), 'wooden-blocks', 'A child building with wooden blocks')


# ── the ratio calculator ────────────────────────────────────────────────────────────
GROUPS = [  # (key, name, ages, staff, children, max group) — ontario.ca, checked 2026-09-30
    ('infant', 'Infant', 'under 18 months', 3, 10, 10),
    ('toddler', 'Toddler', '18 to 30 months', 1, 5, 15),
    ('preschool', 'Preschool', '30 months to 6 years', 1, 8, 24),
    ('kindergarten', 'Kindergarten', '44 months to 7 years', 1, 13, 26),
    ('primary', 'Primary/junior school age', '68 months to 13 years', 1, 15, 30),
    ('junior', 'Junior school age', '9 to 13 years', 1, 20, 20),
]
INP = 'style="width:90px;padding:8px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:15px;text-align:center"'
TD = 'style="padding:10px 12px;border-bottom:1px solid #EEF2F7"'
TH = 'style="padding:10px 12px;text-align:left;font-size:12.5px;color:var(--gray);border-bottom:2px solid #E2E8F0"'

RATIO = page(
    'ratio-calculator', 'Free tool',
    'Ontario child care ratio calculator',
    'Enter how many children you have in each age group and see how many staff and groups Ontario’s ratios require, for centres and for licensed home child care.',
    '<div class="card" style="padding:24px;overflow-x:auto"><h2 style="font-size:21px;font-weight:900;color:var(--dark);margin-bottom:4px">Licensed child care centre</h2>'
    '<p style="color:var(--gray);font-size:14px;margin-bottom:14px">Staff needed for each age group, with children split into the fewest groups the maximum group size allows.</p>'
    '<table id="ktRatioTable" style="width:100%;border-collapse:collapse;font-size:14.5px;min-width:640px"><thead><tr>'
    '<th ' + TH + '>Age group</th><th ' + TH + '>Ratio</th><th ' + TH + '>Max group</th><th ' + TH + '>Children</th><th ' + TH + '>Groups</th><th ' + TH + '>Staff needed</th></tr></thead><tbody>'
    + ''.join('<tr><td %s><b>%s</b><div style="font-size:12.5px;color:var(--gray)">%s</div></td><td %s>%s to %s</td><td %s>%s</td>'
              '<td %s><input type="number" min="0" max="999" value="0" data-kt-ratio="%s" data-s="%s" data-c="%s" data-m="%s" aria-label="Number of children" %s></td>'
              '<td %s data-kt-groups="%s">0</td><td %s><b data-kt-staff="%s">0</b></td></tr>' % (
                  TD, n, ages, TD, s, c, TD, m, TD, k, s, c, m, INP, TD, k, TD, k) for k, n, ages, s, c, m in GROUPS)
    + '</tbody><tfoot><tr><td ' + TD + ' colspan="3"><b>Total</b></td><td ' + TD + '><b id="ktRatioKids">0</b></td><td ' + TD + '><b id="ktRatioGroups">0</b></td><td ' + TD + '><b id="ktRatioStaff" style="font-size:18px;color:var(--teal)">0</b></td></tr></tfoot></table></div>'
    + '<div class="card" style="padding:24px;margin-top:22px"><h2 style="font-size:21px;font-weight:900;color:var(--dark);margin-bottom:4px">Licensed home child care</h2>'
    '<p style="color:var(--gray);font-size:14px;margin-bottom:14px">At most 6 children under 13, of whom at most 3 are under 2. The provider’s own children under 4 count.</p>'
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">'
    '<label style="font-weight:700;font-size:14px;color:var(--dark)">Children under 2<br><input type="number" min="0" max="20" value="0" id="ktHccU2" ' + INP + '></label>'
    '<label style="font-weight:700;font-size:14px;color:var(--dark)">Children 2 to 12<br><input type="number" min="0" max="20" value="0" id="ktHccOver" ' + INP + '></label>'
    '<label style="font-weight:700;font-size:14px;color:var(--dark)">Provider’s own children under 4<br><input type="number" min="0" max="10" value="0" id="ktHccOwn" ' + INP + '></label>'
    '</div><div id="ktHccResult" style="margin-top:16px;padding:14px 16px;border-radius:12px;background:#ECFDF5;color:#065F46;font-weight:800">Within the limits.</div>'
    '<div id="ktHccOverTotal" style="display:none;margin-top:8px;padding:12px 16px;border-radius:12px;background:#FEF2F2;color:#991B1B;font-weight:800">Over the limit of 6 children under 13.</div>'
    '<div id="ktHccOverU2" style="display:none;margin-top:8px;padding:12px 16px;border-radius:12px;background:#FEF2F2;color:#991B1B;font-weight:800">Over the limit of 3 children under 2.</div></div>'
    + prose('Where these numbers come from', [
        'Ratios, group sizes and home child care limits are from Ontario Regulation 137/15 under the Child Care and Early Years Act, 2014, as summarized at <a href="https://www.ontario.ca/page/child-care-rules-ontario" rel="noopener" target="_blank" %s>ontario.ca</a> (checked September 30, 2026).' % LINK,
        'Mixed-age groups, reduced ratios at certain times of day and your licence’s own conditions have rules of their own. This calculator is a planning aid, not a licensing decision: check with your program advisor.',
        'In KiddieTrac, every room shows its live count against these ratios all day. ' + go('daycare-software-ontario', 'See how it works for Ontario programs') + '.',
    ])
    + cta())

CALC = page(
    'cwelcc-calculator', 'Free tool',
    'CWELCC fee calculator for Ontario',
    'See what families pay under Ontario’s $22-a-day cap, what they save against your full fee, and your monthly parent-fee revenue.',
    '<div class="card" style="padding:24px"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px">'
    '<label style="font-weight:700;font-size:14px;color:var(--dark)">Daily parent fee you charged on December 31, 2024 ($)<br><input type="number" min="0" step="0.01" value="30" id="ktCwDec" ' + INP.replace('90px', '130px') + '></label>'
    '<label style="font-weight:700;font-size:14px;color:var(--dark)">Your full daily fee without CWELCC ($, optional)<br><input type="number" min="0" step="0.01" value="" id="ktCwFull" ' + INP.replace('90px', '130px') + '></label>'
    '<label style="font-weight:700;font-size:14px;color:var(--dark)">Days of care per month<br><input type="number" min="1" max="31" value="21" id="ktCwDays" ' + INP + '></label>'
    '<label style="font-weight:700;font-size:14px;color:var(--dark)">Eligible children (for revenue)<br><input type="number" min="1" max="999" value="1" id="ktCwKids" ' + INP + '></label>'
    '<label style="font-weight:700;font-size:14px;color:var(--dark)">Daily cap ($)<br><input type="number" min="0" step="0.01" value="22" id="ktCwCap" ' + INP + '></label>'
    '</div></div>'
    '<div class="cards-grid cards-3" style="margin-top:22px">'
    '<div class="card" style="text-align:center"><div style="font-size:13px;color:var(--gray);font-weight:700">Parent fee per day</div><div id="ktCwDay" style="font-size:34px;font-weight:900;color:var(--teal)">$22.00</div></div>'
    '<div class="card" style="text-align:center"><div style="font-size:13px;color:var(--gray);font-weight:700">Per month, per child</div><div id="ktCwMonth" style="font-size:34px;font-weight:900;color:var(--dark)">$462.00</div></div>'
    '<div class="card" style="text-align:center"><div style="font-size:13px;color:var(--gray);font-weight:700">Per year, per child</div><div id="ktCwYear" style="font-size:34px;font-weight:900;color:var(--dark)">$5,544.00</div></div>'
    '</div>'
    '<div class="cards-grid cards-3" style="margin-top:18px">'
    '<div class="card" style="text-align:center"><div style="font-size:13px;color:var(--gray);font-weight:700">Family saving per month</div><div id="ktCwSave" style="font-size:26px;font-weight:900;color:var(--green)">–</div><div style="font-size:12px;color:var(--gray)">against your full fee</div></div>'
    '<div class="card" style="text-align:center"><div style="font-size:13px;color:var(--gray);font-weight:700">Parent-fee revenue per month</div><div id="ktCwRev" style="font-size:26px;font-weight:900;color:var(--dark)">$462.00</div><div style="font-size:12px;color:var(--gray)">all eligible children</div></div>'
    '<div class="card" style="text-align:center"><div style="font-size:13px;color:var(--gray);font-weight:700">Gap to your full fee per month</div><div id="ktCwGap" style="font-size:26px;font-weight:900;color:var(--dark)">–</div><div style="font-size:12px;color:var(--gray)">covered under your CWELCC funding agreement</div></div>'
    '</div>'
    + prose('How this is worked out', [
        'Since January 1, 2025, parent fees for CWELCC-eligible children under six in participating Ontario programs have been capped at $22 a day. A program that was already charging less keeps the fee it charged on December 31, 2024. So the parent fee is the lower of your December 31, 2024 fee and the cap.',
        'The gap to your full fee is shown for planning only: your actual funding is set by your service system manager under your CWELCC agreement. If the cap changes, change the cap above.',
        'KiddieTrac applies the reduction on every invoice automatically. ' + go('cwelcc-billing-software', 'See CWELCC billing') + '.',
    ])
    + cta())

SCRIPT = r"""<script>/* Free tools: ratio calculator + CWELCC fee calculator (growth_edits.py). */
(function () {
  function num(el) { var v = parseFloat(el && el.value); return isFinite(v) && v > 0 ? v : 0; }
  function ratio() {
    var kids = 0, groups = 0, staff = 0;
    document.querySelectorAll('[data-kt-ratio]').forEach(function (i) {
      var n = Math.floor(num(i)), s = +i.getAttribute('data-s'), c = +i.getAttribute('data-c'), m = +i.getAttribute('data-m'), k = i.getAttribute('data-kt-ratio');
      var g = n ? Math.ceil(n / m) : 0, st = 0;
      // Children split into g groups as evenly as possible; each group meets the ratio on its own.
      for (var j = 0; j < g; j++) { var size = Math.floor(n / g) + (j < n % g ? 1 : 0); st += Math.ceil(size * s / c); }
      document.querySelector('[data-kt-groups="' + k + '"]').textContent = g;
      document.querySelector('[data-kt-staff="' + k + '"]').textContent = st;
      kids += n; groups += g; staff += st;
    });
    var set = function (id, v) { var e = document.getElementById(id); if (e) e.textContent = v; };
    set('ktRatioKids', kids); set('ktRatioGroups', groups); set('ktRatioStaff', staff);
  }
  function hcc() {
    var u2 = Math.floor(num(document.getElementById('ktHccU2'))), over = Math.floor(num(document.getElementById('ktHccOver'))), own = Math.floor(num(document.getElementById('ktHccOwn')));
    var total = u2 + over + own, a = total > 6, b = u2 > 3;
    document.getElementById('ktHccOverTotal').style.display = a ? '' : 'none';
    document.getElementById('ktHccOverU2').style.display = b ? '' : 'none';
    document.getElementById('ktHccResult').style.display = (a || b) ? 'none' : '';
  }
  // Money in the page's language: $22.00 in English, 22,00 $ in French.
  function money(v) { var fr = /^fr/.test(document.documentElement.lang || ''); try { return v.toLocaleString(fr ? 'fr-CA' : 'en-CA', { style: 'currency', currency: 'CAD', currencyDisplay: 'narrowSymbol' }); } catch (e) { return '$' + v.toFixed(2); } }
  function cw() {
    var dec = num(document.getElementById('ktCwDec')), full = num(document.getElementById('ktCwFull')), days = num(document.getElementById('ktCwDays')) || 21;
    var kids = Math.max(1, Math.floor(num(document.getElementById('ktCwKids')))), cap = num(document.getElementById('ktCwCap'));
    var fee = dec && cap ? Math.min(dec, cap) : (dec || cap);
    var set = function (id, v) { var e = document.getElementById(id); if (e) e.textContent = v; };
    set('ktCwDay', money(fee)); set('ktCwMonth', money(fee * days)); set('ktCwYear', money(fee * days * 12));
    set('ktCwRev', money(fee * days * kids));
    set('ktCwSave', full > fee ? money((full - fee) * days) : '–');
    set('ktCwGap', full > fee ? money((full - fee) * days * kids) : '–');
  }
  function bind() {
    document.querySelectorAll('[data-kt-ratio]').forEach(function (i) { i.addEventListener('input', ratio); });
    ['ktHccU2', 'ktHccOver', 'ktHccOwn'].forEach(function (id) { var e = document.getElementById(id); if (e) e.addEventListener('input', hcc); });
    ['ktCwDec', 'ktCwFull', 'ktCwDays', 'ktCwKids', 'ktCwCap'].forEach(function (id) { var e = document.getElementById(id); if (e) e.addEventListener('input', cw); });
    ratio(); hcc(); cw();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind); else bind();
})();
</script>
"""

META_EN = {
    'childcare-software-canada': ('Childcare Management Software for Canada | KiddieTrac',
                                  'Childcare management software built for Canada: attendance, CAD billing with provincial fee reductions, parent app, ratios and compliance. Free 14-day trial.'),
    'cwelcc-billing-software': ('CWELCC Billing Software for Ontario Child Care | KiddieTrac',
                                'Apply CWELCC fee reductions on every invoice, stay within Ontario’s $22/day cap and keep the records your reporting needs. Free 14-day trial.'),
    'daycare-software-ontario': ('Daycare Software for Ontario Centres & Home Child Care | KiddieTrac',
                                 'Daycare software built for Ontario: CCEYA ratios, CWELCC billing, How Does Learning Happen? and licensing-ready records for centres and home child care agencies.'),
    'ratio-calculator': ('Ontario Child Care Ratio Calculator (Free) | KiddieTrac',
                         'Free calculator: how many staff and groups Ontario’s child care ratios require for infants, toddlers, preschool, kindergarten and school age, plus home child care limits.'),
    'cwelcc-calculator': ('CWELCC Fee Calculator for Ontario (Free) | KiddieTrac',
                          'Free calculator: what families pay under Ontario’s $22/day CWELCC cap, their saving against your full fee, and your monthly parent-fee revenue.'),
}


def growth(out):
    def one(o, n):
        nonlocal out
        assert out.count(o) == 1, ('growth_edits', o[:70], out.count(o))
        out = out.replace(o, n)

    # the pages, before the blog page
    one('<div class="page" id="page-blog">', CANADA + CWELCC + ONTARIO + RATIO + CALC + '<div class="page" id="page-blog">')

    # English titles/descriptions in the router's table (fr/es/hi are in seo_meta.json)
    entries = ''.join('    "%s":{t:"%s",d:"%s"},\n' % (k, t.replace('"', '\\"'), d.replace('"', '\\"')) for k, (t, d) in META_EN.items())
    one('    privacy:{t:"Privacy Policy | KiddieTrac"', entries + '    privacy:{t:"Privacy Policy | KiddieTrac"')

    # menus: desktop (real links), mobile, footer
    one('<a href="/webinars" class="nav-dd-item" onclick="showPage(\'webinars\');return false;">🎥 Webinars</a>',
        '<a href="/webinars" class="nav-dd-item" onclick="showPage(\'webinars\');return false;">🎥 Webinars</a>'
        '<a href="/ratio-calculator" class="nav-dd-item" onclick="showPage(\'ratio-calculator\');return false;">🧮 Ratio calculator</a>'
        '<a href="/cwelcc-calculator" class="nav-dd-item" onclick="showPage(\'cwelcc-calculator\');return false;">💲 CWELCC fee calculator</a>')
    one('<button class="mob-link" onclick="showPage(\'webinars\');closeMobile()">🎥 Webinars</button>',
        '<button class="mob-link" onclick="showPage(\'webinars\');closeMobile()">🎥 Webinars</button>'
        '<button class="mob-link" onclick="showPage(\'ratio-calculator\');closeMobile()">🧮 Ratio calculator</button>'
        '<button class="mob-link" onclick="showPage(\'cwelcc-calculator\');closeMobile()">💲 CWELCC fee calculator</button>')
    one('<li onclick="showPage(\'webinars\')">Webinars</li>',
        '<li onclick="showPage(\'webinars\')">Webinars</li>'
        '<li><a href="/ratio-calculator" onclick="showPage(\'ratio-calculator\');return false;">Ratio calculator</a></li>'
        '<li><a href="/cwelcc-calculator" onclick="showPage(\'cwelcc-calculator\');return false;">CWELCC fee calculator</a></li>'
        '<li><a href="/childcare-software-canada" onclick="showPage(\'childcare-software-canada\');return false;">Childcare software for Canada</a></li>'
        '<li><a href="/cwelcc-billing-software" onclick="showPage(\'cwelcc-billing-software\');return false;">CWELCC billing software</a></li>'
        '<li><a href="/daycare-software-ontario" onclick="showPage(\'daycare-software-ontario\');return false;">Daycare software for Ontario</a></li>')

    i = out.rfind('</body>')
    out = out[:i] + SCRIPT + out[i:]
    return out
