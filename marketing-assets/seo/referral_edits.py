# -*- coding: utf-8 -*-
"""KiddieTrac Referral Program on the marketing site (2026-10-13).

Terms decided by Anthony (use exactly, no other numbers):
  - the referrer earns a one-time $200 CAD for each childcare program they refer that becomes a
    paying KiddieTrac customer
  - paid by e-transfer after the referred program has been a paying customer for 60 days
  - anyone can refer (customers, educators, consultants, parents, anyone)
  - the referred program gets its first month free (first monthly subscription fee, not add-ons)
  Hook: "Give a month. Get $200."

Adds
  /referrals              its own page (page-referrals): hero, how it works, who can refer, the
                          form, FAQ, program terms (effective October 2026, collapsible)
  home                    a compact "Give a month. Get $200." band before HOW IT WORKS
  menus, footer, search   Company dropdown, mobile menu, footer Company column, site search
  META (English) here; fr/es/hi in seo_meta.json (SEO_META below is merged into it)

THE FORM posts to /api/v1/marketing-site/lead with source=referral. The backend
(MarketingSiteController::submitReferral, same day) validates every field, creates a Sales
pipeline lead for the REFERRED program (source "Referral", referrer in the notes), and emails
sales@kiddietrac.com "New referral: <program> (from <referrer>)" with Reply-To the referrer.
The referrer is NOT added to the newsletter. analytics_edits.py already turns a successful
/lead POST into GA4 generate_lead (lead_source=referral).

Words the JavaScript shows live in hidden elements (#rfMsgs), so the page's translator
(ssr_i18n + the runtime walk) translates them like any other text.

PALETTE: the site's navy with a warm "reward" accent, coral -> amber -> gold
(#ff7a59 / #ffb547 / #ffd36e). Photos: /images/life/team-talk (hero) and home-provider.

usage: python referral_edits.py in.html out.html seo_meta.json
"""
import json
import re

PID = 'referrals'
GO = 'href="/referrals" onclick="showPage(\'referrals\');return false;"'
IMG = '/images/life/'
TERMS_VERSION = 'October 2026'


def img(name, alt, sizes, eager=False):
    return ('<img %sdecoding="async" src="%s%s-800.jpg" srcset="%s%s-800.jpg 800w, %s%s-1600.jpg 1600w" '
            'sizes="%s" alt="%s" width="800" height="500">') % (
                '' if eager else 'loading="lazy" ', IMG, name, IMG, name, IMG, name, sizes, alt)


def coin(cls=''):
    """The $200 badge: CSS, not SVG, so its words are translated."""
    return ('<div class="rf-coin%s" aria-hidden="true"><span class="rf-coin-in"><b>$200</b><small>CAD per program</small></span></div>'
            % ((' ' + cls) if cls else ''))


def scroll_to(target):
    return 'href="#%s" onclick="return ktRfGo(\'%s\')"' % (target, target)


