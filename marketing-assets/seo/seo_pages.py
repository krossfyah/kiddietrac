"""Write one real HTML file per address and language (2026-09-30). Python 3.6.

    python3 seo_pages.py <built index.html> <docroot> [--dry]

Search engines were served the same document, with the home page's title and canonical,
at every address, so only the home page could be indexed. This takes the built site
(which already carries seo_edits.py) and writes:

  <docroot>/index.html              the home page (English), article bodies moved out
  <docroot>/p/<page>.html           every other page, English
  <docroot>/p/<lang>.html           home in fr / es / hi
  <docroot>/p/<lang>/<page>.html    every page in fr / es / hi
  <docroot>/p/[<lang>/]blog/<slug>.html   every article, rendered in its language
  <docroot>/blog/body/<slug>.<lang>.html  article bodies, fetched on demand
  <docroot>/sitemap.xml             every address, with its language alternates

.htaccess serves /x from p/x.html when that file exists. Each file has its own title,
description, canonical, hreflang alternates, Open Graph and structured data, and its own
section visible from the first frame. The page's JavaScript is unchanged, so moving
between pages still happens in place.

Nothing here invents content. Page text in fr/es/hi is translated in the browser by the
site's translator, as before; titles and descriptions come from seo_meta.json; articles
come from the posts' own translations.
"""
import datetime
import html
import json
import os
import re
import sys
import urllib.request

import ssr_i18n

