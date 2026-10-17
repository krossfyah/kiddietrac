# -*- coding: utf-8 -*-
"""Related articles on the product pages (2026-10-16, SEO follow-up: link the regulation
articles from the pages they support).

Adds an empty <div class="kt-related" data-slugs="..."> to four pages; seo_pages.py fills it
with that language's blog cards (title, excerpt, link) under a "Keep reading" heading, the same
cards as the blog list, so nothing here needs translating.

  /daycare-software-ontario   2027 changes, Safe Arrival, CWELCC 2026
  /safe-arrival               Safe Arrival article, 2027 changes, QR check-in vs paper
  /cwelcc-calculator          CWELCC 2026, CWELCC explained, revenue leaks
  /cwelcc-billing-software    CWELCC 2026, CWELCC explained, billing in CAD and USD

usage: python related_edits.py in.html out.html
"""
import io
import sys

CTA = '<div style="text-align:center;margin-top:44px"><button class="btn btn-secondary" onclick="showPage(\'contact\')"'
SA_FINAL = '<section class="sa-band sa-final">'

RELATED = [
    ('daycare-software-ontario', CTA, 'ontario-2027-child-care-regulation-changes,safe-arrival-child-care-absence-follow-up,cwelcc-2026-fees-22-dollar-cap-ontario'),
    ('cwelcc-calculator', CTA, 'cwelcc-2026-fees-22-dollar-cap-ontario,cwelcc-explained-ontario,childcare-revenue-leaks'),
    ('cwelcc-billing-software', CTA, 'cwelcc-2026-fees-22-dollar-cap-ontario,cwelcc-explained-ontario,billing-cad-and-usd'),
    ('safe-arrival', SA_FINAL, 'safe-arrival-child-care-absence-follow-up,ontario-2027-child-care-regulation-changes,qr-check-in-vs-paper-sign-in'),
]

CSS_AFTER = '.kt-post a{'
CSS = ('.kt-related{max-width:1080px;margin:48px auto 0}'
       '.kt-related h2{font-size:22px;font-weight:900;color:var(--dark);text-align:center;margin:0 0 16px}\n')


def main(src, dst):
    s = io.open(src, encoding='utf-8', newline='').read()
    for pid, anchor, slugs in RELATED:
        start = s.find('id="page-%s"' % pid)
        if start < 0 or s.count('id="page-%s"' % pid) != 1:
            sys.exit('no single #page-%s' % pid)
        end = s.find('<div class="page', start + 1)          # the next page section
        at = s.find(anchor, start)
        if at < 0 or (end > 0 and at > end):
            sys.exit('anchor not inside #page-%s' % pid)
        block = '<div class="kt-related" data-slugs="%s"></div>' % slugs
        if anchor == SA_FINAL:                                  # between full-width bands
            block = '<section class="section"><div class="container">%s</div></section>' % block
        s = s[:at] + block + s[at:]
    i = s.find(CSS_AFTER)
    if i < 0 or s.count(CSS_AFTER) != 1:
        sys.exit('no single %s' % CSS_AFTER)
    s = s[:i] + CSS + s[i:]
    io.open(dst, 'w', encoding='utf-8', newline='').write(s)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