CSS = """<style>
/* ── KiddieTrac Referral Program (referral_edits.py) ── */
:root{--rf-coral:#ff7a59;--rf-amber:#ffb547;--rf-gold:#ffd36e;--rf-ink:#0b1b33;--rf-mu:#5b6b82;--rf-warm:#fff7f1;--rf-line:#f3dccd;
  --rf-grad:linear-gradient(120deg,#ff7a59 0%,#ffb547 55%,#ffd36e 100%)}
.rf-g{background:var(--rf-grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.rf-wrap{max-width:1180px;margin:0 auto;padding:0 24px}
.rf-center{text-align:center}
.rf-pill{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#ffd9a8;background:rgba(255,122,89,.14);border:1px solid rgba(255,181,71,.38);padding:6px 12px;border-radius:999px}
.rf-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;font:800 16px 'Nunito',sans-serif;padding:14px 24px;border-radius:14px;border:0;cursor:pointer;text-decoration:none;transition:transform .18s ease,box-shadow .18s ease}
.rf-btn:hover{transform:translateY(-2px)}
.rf-btn-p{background:var(--rf-grad);color:#3a1604;box-shadow:0 14px 34px -12px rgba(255,122,89,.85)}
.rf-btn-g{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.24)}
.rf-btn-d{background:var(--rf-ink);color:#fff}
/* the $200 coin */
.rf-coin{--s:150px;width:var(--s);height:var(--s);border-radius:50%;display:grid;place-items:center;flex:0 0 auto;
  background:radial-gradient(circle at 32% 28%,#fff6d6 0%,#ffd36e 34%,#ffb547 62%,#f08a3c 100%);
  box-shadow:0 22px 44px -16px rgba(240,120,50,.75),inset 0 -6px 14px rgba(160,70,0,.28),inset 0 6px 12px rgba(255,255,255,.55);
  position:relative;color:#4a1d00;animation:rfbob 5s ease-in-out infinite}
.rf-coin:before{content:'';position:absolute;inset:9px;border-radius:50%;border:2px dashed rgba(122,52,0,.38)}
.rf-coin-in{display:flex;flex-direction:column;align-items:center;line-height:1;text-align:center;position:relative}
.rf-coin b{font:900 calc(var(--s)*.3) 'Nunito',sans-serif;letter-spacing:-.03em}
.rf-coin small{display:block;margin-top:5px;max-width:calc(var(--s)*.62);font-size:calc(var(--s)*.074);font-weight:900;letter-spacing:.06em;text-transform:uppercase;line-height:1.15}
@keyframes rfbob{0%,100%{transform:translateY(0) rotate(-8deg)}50%{transform:translateY(-8px) rotate(-4deg)}}
/* page banner */
#page-referrals .hero-band.rf-hero{--k6acc:#ff7a59;text-align:left;background:radial-gradient(900px 440px at 90% -5%,rgba(255,122,89,.30),transparent 60%),radial-gradient(700px 380px at 0% 105%,rgba(255,211,110,.16),transparent 60%),linear-gradient(170deg,#061634 0%,#0a2350 60%,#071a3d 100%)!important}
#page-referrals .hero-band.rf-hero p.rf-sub{color:#cfdcec!important;font-size:18.5px!important;max-width:560px}
#page-referrals .hero-band.rf-hero h1{margin:20px 0 16px!important;color:#fff}
#page-referrals .hero-band.rf-hero h1 .rf-g{display:inline-block}
#page-referrals .hero-band.rf-hero .rf-hero-g p{margin:0}
.rf-hero-g{display:grid;grid-template-columns:1.05fr .95fr;gap:56px;align-items:center}
.rf-cta{display:flex;gap:12px;flex-wrap:wrap;margin-top:28px}
.rf-facts{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}
.rf-facts span{font-size:13px;font-weight:800;color:#ffe7cf;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:999px;padding:7px 13px}
.rf-photo{position:relative;margin:0 0 0 auto;max-width:560px}
.rf-photo img{display:block;width:100%;height:auto;aspect-ratio:8/5;object-fit:cover;border-radius:28px;box-shadow:0 40px 80px -36px rgba(0,0,0,.8),0 0 0 1px rgba(255,255,255,.12)}
.rf-photo .rf-coin{position:absolute;left:-34px;bottom:-40px}
.rf-tag{position:absolute;right:16px;top:-16px;background:#fff;color:var(--rf-ink);border-radius:14px;padding:9px 14px;font:900 14.5px 'Nunito',sans-serif;box-shadow:0 18px 34px -14px rgba(0,0,0,.6);transform:rotate(3deg)}
.rf-tag i{font-style:normal;margin-right:6px}
/* sections */
#page-referrals .rf-sec{padding:84px 0;background:#fff}
#page-referrals .rf-sec.warm{background:var(--rf-warm)}
.rf-eb{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#c2410c;background:#fff1e8;border:1px solid #fed7c3;padding:6px 12px;border-radius:999px}
.rf-h2{font-size:clamp(26px,3.4vw,42px);line-height:1.1;font-weight:900;letter-spacing:-.02em;color:var(--rf-ink);margin:14px 0 12px}
.rf-lead{font-size:17px;line-height:1.65;color:#4b5d73;max-width:680px}
.rf-center .rf-lead{margin:0 auto}
.rf-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin-top:44px;counter-reset:rf}
.rf-step{position:relative;background:#fff;border:1px solid var(--rf-line);border-radius:24px;padding:26px 24px 24px;box-shadow:0 24px 50px -38px rgba(150,60,10,.5)}
.rf-step .n{display:grid;place-items:center;width:46px;height:46px;border-radius:15px;background:var(--rf-grad);color:#3a1604;font:900 20px 'Nunito',sans-serif;box-shadow:0 12px 24px -12px rgba(255,122,89,.9)}
.rf-step .e{position:absolute;right:22px;top:20px;font-size:30px}
.rf-step h3{font-size:19px;font-weight:900;color:var(--rf-ink);margin:16px 0 8px}
.rf-step p{font-size:15px;line-height:1.6;color:#4b5d73;margin:0}
.rf-step:not(:last-child):after{content:'';position:absolute;right:-17px;top:44px;width:12px;height:12px;border-top:3px solid #ffb547;border-right:3px solid #ffb547;transform:rotate(45deg);z-index:1}
.rf-who{display:grid;grid-template-columns:.9fr 1.1fr;gap:48px;align-items:center}
.rf-who figure{margin:0;position:relative}
.rf-who img{display:block;width:100%;height:auto;aspect-ratio:8/5;object-fit:cover;border-radius:24px;box-shadow:0 30px 60px -40px rgba(11,27,51,.7)}
.rf-chips{display:flex;flex-wrap:wrap;gap:10px;margin:22px 0 0;padding:0;list-style:none}
.rf-chips li{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--rf-line);border-radius:999px;padding:10px 16px;font-size:15px;font-weight:800;color:var(--rf-ink)}
.rf-note{font-size:13.5px;color:var(--rf-mu);margin-top:16px;line-height:1.55}
/* the form */
#rfForm,#rfTerms,#rfHow{scroll-margin-top:96px}
.rf-card{max-width:900px;margin:40px auto 0;background:#fff;border:1px solid var(--rf-line);border-radius:28px;padding:32px;box-shadow:0 40px 80px -50px rgba(150,60,10,.55);text-align:left}
.rf-cols{display:grid;grid-template-columns:1fr 1fr;gap:28px}
.rf-card fieldset{border:0;margin:0;padding:0;min-width:0}
.rf-card legend{display:flex;align-items:center;gap:10px;font-size:17px;font-weight:900;color:var(--rf-ink);margin:0 0 14px;padding:0}
.rf-card legend i{font-style:normal;display:grid;place-items:center;width:30px;height:30px;border-radius:10px;background:#fff1e8;font-size:16px}
.rf-f{display:block;margin-bottom:13px}
.rf-f>span{display:block;font-size:13.5px;font-weight:800;color:#23405f;margin-bottom:5px}
.rf-f>span em{font-style:normal;color:#c2410c}
.rf-f>span small{font-weight:700;color:var(--rf-mu)}
#page-referrals .rf-in{width:100%;box-sizing:border-box;padding:12px 14px;border:1.5px solid #ead8cb;border-radius:12px;font:600 15px var(--font,'Nunito'),sans-serif;color:var(--rf-ink);background:#fffdfb}
#page-referrals textarea.rf-in{min-height:92px;resize:vertical}
#page-referrals .rf-in:focus{outline:none!important;border-color:#ff7a59!important;box-shadow:0 0 0 4px rgba(255,122,89,.18)!important}
.rf-rel{display:flex;flex-wrap:wrap;gap:8px}
.rf-chip{position:relative;cursor:pointer}
.rf-chip input{position:absolute;opacity:0;width:1px;height:1px}
.rf-chip span{display:inline-block;border:1.5px solid #ead8cb;border-radius:999px;padding:8px 13px;font-size:14px;font-weight:800;color:#23405f;background:#fffdfb;transition:all .15s}
.rf-chip input:checked+span{background:var(--rf-grad);border-color:transparent;color:#3a1604;box-shadow:0 8px 18px -10px rgba(255,122,89,.9)}
.rf-chip input:focus-visible+span{outline:3px solid rgba(255,122,89,.45);outline-offset:2px}
.rf-hint{font-size:12.5px;color:var(--rf-mu);margin:-4px 0 12px;line-height:1.45}
.rf-checks{margin-top:8px;padding-top:18px;border-top:1px dashed #ead8cb;display:grid;gap:10px}
.rf-check{display:flex;gap:10px;align-items:flex-start;font-size:14.5px;line-height:1.5;color:#23405f;cursor:pointer}
.rf-check input{flex:0 0 auto;width:19px;height:19px;margin:2px 0 0;accent-color:#ff7a59}
.rf-check a{color:#c2410c;font-weight:800}
.rf-send{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-top:20px}
.rf-send .rf-btn{min-width:220px}
.rf-msg{font-size:14px;font-weight:800;min-height:20px}
.rf-msg.err{color:#b91c1c}.rf-msg.wait{color:var(--rf-mu)}
.rf-small{font-size:12.5px;color:var(--rf-mu);margin-top:14px;line-height:1.5}
.rf-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}
.rf-done{text-align:center;padding:26px 8px 10px}
.rf-done .rf-coin{--s:120px;margin:0 auto 18px}
.rf-done h3{font-size:clamp(24px,3vw,32px);font-weight:900;color:var(--rf-ink);margin:0 0 10px;letter-spacing:-.01em}
.rf-done p{font-size:16.5px;line-height:1.6;color:#4b5d73;max-width:560px;margin:0 auto 8px}
.rf-done b.rf-prog{color:#c2410c}
/* faq + terms */
.rf-faq{max-width:820px;margin:34px auto 0;text-align:left}
.rf-faq details,.rf-terms{background:#fff;border:1px solid var(--rf-line);border-radius:16px;margin-bottom:10px;padding:16px 20px}
.rf-faq summary,.rf-terms summary{font-weight:900;color:var(--rf-ink);cursor:pointer;font-size:15.5px}
.rf-faq details p{color:#4b5d73;font-size:14.5px;line-height:1.7;margin:10px 0 0}
.rf-terms{max-width:820px;margin:26px auto 0;text-align:left;border-color:#e2e8f0;background:#fbfcfe}
.rf-terms summary small{display:inline-block;margin-left:8px;font-size:12px;font-weight:800;color:#c2410c;background:#fff1e8;border-radius:999px;padding:3px 9px;vertical-align:middle}
.rf-terms ol{margin:16px 0 4px;padding-left:22px;display:grid;gap:9px}
.rf-terms li{font-size:14.5px;line-height:1.6;color:#334155}
.rf-terms li b{color:var(--rf-ink)}
.rf-terms a{color:#c2410c;font-weight:800}
/* closing band */
.rf-final{padding:76px 0;text-align:center;color:#fff;background:radial-gradient(800px 360px at 85% 0%,rgba(255,122,89,.32),transparent 60%),radial-gradient(600px 300px at 10% 100%,rgba(255,211,110,.18),transparent 60%),linear-gradient(165deg,#061634,#0a2350 60%,#071a3d)}
.rf-final h2{font-size:clamp(26px,3.6vw,42px);font-weight:900;color:#fff;margin:0 0 12px;letter-spacing:-.02em}
.rf-final p{font-size:17px;color:#cfdcec;margin:0 auto 26px;max-width:600px;line-height:1.6}
/* home band */
.rf-home{padding:30px 0}
.rf-home-in{display:flex;align-items:center;gap:34px;border-radius:30px;padding:30px 40px;color:#fff;position:relative;overflow:hidden;
  background:radial-gradient(700px 300px at 100% 0%,rgba(255,122,89,.38),transparent 60%),radial-gradient(500px 260px at 0% 100%,rgba(255,211,110,.20),transparent 60%),linear-gradient(160deg,#061634,#0c2857);box-shadow:0 40px 80px -50px rgba(6,22,52,.9)}
.rf-home .rf-coin{--s:132px}
.rf-home-t{flex:1;min-width:0}
.rf-home-t .rf-pill{margin-bottom:10px}
.rf-home h2 .rf-g{white-space:nowrap}
.rf-home h2{font-size:clamp(26px,3.4vw,40px);line-height:1.08;font-weight:900;letter-spacing:-.02em;margin:0 0 8px;color:#fff}
.rf-home p{margin:0;font-size:16px;line-height:1.6;color:#cfdcec;max-width:620px}
.rf-home .rf-btn{flex:0 0 auto}
@media (max-width:980px){
  .rf-hero-g,.rf-who{grid-template-columns:1fr}
  .rf-photo{margin:20px auto 0}
  .rf-steps{grid-template-columns:1fr;max-width:560px;margin-left:auto;margin-right:auto}
  .rf-step:not(:last-child):after{right:auto;left:42px;top:auto;bottom:-15px;transform:rotate(135deg)}
  .rf-home-in{flex-wrap:wrap}
  .rf-home-t{flex:1 1 320px}
}
@media (max-width:640px){
  .rf-wrap{padding:0 16px}
  #page-referrals .rf-sec{padding:58px 0}
  #page-referrals .hero-band.rf-hero p.rf-sub{font-size:16.5px!important}
  .rf-cta .rf-btn{flex:1 1 100%}
  .rf-photo .rf-coin{--s:108px;left:10px;bottom:-30px}
  .rf-tag{right:10px;font-size:13px}
  .rf-card{padding:22px 16px;border-radius:22px;margin-top:28px}
  .rf-cols{grid-template-columns:1fr;gap:10px}
  .rf-send .rf-btn{width:100%;min-width:0}
  .rf-home{padding:20px 0}
  .rf-home-in{display:block;padding:24px 20px;border-radius:24px}
  .rf-home .rf-coin{--s:82px;position:absolute;right:14px;top:16px}
  .rf-home-t .rf-pill{max-width:calc(100% - 92px);box-sizing:border-box}
  .rf-home h2{margin-right:88px}
  .rf-home .rf-btn{width:100%;margin-top:18px;box-sizing:border-box}
  .rf-chips li{font-size:14px;padding:8px 13px}
}
@media (prefers-reduced-motion:reduce){
  .rf-coin{animation:none;transform:rotate(-6deg)}
  .rf-btn,.rf-chip span{transition:none}
  .rf-btn:hover{transform:none}
}
</style>"""


