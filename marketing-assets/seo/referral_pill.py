# -*- coding: utf-8 -*-
"""A small referral pill in the home hero (2026-10-13, Anthony: "quick splash of referral
program on main page (nothing big)"). One line under the hero ticks, linking to /referrals.
usage: python referral_pill.py in.html out.html"""
import sys

CSS = """<style>
/* ── Referral pill in the home hero (referral_pill.py) ── */
.k6-refpill{display:inline-flex;align-items:center;gap:8px;margin:16px 0 0;padding:7px 14px 7px 10px;border-radius:999px;
  background:linear-gradient(120deg,rgba(251,191,36,.16),rgba(249,115,22,.14));border:1px solid rgba(251,191,36,.45);
  color:#fde68a;font:700 14px 'Nunito',sans-serif;text-decoration:none;transition:background .18s,transform .18s}
.k6-refpill b{color:#fff;font-weight:900}
.k6-refpill em{font-style:normal;color:#fbbf24;font-weight:800;white-space:nowrap}
.k6-refpill:hover{background:linear-gradient(120deg,rgba(251,191,36,.26),rgba(249,115,22,.22));transform:translateY(-1px)}
@media (max-width:640px){.k6-refpill{font-size:13px;flex-wrap:wrap;row-gap:2px}}
@media (prefers-reduced-motion:reduce){.k6-refpill{transition:none}.k6-refpill:hover{transform:none}}
</style>"""

PILL = ('<a class="k6-refpill" href="/referrals" onclick="showPage(\'referrals\');return false;">'
        '🎁 <b>Give a month. Get $200.</b> <em>Refer a program →</em></a>')

ANCHOR = '<div class="k6-ticks"><span>No credit card required</span><span>Free data migration</span><span>Cancel anytime</span></div>'


def apply(s):
    if 'class="k6-refpill"' in s:
        return s
    assert s.count(ANCHOR) == 1, ('anchor', s.count(ANCHOR))
    s = s.replace(ANCHOR, ANCHOR + '\n      ' + PILL, 1)
    # CSS right after the home hero's own stylesheet opens (the first .k6 style block)
    i = s.index('id="page-home"')
    j = s.index('</style>', i) + len('</style>')
    return s[:j] + CSS + s[j:]


if __name__ == '__main__':
    out = apply(open(sys.argv[1], encoding='utf-8').read())
    open(sys.argv[2], 'w', encoding='utf-8', newline='').write(out)
    print('pill added', out.count('class="k6-refpill"'))
