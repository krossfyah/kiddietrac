"""Translate the marketing page on the server, exactly as the browser does (2026-09-30).

The site translated French, Spanish and Hindi in the browser (the whole-page translator in
index.html, dictionaries in /i18n/<lang>.json). Search engines that do not run JavaScript
saw English on /fr, /es and /hi. This applies the SAME rules to the HTML, so the
translated words are in the page itself:

    unit   an element whose content is only text + inline elements, translated as a whole
           sentence with its own inline elements re-used ({n}...{/n}); atoms (br, img, wbr,
           [data-k6u]) kept as they are ({n/}). Key: '<pre-order tags>|<normalised text>'.
    loose  a text node outside a unit: 'T|<text>', then '|<text>'.
    attrs  placeholder, title, aria-label, alt: 'A|<text>'.
    select option labels: 'O|<text>', the value pinned to the English first.

Everything not translated is copied from the source byte for byte: the markup is never
re-serialised, so ids, onclick handlers and scripts are untouched. Python 3.6, stdlib only.
Mirrors the functions of the same names in the page's translator; keep them in step.
"""
import html
import re
from html.parser import HTMLParser

INLINE = {'a', 'abbr', 'b', 'br', 'cite', 'em', 'i', 'img', 'mark', 'q', 's', 'small', 'span',
          'strong', 'sub', 'sup', 'time', 'u', 'wbr'}
SKIP = {'script', 'style', 'svg', 'noscript', 'template', 'code', 'textarea', 'select', 'option', 'title', 'head'}
SKIP_IDS = {'page-admin', 'chatMessages'}
DECOR = {'span', 'i', 'strong', 'em', 'b', 'small', 'br', 'img'}
ATTRS = ['placeholder', 'title', 'aria-label', 'alt']
VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'}
LET = re.compile(r'[A-Za-zÀ-ÿ]{2,}')
WJ = '⁠'


def norm(t):
    return re.sub(r'\s+', ' ', t).strip()


def bare(t):
    return re.sub(r'[\s⁠]+', '', t)


class El(object):
    __slots__ = ('tag', 'attrs', 'start', 'end', 'cstart', 'cend', 'kids', 'parent')

    def __init__(self, tag, attrs, start, end, parent):
        self.tag, self.attrs, self.start, self.end, self.parent = tag, dict(attrs), start, end, parent
        self.cstart = self.cend = None
        self.kids = []


class Tx(object):
    __slots__ = ('text', 'start', 'end')

    def __init__(self, text, start, end):
        self.text, self.start, self.end = text, start, end


class Tree(HTMLParser):
    def __init__(self, src):
        HTMLParser.__init__(self, convert_charrefs=True)
        self.src = src
        self.lines = [0]
        for m in re.finditer('\n', src):
            self.lines.append(m.end())
        self.root = El('#root', [], 0, 0, None)
        self.stack = [self.root]
        self.feed(src)
        self.close()
        end = len(src)
        for e in self.stack[1:]:          # anything left open ends with the document
            e.cstart = e.cend = end

    def off(self):
        l, c = self.getpos()
        return self.lines[l - 1] + c

    def handle_starttag(self, tag, attrs):
        s = self.off()
        t = self.get_starttag_text()
        e = El(tag, attrs, s, s + len(t), self.stack[-1])
        self.stack[-1].kids.append(e)
        if tag in VOID:
            e.cstart = e.cend = e.end
        else:
            self.stack.append(e)

    def handle_startendtag(self, tag, attrs):
        s = self.off()
        t = self.get_starttag_text()
        e = El(tag, attrs, s, s + len(t), self.stack[-1])
        e.cstart = e.cend = e.end
        self.stack[-1].kids.append(e)

    def handle_endtag(self, tag):
        if tag in VOID:
            return
        s = self.off()
        for i in range(len(self.stack) - 1, 0, -1):
            if self.stack[i].tag == tag:
                for e in self.stack[i + 1:]:   # implicitly closed
                    e.cstart = e.cend = s
                self.stack[i].cstart = s
                self.stack[i].cend = self.src.find('>', s) + 1
                del self.stack[i:]
                return

    def handle_data(self, data):
        s = self.off()
        top = self.stack[-1]
        if top.tag in ('script', 'style'):
            return
        e = self.src.find('<', s)
        if e < 0:
            e = len(self.src)
        top.kids.append(Tx(data, s, e))


