#!/usr/bin/env python3
# ON THE HOST. Marketing site: Google Fonts -> our own /fonts/kt-site-fonts.css (2026-10-14).
# Usage: site_fonts.py dry|apply
import io, os, re, sys
mode = sys.argv[1]
ROOT = os.path.expanduser('~/public_html')
BUILT = os.path.expanduser('~/kiddietrac/marketing-assets/seo/index.built.html')
LINK = '<link rel="stylesheet" href="/fonts/kt-site-fonts.css?v=20261014">'
SKIP = {'dashboard.html', 'dashboard.src.html', '_preview-demos.html', '_preview-new.html', '_preview-shop.html'}

PRE = re.compile(r'[ \t]*<link rel="preconnect" href="https://fonts\.(?:googleapis|gstatic)\.com"(?: crossorigin)?>[ \t]*\r?\n?')
NUN = re.compile(r'<link href="https://fonts\.googleapis\.com/css2\?family=Nunito:[^"]+" rel="stylesheet">')
DEV = re.compile(r'[ \t]*<link href="https://fonts\.googleapis\.com/css2\?family=Noto\+Sans\+Devanagari:[^"]+" rel="stylesheet">[ \t]*\r?\n?')

def fix(s):
    s2 = PRE.sub('', s)
    n_nun = len(NUN.findall(s2))
    if n_nun:
        s2 = NUN.sub(LINK, s2, count=1)
        s2 = NUN.sub('', s2)
        s2 = DEV.sub('', s2)
    elif DEV.search(s2):
        s2 = DEV.sub(LINK + '\n', s2, count=1)
        s2 = DEV.sub('', s2)
    return s2

files = []
for d, _, fs in os.walk(ROOT):
    for f in fs:
        if f.endswith(('.html', '.shtml')):
            p = os.path.join(d, f)
            if os.path.relpath(p, ROOT) in SKIP:
                continue
            files.append(p)
files.append(BUILT)

changed, left = 0, []
for p in files:
    s = io.open(p, encoding='utf-8', errors='surrogateescape').read()
    if 'fonts.googleapis' not in s and 'fonts.gstatic' not in s:
        continue
    t = fix(s)
    if 'fonts.googleapis' in t or 'fonts.gstatic' in t:
        left.append(os.path.relpath(p, ROOT) if p.startswith(ROOT) else p)
    if t != s:
        changed += 1
        if mode == 'apply':
            io.open(p + '.kttmp', 'w', encoding='utf-8', errors='surrogateescape', newline='').write(t)
            os.replace(p + '.kttmp', p)
print(mode, 'files changed:', changed)
print('still referencing Google fonts:', len(left))
for x in left[:20]:
    print('  ', x)