# ── home: a compact band before HOW IT WORKS ───────────────────────────────────────────
HOME = ('<!-- ═══ REFERRAL PROGRAM (referral_edits.py) ═══ -->\n'
        '<section class="rf-home"><div class="k6-wrap"><div class="rf-home-in k6-rv">'
        + coin() +
        '<div class="rf-home-t"><span class="rf-pill">🎁 Referral program</span>'
        '<h2>Give a month. <span class="rf-g">Get $200.</span></h2>'
        '<p>Know a childcare program that would love KiddieTrac? They get their first month free, and you get $200 CAD once they’ve been a paying customer for 60 days. Anyone can refer.</p></div>'
        '<a class="rf-btn rf-btn-p" ' + GO + '>Refer a program →</a>'
        '</div></div></section>\n\n')


# ── the page ─────────────────────────────────────────────────────────────────────────
def _field(name, label, kind='text', req=False, hint='', ph='', auto='', extra=''):
    star = ' <em>*</em>' if req else ''
    hint_html = (' <small>%s</small>' % hint) if hint else ''
    attrs = ' name="%s" id="rf_%s"' % (name, name)
    if req:
        attrs += ' required'
    if ph:
        attrs += ' placeholder="%s"' % ph
    if auto:
        attrs += ' autocomplete="%s"' % auto
    if kind == 'textarea':
        ctl = '<textarea class="rf-in"%s maxlength="3000"%s></textarea>' % (attrs, extra)
    else:
        ctl = '<input class="rf-in" type="%s"%s maxlength="160"%s>' % (kind, attrs, extra)
    return '<label class="rf-f" for="rf_%s"><span>%s%s%s</span>%s</label>' % (name, label, star, hint_html, ctl)


