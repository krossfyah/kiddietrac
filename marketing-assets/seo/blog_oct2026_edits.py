# -*- coding: utf-8 -*-
"""Three regulation articles on the blog (2026-10-16, Anthony: "go ahead" on the drafts from the
SEO audit). Facts were checked against O. Reg. 137/15, the amending O. Reg. 197/26 and the July
2026 CWELCC Cost-Based Funding Guideline; each article lists its sources.

Adds
  /blog/ontario-2027-child-care-regulation-changes   what changes on January 1, 2027
  /blog/cwelcc-2026-fees-22-dollar-cap-ontario       the $22 cap, cost-based funding, records
  /blog/safe-arrival-child-care-absence-follow-up    s.50 safe arrival, a procedure and a log
  in EN/FR/ES/HI, from blog_oct2026_posts.json (bodies, titles, excerpts, translations).

Also
  - the April 2025 CWELCC explainer points to the 2026 article;
  - the October 2026 changelog sentence no longer reads as if sleep room checks were one of the
    January 2027 changes (they are s.33.1, already in force). The fr/es/hi strings are updated
    by the deploy script (keys are the English sentence);
  - links inside articles get a style (articles had none before).

usage: python blog_oct2026_edits.py in.html out.html
"""
import io
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))

CHANGELOG_OLD = ('For Ontario centres and agencies: live ratio checks against O. Reg. 137/15, name-to-face '
                 'headcounts with an acting supervisor, sleep room checks, missing-child drills and a hazard '
                 'register for the January 2027 changes, policy sign-offs with a training matrix, OCCMS fee '
                 'subsidy reporting and ECE workforce funding in payroll. ')
CHANGELOG_NEW = ('For Ontario centres and agencies: live ratio checks against O. Reg. 137/15, sleep room checks, '
                 'OCCMS fee subsidy reporting and ECE workforce funding in payroll, plus tools for the January '
                 '2027 changes: name-to-face headcounts with an acting supervisor, missing-child drills, a hazard '
                 'register and policy sign-offs with a training matrix. ')

CSS_AFTER = '.kt-post-more h3{font-size:20px;font-weight:900;color:var(--dark);margin:0 0 16px;text-align:center}\n'
CSS = '.kt-post a{color:var(--teal-dk);font-weight:700;text-decoration:underline;text-underline-offset:2px;overflow-wrap:anywhere}\n'


def one(s, old, new):
    if s.count(old) != 1:
        sys.exit('expected once, found %d: %r' % (s.count(old), old[:90]))
    return s.replace(old, new)


def main(src, dst):
    s = io.open(src, encoding='utf-8', newline='').read()
    data = json.load(io.open(os.path.join(HERE, 'blog_oct2026_posts.json'), encoding='utf-8'))

    m = re.search(r'var POSTS = (\[.*?\]);\n', s)
    posts = json.loads(m.group(1))
    clash = set(p['slug'] for p in posts) & set(p['slug'] for p in data['posts'])
    if clash:
        sys.exit('already on the blog: %s' % ', '.join(sorted(clash)))
    old = [p for p in posts if p['slug'] == 'cwelcc-explained-ontario'][0]
    for lang, note in sorted(data['pointer'].items()):
        if lang == 'en':
            old['body'] = note + '\n\n' + old['body']
        else:
            old['i18n'][lang]['body'] = note + '\n\n' + old['i18n'][lang]['body']
    posts = data['posts'] + posts            # newest first, like the rest of the list
    s = s[:m.start(1)] + json.dumps(posts, ensure_ascii=False).replace('</', '<\\/') + s[m.end(1):]

    # ontario_edits.py now writes the new sentence itself; only older bases carry the old one.
    if CHANGELOG_OLD in s:
        s = one(s, CHANGELOG_OLD, CHANGELOG_NEW)
    s = one(s, CSS_AFTER, CSS_AFTER + CSS)
    io.open(dst, 'w', encoding='utf-8', newline='').write(s)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
