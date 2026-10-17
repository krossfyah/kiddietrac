# -*- coding: utf-8 -*-
"""KiddieTrac Reseller Program on the marketing site (2026-10-16).

Anthony: "add reseller option to marketing site under pricing but dont add pricing just have
contact sales and add info about reseller program". He chose (no figures anywhere):
  benefits  a reseller margin, white label (own brand), a partner portal (set up and manage
            their customers' accounts, see and send their invoices), training & sales support
  audience  childcare consultants, IT / software resellers, associations & networks,
            regional partners outside Canada
  placement a Resellers card under the plans on /pricing + its own page /resellers

Adds
  /resellers        page-resellers: hero, who it's for, what you get, how it works, a pointer
                    to the referral program, closing band. Every button is "Contact sales".
  /pricing          a Resellers & partners band under the plans (no price)
  contact form      "🤝 Reseller Program" in "I'm interested in…"; ktResellerSales() opens
                    Contact with it chosen (same pattern as ktShopQuote). The server keeps the
                    interest on the Sales lead and in the sales@ email subject.
  menus, footer, search, META (English) here; fr/es/hi titles in seo_meta.json

usage: python reseller_edits.py in.html out.html seo_meta.json
"""
import json
import re

PID = 'resellers'
GO = 'href="/resellers" onclick="showPage(\'resellers\');return false;"'
SALES = 'href="/contact" onclick="return ktResellerSales()"'
IMG = '/images/life/'

