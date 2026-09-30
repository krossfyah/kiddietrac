"""Search engines see one page per address (2026-09-30).

Anthony asked how to maximise SEO. Every address on the site (all 44 in the sitemap, every
blog post, /fr, /es) returned the same 1.2MB document with the home page's title,
description and canonical, so Google could only ever index the home page. seo_pages.py
(run on the server after each deploy) now writes one real HTML file per page and language.
This step makes the page's own JavaScript agree with those files:

  - /fr, /es, /hi address prefixes: the router, the blog and the translator read the
    language from the address and keep it when you move between pages; choosing a
    language in the menu moves you to that language's address.
  - canonical, og:url and the hreflang alternates follow client-side navigation.
  - page titles and descriptions per language (KT_META_I18N, from seo_meta.json).
  - menu links that were onclick-only get a real href (crawlable, open-in-new-tab works);
    the click still stays in the page.
  - the blog reuses a server-rendered article and fetches an article body on demand, so
    the bodies (about 480KB of every page view) can move out of the page.
"""
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = 'https://www.kiddietrac.com'
LANGS = ['en', 'fr', 'es', 'hi']


def _one(out, o, n, where=None):
    s = out if where is None else where
    assert s.count(o) == 1, ('seo_edits', o[:80], s.count(o))
    return out.replace(o, n) if where is None else s.replace(o, n)


def _in_script(out, marker, pairs):
    """Replace inside the one <script> containing marker."""
    i = out.find(marker)
    assert i > 0, ('seo_edits marker', marker)
    a = out.rfind('<script', 0, i)
    b = out.find('</script>', i)
    js = out[a:b]
    for o, n, *cnt in pairs:
        c = cnt[0] if cnt else 1
        assert js.count(o) == c, ('seo_edits', marker[:30], o[:70], js.count(o))
        js = js.replace(o, n)
    return out[:a] + js + out[b:]


