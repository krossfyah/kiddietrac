# -*- coding: utf-8 -*-
"""Ontario features on the marketing site (2026-10-13, Anthony: "add the new features we added
for ontario to the marketing site somewhere" -> chose "Ontario page + changelog + directory").

Facts come from the 2026-10-13 commits and their help articles (75e03e14 workforce funding,
b4d79ebe OCCMS ROA, c9966731 ratio rules, 9e5fff83 policies, 55196711 sleep checks, 2dcac8cd
missing-child drills + hazards, 87df5541 headcounts + acting supervisor). Nothing here claims
Ministry approval; the page says KiddieTrac is not affiliated with the Government of Ontario.

Adds
  /daycare-software-ontario  "Built for Ontario's rules": 7 cards with the O. Reg. 137/15 refs
  /changelog                 October 2026 entry, linking to the Ontario page
  home directory             Headcounts, Sleep room checks, Missing-child drills, Hazard register,
                             Policy sign-offs, Training matrix, ECE workforce funding, OCCMS reporting

usage: python ontario_edits.py in.html out.html
"""
import sys

GO = 'href="/daycare-software-ontario" onclick="showPage(\'daycare-software-ontario\');return false;"'

CARDS = [
    ('👩‍🏫', 'Ontario ratio rules',
     'Each room at a licensed centre is checked against Schedule 1: ratios, maximum group sizes, mixed-age groups, the reduced ratios allowed at arrival, departure and rest times, family age groups, and the share of RECEs each group needs. Directors and educators see the same live result.',
     'O. Reg. 137/15 ss.8, 8.1, 54 and Schedules 1 and 4'),
    ('👀', 'Headcounts and the acting supervisor',
     'Educators count children name-to-face from their phone, in the room or on a walk. If a child is not seen, directors, admins and the acting supervisor get an urgent alert, and the serious occurrence report is pre-filled. Each centre names who acts for the supervisor when they are away.',
     'O. Reg. 137/15 s.11 and s.6.1(2)'),
    ('😴', 'Sleep room checks',
     'A countdown for every sleeping child under 24 months at the interval in your sleep policy, an alert to the room’s educators and then the director when a check is overdue, physician sleep-position directions on the child’s record, and a report for your inspector.',
     'O. Reg. 137/15 s.33.1'),
    ('🚨', 'Missing-child drills and hazards',
     'Log missing-child drills with the area, the scenario and the time to locate the child, and see when the next one is due every 3 months, next to your monthly fire drills. Educators report hazards; directors record what was done and tell current families by email and in the app.',
     'O. Reg. 137/15 s.68, s.68.2 and s.24.3'),
    ('📚', 'Policies, sign-offs and training',
     'Publish your policies, and staff read them and sign with their full name. A new version asks everyone to sign again. Record training and contraventions, export a training matrix, and start from a checklist of Ontario policy titles.',
     'O. Reg. 137/15 s.6.1'),
    ('🧾', 'OCCMS fee subsidy reporting',
     'KiddieTrac prepares the monthly Record of Attendance for children with a fee subsidy from check-ins, absences and closures, tracks the absence allowance, and has a second person sign it off. Print it laid out like the OCCMS form to key in, plus a CWELCC monthly summary by age group.',
     'Fee subsidy and CWELCC'),
    ('💵', 'ECE workforce funding in payroll',
     'The Wage Enhancement Grant and CWELCC workforce compensation are worked out per person and shown on payslips as separate lines. Each funding payment is checked against the 32-day rule, and a year-end reconciliation compares funding received with wages paid.',
     'WEG and CWELCC'),
]


def card(icon, title, body, ref):
    return ('<div class="card on-rule">'
            '<div class="on-rule-i" aria-hidden="true">%s</div>'
            '<h3>%s</h3>'
            '<p>%s</p>'
            '<div class="on-rule-ref">%s</div>'
            '</div>') % (icon, title, body, ref)