CSS = """<style>
/* ── KiddieTrac Reseller Program (reseller_edits.py) ── */
:root{--rs-ink:#0b1b33;--rs-mu:#5b6b82;--rs-line:#dfe7f1;--rs-soft:#f4f8fc;
  --rs-grad:linear-gradient(120deg,#22c1c3 0%,#3b82f6 55%,#7c3aed 100%)}
.rs-g{background:var(--rs-grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.rs-wrap{max-width:1180px;margin:0 auto;padding:0 24px}
.rs-center{text-align:center}
.rs-pill{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#bfe9ff;background:rgba(59,130,246,.16);border:1px solid rgba(125,211,252,.4);padding:6px 12px;border-radius:999px}
.rs-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;font:800 16px 'Nunito',sans-serif;padding:14px 24px;border-radius:14px;border:0;cursor:pointer;text-decoration:none;transition:transform .18s ease,box-shadow .18s ease}
.rs-btn:hover{transform:translateY(-2px)}
.rs-btn-p{background:var(--rs-grad);color:#fff;box-shadow:0 14px 34px -12px rgba(59,130,246,.85)}
.rs-btn-g{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.24)}
.rs-btn-o{background:#fff;color:var(--rs-ink);border:1px solid var(--rs-line)}
.rs-hero{padding:120px 0 70px !important}
.rs-hero-g{display:grid;grid-template-columns:1.05fr .95fr;gap:48px;align-items:center}
.rs-hero-g>div:first-child{text-align:left}   /* .hero-band centres text; beside a photo it reads left */
.rs-hero h1{font:900 clamp(34px,5vw,56px)/1.05 'Nunito',sans-serif;color:#fff;margin:16px 0 14px;letter-spacing:-.02em}
.rs-sub{color:rgba(255,255,255,.86);font-size:18px;line-height:1.65;max-width:560px;margin:0 0 24px}
.rs-cta{display:flex;gap:12px;flex-wrap:wrap}
.rs-facts{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}
.rs-facts span{font-size:13.5px;font-weight:700;color:#e6f1ff;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);padding:7px 12px;border-radius:999px}
.rs-photo{position:relative;margin:0;border-radius:22px;overflow:hidden;box-shadow:0 30px 60px -24px rgba(0,0,0,.6)}
.rs-photo img{display:block;width:100%;height:auto;aspect-ratio:8/5;object-fit:cover}
.rs-tag{position:absolute;left:16px;bottom:16px;display:inline-flex;align-items:center;gap:8px;background:#fff;color:var(--rs-ink);font-weight:800;font-size:14px;padding:9px 14px;border-radius:12px;box-shadow:0 10px 24px -10px rgba(0,0,0,.45)}
.rs-sec{padding:76px 0}
.rs-sec.alt{background:var(--rs-soft)}
.rs-eb{display:inline-block;font-size:12.5px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#3b82f6;margin-bottom:10px}
.rs-h2{font:900 clamp(26px,3.4vw,38px)/1.15 'Nunito',sans-serif;color:var(--rs-ink);margin:0 0 12px}
.rs-lead{color:var(--rs-mu);font-size:17px;line-height:1.65;max-width:680px;margin:0 auto 36px}
.rs-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}
.rs-card{background:#fff;border:1px solid var(--rs-line);border-radius:18px;padding:24px 22px;box-shadow:0 12px 28px -20px rgba(15,40,80,.35)}
.rs-card .ic{width:48px;height:48px;border-radius:14px;display:grid;place-items:center;font-size:24px;background:linear-gradient(135deg,#e0f7fa,#ede9fe);margin-bottom:14px}
.rs-card h3{font:900 18px/1.3 'Nunito',sans-serif;color:var(--rs-ink);margin:0 0 8px}
.rs-card p{color:var(--rs-mu);font-size:15px;line-height:1.6;margin:0}
.rs-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;counter-reset:rs}
.rs-step{position:relative;background:#fff;border:1px solid var(--rs-line);border-radius:18px;padding:26px 22px 22px}
.rs-step:before{counter-increment:rs;content:counter(rs);position:absolute;top:-16px;left:22px;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font:900 15px 'Nunito',sans-serif;color:#fff;background:var(--rs-grad);box-shadow:0 8px 18px -8px rgba(59,130,246,.9)}
.rs-step h3{font:900 17px/1.3 'Nunito',sans-serif;color:var(--rs-ink);margin:6px 0 8px}
.rs-step p{color:var(--rs-mu);font-size:15px;line-height:1.6;margin:0}
.rs-note{max-width:860px;margin:40px auto 0;display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;background:#fff;border:1px dashed #b9c8dc;border-radius:16px;padding:18px 22px}
.rs-note p{margin:0;color:var(--rs-mu);font-size:15px;line-height:1.55;flex:1 1 320px}
.rs-note a{font-weight:800;color:#2563eb;text-decoration:none;white-space:nowrap}
.rs-final{padding:80px 0;text-align:center;background:radial-gradient(120% 140% at 50% 0%,#16325c 0%,#0b1b33 60%)}
.rs-final h2{font:900 clamp(28px,4vw,42px)/1.15 'Nunito',sans-serif;color:#fff;margin:0 0 12px}
.rs-final p{color:rgba(255,255,255,.82);font-size:17px;margin:0 auto 26px;max-width:560px}
/* the band under the plans on /pricing */
.rs-band{margin:36px auto 0;max-width:1080px;display:grid;grid-template-columns:auto 1fr auto;gap:22px;align-items:center;text-align:left;
  background:linear-gradient(135deg,#0b1b33 0%,#16325c 100%);border-radius:20px;padding:26px 28px;box-shadow:0 22px 44px -26px rgba(11,27,51,.8)}
.rs-band .ic{width:56px;height:56px;border-radius:16px;display:grid;place-items:center;font-size:28px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14)}
.rs-band h3{font:900 21px/1.25 'Nunito',sans-serif;color:#fff;margin:0 0 6px}
.rs-band p{color:rgba(255,255,255,.8);font-size:15px;line-height:1.55;margin:0}
.rs-band .rs-cta{justify-content:flex-end}
.rs-band .rs-btn{padding:12px 18px;font-size:15px}
@media (max-width:980px){.rs-hero-g{grid-template-columns:1fr}.rs-hero-g>div:first-child{text-align:center}
  .rs-sub{margin-left:auto;margin-right:auto}.rs-hero .rs-cta,.rs-facts{justify-content:center}.rs-grid,.rs-steps{grid-template-columns:repeat(2,minmax(0,1fr))}
  .rs-band{grid-template-columns:1fr;text-align:center}.rs-band .ic{margin:0 auto}.rs-band .rs-cta{justify-content:center}}
@media (max-width:600px){.rs-hero{padding:96px 0 52px !important}.rs-sec{padding:56px 0}.rs-grid,.rs-steps{grid-template-columns:1fr;gap:22px}
  .rs-wrap{padding:0 16px}.rs-sub{font-size:16.5px}.rs-cta .rs-btn{flex:1 1 100%}}
</style>"""