REL = [('customer', 'KiddieTrac customer'), ('educator', 'Educator'), ('consultant', 'Consultant'),
       ('parent', 'Parent'), ('other', 'Other')]

FORM = (
    '<div class="rf-card" id="rfCard">'
    '<form id="rfFormEl" novalidate onsubmit="return ktReferral(event)">'
    '<div class="rf-cols">'
    '<fieldset><legend><i>🙋</i>About you</legend>'
    + _field('name', 'Your name', req=True, auto='name')
    + _field('email', 'Your email', 'email', req=True, auto='email', ph='you@example.com')
    + _field('phone', 'Your phone', 'tel', hint='(optional)', auto='tel')
    + '<div class="rf-f" role="radiogroup" aria-labelledby="rfRelL"><span id="rfRelL">You are… <em>*</em></span><div class="rf-rel">'
    + ''.join('<label class="rf-chip"><input type="radio" name="relationship" value="%s"><span>%s</span></label>' % (v, t) for v, t in REL)
    + '</div></div>'
    '</fieldset>'
    '<fieldset><legend><i>🏫</i>The program you’re referring</legend>'
    + _field('program', 'Program name', req=True, ph='e.g. Little Acorns Child Care')
    + _field('contact_name', 'Contact person', req=True, hint='(owner or director)')
    + _field('contact_email', 'Their email', 'email')
    + _field('contact_phone', 'Their phone', 'tel')
    + '<p class="rf-hint">An email or a phone number for them, or both.</p>'
    + _field('city', 'City / province', ph='e.g. Orangeville, ON')
    + '</fieldset>'
    '</div>'
    + _field('notes', 'Anything that would help?', 'textarea', hint='(optional)', ph='How you know them, what they use today, the best time to reach them…')
    + '<div class="rf-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
    '<div class="rf-checks">'
    '<label class="rf-check"><input type="checkbox" name="consent_share" required><span>I have permission to share this contact’s details with KiddieTrac. <em style="font-style:normal;color:#c2410c">*</em></span></label>'
    '<label class="rf-check"><input type="checkbox" name="accept_terms" required><span>I agree to the <a ' + scroll_to('rfTerms') + '>referral program terms</a>. <em style="font-style:normal;color:#c2410c">*</em></span></label>'
    '</div>'
    '<div id="ktRefRc" style="margin-top:14px"></div>'
    '<div class="rf-send"><button class="rf-btn rf-btn-p" type="submit">Send my referral →</button>'
    '<div class="rf-msg" id="rfMsg" role="status" aria-live="polite"></div></div>'
    '<p class="rf-small">We use these details only for this referral. Referring someone doesn’t sign you up for our newsletter.</p>'
    '</form>'
    '<div class="rf-done" id="rfDone" hidden tabindex="-1">' + coin() +
    '<h3>Thank you! 🎉</h3>'
    '<p>We’ll reach out to <b class="rf-prog">the program</b> and keep you posted.</p>'
    '<p>If they become a paying customer, you’ll get $200 CAD by e-transfer once they’ve been with us for 60 days.</p>'
    '<div class="rf-cta" style="justify-content:center"><button class="rf-btn rf-btn-d" type="button" onclick="ktReferralAgain()">Refer another program</button></div>'
    '</div>'
    '<div id="rfMsgs" hidden>'
    '<span data-m="req">Please fill in the fields marked with *.</span>'
    '<span data-m="rel">Please tell us how you know KiddieTrac: customer, educator, consultant, parent or other.</span>'
    '<span data-m="reach">Please add the program contact’s email or phone.</span>'
    '<span data-m="email">Please check the email addresses.</span>'
    '<span data-m="self">That’s your own email address. Self-referrals aren’t eligible.</span>'
    '<span data-m="checks">Please confirm you have permission to share their details, and agree to the program terms.</span>'
    '<span data-m="robot">Please complete the “I’m not a robot” check.</span>'
    '<span data-m="wait">Sending…</span>'
    '<span data-m="fail">Sorry, that didn’t go through. Please try again, or email sales@kiddietrac.com.</span>'
    '</div>'
    '</div>')

