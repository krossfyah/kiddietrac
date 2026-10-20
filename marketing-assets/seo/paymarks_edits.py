# -*- coding: utf-8 -*-
"""Card brand acceptance marks wherever the site talks about payments (2026-10-20).

Anthony: "use card brand logos where you can with anything related to payments", with the
official marks (Visa, Mastercard, Interac), unaltered, as acceptance marks. The artwork is the
site's existing /images/brands/{visa,mastercard,interac}.svg. Five places:

  1. the Wallet card-payments section: logo chips replace the text badges
  2. the hero's "Invoice paid" bubble: the Interac mark instead of a card emoji
  3. "New in KiddieTrac" > card payments: Visa + Mastercard beside the processors
  4. FAQ "Which payment methods…": a row of marks under the answer
  5. "Invoices that send themselves": a row of marks under the card text

Marks sit on white chips at a legible size; no alt text that needs translating except the
brand names, which are the same in every language.

usage: python paymarks_edits.py in.html out.html
"""
import io
import sys

MARKS = ('<span class="pm-row" translate="no">'
         '<span class="pm-chip"><img src="/images/brands/visa.svg" alt="Visa" loading="lazy"></span>'
         '<span class="pm-chip"><img src="/images/brands/mastercard.svg" alt="Mastercard" loading="lazy"></span>'
         '<span class="pm-chip"><img src="/images/brands/interac.svg" alt="Interac" loading="lazy"></span>'
         '</span>')

CSS = ('<style>.pm-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px}'
       '.pm-chip{display:inline-flex;align-items:center;justify-content:center;height:38px;min-width:60px;padding:0 12px;background:#fff;'
       'border:1px solid #E2E8F0;border-radius:10px;box-shadow:0 2px 6px rgba(15,23,42,.06)}'
       '.pm-chip img{height:22px;width:auto;display:block}'
       '.pm-chip img[src*="interac"]{height:30px}'
       '</style>')

EDITS = [
    # 1. Wallet section: text badges -> logo chips (the section's own CSS still styles the row)
    ('<div class="cpw-brands" translate="no"><span>VISA</span><span>Mastercard</span><span>Interac Debit</span></div>',
     CSS + '<div class="cpw-brands" translate="no">' + MARKS.replace('<span class="pm-row" translate="no">', '').rsplit('</span>', 1)[0] + '</div>'),
    # 2. hero bubble
    ('<div class="k6-toast" style="top:30%"><i style="background:#e5f7fb">💳</i>',
     '<div class="k6-toast" style="top:30%"><i style="background:#fff"><img src="/images/brands/interac.svg" alt="" style="width:26px;height:26px;display:block"></i>'),
    # 3. "New in KiddieTrac" card payments
    ('<img src="/images/brands/zumrails.svg" alt="Zūm Rails" style="height:16px"></div></div>',
     '<img src="/images/brands/zumrails.svg" alt="Zūm Rails" style="height:16px"><img src="/images/brands/visa.svg" alt="Visa" style="height:14px"><img src="/images/brands/mastercard.svg" alt="Mastercard" style="height:20px"></div></div>'),
    # 4. FAQ answer
    ('and exports Sage 50 and CSV files.</p></div></div>',
     'and exports Sage 50 and CSV files.</p>' + MARKS + '</div></div>'),
    # 5. invoicing card
    ('accept cards &amp; Interac. Get paid on time without chasing.</p></div>',
     'accept cards &amp; Interac. Get paid on time without chasing.</p>' + MARKS + '</div>'),
]


def main(src, dst):
    s = io.open(src, encoding='utf-8', newline='').read()
    if 'class="pm-chip"' in s:
        sys.exit('paymarks_edits already applied')
    for old, new in EDITS:
        if s.count(old) != 1:
            sys.exit('expected once, found %d: %r' % (s.count(old), old[:80]))
        s = s.replace(old, new)
    io.open(dst, 'w', encoding='utf-8', newline='').write(s)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