def img(name, alt):
    return ('<img decoding="async" src="%s%s-800.jpg" srcset="%s%s-800.jpg 800w, %s%s-1600.jpg 1600w" '
            'sizes="(max-width:980px) 100vw, 46vw" alt="%s" width="800" height="500">') % (IMG, name, IMG, name, IMG, name, alt)


def cards(items, cls='rs-card'):
    return ''.join('<div class="%s"><div class="ic" aria-hidden="true">%s</div><h3>%s</h3><p>%s</p></div>' % (cls, i, h, p)
                   for i, h, p in items)


WHO = [
    ('🧭', 'Childcare consultants', 'Licensing, start-up and operations consultants who already advise centres and home child care agencies.'),
    ('💻', 'IT and software resellers', 'Managed service providers and software resellers who look after the tools small businesses run on.'),
    ('🤝', 'Associations and networks', 'Childcare associations, franchise groups and agency networks that want one platform for their members.'),
    ('🌎', 'Regional partners', 'Partners who represent KiddieTrac in the United States and other countries.'),
]
GET = [
    ('💰', 'A reseller margin', 'Earn a margin on every customer you bring to KiddieTrac. We go through the details with you when we talk.'),
    ('🏷️', 'Your own brand', 'Offer KiddieTrac under your own name and logo with white label, so your customers see your brand.'),
    ('🧑‍💼', 'A partner portal', 'Set up your customers’ accounts, manage them from one sign-in, and see and send their invoices.'),
    ('🎓', 'Training and sales support', 'Product training, help with demos, sales materials and a named partner contact at KiddieTrac.'),
]
STEPS = [
    ('Tell us about your business', 'Contact sales and choose Reseller Program. Tell us who you work with and where.'),
    ('Talk it through', 'We set up a call to go through the program, the margin and how white label works.'),
    ('Get set up', 'Once we agree, you get your partner portal, training and sales materials.'),
    ('Bring your customers', 'Set up your customers’ accounts, onboard them with our help, and grow from there.'),
]

PAGE = (
    '<div class="page" id="page-resellers">\n'
    '<div class="hero-band rs-hero"><div class="rs-wrap"><div class="rs-hero-g"><div>'
    '<span class="rs-pill">🤝 KiddieTrac reseller program</span>'
    '<h1>Resell KiddieTrac to <span class="rs-g">the programs you serve</span></h1>'
    '<p class="rs-sub">Bring KiddieTrac to the childcare centres and home child care agencies you already work with. '
    'Earn a margin on every customer, sell it under your own brand if you like, and manage your customers from your own partner portal.</p>'
    '<div class="rs-cta"><a class="rs-btn rs-btn-p" ' + SALES + '>Contact sales →</a>'
    '<a class="rs-btn rs-btn-g" href="#rsGet" onclick="return ktRsGo(\'rsGet\')">What you get</a></div>'
    '<div class="rs-facts"><span>💰 Reseller margin</span><span>🏷️ Your own brand</span><span>🧑‍💼 Partner portal</span></div>'
    '</div><figure class="rs-photo">' + img('director-laptop', 'A childcare director working on a laptop')
    + '<span class="rs-tag">🤝 Partner program</span></figure></div></div></div>\n'
    '<section class="rs-sec" id="rsWho"><div class="rs-wrap"><div class="rs-center"><span class="rs-eb">Who it’s for</span>'
    '<h2 class="rs-h2">Made for people childcare programs already trust</h2>'
    '<p class="rs-lead">If centres and agencies come to you for advice, tools or support, KiddieTrac can be part of what you offer.</p></div>'
    '<div class="rs-grid">' + cards(WHO) + '</div></div></section>\n'
    '<section class="rs-sec alt" id="rsGet"><div class="rs-wrap"><div class="rs-center"><span class="rs-eb">What you get</span>'
    '<h2 class="rs-h2">Everything you need to sell and support it</h2>'
    '<p class="rs-lead">You keep the relationship with your customers. We give you the product, the tools and the people behind you.</p></div>'
    '<div class="rs-grid">' + cards(GET) + '</div></div></section>\n'
    '<section class="rs-sec" id="rsHow"><div class="rs-wrap"><div class="rs-center"><span class="rs-eb">How it works</span>'
    '<h2 class="rs-h2">Four steps to your first customer</h2>'
    '<p class="rs-lead">Partner pricing and terms are arranged with our sales team, so the first step is a conversation.</p></div>'
    '<div class="rs-steps">' + ''.join('<div class="rs-step"><h3>%s</h3><p>%s</p></div>' % s for s in STEPS) + '</div>'
    '<div class="rs-note"><p>Not ready to resell? Our referral program thanks you with $200 CAD for each childcare program you introduce that becomes a customer.</p>'
    '<a ' + GO.replace('resellers', 'referrals') + '>See the referral program →</a></div>'
    '</div></section>\n'
    '<section class="rs-final"><div class="rs-wrap">'
    '<h2>Let’s grow <span class="rs-g">together</span></h2>'
    '<p>Tell us about your business and we’ll be in touch to talk through the reseller program.</p>'
    '<div class="rs-cta" style="justify-content:center"><a class="rs-btn rs-btn-p" ' + SALES + '>Contact sales →</a></div>'
    '</div></section>\n'
    '</div>\n')