SECTION = ('<style>'
           '.on-rules{margin-top:34px}'
           '.on-rules-h{text-align:center;max-width:760px;margin:0 auto 20px}'
           '.on-rules-h h2{font-size:26px;font-weight:900;color:var(--dark);margin:6px 0 10px}'
           '.on-rules-h p{color:var(--gray);font-size:15.5px;line-height:1.7}'
           '.on-rules-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}'
           '.on-rule{padding:22px;display:flex;flex-direction:column}'
           '.on-rule-i{font-size:28px;margin-bottom:6px}'
           '.on-rule h3{font-size:17px;font-weight:900;color:var(--dark);margin-bottom:6px}'
           '.on-rule p{color:var(--gray);font-size:14.5px;line-height:1.65;flex:1}'
           '.on-rule-ref{margin-top:12px;font-size:12px;font-weight:800;color:#047857;background:#ECFDF5;border-radius:999px;padding:4px 12px;align-self:flex-start}'
           '.on-rules-note{margin:16px auto 0;max-width:760px;text-align:center;font-size:13px;color:var(--gray);line-height:1.6}'
           '</style>'
           '<div class="on-rules" id="ontario-rules">'
           '<div class="on-rules-h">'
           '<div class="section-label">New for Ontario</div>'
           '<h2>Built for Ontario’s rules</h2>'
           '<p>KiddieTrac now keeps the records Ontario’s child care regulation, O. Reg. 137/15, asks for, including the changes that take effect on January 1, 2027.</p>'
           '</div>'
           '<div class="on-rules-g">' + ''.join(card(*c) for c in CARDS) + '</div>'
           '<p class="on-rules-note">Most of these are switched on for each agency or location. KiddieTrac keeps the records and sends the reminders; meeting the regulation stays with your program. KiddieTrac is not affiliated with the Government of Ontario.</p>'
           '</div>')

LOG = ('<div class="kt-log-item">'
       '<div class="kt-log-date">October 2026 <span class="kt-log-new">NEW</span>\n</div>'
       '<div class="kt-log-body">'
       '<h3>🍁 Built for Ontario’s rules</h3>'
       '<p>For Ontario centres and agencies: live ratio checks against O. Reg. 137/15, sleep room checks, OCCMS fee subsidy reporting and ECE workforce funding in payroll, plus tools for the January 2027 changes: name-to-face headcounts with an acting supervisor, missing-child drills, a hazard register and policy sign-offs with a training matrix. '
       '<a ' + GO + ' style="color:var(--teal);font-weight:800">See what’s built for Ontario</a></p>'
       '</div></div>\n        ')


def li_link(text):
    return '<li>\n<a ' + GO + ' style="color:#047857">' + text + '</a>\n</li>'


def sub(seg, a, b):
    assert seg.count(a) == 1, (a[:80], seg.count(a))
    return seg.replace(a, b)


def apply(s):
    assert 'id="ontario-rules"' not in s, 'already applied'
    # 1. the Ontario page
    i = s.index('id="page-daycare-software-ontario"')
    j = s.index('<div class="page"', i)
    seg = s[i:j]
    seg = sub(seg, '<div class="card" style="margin-top:26px;padding:28px">',
              SECTION + '<div class="card" style="margin-top:26px;padding:28px">')
    s = s[:i] + seg + s[j:]

    # 2. the changelog
    i = s.index('id="page-changelog"')
    j = s.index('<div class="kt-log-item">', i)
    assert s.index('<div class="kt-log">', i) < j
    s = s[:j] + LOG + s[j:]

    # 3. the directory on the home page
    i = s.index('<section class="k6-dir">')
    j = s.index('</section>', i)
    seg = s[i:j]
    seg = sub(seg, '<li>Room ratios</li>', '<li>Room ratios</li>\n<li>Headcounts</li>\n<li>Sleep room checks</li>')
    seg = sub(seg, '<li>Incident reports</li>', '<li>Incident reports</li>\n<li>Missing-child drills</li>\n<li>Hazard register</li>')
    seg = sub(seg, '<li>Roles &amp; permissions</li>',
              '<li>Roles &amp; permissions</li>\n<li>Policy sign-offs</li>\n<li>Training matrix</li>\n' + li_link('ECE workforce funding'))
    seg = sub(seg, '<li>CWELCC subsidies</li>', '<li>CWELCC subsidies</li>\n' + li_link('OCCMS reporting'))
    s = s[:i] + seg + s[j:]
    return s


if __name__ == '__main__':
    src = open(sys.argv[1], encoding='utf-8').read()
    out = apply(src)
    with open(sys.argv[2], 'w', encoding='utf-8', newline='') as f:
        f.write(out)
    print('ok', len(src), '->', len(out))