FAQ = [
    ('How much do I earn?',
     'A one-time $200 CAD for each program you refer that becomes a paying KiddieTrac customer. Refer more than one program, and you earn $200 for each one that joins.'),
    ('When and how do I get paid?',
     'By e-transfer, once the program you referred has been a paying customer for 60 days. We’ll contact you to arrange it.'),
    ('What does the program I refer get?',
     'Their first month free: we waive their first monthly subscription fee. Add-ons, such as SMS &amp; voice alerts, are billed as usual.'),
    ('Who can refer?',
     'Anyone: KiddieTrac customers, educators, consultants, parents, or anyone who knows a childcare program. You can’t refer your own agency or centre.'),
    ('What if someone else already referred them?',
     'Each new program earns one reward, and the first valid referral we receive gets it. Your referral also has to reach us before the program signs up or books a demo with us another way.'),
    ('Which programs count?',
     'New customers only. A program that already uses KiddieTrac, or that cancelled in the last 12 months, isn’t eligible.'),
]

TERMS = [
    ('The reward.', 'KiddieTrac pays the person who refers a program (the referrer) a one-time referral fee of $200 CAD for each eligible program they refer that becomes a paying KiddieTrac customer.'),
    ('When it’s paid.', 'The fee is paid by e-transfer after the referred program has been a paying customer for 60 days.'),
    ('The referred program’s offer.', 'The referred program’s first month is free. This applies to its first monthly subscription fee only, not to add-ons such as SMS &amp; voice alerts.'),
    ('Who can refer.', 'Anyone can take part: KiddieTrac customers, educators, consultants, parents and anyone else.'),
    ('Refer first.', 'The referral must reach us through this page before the referred program signs up for KiddieTrac or books a demo with us through another channel.'),
    ('New customers only.', 'The referred program can’t be a current KiddieTrac customer, or a customer that cancelled within the last 12 months.'),
    ('One reward per program.', 'Each new program earns one reward. If more than one person refers the same program, the first valid referral we receive gets it.'),
    ('No self-referrals.', 'You can’t refer your own agency or centre.'),
    ('Permission.', 'By sending a referral, you confirm you have permission to share the contact’s details with KiddieTrac.'),
    ('Changes.', 'KiddieTrac may change or end the program at any time. Referrals sent before a change keep the terms that applied when they were sent.'),
    ('Taxes.', 'Rewards may be taxable income. The referrer is responsible for reporting them.'),
    ('Where it applies.', 'Void where prohibited.'),
]