BAND = (
    '<div class="rs-band"><div class="ic" aria-hidden="true">🤝</div>'
    '<div><h3>Resellers and partners</h3>'
    '<p>Bring KiddieTrac to the programs you work with, under your own brand if you like. Partner pricing is arranged with our sales team.</p></div>'
    '<div class="rs-cta"><a class="rs-btn rs-btn-p" ' + SALES + '>Contact sales →</a>'
    '<a class="rs-btn rs-btn-g" ' + GO + '>Reseller program</a></div></div>\n  ')

SCRIPT = r"""<script>
/* Reseller program (reseller_edits.py): every button opens Contact with "Reseller Program" chosen. */
(function () {
  window.ktRsGo = function (id) {
    var e = document.getElementById(id);
    if (!e) { return false; }
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    try { e.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); } catch (x) { e.scrollIntoView(); }
    return false;
  };
  window.ktResellerSales = function () {
    window.showPage('contact');
    function pick() {
      var sel = document.getElementById('cfInterest');
      if (!sel) { return false; }
      for (var i = 0; i < sel.options.length; i++) {
        if (/reseller/i.test(sel.options[i].value)) { sel.selectedIndex = i; return true; }
      }
      return false;
    }
    if (!pick()) { setTimeout(pick, 60); }
    return false;
  };
})();
</script>
"""

META_EN = ('Reseller Program: Partner with KiddieTrac | KiddieTrac',
           'Resell KiddieTrac to the childcare programs you serve. Earn a reseller margin, sell under your own brand, manage customers from a partner portal, with training and sales support. Contact sales.')

SEO_META = {
    'fr': {'t': 'Programme de revendeurs : devenez partenaire de KiddieTrac | KiddieTrac',
           'd': 'Revendez KiddieTrac aux services de garde que vous servez. Marge de revendeur, votre propre marque, portail partenaire pour gérer vos clients, formation et soutien aux ventes. Contactez les ventes.'},
    'es': {'t': 'Programa de revendedores: asóciese con KiddieTrac | KiddieTrac',
           'd': 'Revenda KiddieTrac a los programas de cuidado infantil que atiende. Margen de revendedor, su propia marca, portal de socios para gestionar clientes, capacitación y apoyo de ventas. Contacte a ventas.'},
    'hi': {'t': 'रीसेलर प्रोग्राम: KiddieTrac के पार्टनर बनें | KiddieTrac',
           'd': 'जिन चाइल्डकेयर प्रोग्राम के साथ आप काम करते हैं, उन्हें KiddieTrac बेचें। रीसेलर मार्जिन, आपका अपना ब्रांड, ग्राहकों के लिए पार्टनर पोर्टल, ट्रेनिंग और सेल्स सहायता। सेल्स से संपर्क करें।'},
}


