# -*- coding: utf-8 -*-
"""Ontario ratio rules article (2026-10-16, Anthony approved the draft). Every ratio, group size
and qualified-staff share is from O. Reg. 137/15 Schedules 1 and 4 and ss. 8, 8.1; the home
child care limits are credited to ontario.ca (they are in the Act, s. 6 (3)).

Adds
  /blog/ontario-child-care-ratios in EN/FR/ES/HI, from blog_ratios_posts.json.
Also
  - the Ontario ratio card said "and an RECE in every group"; the ratio check (OntarioRatios.php)
    counts the Schedule 1 col. 5 share of RECEs on file, so it now says that. fr/es/hi strings
    are swapped by tr_blog_ratios.py;
  - /daycare-software-ontario "Keep reading": ratios, 2027 changes, Safe Arrival.

usage: python blog_ratios_edits.py in.html out.html
"""
import io
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))

CARD_OLD = ('Each room at a licensed centre is checked against Schedule 1: ratios, maximum group sizes and '
            'qualified staff, mixed-age groups, the reduced ratios allowed at arrival, departure and rest '
            'times, family age groups and an RECE in every group. ')
CARD_NEW = ('Each room at a licensed centre is checked against Schedule 1: ratios, maximum group sizes, '
            'mixed-age groups, the reduced ratios allowed at arrival, departure and rest times, family age '
            'groups, and the share of RECEs each group needs. ')
REL_OLD = 'data-slugs="ontario-2027-child-care-regulation-changes,safe-arrival-child-care-absence-follow-up,cwelcc-2026-fees-22-dollar-cap-ontario"'
REL_NEW = 'data-slugs="ontario-child-care-ratios,ontario-2027-child-care-regulation-changes,safe-arrival-child-care-absence-follow-up"'


def one(s, old, new):
    if s.count(old) != 1:
        sys.exit('expected once, found %d: %r' % (s.count(old), old[:90]))
    return s.replace(old, new)


def main(src, dst):
    s = io.open(src, encoding='utf-8', newline='').read()
    new = json.load(io.open(os.path.join(HERE, 'blog_ratios_posts.json'), encoding='utf-8'))
    m = re.search(r'var POSTS = (\[.*?\]);\n', s)
    posts = json.loads(m.group(1))
    if set(p['slug'] for p in posts) & set(p['slug'] for p in new):
        sys.exit('already on the blog')
    s = s[:m.start(1)] + json.dumps(new + posts, ensure_ascii=False).replace('</', '<\\/') + s[m.end(1):]
    if CARD_OLD in s:                      # ontario_edits.py writes CARD_NEW itself from now on
        s = one(s, CARD_OLD, CARD_NEW)
    if REL_OLD in s:                       # likewise related_edits.py
        s = one(s, REL_OLD, REL_NEW)
    io.open(dst, 'w', encoding='utf-8', newline='').write(s)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