def seo(out):
    meta = json.load(open(os.path.join(HERE, 'seo_meta.json'), encoding='utf-8'))

    # 1. Language prefix, alternates and the language-switch URL, before anything routes.
    head_js = (
        '<script>/* SEO addresses (seo_edits.py): /fr /es /hi carry the language. */(function(){'
        'var m=/^\\/(fr|es|hi)(?=\\/|$)/.exec(location.pathname);'
        'window.KT_PATH_LANG=m?m[1]:"";window.KT_LP=m?"/"+m[1]:"";'
        'window.__ktInitialPath=m?(location.pathname.slice(m[0].length)||"/"):location.pathname;'
        'function rest(){return location.pathname.replace(/^\\/(fr|es|hi)(?=\\/|$)/,"")||"/";}'
        'function url(lp,r){return "' + BASE + '"+(r==="/"?(lp||"/"):lp+r);}'
        'window.ktSetAlt=function(r){r=r||rest();try{'
        'var c=document.querySelector(\'link[rel="canonical"]\');if(c)c.setAttribute("href",url(window.KT_LP,r));'
        'var o=document.querySelector(\'meta[property="og:url"]\');if(o)o.setAttribute("content",url(window.KT_LP,r));'
        'document.querySelectorAll("link[data-kt-alt]").forEach(function(l){var k=l.getAttribute("data-kt-alt");'
        'l.setAttribute("href",url(k==="en"||k==="x-default"?"":"/"+k,r));});}catch(e){}};'
        'window.ktLangUrl=function(l){var lp=(l&&l!=="en")?"/"+l:"";if(lp===window.KT_LP)return;var r=rest();'
        'window.KT_LP=lp;window.KT_PATH_LANG=lp?l:"";var np=r==="/"?(lp||"/"):lp+r;'
        'try{history.replaceState(history.state,"",np+location.search+location.hash);}catch(e){}window.ktSetAlt(r);'
        # The title follows the language too (the blog re-titles an open article on kt:lang).
        'try{var sg=r.replace(/^\/+|\/+$/g,"");if(window.ktApplyMeta)window.ktApplyMeta(sg&&document.getElementById("page-"+sg)?sg:(/^blog\//.test(sg)?"blog":"home"));}catch(e){}};'
        # Back/forward into an entry made in another language: the address decides. Registered
        # before the router's own popstate, so it sees the updated prefix.
        'window.addEventListener("popstate",function(){var m=/^\\/(fr|es|hi)(?=\\/|$)/.exec(location.pathname);'
        'var pl=m?m[1]:"";window.KT_PATH_LANG=pl;window.KT_LP=pl?"/"+pl:"";'
        'try{if(window.ktI18n&&window.setLang&&window.ktI18n.lang()!==(pl||"en"))window.setLang(pl||"en");}catch(e){}});'
        '})();</script>\n'
        '<script>window.KT_META_I18N=' + json.dumps(meta, ensure_ascii=False, separators=(',', ':')) + ';</script>')
    out = _one(out, '<script>window.__ktInitialPath=location.pathname;</script>', head_js)

    # Home alternates in the head; seo_pages.py rewrites this block for every other page.
    alts = ''.join('<link rel="alternate" hreflang="%s" href="%s" data-kt-alt="%s">\n' % (
        l, BASE + ('/' if l == 'en' else '/' + l), l) for l in LANGS)
    alts += '<link rel="alternate" hreflang="x-default" href="%s/" data-kt-alt="x-default">\n' % BASE
    out = _one(out, '<link rel="canonical" href="https://www.kiddietrac.com/">',
               '<link rel="canonical" href="https://www.kiddietrac.com/">\n<!--KT-ALT-->\n' + alts + '<!--/KT-ALT-->')

    # 2. Router: strip the prefix to find the page, keep it when moving, per-language titles.
    out = _in_script(out, 'SEO: per-page <title>', [
        ("function applyMeta(id){ var m=META[id];",
         "function applyMeta(id){ var I=window.KT_META_I18N||{}; var m=(I[window.KT_PATH_LANG||'en']||{})[id]||META[id];"),
        ("function applyCanon(id){ var u='https://www.kiddietrac.com'+((id&&id!=='home')?('/'+id):'/'); if(canEl)canEl.setAttribute('href',u); if(ogu)ogu.setAttribute('content',u); }",
         "window.ktApplyMeta=function(i){ applyMeta(i); }; function applyCanon(id){ var r=(id&&id!=='home')?('/'+id):'/'; if(window.ktSetAlt){ window.ktSetAlt(r); return; } var u='https://www.kiddietrac.com'+r; if(canEl)canEl.setAttribute('href',u); if(ogu)ogu.setAttribute('content',u); }"),
        ("var path=(id&&id!=='home')?('/'+id):'/';\n        if(location.pathname!==path || location.hash){ history.pushState({ktid:id},'',path); }",
         "var path=(id&&id!=='home')?('/'+id):'/'; var LP=window.KT_LP||''; var full=path==='/'?(LP||'/'):LP+path;\n        if(location.pathname!==full || location.hash){ history.pushState({ktid:id},'',full); }"),
        ("var seg=(location.pathname||'/').replace(/^\\/+|\\/+$/g,'');",
         "var seg=(location.pathname||'/').replace(/^\\/(fr|es|hi)(?=\\/|$)/,'').replace(/^\\/+|\\/+$/g,'');", 2),
    ])

    # 3. Translator: the address decides the language; choosing one moves the address.
    out = _in_script(out, 'whole-page translation (2026-09-28)', [
        ("try { localStorage.setItem('kt_lang', l); } catch (e) {}",
         "try { localStorage.setItem('kt_lang', l); } catch (e) {}\n    try { if (window.ktLangUrl) window.ktLangUrl(l); } catch (e) {}"),
        ("var want = null;\n    try { want = localStorage.getItem('kt_lang'); } catch (e) {}",
         "var want = window.KT_PATH_LANG || null;\n    if (!want) { try { want = localStorage.getItem('kt_lang'); } catch (e) {} }"),
    ])

    # 4. Blog: addresses keep the prefix; reuse a server-rendered article; bodies on demand.
    out = _in_script(out, 'THE BLOG HAD NO ARTICLES', [
        ("'<a class=\"blog-more\" href=\"/blog/' + esc(p.slug)",
         "'<a class=\"blog-more\" href=\"' + (window.KT_LP || '') + '/blog/' + esc(p.slug)"),
        ("<a class=\"kt-post-back\" href=\"/blog\">",
         "<a class=\"kt-post-back\" href=\"' + (window.KT_LP || '') + '/blog\">"),
        ("'<article class=\"kt-post\" lang=\"' + L() + '\">' + tx(p, 'body') + '</article>'",
         "'<article class=\"kt-post\" lang=\"' + L() + '\" data-slug=\"' + esc(p.slug) + '\">' + bodyOf(p) + '</article>'"),
        ("  var wrap = document.createElement('div');\n  wrap.className = 'kt-post-wrap';\n  wrap.setAttribute('translate', 'no');\n  page.appendChild(wrap);",
         "  /* A server-rendered article (seo_pages.py) is already in the page: use its wrap. */\n"
         "  var wrap = page.querySelector('.kt-post-wrap');\n"
         "  if (!wrap) { wrap = document.createElement('div'); wrap.className = 'kt-post-wrap'; wrap.setAttribute('translate', 'no'); page.appendChild(wrap); }\n"
         "  /* Article bodies live in /blog/body/<slug>.<lang>.html (seo_pages.py moves them out of\n"
         "     the page). Inline bodies, a server-rendered article, then the file, then English. */\n"
         "  var BODY = {};\n"
         "  function bodyOf(p) {\n"
         "    var l = L(), k = p.slug + '.' + l;\n"
         "    var inline = l === 'en' ? p.body : (p.i18n && p.i18n[l] && p.i18n[l].body);\n"
         "    if (inline) return inline;\n"
         "    if (BODY[k] != null) return BODY[k];\n"
         "    var ssr = page.querySelector('article.kt-post[data-slug=\"' + p.slug + '\"][lang=\"' + l + '\"]');\n"
         "    if (ssr && ssr.innerHTML.length > 200) { BODY[k] = ssr.innerHTML; return BODY[k]; }\n"
         "    function get(lang) { return fetch('/blog/body/' + p.slug + '.' + lang + '.html').then(function (r) { return r.ok ? r.text() : ''; }); }\n"
         "    get(l).then(function (h) { return h || (l !== 'en' ? get('en') : ''); }).catch(function () { return ''; }).then(function (h) {\n"
         "      BODY[k] = h || (p.i18n && p.i18n[l] && p.i18n[l].excerpt) || p.excerpt || '';\n"
         "      if (current === p.slug && page.classList.contains('reading')) { render(p); }\n"
         "    });\n"
         "    return '<p style=\"color:#94A3B8\">…</p>';\n"
         "  }"),
        ("    var url = 'https://www.kiddietrac.com/blog/' + p.slug;\n    if (canEl) canEl.setAttribute('href', url);\n    if (ogu) ogu.setAttribute('content', url);",
         "    if (window.ktSetAlt) { window.ktSetAlt('/blog/' + p.slug); } else {\n    var url = 'https://www.kiddietrac.com/blog/' + p.slug;\n    if (canEl) canEl.setAttribute('href', url);\n    if (ogu) ogu.setAttribute('content', url); }"),
        ("try { history.replaceState(history.state, '', '/blog'); } catch (e) {}",
         "try { history.replaceState(history.state, '', (window.KT_LP || '') + '/blog'); } catch (e) {}"),
        ("try { history.pushState({ ktpost: slug }, '', '/blog/' + slug); } catch (e) {}",
         "try { history.pushState({ ktpost: slug }, '', (window.KT_LP || '') + '/blog/' + slug); } catch (e) {}"),
        ("try { history.replaceState({ ktpost: s }, '', '/blog/' + s); } catch (e) {}",
         "try { history.replaceState({ ktpost: s }, '', (window.KT_LP || '') + '/blog/' + s); } catch (e) {}"),
        ("function slugFrom(path) { var m = /^\\/blog\\/([a-z0-9-]+)\\/?$/.exec(path || ''); return m ? m[1] : null; }",
         "function slugFrom(path) { var m = /^\\/blog\\/([a-z0-9-]+)\\/?$/.exec(String(path || '').replace(/^\\/(fr|es|hi)(?=\\/|$)/, '')); return m ? m[1] : null; }"),
        ("if (/^\\/blog\\/?$/.test(location.pathname)) page.classList.remove('reading');",
         "if (/^\\/blog\\/?$/.test(location.pathname.replace(/^\\/(fr|es|hi)(?=\\/|$)/, ''))) page.classList.remove('reading');"),
    ])

    # 5. onclick-only menu links get a real address. The click still stays in the page.
    def link(m):
        pid = m.group(2)
        href = '/' if pid == 'home' else '/' + pid
        return '<a href="%s"%s onclick="showPage(\'%s\');return false;"' % (href, m.group(1), pid)
    out, n = re.subn(r'<a\b(?![^>]*\bhref=)([^>]*?)\s*onclick="showPage\(\'([a-z0-9-]+)\'\)"', link, out)
    assert n >= 30, ('seo_edits links', n)
    return out