def _one(out, o, n):
    assert out.count(o) == 1, ('reseller_edits', o[:80], out.count(o))
    return out.replace(o, n)


def resellers(out, seo_meta=None):
    assert 'id="page-resellers"' not in out, 'reseller_edits already applied'
    out = _one(out, '</head>', CSS + '\n</head>')
    # the page, after the referral program page (both sit before the blog)
    out = _one(out, '<div class="page" id="page-blog">', PAGE + '<div class="page" id="page-blog">')
    # pricing: under the plans, before the free-migration note
    mig = '<div style="text-align:center;margin-top:40px;padding:28px;background:var(--off);border-radius:14px">\n    <div style="font-size:14px;font-weight:700;color:var(--dark)">🔄 Switching platforms?'
    out = _one(out, mig, BAND + mig)
    # contact form: the interest option
    out = _one(out, '<option>🏷️ White Label Partnership</option>',
               '<option>🏷️ White Label Partnership</option><option>🤝 Reseller Program</option>')
    # English META in the router's table
    out = _one(out, '    privacy:{t:"Privacy Policy | KiddieTrac"',
               '    resellers:{t:%s,d:%s},\n    privacy:{t:"Privacy Policy | KiddieTrac"' % (
                   json.dumps(META_EN[0], ensure_ascii=False), json.dumps(META_EN[1], ensure_ascii=False)))
    # menus: desktop Company dropdown, mobile, footer Company, site search (after the referral program)
    ref_dd = '<a class="nav-dd-item" href="/referrals" onclick="showPage(\'referrals\');return false;">🎁 Referral Program</a>'
    out = _one(out, ref_dd, ref_dd + '\n          <a ' + GO.replace('href=', 'class="nav-dd-item" href=') + '>🤝 Reseller Program</a>')
    ref_m = '<button class="mob-link" onclick="showPage(\'referrals\');closeMobile()">🎁 Referral Program</button>'
    out = _one(out, ref_m, ref_m + '\n  <button class="mob-link" onclick="showPage(\'resellers\');closeMobile()">🤝 Reseller Program</button>')
    ref_f = '<li><a href="/referrals" onclick="showPage(\'referrals\');return false;">Referral Program</a></li>'
    out = _one(out, ref_f, ref_f + '<li><a ' + GO + '>Reseller Program</a></li>')
    ref_s = "  {icon:'🎁',t:'Referral Program',s:'Give a month, get $200: refer a childcare program',page:'referrals'},"
    out = _one(out, ref_s, ref_s + "\n  {icon:'🤝',t:'Reseller Program',s:'Resell KiddieTrac under your own brand',page:'resellers'},")
    i = out.rfind('</body>')
    assert i > 0
    out = out[:i] + SCRIPT + out[i:]
    if seo_meta is not None:
        m = re.search(r'<script>window\.KT_META_I18N=.*?;</script>', out)
        assert m, 'no KT_META_I18N'
        out = out[:m.start()] + '<script>window.KT_META_I18N=' + json.dumps(seo_meta, ensure_ascii=False, separators=(',', ':')) + ';</script>' + out[m.end():]
    return out


def merge_seo_meta(meta):
    for lang, v in SEO_META.items():
        meta.setdefault(lang, {})[PID] = v
    return meta


if __name__ == '__main__':
    import sys
    src, dst, meta_path = sys.argv[1], sys.argv[2], sys.argv[3]
    meta = merge_seo_meta(json.load(open(meta_path, encoding='utf-8')))
    open(meta_path, 'w', encoding='utf-8', newline='').write(json.dumps(meta, ensure_ascii=False, indent=2))
    s = resellers(open(src, encoding='utf-8').read(), meta)
    open(dst, 'w', encoding='utf-8', newline='').write(s)
    print(dst, len(s), 'bytes')
