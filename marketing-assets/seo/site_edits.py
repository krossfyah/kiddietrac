"""Content additions to the non-home pages. Imported by build.py; every item maps to a
real screen in the portal (app-v2-shell.js nav) or a dated build in the project notes."""

CATS = [
    ('📋', 'Daily operations', ['QR check-in &amp; kiosk', 'Live attendance', 'Room ratios', 'Daily logs', 'Photos &amp; video',
                               'Room assignments', 'Closures &amp; holidays', 'Field trips with GPS', 'Bus routes',
                               'Late pick-ups', 'Vacation holds']),
    ('👨‍👩‍👧', 'Families', ['Parent app &amp; portal', 'Messenger', 'Announcements', 'SMS broadcasts',
                       'Voice announcement calls', 'Daily summary emails', 'AI daily recaps', 'Parent feedback &amp; NPS',
                       'Forms to sign', 'Family documents']),
    ('💳', 'Billing &amp; payments', ['Automated invoicing', 'Bulk invoice runs', 'Payment schedules', 'CWELCC subsidies',
                                    'Card, EFT &amp; Interac', 'CAD + USD billing', 'Refunds', 'Account ledgers',
                                    'QuickBooks', 'Expenses']),
    ('👩‍🏫', 'Staff', ['Time clock', 'Timesheets', 'Payroll', 'Staff calendar', 'Time off', 'Substitutes',
                     'Certifications', 'Background checks', 'Roles &amp; permissions']),
    ('🎨', 'Learning', ['Lesson plans', 'Curriculum', 'Observations', 'Report cards', 'Awards', 'Conferences']),
    ('🩺', 'Health &amp; safety', ['Immunizations &amp; reminders', 'Medications', 'Allergy alerts', 'Incident reports',
                                 'Inspection checklists', 'Home visit reports', 'Weekly menus', 'CACFP meals']),
    ('📈', 'Growth &amp; insights', ['Waitlist', 'Tours', 'Re-enrolment', 'Marketing campaigns', 'Drip campaigns',
                                   'Enrolment forecast', 'AI churn risk', 'AI photo tagging', 'AI document extraction']),
    ('🛡️', 'Security &amp; admin', ['Two-factor sign-in', 'Security alerts', 'Audit log', 'Nightly backups',
                                   'Data retention', 'e-Signatures', 'Custom forms', 'White-label branding',
                                   'Email from your domain']),
]

DIRECTORY_CSS = """<style>
.k6-dir{padding:70px 0 20px}
.k6-dir-h{text-align:center;margin-bottom:34px}
.k6-dir-g{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.k6-dir-c{background:#fff;border:1px solid rgba(11,27,51,.08);border-radius:20px;padding:20px;transition:transform .3s,box-shadow .3s}
.k6-dir-c:hover{transform:translateY(-4px);box-shadow:0 28px 56px -32px rgba(11,27,51,.4)}
.k6-dir-c h3{display:flex;align-items:center;gap:10px;font-size:16.5px;font-weight:900;margin:0 0 12px;color:#0b1b33}
.k6-dir-c h3 i{font-style:normal;width:38px;height:38px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(135deg,#e5f7fb,#eefbe6);font-size:19px}
.k6-dir-c ul{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px}
.k6-dir-c li{font-size:12.5px;font-weight:800;color:#23405f;background:#f4f7fb;border-radius:8px;padding:5px 9px}
.k6-dir-n{display:inline-block;margin-top:8px;font-size:14px;color:#5b6b82;font-weight:700}
@media (max-width:980px){.k6-dir-g{grid-template-columns:1fr 1fr}}
@media (max-width:640px){.k6-dir-g{grid-template-columns:1fr}}
</style>"""