def _terms_html():
    items = ''.join('<li><b>%s</b> %s</li>' % (h, t) for h, t in TERMS)
    items += ('<li><b>Questions?</b> <a href="/contact" onclick="showPage(\'contact\');return false;">Contact us</a> '
              'and we’ll be happy to help.</li>')
    return ('<details class="rf-terms" id="rfTerms"><summary>Referral program terms <small>Effective October 2026</small></summary>'
            '<ol>' + items + '</ol></details>')


PAGE = (
    '<div class="page" id="page-referrals">\n'
    '<div class="hero-band rf-hero"><div class="rf-wrap"><div class="rf-hero-g">'
    '<div><span class="rf-pill">🎁 KiddieTrac referral program</span>'
    '<h1>Give a month. <span class="rf-g">Get $200.</span></h1>'
    '<p class="rf-sub">Know a childcare program that would love KiddieTrac? Introduce us. They get their first month free, and once they’ve been a paying customer for 60 days, we send you $200 CAD by e-transfer.</p>'
    '<div class="rf-cta"><a class="rf-btn rf-btn-p" ' + scroll_to('rfForm') + '>Refer a program →</a>'
    '<a class="rf-btn rf-btn-g" ' + scroll_to('rfHow') + '>How it works</a></div>'
    '<div class="rf-facts"><span>💸 $200 CAD per program</span><span>🎁 Their first month free</span><span>🙌 Anyone can refer</span></div>'
    '</div>'
    '<figure class="rf-photo">' + img('team-talk', 'Three women talking over coffee at a table', '(max-width:980px) 100vw, 46vw', eager=True)
    + '<span class="rf-tag"><i>🎁</i>First month free</span>' + coin() + '</figure>'
    '</div></div></div>\n'
    # how it works
    '<section class="rf-sec" id="rfHow"><div class="rf-wrap"><div class="rf-center"><span class="rf-eb">How it works</span>'
    '<h2 class="rf-h2">Three steps, one thank-you</h2>'
    '<p class="rf-lead">You make the introduction. We do the rest, and we say thanks when it works out.</p></div>'
    '<div class="rf-steps">'
    '<div class="rf-step"><span class="n">1</span><span class="e" aria-hidden="true">💌</span><h3>Refer a program</h3>'
    '<p>Fill in the form below with the program’s details and how to reach them. We’ll take it from there.</p></div>'
    '<div class="rf-step"><span class="n">2</span><span class="e" aria-hidden="true">🎁</span><h3>They get their first month free</h3>'
    '<p>When they become a KiddieTrac customer, their first monthly subscription fee is on us.</p></div>'
    '<div class="rf-step"><span class="n">3</span><span class="e" aria-hidden="true">💸</span><h3>You get $200</h3>'
    '<p>Once they’ve been a paying customer for 60 days, we send you $200 CAD by e-transfer.</p></div>'
    '</div></div></section>\n'
    # who can refer
    '<section class="rf-sec warm"><div class="rf-wrap"><div class="rf-who">'
    '<figure>' + img('home-provider', 'A childcare provider building with blocks alongside a young girl', '(max-width:980px) 100vw, 42vw') + '</figure>'
    '<div><span class="rf-eb">Who can refer</span>'
    '<h2 class="rf-h2">Anyone who knows a great program</h2>'
    '<p class="rf-lead">Childcare runs on word of mouth. If you know a centre, a home child care agency or a before-and-after school program that could use a hand with the paperwork, tell us.</p>'
    '<ul class="rf-chips"><li>🏫 KiddieTrac customers</li><li>🧑‍🏫 Educators</li><li>🧭 Consultants</li><li>👪 Parents</li><li>🤝 Anyone else</li></ul>'
    '<p class="rf-note">You can’t refer your own agency or centre. The program must be new to KiddieTrac.</p>'
    '</div></div></div></section>\n'
    # the form
    '<section class="rf-sec" id="rfForm"><div class="rf-wrap"><div class="rf-center"><span class="rf-eb">Refer a program</span>'
    '<h2 class="rf-h2">Who should we say hello to?</h2>'
    '<p class="rf-lead">Tell us about the program and how to reach you. We’ll reach out to them about KiddieTrac and keep you posted.</p></div>'
    + FORM +
    '</div></section>\n'
    # faq + terms
    '<section class="rf-sec warm"><div class="rf-wrap"><div class="rf-center"><span class="rf-eb">FAQ</span>'
    '<h2 class="rf-h2">Questions about the referral program</h2></div>'
    '<div class="rf-faq">' + ''.join('<details><summary>%s</summary><p>%s</p></details>' % (q, a) for q, a in FAQ) + '</div>'
    + _terms_html() +
    '</div></section>\n'
    # closing band
    '<section class="rf-final"><div class="rf-wrap">'
    '<h2>Know a program that deserves <span class="rf-g">its evenings back?</span></h2>'
    '<p>Give them a month on us. We’ll say thanks with $200.</p>'
    '<div class="rf-cta" style="justify-content:center"><a class="rf-btn rf-btn-p" ' + scroll_to('rfForm') + '>Refer a program →</a></div>'
    '</div></section>\n'
    '</div>\n')