BASE = 'https://www.kiddietrac.com'
LANGS = ['en', 'fr', 'es', 'hi']
HTML_LANG = {'en': 'en', 'fr': 'fr-CA', 'es': 'es', 'hi': 'hi'}
OG_LOCALE = {'en': 'en_CA', 'fr': 'fr_CA', 'es': 'es_US', 'hi': 'hi_IN'}
# admin is the sign-in portal link; privacy and terms are real directories of their own.
SKIP = {'admin', 'privacy', 'terms'}
UI = {
    'en': {'min read': 'min read', 'KiddieTrac team': 'KiddieTrac team', '← All articles': '← All articles',
           'See it in KiddieTrac': 'See it in KiddieTrac',
           'Book a short demo or start a free trial — no credit card needed.': 'Book a short demo or start a free trial — no credit card needed.',
           'Talk to us →': 'Talk to us →', 'Keep reading': 'Keep reading', 'Read more →': 'Read more →',
           'KiddieTrac Blog': 'KiddieTrac Blog', 'Home': 'Home', 'Blog': 'Blog'},
    'fr': {'min read': 'min de lecture', 'KiddieTrac team': 'Équipe KiddieTrac', '← All articles': '← Tous les articles',
           'See it in KiddieTrac': 'Voyez-le dans KiddieTrac',
           'Book a short demo or start a free trial — no credit card needed.': 'Réservez une courte démo ou commencez un essai gratuit — sans carte de crédit.',
           'Talk to us →': 'Parlez-nous →', 'Keep reading': 'À lire aussi', 'Read more →': 'Lire la suite →',
           'KiddieTrac Blog': 'Blogue KiddieTrac', 'Home': 'Accueil', 'Blog': 'Blogue'},
    'es': {'min read': 'min de lectura', 'KiddieTrac team': 'Equipo KiddieTrac', '← All articles': '← Todos los artículos',
           'See it in KiddieTrac': 'Véalo en KiddieTrac',
           'Book a short demo or start a free trial — no credit card needed.': 'Reserve una demostración breve o comience una prueba gratuita, sin tarjeta de crédito.',
           'Talk to us →': 'Hable con nosotros →', 'Keep reading': 'Siga leyendo', 'Read more →': 'Leer más →',
           'KiddieTrac Blog': 'Blog de KiddieTrac', 'Home': 'Inicio', 'Blog': 'Blog'},
    'hi': {'min read': 'मिनट में पढ़ें', 'KiddieTrac team': 'KiddieTrac टीम', '← All articles': '← सभी लेख',
           'See it in KiddieTrac': 'इसे KiddieTrac में देखें',
           'Book a short demo or start a free trial — no credit card needed.': 'एक छोटा डेमो बुक करें या मुफ़्त ट्रायल शुरू करें — क्रेडिट कार्ड की ज़रूरत नहीं।',
           'Talk to us →': 'हमसे बात करें →', 'Keep reading': 'आगे पढ़ें', 'Read more →': 'और पढ़ें →',
           'KiddieTrac Blog': 'KiddieTrac ब्लॉग', 'Home': 'होम', 'Blog': 'ब्लॉग'},
}
CAT = {
    'en': {'compliance': 'Compliance', 'finance': 'Finance', 'ops': 'Operations', 'families': 'Families',
           'development': 'Child development', 'growth': 'Growth', 'tech': 'Technology'},
    'fr': {'compliance': 'Conformité', 'finance': 'Finances', 'ops': 'Opérations', 'families': 'Familles',
           'development': 'Développement de l’enfant', 'growth': 'Croissance', 'tech': 'Technologie'},
    'es': {'compliance': 'Cumplimiento', 'finance': 'Finanzas', 'ops': 'Operaciones', 'families': 'Familias',
           'development': 'Desarrollo infantil', 'growth': 'Crecimiento', 'tech': 'Tecnología'},
    'hi': {'compliance': 'अनुपालन', 'finance': 'वित्त', 'ops': 'संचालन', 'families': 'परिवार',
           'development': 'बाल विकास', 'growth': 'विकास', 'tech': 'तकनीक'},
}
MONTHS = {
    'en': ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    'fr': ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'],
    'es': ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'],
    'hi': ['जन॰', 'फ़र॰', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुल॰', 'अग॰', 'सित॰', 'अक्तू॰', 'नव॰', 'दिस॰'],
}


def esc(s):
    return html.escape('' if s is None else str(s), quote=True)


def url(lang, rest):
    lp = '' if lang == 'en' else '/' + lang
    return BASE + ((lp or '/') if rest == '/' else lp + rest)


def fmt_date(ymd, lang):
    y, m, d = [int(x) for x in ymd.split('-')]
    mo = MONTHS[lang][m - 1]
    return '%s %d, %d' % (mo, d, y) if lang == 'en' else '%d %s %d' % (d, mo, y)


def tx(p, field, lang):
    if lang != 'en':
        t = (p.get('i18n') or {}).get(lang, {}).get(field)
        if t:
            return t
    return p.get(field) or ''


def one(s, o, n):
    assert s.count(o) == 1, ('seo_pages', o[:80], s.count(o))
    return s.replace(o, n)


def set_attr(s, pattern, value):
    """Replace the content/href of the single tag matched by pattern (group 1 = value)."""
    m = list(re.finditer(pattern, s))
    assert len(m) == 1, ('seo_pages tag', pattern, len(m))
    m = m[0]
    return s[:m.start(1)] + esc(value) + s[m.end(1):]


def head(doc, lang, rest, title, desc, ld_extra, image=None, keep_faq=False):
    doc = re.sub(r'<html lang="[^"]*"', '<html lang="%s"' % HTML_LANG[lang], doc, count=1)
    doc = re.sub(r'<title>[^<]*</title>', '<title>' + esc(title) + '</title>', doc, count=1)
    doc = set_attr(doc, r'<meta name="description" content="([^"]*)"', desc)
    doc = set_attr(doc, r'<meta property="og:title" content="([^"]*)"', title)
    doc = set_attr(doc, r'<meta property="og:description" content="([^"]*)"', desc)
    doc = set_attr(doc, r'<meta property="og:url" content="([^"]*)"', url(lang, rest))
    doc = set_attr(doc, r'<meta property="og:locale" content="([^"]*)"', OG_LOCALE[lang])
    doc = set_attr(doc, r'<meta name="twitter:title" content="([^"]*)"', title)
    doc = set_attr(doc, r'<meta name="twitter:description" content="([^"]*)"', desc)
    doc = set_attr(doc, r'<link rel="canonical" href="([^"]*)"', url(lang, rest))
    if image:
        doc = set_attr(doc, r'<meta property="og:image" content="([^"]*)"', image)
        doc = set_attr(doc, r'<meta name="twitter:image" content="([^"]*)"', image)
    alts = ''.join('<link rel="alternate" hreflang="%s" href="%s" data-kt-alt="%s">\n' % (l, url(l, rest), l) for l in LANGS)
    alts += '<link rel="alternate" hreflang="x-default" href="%s" data-kt-alt="x-default">\n' % url('en', rest)
    doc = re.sub(r'<!--KT-ALT-->.*?<!--/KT-ALT-->', lambda m: '<!--KT-ALT-->\n' + alts + '<!--/KT-ALT-->', doc, count=1, flags=re.S)
    if not keep_faq:
        # The FAQ markup describes the FAQ on the home page; on any other page it is not visible.
        doc = re.sub(r'<script type="application/ld\+json">(?:(?!</script>).)*"FAQPage"(?:(?!</script>).)*</script>\s*', '', doc, count=1, flags=re.S)
    if ld_extra:
        blocks = ''.join('<script type="application/ld+json">%s</script>\n' % json.dumps(b, ensure_ascii=False) for b in ld_extra)
        doc = doc.replace('</head>', blocks + '</head>', 1)
    return doc


def activate(doc, pid, reading=False):
    doc = one(doc, '<div class="page active" id="page-home">', '<div class="page" id="page-home">')
    cls = 'page active reading' if reading else 'page active'
    return one(doc, '<div class="page" id="page-%s">' % pid, '<div class="%s" id="page-%s">' % (cls, pid))


def prefix_links(doc, lang, page_ids):
    """Links carry the language: /pricing -> /fr/pricing, /blog/x -> /fr/blog/x."""
    if lang == 'en':
        return doc
    ids = '|'.join(re.escape(i) for i in sorted(page_ids, key=len, reverse=True))
    doc = re.sub(r'href="/(%s|blog/[a-z0-9-]+)"' % ids, lambda m: 'href="/%s/%s"' % (lang, m.group(1)), doc)
    doc = doc.replace('<a href="/" onclick="showPage(\'home\')', '<a href="/%s" onclick="showPage(\'home\')' % lang)
    return doc


def crumbs(lang, items):
    return {'@context': 'https://schema.org', '@type': 'BreadcrumbList', 'itemListElement': [
        {'@type': 'ListItem', 'position': i + 1, 'name': n, 'item': u} for i, (n, u) in enumerate(items)]}


def card(p, lang):
    lp = '' if lang == 'en' else '/' + lang
    thumb = ('<div class="blog-thumb k7-ph" style="background-image:url(/images/life/%s-800.jpg)"><i>%s</i></div>' % (esc(p['image']), esc(p.get('emoji') or '📝'))
             if p.get('image') else '<div class="blog-thumb">%s</div>' % esc(p.get('emoji') or '📝'))
    return ('<div class="blog-card" data-cat="%s" data-slug="%s">' % (esc(p['cat']), esc(p['slug'])) + thumb
            + '<div class="blog-body"><span class="blog-cat %s">%s</span>' % (esc(p['cat']), esc(CAT[lang].get(p['cat'], p['cat'])))
            + '<div class="blog-meta">%s · %s %s</div>' % (fmt_date(p['date'], lang), p.get('minutes') or 5, UI[lang]['min read'])
            + '<div class="blog-title">%s</div><div class="blog-excerpt">%s</div>' % (esc(tx(p, 'title', lang)), esc(tx(p, 'excerpt', lang)))
            + '<a class="blog-more" href="%s/blog/%s">%s</a></div></div>' % (lp, esc(p['slug']), UI[lang]['Read more →']))


def article(p, lang, posts):
    """The same markup the page's render(p) produces, so the client re-render is invisible."""
    u = UI[lang]
    lp = '' if lang == 'en' else '/' + lang
    same = [x for x in posts if x['slug'] != p['slug'] and x['cat'] == p['cat']]
    rest = [x for x in posts if x['slug'] != p['slug'] and x['cat'] != p['cat']]
    rel = (same + rest)[:3]
    hero = ('<div class="hero-band k7-posthero" style="--k7img:url(/images/life/%s-1600.jpg)"><div class="container">' % esc(p['image'])
            if p.get('image') else '<div class="hero-band"><div class="container">')
    return ('<div class="kt-post-wrap" translate="no">' + hero
            + '<div class="section-label" style="color:var(--green-lt)">%s</div>' % esc(CAT[lang].get(p['cat'], p['cat']))
            + '<h1 class="section-title" style="color:white;max-width:860px;margin:0 auto">%s</h1>' % esc(tx(p, 'title', lang))
            + '<div class="kt-post-meta">%s · %s %s · %s</div>' % (fmt_date(p['date'], lang), p.get('minutes') or 5, u['min read'], u['KiddieTrac team'])
            + '</div></div><section class="section"><div class="container">'
            + '<div style="max-width:720px;margin:0 auto"><a class="kt-post-back" href="%s/blog">%s</a></div>' % (lp, u['← All articles'])
            + '<article class="kt-post" lang="%s" data-slug="%s">%s</article>' % (lang, esc(p['slug']), tx(p, 'body', lang))
            + '<div class="kt-post-cta"><div><b>%s</b><span>%s</span></div>' % (u['See it in KiddieTrac'], u['Book a short demo or start a free trial — no credit card needed.'])
            + '<button type="button" data-go="contact">%s</button></div>' % u['Talk to us →']
            + '<div class="kt-post-more"><h3>%s</h3><div class="cards-grid cards-3">%s</div></div>' % (u['Keep reading'], ''.join(card(x, lang) for x in rel))
            + '</div></section></div>')


def load_dict(root, lang):
    """The same dictionary the page's translator loads: /i18n/<lang>.json + the portal's /extra."""
    d = json.load(open(os.path.join(root, 'i18n', lang + '.json'), encoding='utf-8'))
    try:
        r = urllib.request.urlopen('https://api.kiddietrac.com/api/v1/marketing-site/i18n/%s/extra' % lang, timeout=20)
        extra = json.loads(r.read().decode('utf-8')) or {}
        d.update(extra)
    except Exception as e:  # the built dictionary alone is still a full translation
        print('  (no /extra for %s: %s)' % (lang, e))
    return d


def inner_span(doc, el_id):
    tree = ssr_i18n.Tree(doc)
    stack = list(tree.root.kids)
    while stack:
        e = stack.pop()
        if isinstance(e, ssr_i18n.El):
            if e.attrs.get('id') == el_id:
                return e.end, e.cstart
            stack.extend(e.kids)
    raise AssertionError('no #' + el_id)


def lang_base(doc, lang, posts, d):
    """The whole page in one language: blog cards in that language, then every sentence the
    page's translator would translate, translated on the server."""
    if lang != 'en':
        a, b = inner_span(doc, 'blogGrid')
        doc = doc[:a] + ''.join(card(p, lang) for p in posts) + doc[b:]
        doc = one(doc, 'id="blogGrid"', 'id="blogGrid" translate="no"')
        doc, st = ssr_i18n.translate_doc(doc, d)
        print('  %s: %d/%d sentences, %d labels, %d attributes, %d options translated' % (
            lang, st['units_tr'], st['units'], st['loose_tr'], st['attrs'], st['options']))
    # Tells the page's scripts which language the words are already in.
    return doc.replace('<head>', '<head>\n<script>window.KT_SSR_LANG="%s";</script>' % lang, 1)


NOT_FOUND = ('<div class="page active" id="page-notfound"><div class="hero-band"><div class="container">'
             '<div class="section-label" style="color:var(--green-lt)">404</div>'
             '<h1 class="section-title" style="color:white">We couldn’t find that page</h1>'
             '<p class="section-sub" style="color:rgba(255,255,255,.82);margin:0 auto">The link may be out of date, or the address may have a typo.</p>'
             '</div></div><section class="section"><div class="container" style="text-align:center;max-width:720px">'
             '<p style="font-size:17px;margin-bottom:22px">Here are the places most people are looking for:</p>'
             '<div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:26px">'
             + ''.join('<a class="btn-secondary" href="%s" onclick="showPage(\'%s\');return false;">%s</a>' % (h, pid, t) for h, pid, t in [
                 ('/', 'home', 'Home'), ('/solutions', 'solutions', 'Features'), ('/pricing', 'pricing', 'Pricing'),
                 ('/blog', 'blog', 'Blog'), ('/contact', 'contact', 'Book a demo'), ('/support', 'support', 'Get support')])
             + '</div><p style="color:#64748B">Already a customer? <a href="https://app.kiddietrac.com/">Sign in to KiddieTrac</a>.</p>'
             '</div></section></div>')


def not_found_page(doc):
    d = re.sub(r'<title>[^<]*</title>', '<title>Page not found | KiddieTrac</title>', doc, count=1)
    d = set_attr(d, r'<meta name="description" content="([^"]*)"', 'The page you were looking for is not on the KiddieTrac website.')
    d = set_attr(d, r'<meta name="robots" content="([^"]*)"', 'noindex,follow')
    d = re.sub(r'<link rel="canonical" href="[^"]*">\s*', '', d, count=1)
    d = re.sub(r'<!--KT-ALT-->.*?<!--/KT-ALT-->', '', d, count=1, flags=re.S)
    d = re.sub(r'<script type="application/ld\+json">(?:(?!</script>).)*"FAQPage"(?:(?!</script>).)*</script>\s*', '', d, count=1, flags=re.S)
    d = one(d, '<div class="page active" id="page-home">', NOT_FOUND + '<div class="page" id="page-home">')
    # The router would otherwise take an unknown address to the home page.
    return d.replace('<head>', '<head>\n<script>window.KT_NOT_FOUND=location.pathname;</script>', 1)


def main():
    src, root = sys.argv[1], sys.argv[2]
    dry = '--dry' in sys.argv
    doc = open(src, encoding='utf-8').read()
    assert '<!--KT-ALT-->' in doc, 'built without seo_edits.py'
    here = os.path.dirname(os.path.abspath(__file__))
    meta_i18n = json.load(open(os.path.join(here, 'seo_meta.json'), encoding='utf-8'))

    # English titles/descriptions: the page's own META table (+ seo_meta.json en).
    router = doc[doc.find('var META={'):]
    meta_en = {}
    for m in re.finditer(r'(?:"([a-z-]+)"|([a-z]+)):\{t:"((?:[^"\\]|\\.)*)",d:"((?:[^"\\]|\\.)*)"\}', router[:router.find('};') + 2]):
        meta_en[m.group(1) or m.group(2)] = {'t': json.loads('"%s"' % m.group(3)), 'd': json.loads('"%s"' % m.group(4))}
    meta_en.update(meta_i18n.get('en', {}))
    page_ids = [p for p in re.findall(r'<div class="page(?: active)?" id="page-([a-z0-9-]+)">', doc) if p not in SKIP]
    missing = [p for p in page_ids if p not in meta_en]
    assert not missing, ('no English title/description for', missing)

    # Posts: bodies out to files, the page keeps titles and excerpts.
    m = re.search(r'var POSTS = (\[.*?\]);\n', doc)
    posts = json.loads(m.group(1))
    bodies = {}
    slim = []
    for p in posts:
        q = dict(p)
        bodies[(p['slug'], 'en')] = q.pop('body', '') or ''
        if q.get('i18n'):
            q['i18n'] = {}
            for l, t in p['i18n'].items():
                t2 = dict(t)
                if t2.get('body'):
                    bodies[(p['slug'], l)] = t2.pop('body')
                q['i18n'][l] = t2
        slim.append(q)
    doc = doc[:m.start(1)] + json.dumps(slim, ensure_ascii=False) + doc[m.end(1):]

    today = datetime.date.today().isoformat()
    written = {}

    def put(rel, content):
        written[rel] = len(content.encode('utf-8'))
        if dry:
            return
        path = os.path.join(root, rel)
        d = os.path.dirname(path)
        if d and not os.path.isdir(d):
            os.makedirs(d)
        with open(path + '.tmp', 'w', encoding='utf-8', newline='\n') as f:
            f.write(content)
        os.replace(path + '.tmp', path)

    for (slug, l), b in bodies.items():
        if b:
            put('blog/body/%s.%s.html' % (slug, l), b)

    sitemap = []
    slim_doc = doc
    for lang in LANGS:
        doc = lang_base(slim_doc, lang, posts, None if lang == 'en' else load_dict(root, lang))
        if lang == 'en':
            nf = not_found_page(doc)
            put('p/404.html', nf)
            # The host's own config sends every 404 to /404.shtml (the .htaccess ErrorDocument
            # is not honoured), so that file IS the not-found page.
            put('404.shtml', nf)
        mt = meta_en if lang == 'en' else meta_i18n[lang]
        home_t = UI[lang]['Home']
        for pid in page_ids:
            rest = '/' if pid == 'home' else '/' + pid
            info = mt.get(pid) or meta_en[pid]
            ld = [] if pid == 'home' else [crumbs(lang, [(home_t, url(lang, '/')), (info['t'].split(' | ')[0].split(' — ')[0], url(lang, rest))])]
            d = head(doc, lang, rest, info['t'], info['d'], ld, keep_faq=(pid == 'home'))
            if pid != 'home':
                d = activate(d, pid)
            d = prefix_links(d, lang, page_ids)
            if pid == 'home' and lang == 'en':
                put('index.html', d)
            elif pid == 'home':
                put('p/%s.html' % lang, d)
            else:
                put('p/%s%s.html' % ('' if lang == 'en' else lang + '/', pid), d)
            if lang == 'en':
                sitemap.append((rest, today))

        blog_t = UI[lang]['KiddieTrac Blog']
        for p in posts:
            rest = '/blog/' + p['slug']
            title = tx(p, 'title', lang)
            desc = tx(p, 'excerpt', lang)
            img = (BASE + '/images/life/%s-1600.jpg' % p['image']) if p.get('image') else None
            art = {'@context': 'https://schema.org', '@type': 'BlogPosting', 'headline': title, 'description': desc,
                   'datePublished': p['date'], 'dateModified': p['date'], 'inLanguage': HTML_LANG[lang],
                   'mainEntityOfPage': url(lang, rest), 'author': {'@type': 'Organization', 'name': 'KiddieTrac', 'url': BASE},
                   'publisher': {'@type': 'Organization', 'name': 'KiddieTrac', 'logo': {'@type': 'ImageObject', 'url': BASE + '/kiddietrac-logo.png'}}}
            if img:
                art['image'] = img
            ld = [art, crumbs(lang, [(UI[lang]['Home'], url(lang, '/')), (UI[lang]['Blog'], url(lang, '/blog')), (title, url(lang, rest))])]
            d = head(doc, lang, rest, title + ' | ' + blog_t, desc, ld, image=img)
            d = activate(d, 'blog', reading=True)
            d = one(d, '<div class="page active reading" id="page-blog">',
                    '<div class="page active reading" id="page-blog">' + article(p, lang, posts))
            d = prefix_links(d, lang, page_ids)
            put('p/%sblog/%s.html' % ('' if lang == 'en' else lang + '/', p['slug']), d)
            if lang == 'en':
                sitemap.append((rest, p['date']))

    # Sitemap: every address, each listing its language versions.
    x = ['<?xml version="1.0" encoding="UTF-8"?>',
         '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">']
    for rest, lastmod in sitemap:
        alts = ''.join('<xhtml:link rel="alternate" hreflang="%s" href="%s"/>' % (l, url(l, rest)) for l in LANGS)
        alts += '<xhtml:link rel="alternate" hreflang="x-default" href="%s"/>' % url('en', rest)
        for l in LANGS:
            x.append('<url><loc>%s</loc><lastmod>%s</lastmod>%s</url>' % (url(l, rest), lastmod, alts))
    for extra in ['/privacy', '/terms']:
        x.append('<url><loc>%s%s</loc><lastmod>%s</lastmod></url>' % (BASE, extra, today))
    x.append('</urlset>')
    put('sitemap.xml', '\n'.join(x) + '\n')

    pages = [k for k in written if k.startswith('p/') or k == 'index.html']
    print('pages %d, bodies %d, sitemap urls %d' % (len(pages), len([k for k in written if k.startswith('blog/body/')]), len(x) - 3))
    print('page size: index %d KB (was %d KB)' % (written['index.html'] // 1024, len(open(src, encoding='utf-8').read().encode('utf-8')) // 1024))


if __name__ == '__main__':
    main()
