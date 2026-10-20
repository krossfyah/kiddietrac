"""White-label feature list: campaigns from your own mailbox (2026-10-20).

Run on the host from ~/kiddietrac/marketing-assets/seo:
    python3 wl_sendfrom_edits.py index.built.html ~/public_html/i18n
Adds one line to the "White Label Platform" list (Solutions) in the build, and its
fr/es/hi translations, keyed the way the site's translator reads them. seo_pages.py then
regenerates the pre-rendered pages in all four languages.
"""
import io, json, sys

built, i18n_dir = sys.argv[1], sys.argv[2]

EN = 'Marketing campaigns sent from your own Gmail or Google Workspace mailbox'
TR = {
    'fr': 'Campagnes marketing envoyées depuis votre propre boîte Gmail ou Google Workspace',
    'es': 'Campañas de marketing enviadas desde su propio buzón de Gmail o Google Workspace',
    'hi': 'आपके अपने Gmail या Google Workspace मेलबॉक्स से भेजे जाने वाले मार्केटिंग अभियान',
}
LI = ("<li style='display:flex;align-items:flex-start;gap:10px;font-size:14px;color:var(--dark)'>"
      "<span style='color:var(--green);font-weight:900;margin-top:1px'>✓</span>{}</li>")
ANCHOR = LI.format('Branded email notifications and daily reports')

s = io.open(built, encoding='utf-8', newline='').read()
if EN in s:
    sys.exit('already has the line')
if s.count(ANCHOR) != 1:
    sys.exit('anchor count %d' % s.count(ANCHOR))
s = s.replace(ANCHOR, ANCHOR + LI.format(EN))
io.open(built, 'w', encoding='utf-8', newline='').write(s)
print('build: line added')

key = 'span|✓' + EN
for lang, text in TR.items():
    p = '%s/%s.json' % (i18n_dir, lang)
    d = json.load(io.open(p, encoding='utf-8'))
    d[key] = '{0}✓{/0}' + text
    tmp = p + '.uploading'
    # Same shape as the live files: compact, ASCII-escaped.
    io.open(tmp, 'w', encoding='utf-8').write(json.dumps(d, ensure_ascii=True, separators=(',', ':')))
    json.load(io.open(tmp, encoding='utf-8'))      # valid before it replaces the live file
    print(lang, 'translation added')