SCRIPT = r"""<script>
/* Referral program form (referral_edits.py): posts to /marketing-site/lead with source=referral. */
(function () {
  var API = 'https://api.kiddietrac.com/api/v1/marketing-site/lead';
  var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  window.ktRfGo = function (id) {
    var e = document.getElementById(id);
    if (!e) { return false; }
    if (e.tagName === 'DETAILS') { e.open = true; }
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    try { e.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); } catch (x) { e.scrollIntoView(); }
    return false;
  };
  function m(k) {
    var e = document.querySelector('#rfMsgs [data-m="' + k + '"]');
    return e ? e.textContent : k;
  }
  function say(cls, k) {
    var b = document.getElementById('rfMsg');
    if (b) { b.className = 'rf-msg ' + cls; b.textContent = k ? m(k) : ''; }
  }
  function v(f, n) { var x = f.elements[n]; return x ? String(x.value || '').trim() : ''; }
  function bad(f, n, on) { var x = f.elements[n]; if (x && x.setAttribute) { if (on) { x.setAttribute('aria-invalid', 'true'); } else { x.removeAttribute('aria-invalid'); } } }
  window.ktReferral = function (ev) {
    if (ev && ev.preventDefault) { ev.preventDefault(); }
    var f = document.getElementById('rfFormEl');
    if (!f || f.getAttribute('data-busy') === '1') { return false; }
    var d = {
      source: 'referral', terms_version: 'October 2026',
      name: v(f, 'name'), email: v(f, 'email'), phone: v(f, 'phone'),
      relationship: (f.querySelector('input[name="relationship"]:checked') || {}).value || '',
      program: v(f, 'program'), contact_name: v(f, 'contact_name'), contact_email: v(f, 'contact_email'),
      contact_phone: v(f, 'contact_phone'), city: v(f, 'city'), notes: v(f, 'notes'), website: v(f, 'website'),
      consent_share: !!(f.elements.consent_share && f.elements.consent_share.checked),
      accept_terms: !!(f.elements.accept_terms && f.elements.accept_terms.checked)
    };
    var req = ['name', 'email', 'program', 'contact_name'], miss = false;
    req.forEach(function (n) { var no = !d[n]; bad(f, n, no); if (no) { miss = true; } });
    if (miss) { say('err', 'req'); return false; }
    if (!d.relationship) { say('err', 'rel'); return false; }
    if (!d.contact_email && !d.contact_phone) { bad(f, 'contact_email', true); say('err', 'reach'); return false; }
    var be = !EMAIL.test(d.email), bc = d.contact_email && !EMAIL.test(d.contact_email);
    bad(f, 'email', be); bad(f, 'contact_email', bc);
    if (be || bc) { say('err', 'email'); return false; }
    if (d.contact_email && d.contact_email.toLowerCase() === d.email.toLowerCase()) { say('err', 'self'); return false; }
    if (!d.consent_share || !d.accept_terms) { say('err', 'checks'); return false; }
    try {
      if (window.KT_RECAPTCHA_KEY && window.grecaptcha && grecaptcha.render) {
        if (window.__ktRefRcId == null) { window.__ktRefRcId = grecaptcha.render(document.getElementById('ktRefRc'), { sitekey: window.KT_RECAPTCHA_KEY }); }
        d.recaptcha_token = grecaptcha.getResponse(window.__ktRefRcId);
        if (!d.recaptcha_token) { say('err', 'robot'); return false; }
      }
    } catch (x) {}
    f.setAttribute('data-busy', '1');
    say('wait', 'wait');
    fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(d) })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || !j.ok) { var e = new Error(r.status); e.j = j; throw e; } return j; });
      })
      .then(function () {
        f.removeAttribute('data-busy');
        say('', '');
        var done = document.getElementById('rfDone');
        var p = done && done.querySelector('.rf-prog');
        if (p) { p.textContent = d.program; }
        f.hidden = true;
        if (done) { done.hidden = false; try { done.focus({ preventScroll: true }); } catch (x) {} window.ktRfGo('rfForm'); }
        try { if (window.grecaptcha && window.__ktRefRcId != null) { grecaptcha.reset(window.__ktRefRcId); } } catch (x) {}
      })
      .catch(function (e) {
        f.removeAttribute('data-busy');
        say('err', e && e.j && e.j.error === 'self_referral' ? 'self' : 'fail');
      });
    return false;
  };
  window.ktReferralAgain = function () {
    var f = document.getElementById('rfFormEl'), done = document.getElementById('rfDone');
    if (!f) { return; }
    ['program', 'contact_name', 'contact_email', 'contact_phone', 'city', 'notes'].forEach(function (n) { if (f.elements[n]) { f.elements[n].value = ''; } });
    f.elements.consent_share.checked = false;
    if (done) { done.hidden = true; }
    f.hidden = false;
    say('', '');
    try { f.elements.program.focus(); } catch (x) {}
  };
})();
</script>
"""

