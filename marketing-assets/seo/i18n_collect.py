"""New untranslatable keys introduced by a build: missing(new) - missing(old), per language.
usage: python collect.py old.html new.html i18n_dir out.json"""
import json
import sys

sys.path.insert(0, 'host')
import ssr_i18n as S  # noqa: E402


def missing_for(src, d):
    missing = {}

    class Collect(S.Translator):
        def unit(self, e):
            r = S.Translator.unit(self, e)
            if r is None:
                n = [0]

                def rec(node):
                    out = []
                    for c in node.kids:
                        if not isinstance(c, S.El):
                            out.append(c.text)
                        else:
                            i = n[0]
                            n[0] += 1
                            out.append('{%d/}' % i if self.is_atom(c) else '{%d}%s{/%d}' % (i, rec(c), i))
                    return ''.join(out)
                missing[self.key_of(e)] = S.norm(rec(e))
            return r

        def loose(self, t):
            v = t.text
            if S.LET.search(v) and self.d.get('T|' + S.norm(v)) is None and self.d.get('|' + S.norm(v)) is None:
                missing['T|' + S.norm(v)] = S.norm(v)
            return S.Translator.loose(self, t)

        def start_tag(self, e, tr):
            for a in S.ATTRS:
                v = e.attrs.get(a)
                if v and S.LET.search(v) and self.d.get('A|' + S.norm(v)) is None:
                    missing['A|' + S.norm(v)] = S.norm(v)
            return S.Translator.start_tag(self, e, tr)

    tree = S.Tree(src)
    body = S.find(tree.root, 'body')
    Collect(src, d).render(body, is_body=True)
    return missing


old = open(sys.argv[1], encoding='utf-8').read()
new = open(sys.argv[2], encoding='utf-8').read()
res = {}
for lang in ('fr', 'es', 'hi'):
    d = json.load(open('%s/%s.json' % (sys.argv[3], lang), encoding='utf-8'))
    a = missing_for(old, d)
    b = missing_for(new, d)
    res[lang] = {k: v for k, v in b.items() if k not in a}
    print(lang, 'old missing', len(a), 'new missing', len(b), 'introduced', len(res[lang]))
json.dump(res, open(sys.argv[4], 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