def directory_html():
    total = sum(len(c[2]) for c in CATS)
    cards = ''.join(
        '<div class="k6-dir-c reveal"><h3><i>%s</i>%s</h3><ul>%s</ul></div>'
        % (ic, name, ''.join('<li>%s</li>' % f for f in feats))
        for ic, name, feats in CATS)
    return (DIRECTORY_CSS
            + '<section class="k6-dir"><div class="container">'
            + '<div class="k6-dir-h"><div class="section-label">Everything in KiddieTrac</div>'
            + '<h2 class="section-title">%d+ tools, one login</h2>' % (total // 10 * 10)
            + '<p class="section-sub" style="margin:0 auto">Every one of these is live in the platform today, included on the plan that fits your program.</p></div>'
            + '<div class="k6-dir-g">' + cards + '</div></div></section>')


CHANGELOG = [
    ('September 2026', '💳 Card payments with Helcim',
     'A third payment provider alongside Zūm Rails and Stripe. Cards are collected inside Helcim\'s own secure checkout, so card numbers never reach KiddieTrac, and each payment is confirmed server-side before it counts against an invoice.'),
    ('September 2026', '📞 Voice announcement calls &amp; Telnyx SMS',
     'Broadcast an urgent announcement as an automated phone call, not just a text. SMS can now run on Telnyx as well as Twilio, with automatic failover if a carrier is unavailable.'),
    ('September 2026', '✅ Text-alert consent records',
     'Every opt-in and opt-out is confirmed to the family by email and text and filed in their documents. A STOP reply now applies to every account tied to that phone number.'),
    ('September 2026', '🗓️ Payment schedules &amp; service fees',
     'Instalment plans issue each invoice a set number of days before it is due. Agencies can optionally apply card and EFT service-fee percentages at the moment of payment.'),
    ('September 2026', '🛡️ Verified nightly backups',
     'Every night the platform is backed up and verified, with 14 days retained and a schedule platform admins can adjust.'),
    ('August 2026', '✉️ Email from your own domain',
     'Invoices, reports and notices can go out from your agency\'s own Microsoft 365 or Google Workspace mailbox instead of a KiddieTrac address.'),
    ('August 2026', '🔐 Two-factor sign-in &amp; security alerts',
     'Two-factor authentication for staff accounts, and real-time alerts when something suspicious happens, such as repeated failed sign-ins.'),
]


def changelog_html():
    return ''.join(
        '<div class="kt-log-item"><div class="kt-log-date">%s <span class="kt-log-new">NEW</span></div>'
        '<div class="kt-log-body"><h3>%s</h3><p>%s</p></div></div>\n        ' % (d, t, b)
        for d, t, b in CHANGELOG)


def apply(out):
    # Solutions: the directory goes straight after the page banner.
    a = out.find('id="page-solutions"')
    hb = out.find('<div class="hero-band">', a)
    end = out.find('</div></div>', hb) + len('</div></div>')
    assert a > 0 and hb > a and end > hb
    out = out[:end] + '\n' + directory_html() + out[end:]
    # What's new: newest first.
    c = out.find('id="page-changelog"')
    k = out.find('<div class="kt-log">', c) + len('<div class="kt-log">')
    assert c > 0 and k > c
    out = out[:k] + '\n        ' + changelog_html() + out[k:]
    return out


# ── Real flags ────────────────────────────────────────────────────────────────
# Windows draws flag emoji as two letters ("CA", "US"), so every flag emoji in the
# page's HTML becomes a flag-icons SVG hosted at /images/flags/. Scripts are left alone.
import re as _re

FLAG_NAMES = {'ca': 'Canada', 'us': 'United States', 'gb': 'United Kingdom', 'au': 'Australia'}
FLAG_CSS = ('<style>.k6-flag{display:inline-block;height:.95em;width:auto;aspect-ratio:4/3;border-radius:3px;'
            'vertical-align:-.12em;box-shadow:0 0 0 1px rgba(11,27,51,.12);margin-right:.3em}'
            '#page-global .card-icon .k6-flag{height:40px;border-radius:8px;margin:0;'
            'box-shadow:0 8px 18px -8px rgba(11,27,51,.45),0 0 0 1px rgba(11,27,51,.08)}'
            '#page-global .card-icon{background:none!important;width:auto!important;height:auto!important}</style>')


def _flag_img(m):
    code = ''.join(chr(ord(c) - 0x1F1E6 + ord('a')) for c in m.group(0))
    if code not in FLAG_NAMES:
        return m.group(0)
    return '<img class="k6-flag" src="/images/flags/%s.svg" alt="%s">' % (code, FLAG_NAMES[code])


def flags(out):
    parts = _re.split(r'(<script\b.*?</script>)', out, flags=_re.S)
    pat = _re.compile('[\U0001F1E6-\U0001F1FF]{2}')
    for i in range(0, len(parts), 2):          # even indexes are outside <script>
        parts[i] = pat.sub(_flag_img, parts[i])
    out = ''.join(parts)
    j = out.rfind('</body>')
    return out[:j] + FLAG_CSS + '\n' + out[j:]


_apply_content = apply


def apply(out):
    return flags(_apply_content(out))


# ── Cookie consent ────────────────────────────────────────────────────────────
CONSENT_HEAD = ("<script>/* Consent defaults, before anything else runs. The old notice's \"Essential only\" "
                "button called the same function as \"Got it\", so its kt_cookie flag cannot be trusted: "
                "only an explicit analytics choice in kt_consent keeps it. */(function(){try{"
                "var c=JSON.parse(localStorage.getItem('kt_consent')||'null');var ok=!!(c&&c.v===1&&c.analytics);"
                "if(!ok){localStorage.removeItem('kt_cookie');}"
                "window.dataLayer=window.dataLayer||[];function g(){dataLayer.push(arguments);}"
                "g('consent','default',{analytics_storage:ok?'granted':'denied',ad_storage:'denied',ad_user_data:'denied',"
                "ad_personalization:'denied',functionality_storage:'granted',security_storage:'granted',wait_for_update:500});"
                "}catch(e){}})();</script>")

PRIVACY_COOKIES = (
    '<h3 style="font-size:18px;font-weight:800;color:var(--dark);margin:28px 0 10px">8. Cookies &amp; Analytics</h3>'
    '<p style="font-size:15px;color:var(--gray);line-height:1.8">This website uses <strong>strictly necessary</strong> '
    'storage to work: remembering your cookie choice, your language and form security. With your permission it also '
    'uses <strong>analytics cookies</strong> (Google Analytics 4, with IP anonymisation) to understand which pages are '
    'useful. Analytics only runs after you choose “Accept all” or switch it on, and turning it off deletes those cookies. '
    'We do not use advertising, retargeting or cross-site tracking cookies. You can change your choice at any time: '
    '<a onclick="ktCookieSettings()" style="color:var(--teal);font-weight:700;cursor:pointer">Cookie settings</a>.</p>')


def consent(out):
    out = out.replace('<head>', '<head>\n' + CONSENT_HEAD, 1)
    # Retire the old notice's markup (its script is null-safe).
    a = out.find('<div class="kt-cookie" id="ktCookie"')
    if a > 0:
        b = out.find('</div>\n</div>', a)
        assert b > a
        out = out[:a] + '<!-- old cookie notice replaced by #ktConsent -->' + out[b + len('</div>\n</div>'):]
    # Footer: a way back to the choice from every page.
    t = "<li onclick=\"showPage('terms')\">Terms of Use</li>"
    assert out.count(t) == 1
    out = out.replace(t, t + '<li onclick="ktCookieSettings()">Cookie settings</li>')
    # Privacy page: a Cookies section before Contact (renumbered).
    p = out.find('id="page-privacy"')
    c = out.find('8. Contact Us</h3>', p)
    assert p > 0 and c > p
    h = out.rfind('<h3', p, c)
    out = out[:h] + PRIVACY_COOKIES + out[h:c] + '9. Contact Us</h3>' + out[c + len('8. Contact Us</h3>'):]
    j = out.rfind('</body>')
    return out[:j] + open('consent.html', encoding='utf-8').read() + '\n' + out[j:]


_apply_flags = apply


def apply(out):
    return consent(_apply_flags(out))


# ── Translation atoms: live values that must not be part of a sentence's key ──
def atoms(out):
    for i in ('price-starter', 'price-pro'):
        a = 'id="%s"' % i
        assert out.count(a) == 1, i
        out = out.replace(a, a + ' data-k6u')
    return out


_apply_consent = apply


def apply(out):
    return atoms(_apply_consent(out))


# ── Whole-site translation runtime + locale-aware booking dates ───────────────
import time as _time

I18N_VERSION = _time.strftime('%Y%m%d%H%M')


def i18n(out):
    a = "d.toLocaleDateString('en-CA',"
    assert out.count(a) == 1
    out = out.replace(a, "d.toLocaleDateString((typeof currentLang!=='undefined'&&currentLang==='fr')?'fr-CA':((typeof currentLang!=='undefined'&&currentLang==='es')?'es':((typeof currentLang!=='undefined'&&currentLang==='hi')?'hi-IN':'en-CA')),")
    rt = open('i18n_runtime.js', encoding='utf-8').read().replace('__K6I18N_VERSION__', I18N_VERSION)
    j = out.rfind('</body>')
    return out[:j] + '<script>\n' + rt + '\n</script>\n' + out[j:]


_apply_atoms = apply


def apply(out):
    return i18n(_apply_atoms(out))


# ── Live chat: a person from the team, Maya covering (always as the assistant) ──
import os as _os


def livechat(out):
    def one(a, b):
        nonlocal out
        assert out.count(a) == 1, a[:70]
        out = out.replace(a, b)

    # Maya's answer, split out of sendChat so the live layer can hold it for a person.
    one("""  addMsg(msg, 'user');
  input.value = '';

  const learned = ktLearn(msg);
  const ack = ktAck(msg);""", """  addMsg(msg, 'user');
  input.value = '';
  ktMayaRespond(msg, ktLearn(msg));
}
/* Maya's answer to one visitor line. Split out of sendChat so the live-chat layer can
   hold it back while a person is fetched, and use it if nobody comes. */
function ktMayaRespond(msg, learned) {
  const ack = ktAck(msg);""")
    # Header + greeting: say what she is from the first line.
    one('<div class="chat-avatar">👩🏻‍💼</div>', '<div class="chat-avatar">✨</div>')
    one('<div class="chat-header-info"><strong>Maya — KiddieTrac</strong><span><i class="chat-presence"></i>Online — usually replies in a minute</span></div>',
        '<div class="chat-header-info" id="ktChatHead" translate="no"><strong>Maya · virtual assistant</strong><span><i class="chat-presence off"></i>KiddieTrac</span></div>')
    one("👋 Hi, I'm Maya. How can I help you today?", "👋 Hi, I'm Maya, KiddieTrac's virtual assistant.")
    one('What are you trying to sort out? Pricing, CWELCC, moving off another system — whatever it is, ask away.',
        'Ask me anything — pricing, CWELCC, moving off another system. Rather talk to a person? Tap <strong>Talk to a person</strong> once we start and I\'ll get the team.')
    one("""No — I am KiddieTrac's assistant, not a person. I did not want to pretend otherwise.`,
      `I can answer most things here, and anything I cannot I will pass to the team with this conversation attached. Want me to get someone to email you?`""",
        """No — I am KiddieTrac's virtual assistant, not a person. I did not want to pretend otherwise.`,
      `I can answer most things here. If you would rather talk to someone on the team, tap **Talk to a person** below and I will get them.`""")
    # The ✕ closes the chat for the team as well; leaving the page does not.
    one("""          context: ktContext(),
        }),""", """          context: ktContext(),
          reason: silent ? 'left' : 'closed',
        }),""")
    # Which page they were on, for the inbox.
    one("sender: sender, message: String(message).slice(0, 2000) }), keepalive: true",
        "sender: sender, message: String(message).slice(0, 2000), page: (location.pathname + location.hash).slice(0, 255) }), keepalive: true")
    j = out.rfind('</body>')
    return out[:j] + open('livechat.html', encoding='utf-8').read() + '\n' + out[j:]


# i18n stays out of the live build until it has been checked in a real browser:
# K6_I18N=0 python build.py live
_apply_i18n = apply


def apply(out):
    base = _apply_i18n(out) if _os.environ.get('K6_I18N', '1') != '0' else atoms(_apply_consent(out))
    return livechat(base) if _os.environ.get('K6_LIVECHAT', '1') != '0' else base


# ── Blog: real articles behind the cards (2026-09-28) ──
import glob as _glob
import html as _html
import json as _json

_BLOG_CATS = {'compliance': 'Compliance', 'finance': 'Finance', 'ops': 'Operations', 'families': 'Families',
              'development': 'Child development', 'growth': 'Growth', 'tech': 'Technology'}
_MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']


def _posts():
    posts = []
    tr = {}
    for f in sorted(_glob.glob('blog/posts-*.json')):
        if f.endswith('.i18n.json'):
            tr.update(_json.load(open(f, encoding='utf-8')))      # {slug: {fr: {...}, es: {...}}}
            continue
        posts.extend(_json.load(open(f, encoding='utf-8')))
    for p in posts:
        if p['slug'] in tr:
            p['i18n'] = tr[p['slug']]
    slugs = [p['slug'] for p in posts]
    assert len(slugs) == len(set(slugs)), 'duplicate blog slug'
    for p in posts:
        assert p['cat'] in _BLOG_CATS, p['slug']
    return sorted(posts, key=lambda p: (p['date'], p['title']), reverse=True)


def _card(p):
    e = _html.escape
    y, m, d = p['date'].split('-')
    return ('<div class="blog-card" data-cat="%s" data-slug="%s"><div class="blog-thumb">%s</div><div class="blog-body">'
            '<span class="blog-cat %s">%s</span><div class="blog-meta">%s %d, %s · %d min read</div>'
            '<div class="blog-title">%s</div><div class="blog-excerpt">%s</div>'
            '<a class="blog-more" href="/blog/%s">Read more →</a></div></div>') % (
        e(p['cat']), e(p['slug']), e(p.get('emoji', '📝')), e(p['cat']), _BLOG_CATS[p['cat']],
        _MON[int(m) - 1], int(d), y, int(p.get('minutes', 5)), e(p['title']), e(p['excerpt']), e(p['slug']))


def blog(out):
    posts = _posts()
    if not posts:
        return out
    # Static cards too, so the page is right before any script runs (and for crawlers).
    a = out.find('<div class="cards-grid cards-3" id="blogGrid">')
    b = out.find('\n  </div>\n</div></section></div>\n<div class="page" id="page-testimonials">', a)
    assert 0 < a < b, 'blog grid not found'
    head = '<div class="cards-grid cards-3" id="blogGrid">'
    out = out[:a] + head + ''.join(_card(p) for p in posts) + out[b:]
    old = ('Guides for Canadian childcare operators: CWELCC compliance, subsidy recovery, QR check-in ROI, '
           'multi-currency billing and growing your agency.')
    assert out.count(old) == 1
    out = out.replace(old, 'Practical guides for childcare operators: CWELCC and compliance, billing, running a centre, '
                           'working with families, child development and growing your program.')
    # The router sends an unknown path home before the blog script runs; remember where we came in.
    out = out.replace('<head>', '<head>\n<script>window.__ktInitialPath=location.pathname;</script>', 1)
    data = _json.dumps(posts, ensure_ascii=False).replace('</', r'<\/')
    j = out.rfind('</body>')
    return out[:j] + open('blog.html', encoding='utf-8').read().replace('/*__KT_POSTS__*/[]', data) + '\n' + out[j:]


_apply_livechat_chain = apply


def apply(out):
    return blog(_apply_livechat_chain(out))


# -- Real-life photos + video (life_edits.py); K6_LIFE=0 builds without them --
import life_edits as _life

_apply_blog_chain = apply


def apply(out):
    base = _apply_blog_chain(out)
    return _life.life(base) if _os.environ.get('K6_LIFE', '1') != '0' else base


# -- Shop look-book (shop_edits.py); K6_SHOP=0 builds without it --
import shop_edits as _shop

_apply_life_chain = apply


def apply(out):
    base = _apply_life_chain(out)
    return _shop.shop(base) if _os.environ.get('K6_SHOP', '1') != '0' else base


# -- Phone layout fixes (mobile_edits.py); K6_MOBILE=0 builds without them --
import mobile_edits as _mobile

_apply_shop_chain = apply


def apply(out):
    base = _apply_shop_chain(out)
    return _mobile.mobile(base) if _os.environ.get('K6_MOBILE', '1') != '0' else base


# -- SMS & voice alerts add-on on Pricing (pricing_edits.py); K6_ADDON=0 builds without it --
import pricing_edits as _pricing

_apply_mobile_chain = apply


def apply(out):
    base = _apply_mobile_chain(out)
    return _pricing.addon(base) if _os.environ.get('K6_ADDON', '1') != '0' else base


# -- Vulnerability reporting page (security_edits.py); K6_VR=0 builds without it --
import security_edits as _security

_apply_addon_chain = apply


def apply(out):
    base = _apply_addon_chain(out)
    return _security.security(base) if _os.environ.get('K6_VR', '1') != '0' else base


# -- Accessibility + DEI policy pages (policy_edits.py); K6_POLICIES=0 builds without them --
import policy_edits as _policies

_apply_vr_chain = apply


def apply(out):
    base = _apply_vr_chain(out)
    return _policies.policies(base) if _os.environ.get('K6_POLICIES', '1') != '0' else base


# -- More real-life photos on pages that had none (life2_edits.py); K6_LIFE2=0 builds without --
import life2_edits as _life2

_apply_policies_chain = apply


def apply(out):
    base = _apply_policies_chain(out)
    return _life2.life2(base) if _os.environ.get('K6_LIFE2', '1') != '0' else base


# -- Hindi buttons, toll-free number, iPhone/Android band (extras_edits.py); K6_EXTRAS=0 builds without --
import extras_edits as _extras

_apply_life2_chain = apply


def apply(out):
    base = _apply_life2_chain(out)
    return _extras.extras(base) if _os.environ.get('K6_EXTRAS', '1') != '0' else base


# -- Webinars + Internet Safety pages (resources_edits.py); K6_RES=0 builds without --
import resources_edits as _res

_apply_extras_chain = apply


def apply(out):
    base = _apply_extras_chain(out)
    return _res.resources(base) if _os.environ.get('K6_RES', '1') != '0' else base


# -- Booking wizard with a full-month calendar (booking_edits.py); K6_BOOK=0 builds without --
import booking_edits as _book

_apply_res_chain = apply


def apply(out):
    base = _apply_res_chain(out)
    return _book.booking(base) if _os.environ.get('K6_BOOK', '1') != '0' else base


# -- Footer newsletter that really subscribes (newsletter_edits.py); K6_NEWS=0 builds without --
import newsletter_edits as _news

_apply_book_chain = apply


def apply(out):
    base = _apply_book_chain(out)
    return _news.newsletter(base) if _os.environ.get('K6_NEWS', '1') != '0' else base


# -- Contact page upgrades + /support page (contact_edits.py); K6_CONTACT=0 builds without --
import contact_edits as _contact

_apply_news_chain = apply


def apply(out):
    base = _apply_news_chain(out)
    return _contact.contact(base) if _os.environ.get('K6_CONTACT', '1') != '0' else base


# -- Brochure download links (brochure_edits.py); K6_BROCHURE=0 builds without --
import brochure_edits as _brochure

_apply_contact_chain = apply


def apply(out):
    base = _apply_contact_chain(out)
    return _brochure.brochure(base) if _os.environ.get('K6_BROCHURE', '1') != '0' else base


# -- Accurate hosting claims (claims_edits.py); K6_CLAIMS=0 builds without --
import claims_edits as _claims

_apply_brochure_chain = apply


def apply(out):
    base = _apply_brochure_chain(out)
    return _claims.claims(base) if _os.environ.get('K6_CLAIMS', '1') != '0' else base


# -- One page per address for search engines (seo_edits.py); K6_SEO=0 builds without --
import seo_edits as _seo

_apply_claims_chain = apply


def apply(out):
    base = _apply_claims_chain(out)
    return _seo.seo(base) if _os.environ.get('K6_SEO', '1') != '0' else base