def _is_el(n):
    return isinstance(n, El)


class Translator(object):
    def __init__(self, src, d):
        self.src, self.d = src, d
        self.by_text = {}
        for k, v in d.items():
            if k[:1] in ('A', 'P') or re.search(r'\{\d', v):
                continue
            self.by_text[bare(k[k.index('|') + 1:])] = v
        self.stats = {'units': 0, 'units_tr': 0, 'loose': 0, 'loose_tr': 0, 'attrs': 0, 'options': 0}

    # ── the same predicates as the page's translator ──
    def is_atom(self, e):
        return e.tag in ('br', 'img', 'wbr') or 'data-k6u' in e.attrs

    def skipped(self, e):
        return e.tag in SKIP or e.attrs.get('translate') == 'no' or e.attrs.get('id') in SKIP_IDS

    def inline_only(self, e):
        for c in e.kids:
            if not _is_el(c):
                continue
            if c.tag not in INLINE and 'data-k6u' not in c.attrs:
                return False
            if not self.is_atom(c) and not self.inline_only(c):
                return False
        return True

    def container_only(self, e):
        n = 0
        for c in e.kids:
            if not _is_el(c) and c.text.strip():
                return False
            if _is_el(c) and not self.is_atom(c):
                n += 1
        return n >= 2

    def is_unit(self, e):
        return self.inline_only(e) and not self.container_only(e)

    def text_of(self, e):
        out = []
        for c in e.kids:
            if not _is_el(c):
                out.append(c.text)
            else:
                out.append(WJ if self.is_atom(c) else self.text_of(c))
        return ''.join(out)

    def elems(self, e, acc):
        for c in e.kids:
            if _is_el(c):
                acc.append(c)
                if not self.is_atom(c):
                    self.elems(c, acc)
        return acc

    def key_of(self, e):
        return ','.join(x.tag + ('*' if self.is_atom(x) else '') for x in self.elems(e, [])) + '|' + norm(self.text_of(e))

    def decorative_only(self, e):
        for x in self.all(e):
            if x.tag not in DECOR or 'data-k6u' in x.attrs:
                return False
        return True

    def all(self, e):
        for c in e.kids:
            if _is_el(c):
                yield c
                for y in self.all(c):
                    yield y

    # ── output ──
    def start_tag(self, e, translate_attrs):
        t = self.src[e.start:e.end]
        if not translate_attrs:
            return t
        for a in ATTRS:
            v = e.attrs.get(a)
            if not v or not LET.search(v):
                continue
            tv = self.d.get('A|' + norm(v))
            if tv is None:
                continue
            self.stats['attrs'] += 1
            t = re.sub(r'(\s%s\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)' % re.escape(a),
                       lambda m: m.group(1) + '"' + html.escape(tv, quote=True) + '"', t, count=1)
        return t

    def raw(self, e):
        return self.src[e.start:e.cend]

    def children(self, e, fn):
        """Render e's content: kids via fn, the gaps between them (comments) verbatim."""
        out, pos = [], e.end
        for c in e.kids:
            out.append(self.src[pos:c.start])
            out.append(fn(c))
            pos = c.cend if _is_el(c) else c.end
        out.append(self.src[pos:e.cstart])
        return ''.join(out)

    def loose(self, t):
        v = t.text
        if not LET.search(v):
            return self.src[t.start:t.end]
        self.stats['loose'] += 1
        tr = self.d.get('T|' + norm(v))
        if tr is None:
            tr = self.d.get('|' + norm(v))
        if tr is None or norm(v) == norm(tr):
            return self.src[t.start:t.end]
        self.stats['loose_tr'] += 1
        lead = re.match(r'^\s*', v).group(0)
        trail = re.search(r'\s*$', v).group(0)
        return lead + html.escape(tr, quote=False) + trail

    def select(self, e):
        def opt(o):
            if not _is_el(o) or o.tag != 'option':
                return self.src[o.start:o.end] if not _is_el(o) else self.raw(o)
            texts = [k for k in o.kids]
            st = self.src[o.start:o.end]
            if len(texts) != 1 or _is_el(texts[0]):
                return self.raw(o)
            en = texts[0].text
            if not LET.search(en):
                return self.raw(o)
            tr = self.d.get('O|' + norm(en))
            if tr is None:
                return self.raw(o)
            self.stats['options'] += 1
            if 'value' not in o.attrs:     # the answer submitted stays English
                st = st[:-1].rstrip('/') + ' value="%s">' % html.escape(en, quote=True)
            return st + html.escape(tr, quote=False) + self.src[o.cstart:o.cend]
        return self.start_tag(e, True) + self.children(e, opt) + self.src[e.cstart:e.cend]

    def unit(self, e):
        self.stats['units'] += 1
        tpl = self.d.get(self.key_of(e))
        els = self.elems(e, [])
        if tpl is not None:
            used = set(m.group(1) or m.group(3) for m in re.finditer(r'\{(\d+)(/?)\}|\{/(\d+)\}', tpl))
            if all(str(i) in used for i in range(len(els))):
                self.stats['units_tr'] += 1
                return self.build(els, tpl)
            return None
        plain = self.by_text.get(bare(norm(self.text_of(e))))
        if plain is not None and self.decorative_only(e) and not any(x.tag in ('img', 'br') for x in self.all(e)):
            self.stats['units_tr'] += 1
            return html.escape(plain, quote=False)
        return None

    def build(self, els, tpl):
        """The template, with each {n}..{/n} re-using element n's own tags."""
        out, stack, last = [], [], 0
        for m in re.finditer(r'\{(\d+)(/?)\}|\{/(\d+)\}', tpl):
            out.append(html.escape(tpl[last:m.start()], quote=False))
            last = m.end()
            if m.group(3) is not None:
                if stack:
                    out.append(stack.pop())
                continue
            i = int(m.group(1))
            if i >= len(els):
                continue
            el = els[i]
            if m.group(2) or self.is_atom(el):
                out.append(self.render(el) if 'data-k6u' in el.attrs else self.raw(el))
            else:
                out.append(self.src[el.start:el.end])
                stack.append(self.src[el.cstart:el.cend])
        out.append(html.escape(tpl[last:], quote=False))
        while stack:
            out.append(stack.pop())
        return ''.join(out)

    def render(self, e, is_body=False):
        if not _is_el(e):
            return self.loose(e)
        sk = self.skipped(e)
        if sk and e.tag not in ('textarea', 'select'):
            return self.raw(e)
        if e.tag == 'select':
            return self.select(e)
        if sk:     # textarea: its placeholder is read, its contents are not translated
            return self.start_tag(e, True) + self.src[e.end:e.cend]
        head = self.start_tag(e, True)
        if not is_body and self.is_unit(e) and LET.search(self.text_of(e)):
            inner = self.unit(e)
            if inner is not None:
                return head + inner + self.src[e.cstart:e.cend]
            return head + self.src[e.end:e.cend]   # untranslated unit stays exactly as it was
        return head + self.children(e, self.render) + self.src[e.cstart:e.cend]


def find(e, tag):
    for c in e.kids:
        if _is_el(c):
            if c.tag == tag:
                return c
            r = find(c, tag)
            if r:
                return r
    return None


def translate_doc(src, d):
    """Return (translated html, stats). Only <body> is walked, like the page's translator."""
    tree = Tree(src)
    body = find(tree.root, 'body')
    assert body is not None, 'no <body>'
    t = Translator(src, d)
    out = src[:body.start] + t.render(body, is_body=True) + src[body.cend:]
    return out, t.stats