META_EN = ('Referral Program: Give a Month, Get $200 | KiddieTrac',
           'Refer a childcare program to KiddieTrac. They get their first month free, and you get a one-time $200 CAD by e-transfer once they’ve been a paying customer for 60 days. Anyone can refer.')

SEO_META = {
    'fr': {'t': 'Programme de recommandation : offrez un mois, recevez 200 $ | KiddieTrac',
           'd': 'Recommandez un service de garde à KiddieTrac. Il obtient son premier mois gratuit, et vous recevez 200 $ CA une seule fois par virement électronique après 60 jours comme client payant. Tout le monde peut recommander.'},
    'es': {'t': 'Programa de referidos: regala un mes, recibe $200 | KiddieTrac',
           'd': 'Recomienda un programa de cuidado infantil a KiddieTrac. Recibe su primer mes gratis y tú recibes un pago único de $200 CAD por transferencia electrónica después de 60 días como cliente de pago. Cualquiera puede recomendar.'},
    'hi': {'t': 'रेफ़रल प्रोग्राम: एक महीना दें, $200 पाएँ | KiddieTrac',
           'd': 'किसी चाइल्डकेयर प्रोग्राम को KiddieTrac के लिए रेफ़र करें। उन्हें पहला महीना मुफ़्त मिलता है, और 60 दिन तक भुगतान करने वाला ग्राहक रहने के बाद आपको ई-ट्रांसफ़र से एक बार $200 CAD मिलते हैं। कोई भी रेफ़र कर सकता है।'},
}


def _one(out, o, n):
    assert out.count(o) == 1, ('referral_edits', o[:80], out.count(o))
    return out.replace(o, n)


def referrals(out, seo_meta=None):
    """Apply to the built page (index.built.html). seo_meta: the merged seo_meta.json dict, to
    re-embed KT_META_I18N; None leaves the embedded copy alone."""
    assert 'id="page-referrals"' not in out, 'referral_edits already applied'
    out = _one(out, '</head>', CSS + '\n</head>')
    # home band before HOW IT WORKS (after the reviews)
    out = _one(out, '<!-- ═══ HOW IT WORKS ═══ -->', HOME + '<!-- ═══ HOW IT WORKS ═══ -->')
    # the page, before the blog page
    out = _one(out, '<div class="page" id="page-blog">', PAGE + '<div class="page" id="page-blog">')
    # English META in the router's table
    out = _one(out, '    privacy:{t:"Privacy Policy | KiddieTrac"',
               '    referrals:{t:%s,d:%s},\n    privacy:{t:"Privacy Policy | KiddieTrac"' % (
                   json.dumps(META_EN[0], ensure_ascii=False), json.dumps(META_EN[1], ensure_ascii=False)))
    # menus: desktop Company dropdown, mobile, footer Company, site search
    careers = '<a href="/careers" class="nav-dd-item" onclick="showPage(\'careers\');return false;">💼 Careers</a>'
    out = _one(out, careers, careers + '\n          <a ' + GO.replace('href=', 'class="nav-dd-item" href=') + '>🎁 Referral Program</a>')
    about_m = '<button class="mob-link" onclick="showPage(\'about\');closeMobile()">🏢 About Us</button>'
    out = _one(out, about_m, about_m + '\n  <button class="mob-link" onclick="showPage(\'referrals\');closeMobile()">🎁 Referral Program</button>')
    out = _one(out, '<li onclick="showPage(\'careers\')">Careers</li>',
               '<li onclick="showPage(\'careers\')">Careers</li><li><a ' + GO + '>Referral Program</a></li>')
    out = _one(out, "  {icon:'💼',t:'Careers',s:'Remote roles at KiddieTrac',page:'careers'},",
               "  {icon:'💼',t:'Careers',s:'Remote roles at KiddieTrac',page:'careers'},\n"
               "  {icon:'🎁',t:'Referral Program',s:'Give a month, get $200: refer a childcare program',page:'referrals'},")
    # the form's script, before </body>
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
    s = referrals(open(src, encoding='utf-8').read(), meta)
    open(dst, 'w', encoding='utf-8', newline='').write(s)
    print(dst, len(s), 'bytes')
