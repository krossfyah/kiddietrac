"""Port the in-place live edits that index.built.html is missing (2026-10-09 hosting-vendor
scrub of the privacy policy) so a regenerate does not undo them.
usage: python rebase.py in.html out.html"""
import sys

PORTS = [
    ('We use carefully vetted third-party services including Stripe (payment processing), SendGrid (email), AWS Canada (infrastructure), and Google Analytics (anonymized usage).',
     'We use carefully vetted third-party services including Stripe (payment processing), an email delivery service, secure North American data centres (infrastructure), and Google Analytics (anonymized usage).'),
]

s = open(sys.argv[1], encoding='utf-8').read()
for o, n in PORTS:
    c = s.count(o)
    if c == 0 and n in s:
        print('already ported:', n[:60])
        continue
    assert c >= 1, ('rebase: not found', o[:60])
    s = s.replace(o, n)
    print('ported x%d: %s' % (c, n[:60]))
open(sys.argv[2], 'w', encoding='utf-8', newline='').write(s)
