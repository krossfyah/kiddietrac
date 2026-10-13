# -*- coding: utf-8 -*-
"""Real photos on the Safe Arrival page (2026-10-13, Anthony: "add some real images to the
safe arrival page and not just all text").

Two additions, both from the site's own photo library (/images/life, already used across
the site):
  1. "A real morning" storyboard right after the hero: four photos in time order, using
     the page's default timings (expected 9:00, parents asked +15, staff alerted +20).
  2. A full-width photo banner before "Who hears what".
Facts match the rest of the page (SafeArrival.php / SafeArrivalCommand.php).

usage: python safearrival_photos.py in.html out.html   (or import apply())"""
import sys

IMG = '/images/life/'

CSS = """<style>
/* ── Safe Arrival photos (safearrival_photos.py) ── */
.sa-story{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:36px}
.sa-ph{background:#fff;border:1px solid #dcefe7;border-radius:22px;overflow:hidden;box-shadow:0 22px 46px -34px rgba(2,44,34,.5);display:flex;flex-direction:column}
.sa-ph figure{margin:0;position:relative;aspect-ratio:4/3;overflow:hidden;background:#d1fae5}
.sa-ph img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .6s ease}
.sa-ph:hover img{transform:scale(1.04)}
.sa-ph .sa-t{position:absolute;left:12px;top:12px;background:rgba(2,44,34,.86);color:#6ee7b7;font-weight:800;font-size:13px;letter-spacing:.02em;border-radius:999px;padding:5px 11px;backdrop-filter:blur(4px)}
.sa-ph .sa-t.amb{color:#fde68a}.sa-ph .sa-t.cor{color:#fecaca}
.sa-ph .sa-pb{padding:16px 18px 18px}
.sa-ph h3{margin:0 0 6px;font-size:17px;font-weight:800;color:#022c22}
.sa-ph p{margin:0;font-size:14.5px;line-height:1.55;color:#3f5f55}
.sa-photo-band{position:relative;min-height:380px;display:flex;align-items:center;overflow:hidden;background:#022c22}
.sa-photo-band img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 30%}
.sa-photo-band::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(2,44,34,.94) 0%,rgba(2,44,34,.78) 42%,rgba(2,44,34,.18) 100%)}
.sa-photo-band .sa-wrap{position:relative;z-index:1;width:100%}
.sa-photo-band blockquote{margin:0;max-width:560px;color:#fff;font-size:clamp(24px,3.2vw,36px);line-height:1.18;font-weight:900;letter-spacing:-.01em}
.sa-photo-band blockquote span{background:linear-gradient(120deg,#6ee7b7,#34d399 50%,#a7f3d0);-webkit-background-clip:text;background-clip:text;color:transparent}
.sa-photo-band p{max-width:520px;margin:16px 0 0;color:#c7ead9;font-size:16.5px;line-height:1.6}
@media (max-width:980px){.sa-story{grid-template-columns:1fr 1fr}}
@media (max-width:640px){
  .sa-story{grid-template-columns:1fr;gap:14px}
  .sa-photo-band{min-height:440px;align-items:flex-end}
  .sa-photo-band::after{background:linear-gradient(0deg,rgba(2,44,34,.95) 0%,rgba(2,44,34,.82) 48%,rgba(2,44,34,.2) 100%)}
  .sa-photo-band .sa-wrap{padding-bottom:34px}
}
@media (prefers-reduced-motion:reduce){.sa-ph img{transition:none}.sa-ph:hover img{transform:none}}
</style>"""


def img(name, alt, sizes):
    return ('<img loading="lazy" decoding="async" src="%s%s-800.jpg" srcset="%s%s-800.jpg 800w, %s%s-1600.jpg 1600w" '
            'sizes="%s" alt="%s">') % (IMG, name, IMG, name, IMG, name, sizes, alt)


def card(name, alt, time, tone, title, text):
    return ('<div class="sa-ph"><figure>' + img(name, alt, '(max-width:640px) 100vw, (max-width:980px) 50vw, 25vw')
            + '<span class="sa-t%s">%s</span></figure><div class="sa-pb"><h3>%s</h3><p>%s</p></div></div>') % (
                (' ' + tone) if tone else '', time, title, text)


STORY = ('<section class="sa-sec"><div class="sa-wrap"><div class="sa-center">'
         '<span class="sa-eb">A real morning</span>'
         '<h2 class="sa-h2">Most mornings, nobody hears a thing</h2>'
         '<p class="sa-lead">When children arrive as expected, Safe Arrival stays quiet. It only speaks up for the child nobody has accounted for yet.</p>'
         '</div><div class="sa-story">'
         + card('walking-together', 'Two children walking together along a country path', '8:40', '',
                'On the way', 'Families head in on their usual schedule. There is nothing for anyone to do.')
         + card('parent-hand', 'A child holding a parent’s hand at drop-off', '9:00', '',
                'Signed in at the door', 'Once a child is signed in, their check closes on its own.')
         + card('phone-crayons', 'A parent’s phone on a table among crayons', '9:15', 'amb',
                'Parents asked first', 'Still not here and no absence reported? One message to the parents, by app, email or text.')
         + card('educator-board', 'An educator at the front of a classroom with children', '9:35', 'cor',
                'Your team steps in', 'No answer yet? The room’s educators and the director are alerted, with a number to call.')
         + '</div></div></section>')

BAND = ('<section class="sa-photo-band">' + img('educator-child', 'An educator talking with a young child at a table', '100vw')
        + '<div class="sa-wrap"><blockquote>Every child is <span>here, on the way, or accounted for.</span></blockquote>'
        '<p>That is the answer Safe Arrival gives your team each morning, without a round of phone calls.</p></div></section>')

DEMO_ANCHOR = '<section class="sa-band sa-demo"'
WHO_ANCHOR = '<section class="sa-sec alt"><div class="sa-wrap"><div class="sa-center"><span class="sa-eb">Who hears what</span>'
CSS_ANCHOR = '/* ── KiddieTrac Safe Arrival sub-brand (safearrival_edits.py) ── */'


def apply(s):
    if 'class="sa-story"' in s:
        return s  # already applied
    i = s.index('id="page-safe-arrival"')
    for a in (DEMO_ANCHOR, WHO_ANCHOR):
        assert s.count(a) == 1, ('anchor not unique', a[:50], s.count(a))
        assert s.index(a) > i, ('anchor before the page', a[:50])
    s = s.replace(DEMO_ANCHOR, STORY + '\n' + DEMO_ANCHOR, 1)
    s = s.replace(WHO_ANCHOR, BAND + '\n' + WHO_ANCHOR, 1)
    # CSS: right after the Safe Arrival sub-brand stylesheet.
    c = s.index(CSS_ANCHOR)
    end = s.index('</style>', c) + len('</style>')
    s = s[:end] + CSS + s[end:]
    return s


if __name__ == '__main__':
    src = open(sys.argv[1], encoding='utf-8').read()
    out = apply(src)
    with open(sys.argv[2], 'w', encoding='utf-8', newline='') as f:
        f.write(out)
    print('photos added', out.count('class="sa-ph"'), 'cards +', out.count('class="sa-photo-band"'), 'band')
