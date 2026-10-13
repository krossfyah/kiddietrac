"""KiddieTrac Safe Arrival on the marketing site (2026-10-13).

Anthony: "add the safe arrival to the marketing website with a unique branding to the safe
arrival feature".

Adds
  /safe-arrival           its own page (page-safe-arrival) with the Safe Arrival sub-brand
  home                    a compact showcase after the QR check-in section
  menus, footer, search   links to the page
  Everything in KiddieTrac  "Safe Arrival alerts" in Daily operations
  changelog, FAQ (+ FAQPage JSON-LD)
  META (English) here; fr/es/hi in seo_meta.json (SEO_META below is merged into it)

SUB-BRAND: "KiddieTrac Safe Arrival", a shield with a check mark (inline SVG), deep forest
green -> emerald -> mint (#022c22 / #10b981 / #6ee7b7), amber for "overdue" and coral for
"staff alerted". Deliberately not the main teal.

FACTS, and where they come from (read 2026-10-13 on the host):
  backend/app/Support/SafeArrival.php            defaults: off; 15 min grace (0-180);
                                                 20 min before staff (5-240); 09:00 default time;
                                                 only children SCHEDULED today at an OPEN centre
  backend/app/Console/Commands/SafeArrivalCommand.php
                                                 every 5 minutes; parents asked ONCE through their
                                                 own channels (app/push, email, SMS as they chose);
                                                 then the room's educators + centre directors
                                                 (agency admins if nobody is attached), in-app +
                                                 push; email to anyone the push did not reach and
                                                 their names on the check; closes itself on
                                                 sign-in or absence
  backend/app/Http/Controllers/Api/SafeArrivalController.php
                                                 close as absent (reason) / parent contacted (note
                                                 required) / other (note); audit log for closing
                                                 and for settings; settings agency admins only
  backend/app/Http/Controllers/Api/AbsenceController.php
                                                 parent "Not attending today" (sick, appointment,
                                                 holiday, family, other) tells the room's
                                                 educators, the director and the agency admin
  backend/resources/help/director/safe-arrival.md, educator/safe-arrival.md
                                                 the board: needs attention first, tap-to-call,
                                                 refreshes every minute, scope by role; replaces
                                                 the old 9:30 reminder
  Texts go through SmsController::sendOne (category checkin_reminder): the agency's SMS
  switch and the parent's own text consent apply. SMS is the "SMS & voice alerts" add-on
  on Pricing ($25/month). Safe Arrival does NOT place voice calls: none claimed.
  No regulatory claims (ontario.ca e-Laws could not be read from here).
"""
import json
import os

PID = 'safe-arrival'
GO = 'href="/safe-arrival" onclick="showPage(\'safe-arrival\');return false;"'

_n = [0]


def shield(size=40, cls='sa-ic'):
    """The Safe Arrival mark. Each copy gets its own gradient id: a gradient defined inside a
    hidden page does not paint for a copy that references it from another page."""
    _n[0] += 1
    g = 'saG%d' % _n[0]
    # The "Arrived" mark (concept 2, chosen by Anthony 2026-10-13): the check doubles as a child
    # with arms up, safe inside the shield; the amber dot is the alert.
    return ('<svg class="%s" width="%d" height="%d" viewBox="0 0 100 100" aria-hidden="true" focusable="false">'
            '<defs><linearGradient id="%s" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#34d399"/>'
            '<stop offset="1" stop-color="#047857"/></linearGradient></defs>'
            '<path d="M50 4 88 18v30c0 25-16 41-38 49C28 89 12 73 12 48V18z" fill="url(#%s)"/>'
            '<path d="M50 4 88 18v30c0 25-16 41-38 49" fill="none" stroke="#065f46" stroke-opacity=".25" stroke-width="3"/>'
            '<circle cx="58" cy="31" r="8.5" fill="#fff"/>'
            '<path d="M30 54l15 15 27-27" fill="none" stroke="#fff" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>'
            '<circle cx="78" cy="22" r="7" fill="#fbbf24" stroke="#fff" stroke-width="2.5"/>'
            '</svg>') % (cls, size, size, g, g)


def lockup(size=40, extra=''):
    return ('<span class="sa-lock%s" translate="no">%s<span class="sa-lock-t"><span>KiddieTrac</span><b>Safe Arrival</b></span></span>'
            % (extra, shield(size)))


CSS = """<style>
/* ── KiddieTrac Safe Arrival sub-brand (safearrival_edits.py) ── */
:root{--sa-ink:#022c22;--sa-deep:#053d31;--sa-em:#10b981;--sa-mint:#6ee7b7;--sa-pale:#d1fae5;--sa-mu:#a3cfc0;--sa-on:#047857;--sa-amb:#f59e0b;--sa-red:#ef4444;
  --sa-grad:linear-gradient(120deg,#6ee7b7 0%,#34d399 50%,#a7f3d0 100%)}
.sa-band{background:radial-gradient(900px 420px at 88% 0%,rgba(52,211,153,.20),transparent 60%),radial-gradient(700px 380px at 0% 100%,rgba(245,158,11,.10),transparent 60%),linear-gradient(165deg,#022c22,#053d31 58%,#03261e);color:#fff;position:relative;overflow:hidden}
.sa-band .k6-h2,.sa-band h1,.sa-band h2{color:#fff}
.sa-g{background:var(--sa-grad);-webkit-background-clip:text;background-clip:text;color:transparent}
.sa-lock{display:inline-flex;align-items:center;gap:10px;font-family:'Nunito',sans-serif}
.sa-lock .sa-ic{flex:0 0 auto;filter:drop-shadow(0 8px 18px rgba(16,185,129,.45))}
.sa-lock-t{display:flex;flex-direction:column;line-height:1;text-align:left}
.sa-lock-t span{font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--sa-mu)}
.sa-lock-t b{font-size:22px;font-weight:900;letter-spacing:-.01em;color:#fff;margin-top:3px}
.sa-pill{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--sa-mint);background:rgba(16,185,129,.14);border:1px solid rgba(110,231,183,.35);padding:6px 12px;border-radius:999px}
.sa-sub{font-size:18px;line-height:1.6;color:var(--sa-mu);max-width:660px}
.sa-btn{display:inline-flex;align-items:center;gap:10px;font:800 16px 'Nunito',sans-serif;padding:14px 24px;border-radius:14px;border:0;cursor:pointer;text-decoration:none;transition:transform .18s ease,box-shadow .18s ease}
.sa-btn:hover{transform:translateY(-2px)}
.sa-btn-p{background:var(--sa-grad);color:#022c22;box-shadow:0 14px 34px -12px rgba(16,185,129,.8)}
.sa-btn-g{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.24)}
/* home showcase */
.sa-home{padding:90px 0}
.sa-home-g{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center}
.sa-home h2{font-size:clamp(28px,3.8vw,44px);line-height:1.08;font-weight:900;letter-spacing:-.02em;margin:18px 0 14px}
.sa-ticks{list-style:none;margin:22px 0 28px;padding:0;display:grid;gap:10px}
.sa-ticks li{display:flex;gap:10px;align-items:flex-start;font-size:15.5px;line-height:1.5;color:#e7f8f1}
.sa-ticks li:before{content:'';flex:0 0 auto;width:20px;height:20px;margin-top:1px;border-radius:7px;background:rgba(16,185,129,.22) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3E%3Cpath d='M5 10.5l3.2 3.2L15 7' fill='none' stroke='%236ee7b7' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center/20px no-repeat}
.sa-tl{position:relative;display:grid;gap:12px;padding-left:30px}
.sa-tl:before{content:'';position:absolute;left:9px;top:10px;bottom:10px;width:2px;background:linear-gradient(#34d399,#f59e0b 60%,#34d399)}
.sa-ev{position:relative;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:14px 16px;transition:background .4s,border-color .4s,transform .4s,opacity .4s;opacity:.55}
.sa-ev:before{content:'';position:absolute;left:-27px;top:18px;width:14px;height:14px;border-radius:50%;background:#053d31;border:3px solid var(--c,#34d399);box-sizing:border-box}
.sa-ev.on{opacity:1;background:rgba(255,255,255,.11);border-color:rgba(110,231,183,.45);transform:translateX(4px)}
.sa-ev.on:before{background:var(--c,#34d399);box-shadow:0 0 0 5px rgba(52,211,153,.18)}
.sa-ev time{display:inline-block;font:900 12.5px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--c,#6ee7b7);letter-spacing:.04em;margin-bottom:3px}
.sa-ev b{display:block;font-size:15.5px;font-weight:900;color:#fff}
.sa-ev span{display:block;font-size:13.5px;color:var(--sa-mu);line-height:1.45;margin-top:2px}
.sa-ev.amb{--c:#fbbf24}.sa-ev.red{--c:#f87171}
.sa-fine{font-size:12.5px;color:#7fb3a2;margin-top:14px}
/* page */
/* the shared page banner sets its navy with !important; this one is the Safe Arrival green */
#page-safe-arrival .hero-band.sa-hero{--k6acc:#10b981;background:radial-gradient(900px 420px at 88% 0%,rgba(52,211,153,.24),transparent 60%),radial-gradient(700px 380px at 0% 100%,rgba(245,158,11,.10),transparent 60%),linear-gradient(165deg,#022c22,#053d31 58%,#03261e)!important}
#page-safe-arrival .hero-band.sa-hero p.sa-sub{color:#bfe3d6!important}
/* green behind the banner's curved bottom edge, so it meets the green demo band */
#page-safe-arrival{background:#03261e}
#page-safe-arrival .sa-sec{background:#fff}
#page-safe-arrival .sa-sec.alt{background:#f3faf7}
.sa-hero h1{font-size:clamp(32px,5vw,56px);line-height:1.05;font-weight:900;letter-spacing:-.02em;color:#fff;margin:22px auto 16px;max-width:860px}
.sa-hero .sa-sub{margin:0 auto}
.sa-hero-cta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:28px}
.sa-facts{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:26px}
.sa-facts span{font-size:13px;font-weight:800;color:#d1fae5;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:999px;padding:7px 13px}
.sa-demo{padding:80px 0}
.sa-wrap{max-width:1200px;margin:0 auto;padding:0 24px}
.sa-center{text-align:center}
.sa-demo-g{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center;margin-top:46px}
.sa-steps{display:flex;flex-direction:column;gap:8px}
.sa-step{display:grid;grid-template-columns:64px 1fr;gap:14px;text-align:left;background:transparent;border:1px solid transparent;border-radius:18px;padding:14px 16px;color:inherit;font-family:inherit;cursor:pointer;position:relative;overflow:hidden;transition:background .25s,border-color .25s}
.sa-step:hover{background:rgba(255,255,255,.04)}
.sa-step.on{background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.15)}
.sa-step time{display:grid;place-items:center;height:40px;border-radius:12px;background:rgba(255,255,255,.08);font:900 14px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--sa-mint);transition:all .25s}
.sa-step.on time{background:var(--sa-grad);color:#022c22;box-shadow:0 10px 24px -10px rgba(16,185,129,.9)}
.sa-step.amb.on time{background:linear-gradient(120deg,#fde68a,#f59e0b)}
.sa-step strong{display:block;font-size:17px;font-weight:900;margin:2px 0 3px;color:#fff}
.sa-step span{display:none;font-size:14.5px;line-height:1.55;color:#bfe3d6}
.sa-step.on span{display:block}
.sa-step i.p{position:absolute;left:0;bottom:0;height:3px;width:0;background:var(--sa-grad)}
.sa-step.on i.p{animation:saprog 5s linear forwards}
.sa-demo.paused .sa-step.on i.p{animation-play-state:paused}
@keyframes saprog{to{width:100%}}
.sa-stage{position:relative;display:flex;justify-content:center}
.sa-phone{position:relative;width:286px;height:574px;border-radius:44px;background:#0b1224;padding:11px;box-shadow:0 50px 100px -30px rgba(0,0,0,.75),0 0 0 1px rgba(255,255,255,.12)}
.sa-notch{position:absolute;top:10px;left:50%;transform:translateX(-50%);width:84px;height:20px;border-radius:12px;background:#0b1224;z-index:3}
.sa-scr{position:absolute;inset:11px;border-radius:34px;overflow:hidden;opacity:0;visibility:hidden;transition:opacity .45s,visibility 0s .45s;background:#f6faf8;color:#0b1b33}
.sa-scr.on{opacity:1;visibility:visible;transition:opacity .45s}
.sa-clock{position:absolute;top:-14px;right:-10px;z-index:5;background:#fff;color:#022c22;border-radius:14px;padding:8px 12px;font:900 15px ui-monospace,SFMono-Regular,Menlo,monospace;box-shadow:0 14px 30px -12px rgba(0,0,0,.6);display:flex;align-items:center;gap:7px}
.sa-clock i{width:9px;height:9px;border-radius:50%;background:#10b981;box-shadow:0 0 0 4px rgba(16,185,129,.25)}
.sa-demo[data-s="3"] .sa-clock i{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.25)}
.sa-clock b{display:none}
.sa-demo[data-s="0"] .sa-clock b[data-t="0"],.sa-demo[data-s="1"] .sa-clock b[data-t="1"],.sa-demo[data-s="2"] .sa-clock b[data-t="2"],.sa-demo[data-s="3"] .sa-clock b[data-t="3"],.sa-demo[data-s="4"] .sa-clock b[data-t="4"]{display:inline}
/* inside the phone */
.sa-app{padding:40px 13px 13px;height:100%;box-sizing:border-box}
.sa-app-h{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.sa-app-h b{display:block;font-size:16px;font-weight:900;line-height:1.1}
.sa-app-h small{display:block;font-size:11px;font-weight:700;color:#5b6b82}
.sa-kp{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:10px}
.sa-kp div{border-radius:10px;padding:7px 6px;text-align:center;background:#eef6ff}
.sa-kp b{display:block;font-size:17px;font-weight:900;line-height:1.1;color:#1e40af}
.sa-kp small{display:block;font-size:9.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#475569}
.sa-kp .ok{background:#ecfdf5}.sa-kp .ok b{color:#166534}
.sa-kp .wt{background:#f1f5f9}.sa-kp .wt b{color:#475569}
.sa-kp .bad{background:#fef2f2}.sa-kp .bad b{color:#991b1b}
.sa-r{display:flex;align-items:center;gap:9px;background:#fff;border:1px solid #e3eaf1;border-radius:12px;padding:9px 10px;margin-bottom:7px;font-size:13.5px;font-weight:800}
.sa-r .av{width:30px;height:30px;border-radius:50%;flex:0 0 auto;display:grid;place-items:center;font-size:13px;font-weight:900;background:#fde68a;color:#78350f}
.sa-r .av.b{background:#bfdbfe;color:#1e3a8a}.sa-r .av.c{background:#fbcfe8;color:#831843}
.sa-r small{display:block;font-size:11px;color:#5b6b82;font-weight:700}
.sa-r em{margin-left:auto;font-style:normal;font-size:10.5px;font-weight:900;border-radius:999px;padding:3px 8px;white-space:nowrap;background:#f1f5f9;color:#475569}
.sa-r em.ok{background:#dcfce7;color:#166534}.sa-r em.ab{background:#ede9fe;color:#5b21b6}.sa-r em.al{background:#fee2e2;color:#991b1b}.sa-r em.ov{background:#fef3c7;color:#92400e}
.sa-sec-h{font-size:11px;font-weight:900;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin:10px 2px 6px}
.sa-lk{background:linear-gradient(170deg,#0f3b31,#0b1b33);height:100%;padding:44px 12px 12px;box-sizing:border-box;color:#fff;text-align:center}
.sa-lk .t{font-size:52px;font-weight:300;letter-spacing:-.02em;line-height:1}
.sa-lk .d{font-size:13px;opacity:.8;margin:4px 0 18px;font-weight:700}
.sa-push{background:rgba(255,255,255,.94);color:#0b1b33;border-radius:16px;padding:10px 12px;text-align:left;box-shadow:0 14px 30px -14px rgba(0,0,0,.6);margin-bottom:8px}
.sa-push-h{display:flex;align-items:center;gap:6px;font-size:10px;font-weight:900;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-bottom:4px}
.sa-push-h small{margin-left:auto;font-size:10px;font-weight:700;letter-spacing:0;text-transform:none}
.sa-push b{display:block;font-size:13.5px;font-weight:900;margin-bottom:2px}
.sa-push p{margin:0;font-size:12px;line-height:1.4;color:#334155}
.sa-push.mute{background:rgba(255,255,255,.14);color:#e2f6ee;font-size:11.5px;font-weight:800;text-align:center}
.sa-sheet{position:absolute;left:0;right:0;bottom:0;background:#fff;border-radius:22px 22px 0 0;padding:16px 14px 14px;box-shadow:0 -10px 30px -10px rgba(0,0,0,.25)}
.sa-sheet h6{margin:0 0 2px;font-size:16px;font-weight:900}
.sa-sheet p{margin:0 0 8px;font-size:11.5px;color:#5b6b82;font-weight:700}
.sa-chips{display:flex;flex-wrap:wrap;gap:6px}
.sa-chips span{border:1.5px solid #e2e8f0;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:800}
.sa-chips span.on{background:#10b981;border-color:#10b981;color:#fff}
.sa-send{margin-top:12px;background:#047857;color:#fff;border-radius:12px;padding:11px;text-align:center;font-weight:900;font-size:13.5px}
.sa-toast{margin:6px 0 0;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:12px;padding:9px 10px;font-size:12px;font-weight:800}
.sa-card{background:#fffbeb;border:1.5px solid #fca5a5;border-radius:14px;padding:10px 11px;margin-top:4px}
.sa-card b{font-size:14px}
.sa-card .mt{font-size:11px;color:#475569;font-weight:700;margin:3px 0 7px;line-height:1.4}
.sa-card .gd{font-size:11.5px;font-weight:800;color:#0b1b33;background:#fff;border-radius:9px;padding:7px 8px;margin-bottom:7px}
.sa-card .gd u{text-decoration:none;color:#047857}
.sa-acts{display:flex;gap:5px;flex-wrap:wrap}
.sa-acts span{font-size:10.5px;font-weight:900;border-radius:8px;padding:5px 7px;background:#f1f5f9;color:#0b1b33}
.sa-acts span.pr{background:#047857;color:#fff}
.sa-done{background:#f0fdf4;border:1.5px solid #86efac;border-radius:14px;padding:10px 11px;margin-top:4px}
.sa-done q{display:block;font-size:12.5px;font-weight:800;color:#0b1b33;margin:5px 0;quotes:none}
.sa-done small{display:block;font-size:11px;color:#475569;font-weight:700}
.sa-audit{display:flex;align-items:center;gap:6px;margin-top:8px;font-size:11px;font-weight:800;color:#065f46}
.sa-demo-note{margin-top:22px;text-align:center;font-size:12.5px;color:#7fb3a2}
/* content sections on the page */
.sa-sec{padding:76px 0}
.sa-sec.alt{background:#f3faf7}
.sa-h2{font-size:clamp(26px,3.4vw,40px);line-height:1.1;font-weight:900;letter-spacing:-.02em;color:#0b1b33;margin:12px 0 12px}
.sa-lead{font-size:17px;line-height:1.65;color:#4b5d73;max-width:720px}
.sa-center .sa-lead{margin:0 auto}
.sa-eb{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#047857;background:#ecfdf5;border:1px solid #a7f3d0;padding:6px 12px;border-radius:999px}
.sa-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:36px}
.sa-c{background:#fff;border:1px solid #dcefe7;border-radius:20px;padding:20px;box-shadow:0 20px 44px -34px rgba(2,44,34,.45)}
.sa-c i{font-style:normal;width:42px;height:42px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(135deg,#d1fae5,#ecfdf5);font-size:20px;margin-bottom:12px}
.sa-c h3{font-size:16.5px;font-weight:900;color:#0b1b33;margin:0 0 6px}
.sa-c p{font-size:14px;line-height:1.6;color:#4b5d73;margin:0}
.sa-two{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:36px}
.sa-who{background:#fff;border:1px solid #dcefe7;border-radius:22px;padding:24px}
.sa-who h3{display:flex;align-items:center;gap:10px;font-size:19px;font-weight:900;color:#0b1b33;margin:0 0 12px}
.sa-who ul{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.sa-who li{display:flex;gap:10px;font-size:14.5px;line-height:1.55;color:#23405f}
.sa-who li:before{content:'';flex:0 0 auto;width:8px;height:8px;border-radius:50%;background:#10b981;margin-top:8px}
.sa-who.team li:before{background:#f59e0b}
.sa-who small{display:block;margin-top:12px;font-size:12.5px;color:#5b6b82;line-height:1.5}
.sa-board{display:grid;grid-template-columns:1.1fr .9fr;gap:40px;align-items:center;margin-top:36px}
.sa-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
.sa-kpis div{border-radius:14px;padding:12px 8px;text-align:center}
.sa-kpis b{display:block;font-size:24px;font-weight:900;line-height:1.1}
.sa-kpis small{display:block;font-size:10px;font-weight:900;letter-spacing:.05em;text-transform:uppercase;color:#475569;margin-top:3px}
.sa-mock{background:#fff;border:1px solid #dcefe7;border-radius:22px;padding:18px;box-shadow:0 30px 60px -40px rgba(2,44,34,.5)}
.sa-mock .sa-r{margin-bottom:8px}
.sa-list{list-style:none;margin:0;padding:0;display:grid;gap:12px}
.sa-list li{font-size:15px;line-height:1.6;color:#23405f}
.sa-list b{color:#0b1b33}
.sa-set{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:30px}
.sa-set>div{display:flex;justify-content:space-between;align-items:center;gap:14px;background:#fff;border:1px solid #dcefe7;border-radius:16px;padding:16px 18px}
.sa-set b{font-size:15px;color:#0b1b33;font-weight:900}
.sa-set span{font-size:13px;color:#5b6b82;display:block;font-weight:700;margin-top:2px}
.sa-set em{font-style:normal;flex:0 0 auto;font:900 13px ui-monospace,SFMono-Regular,Menlo,monospace;color:#047857;background:#ecfdf5;border-radius:10px;padding:6px 10px}
.sa-faq{max-width:800px;margin:30px auto 0}
.sa-faq details{background:#fff;border:1px solid #dcefe7;border-radius:16px;margin-bottom:10px;padding:16px 20px}
.sa-faq summary{font-weight:900;color:#0b1b33;cursor:pointer;font-size:15.5px}
.sa-faq details p{color:#4b5d73;font-size:14.5px;line-height:1.7;margin:10px 0 0}
.sa-final{padding:70px 0;text-align:center}
.sa-final h2{font-size:clamp(26px,3.6vw,40px);font-weight:900;color:#fff;margin:18px 0 10px;letter-spacing:-.02em}
@media (max-width:980px){
  .sa-home-g,.sa-demo-g,.sa-board{grid-template-columns:1fr}
  .sa-cards{grid-template-columns:1fr 1fr}
  .sa-stage{order:-1}
}
@media (max-width:640px){
  .sa-home{padding:64px 0}.sa-demo,.sa-sec{padding:56px 0}
  .sa-wrap{padding:0 16px}
  .sa-cards,.sa-two,.sa-set{grid-template-columns:1fr}
  .sa-kpis{gap:5px}.sa-kpis div{padding:9px 2px;border-radius:10px}.sa-kpis b{font-size:18px}.sa-kpis small{font-size:8px;letter-spacing:.02em}
  .sa-phone{width:262px;height:540px}
  .sa-clock{right:-4px}
  .sa-step{grid-template-columns:56px 1fr;padding:12px}
  .sa-lock-t b{font-size:19px}
  .sa-set>div{flex-direction:column;align-items:flex-start}
}
@media (prefers-reduced-motion:reduce){
  .sa-ev,.sa-scr,.sa-step,.sa-btn{transition:none}
  .sa-step.on i.p{animation:none;width:100%}
  .sa-ev{opacity:1}
}
</style>"""


# ── home: a compact showcase after the QR check-in section ─────────────────────────────
HOME = ('<!-- ═══ SAFE ARRIVAL (safearrival_edits.py) ═══ -->\n'
        '<section class="sa-band sa-home" id="saHome"><div class="sa-wrap"><div class="sa-home-g">'
        '<div>' + lockup(44) +
        '<h2>No child expected today <span class="sa-g">goes unnoticed</span></h2>'
        '<p class="sa-sub">If a child hasn’t been signed in by their usual drop-off time and nobody has reported an absence, KiddieTrac asks the parents. If they don’t answer, the room’s educators and the director are alerted, so someone calls.</p>'
        '<ul class="sa-ticks">'
        '<li>Checks every scheduled child, every 5 minutes, and skips closed days</li>'
        '<li>Parents can answer “Not attending today” in one tap</li>'
        '<li>A live board of who still needs a call, and a record of every check</li>'
        '</ul>'
        '<a class="sa-btn sa-btn-p" ' + GO + '>See how Safe Arrival works →</a>'
        '</div>'
        '<div><div class="sa-tl" id="saTl" aria-label="Example: a Safe Arrival morning">'
        '<div class="sa-ev on"><time>9:00</time><b>Ava and Leo are expected</b><span>Both are scheduled today. Neither is signed in yet.</span></div>'
        '<div class="sa-ev amb"><time>9:15</time><b>Parents asked: “Has Ava arrived?”</b><span>Once, by app notification, email or text, as each parent chose.</span></div>'
        '<div class="sa-ev"><time>9:17</time><b>Ava’s mom: not attending today, unwell</b><span>Recorded as an absence. Ava’s check closes by itself.</span></div>'
        '<div class="sa-ev red"><time>9:35</time><b>No answer for Leo: staff alerted</b><span>The room’s educators and the director get an alert with the parents’ numbers.</span></div>'
        '<div class="sa-ev"><time>9:41</time><b>Closed: “On the way, arriving 10:15”</b><span>Saved with the educator’s name and note.</span></div>'
        '</div><p class="sa-fine">Example morning with the default settings. Names are made up.</p></div>'
        '</div></div></section>\n'
        '<script>(function(){var tl=document.getElementById("saTl");if(!tl)return;var ev=[].slice.call(tl.querySelectorAll(".sa-ev")),i=0,t=null;'
        'var reduce=window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches;'
        'if(reduce){ev.forEach(function(e){e.classList.add("on")});return;}'
        'function show(n){i=n%ev.length;ev.forEach(function(e,k){e.classList.toggle("on",k<=i)});}'
        'function go(){clearInterval(t);show(0);t=setInterval(function(){show(i+1)},2200);}'
        'if("IntersectionObserver" in window){var io=new IntersectionObserver(function(es){if(es[0].isIntersecting){go();io.disconnect();}},{threshold:.3});io.observe(tl);}else go();})();</script>\n\n')


# ── the page ─────────────────────────────────────────────────────────────────────────
def _step(i, t, title, body, amb=False):
    return ('<button class="sa-step%s%s" data-i="%d"><time>%s</time><div><strong>%s</strong><span>%s</span></div><i class="p"></i></button>'
            % (' on' if i == 0 else '', ' amb' if amb else '', i, t, title, body))


DEMO = (
    '<section class="sa-band sa-demo" id="saDemo" data-s="0"><div class="sa-wrap">'
    '<div class="sa-center"><span class="sa-pill">⏱️ One morning, step by step</span>'
    '<h2 class="k6-h2" style="font-size:clamp(28px,3.8vw,46px)">What happens when a child <span class="sa-g">hasn’t arrived</span></h2>'
    '<p class="sa-sub" style="margin:0 auto">Times below use the default settings: parents are asked 15 minutes after the expected time, and staff are alerted 20 minutes after that.</p></div>'
    '<div class="sa-demo-g">'
    '<div class="sa-steps" id="saSteps">'
    + _step(0, '9:00', 'Every scheduled child is expected', 'Each child has their usual drop-off time on their record; children without one use your default time. Only children scheduled today, at a centre that is open today, are checked.')
    + _step(1, '9:15', 'Not signed in? The parents are asked once', 'After your grace period, the parents get “Has Ava arrived?” through the channels each parent chose: app notification, email or text. If she’s on the way, there’s no need to reply.', amb=True)
    + _step(2, '9:17', 'One tap: not attending today', 'The parent picks a reason, such as unwell or an appointment. It is saved as today’s absence, the check closes by itself, and the room’s educators and the director are told.')
    + _step(3, '9:35', 'No answer? Your team is alerted', 'If Leo still isn’t signed in and no absence was reported, his room’s educators and the centre’s director get an alert that opens the Safe Arrival board, with the parents’ phone numbers.', amb=True)
    + _step(4, '9:41', 'Closed, with a record', 'Whoever calls records what they found: an absence with a reason, “parent contacted” with a note, or something else. Who closed it, when and why is kept, and written to the audit log.')
    + '</div>'
    '<div class="sa-stage" aria-hidden="true"><div class="sa-phone"><span class="sa-notch"></span>'
    '<div class="sa-clock"><i></i><b data-t="0">9:00 AM</b><b data-t="1">9:15 AM</b><b data-t="2">9:17 AM</b><b data-t="3">9:35 AM</b><b data-t="4">9:41 AM</b></div>'
    # 0 board, nobody due yet
    '<div class="sa-scr on" data-sc="0"><div class="sa-app">'
    '<div class="sa-app-h">' + shield(28) + '<div><b>Safe Arrival</b><small>Maple Room · today</small></div></div>'
    '<div class="sa-kp"><div><b>12</b><small>Expected</small></div><div class="ok"><b>10</b><small>Arrived</small></div><div class="wt"><b>2</b><small>Not due yet</small></div></div>'
    '<div class="sa-sec-h">Everyone else today</div>'
    '<div class="sa-r"><span class="av">A</span><div>Ava<small>expected 9:00</small></div><em>Not due yet</em></div>'
    '<div class="sa-r"><span class="av b">L</span><div>Leo<small>expected 9:00 (default)</small></div><em>Not due yet</em></div>'
    '<div class="sa-r"><span class="av c">M</span><div>Mia<small>arrived 8:12</small></div><em class="ok">Arrived</em></div>'
    '<div class="sa-r"><span class="av">N</span><div>Noah<small>arrived 8:31</small></div><em class="ok">Arrived</em></div>'
    '</div></div>'
    # 1 parent's lock screen
    '<div class="sa-scr" data-sc="1"><div class="sa-lk"><div class="t">9:15</div><div class="d">Tuesday</div>'
    '<div class="sa-push"><div class="sa-push-h">' + shield(14) + 'KiddieTrac<small>now</small></div>'
    '<b>🛡️ Has Ava arrived?</b><p>Ava was expected at Sunflowers Centre around 9:00 AM and hasn’t been signed in. If they’re not coming in today, please tell us in the app (Not attending today). If they’re on the way, no need to reply.</p></div>'
    '<div class="sa-push mute">✉️ Also by email, and 💬 by text if the parent agreed to texts</div>'
    '</div></div>'
    # 2 parent reports the absence
    '<div class="sa-scr" data-sc="2"><div class="sa-app" style="background:#eef4f8">'
    '<div class="sa-app-h"><span class="av" style="width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#fde68a;color:#78350f;font-weight:900">A</span><div><b>Ava</b><small>Sunflowers Centre · today</small></div></div>'
    '<div class="sa-toast">✓ Reported absent today · unwell</div>'
    '<div class="sa-sheet"><h6>Not attending today</h6><p>Let the centre know Ava won’t be in.</p>'
    '<div class="sa-sec-h" style="margin-top:4px">Reason</div>'
    '<div class="sa-chips"><span class="on">🤒 Unwell</span><span>🩺 Appointment</span><span>✈️ Holiday</span><span>👪 Family</span><span>• Other</span></div>'
    '<div class="sa-send">Tell the centre</div></div>'
    '</div></div>'
    # 3 staff alert + needs attention
    '<div class="sa-scr" data-sc="3"><div class="sa-app">'
    '<div class="sa-push" style="background:#fff;border:1px solid #fecaca"><div class="sa-push-h">' + shield(14) + 'KiddieTrac<small>now</small></div>'
    '<b>⚠️ Safe Arrival: Leo not here</b><p>Leo (Maple Room) was expected around 9:00 AM, hasn’t been signed in, and no absence has been reported. The parents were asked and haven’t answered. Please call them.</p></div>'
    '<div class="sa-sec-h">Needs attention</div>'
    '<div class="sa-card"><b>Leo</b> <span class="sa-r" style="display:inline;border:0;padding:0;margin:0;background:none"><em class="al">Staff alerted</em></span>'
    '<div class="mt">Maple Room · expected 9:00 · parents asked 9:15 · staff alerted 9:35</div>'
    '<div class="gd">Dana (Mother) · <u>📞 555-0142</u></div>'
    '<div class="sa-acts"><span class="pr">Record absence</span><span>Parent contacted</span><span>Other…</span></div></div>'
    '</div></div>'
    # 4 closed
    '<div class="sa-scr" data-sc="4"><div class="sa-app">'
    '<div class="sa-app-h">' + shield(28) + '<div><b>Safe Arrival</b><small>Maple Room · today</small></div></div>'
    '<div class="sa-kp"><div class="ok"><b>11</b><small>Arrived</small></div><div><b>1</b><small>Absent</small></div><div class="wt"><b>0</b><small>Needs attention</small></div></div>'
    '<div class="sa-done"><b>Leo · parent contacted</b><q>“Spoke to mom at 9:40, arriving 10:15”</q><small>Closed by Jordan, educator · 9:41 AM</small>'
    '<div class="sa-audit">' + shield(14) + 'Saved to the audit log</div></div>'
    '<div class="sa-r" style="margin-top:8px"><span class="av">A</span><div>Ava<small>reported by parent</small></div><em class="ab">Absent · unwell</em></div>'
    '</div></div>'
    '</div></div>'
    '</div>'
    '<p class="sa-demo-note">Illustration. Names, centre and numbers are made up.</p>'
    '</div></section>\n'
    '<script>(function(){var sec=document.getElementById("saDemo");if(!sec)return;'
    'var steps=[].slice.call(sec.querySelectorAll(".sa-step")),scr=[].slice.call(sec.querySelectorAll(".sa-scr")),i=0,t=null;'
    'var reduce=window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches;'
    'function show(n){i=(n+steps.length)%steps.length;sec.setAttribute("data-s",String(i));'
    'steps.forEach(function(s,k){s.classList.toggle("on",k===i);if(k===i){var p=s.querySelector(".p");if(p){p.style.animation="none";void p.offsetWidth;p.style.animation="";}}});'
    'scr.forEach(function(s){s.classList.toggle("on",s.getAttribute("data-sc")===String(i));});}'
    'function play(){clearInterval(t);if(reduce)return;t=setInterval(function(){if(!sec.classList.contains("paused"))show(i+1);},5000);}'
    'steps.forEach(function(s,k){s.addEventListener("click",function(){show(k);play();});});'
    'sec.addEventListener("mouseenter",function(){sec.classList.add("paused");});'
    'sec.addEventListener("mouseleave",function(){sec.classList.remove("paused");});'
    'if("IntersectionObserver" in window){var io=new IntersectionObserver(function(es){if(es[0].isIntersecting){show(0);play();io.disconnect();}},{threshold:.3});io.observe(sec);}else play();})();</script>\n')

FAQ = [
    ('Who gets the first message?',
     'The child’s parents and guardians, once, through the channels each of them chose in their profile: app notification, email, or a text message if they agreed to texts. If they answer “Not attending today”, nothing else is sent.'),
    ('Who is alerted when the parents don’t answer?',
     'The educators assigned to the child’s room and the centre’s director, in the app and as a phone notification. If no one is attached to that room or centre, the agency’s admins are alerted, so an alert never goes nowhere.'),
    ('What if an educator’s phone notifications are off?',
     'Anyone the phone notification doesn’t reach gets the same alert by email, and the Safe Arrival board shows their name, so the director can see who wasn’t reached.'),
    ('Does it run on holidays or days a child doesn’t attend?',
     'No. Only children scheduled for that weekday are checked, and only at a centre that is open that day. Closures and holidays on your calendar are skipped, and a child already reported absent is never asked about.'),
    ('Do we need the SMS add-on?',
     'No. Safe Arrival is part of KiddieTrac and works with app notifications and email. Text messages to parents use the SMS &amp; voice alerts add-on, and only go to parents who agreed to texts.'),
    ('Can educators turn it off or change the times?',
     'No. Safe Arrival is off until an agency admin turns it on, and only an agency admin can change its settings. Every change is written to the audit log.'),
]


def _faq_html():
    return '<div class="sa-faq">' + ''.join('<details><summary>%s</summary><p>%s</p></details>' % (q, a) for q, a in FAQ) + '</div>'


PAGE = (
    '<div class="page" id="page-safe-arrival">\n'
    '<div class="hero-band sa-hero"><div class="container">'
    + lockup(52) +
    '<h1>Every child expected today, <span class="sa-g">accounted for</span></h1>'
    '<p class="sa-sub">Safe Arrival watches for children who were scheduled today but haven’t been signed in. It asks the parents first, alerts your team if nobody answers, and keeps a record of how every check was closed.</p>'
    '<div class="sa-hero-cta"><button class="sa-btn sa-btn-p" onclick="showPage(\'contact\')">Book a demo →</button>'
    '<a class="sa-btn sa-btn-g" href="#saDemo" onclick="var e=document.getElementById(\'saDemo\');if(e){e.scrollIntoView({behavior:\'smooth\'});}return false;">See it step by step</a></div>'
    '<div class="sa-facts"><span>⏱️ Checks every 5 minutes</span><span>👪 Parents asked first</span><span>🔔 Staff alerted if no answer</span><span>🧾 Every check recorded</span></div>'
    '</div></div>\n'
    + DEMO +
    # how it decides
    '<section class="sa-sec"><div class="sa-wrap"><div class="sa-center"><span class="sa-eb">Who gets checked</span>'
    '<h2 class="sa-h2">Only the children you’re really expecting</h2>'
    '<p class="sa-lead">Safe Arrival works from the schedule you already keep in KiddieTrac, so families aren’t messaged about days their child was never coming.</p></div>'
    '<div class="sa-cards">'
    '<div class="sa-c"><i>📅</i><h3>Scheduled days only</h3><p>A child is checked only on the weekdays they’re enrolled for.</p></div>'
    '<div class="sa-c"><i>🏫</i><h3>Open days only</h3><p>Holidays and closures on your calendar are skipped for the whole centre.</p></div>'
    '<div class="sa-c"><i>🕘</i><h3>Each child’s own time</h3><p>Set a child’s usual drop-off time on their record. Everyone else uses your default, marked “(default)” on the board.</p></div>'
    '<div class="sa-c"><i>🤒</i><h3>Absences already known</h3><p>When a parent has already reported today’s absence, there is nothing to ask about.</p></div>'
    '</div></div></section>\n'
    # who hears what
    '<section class="sa-sec alt"><div class="sa-wrap"><div class="sa-center"><span class="sa-eb">Who hears what</span>'
    '<h2 class="sa-h2">Parents first, then the people in the room</h2></div>'
    '<div class="sa-two">'
    '<div class="sa-who"><h3>👪 Families</h3><ul>'
    '<li>Asked once, when the grace period after the expected time has passed.</li>'
    '<li>The message goes through each parent’s own choices: app notification, email, or a text if they agreed to texts.</li>'
    '<li>One tap on “Not attending today”, with a reason, records the absence and stops the messages.</li>'
    '<li>If the child is on the way, no reply is needed: the check closes when they’re signed in.</li>'
    '</ul><small>Text messages use the SMS &amp; voice alerts add-on. Safe Arrival works without it through the app and email.</small></div>'
    '<div class="sa-who team"><h3>🧑‍🏫 Your team</h3><ul>'
    '<li>If the parents haven’t answered by your escalation time, the room’s educators and the centre’s director are alerted.</li>'
    '<li>The alert opens the Safe Arrival board, with each guardian’s name and a tap-to-call number.</li>'
    '<li>Anyone a phone notification can’t reach gets the alert by email, and the board names them.</li>'
    '<li>When a parent reports an absence, the room’s educators and the director are told too.</li>'
    '</ul><small>Staff alerts go out even if a family has turned notifications off: a missing child is the centre’s business.</small></div>'
    '</div></div></section>\n'
    # the board
    '<section class="sa-sec"><div class="sa-wrap"><div class="sa-center"><span class="sa-eb">The board</span>'
    '<h2 class="sa-h2">Who still needs a call, at a glance</h2></div>'
    '<div class="sa-board"><div class="sa-mock" aria-hidden="true">'
    '<div class="sa-kpis"><div style="background:#eff6ff"><b style="color:#1e40af">24</b><small>Expected today</small></div>'
    '<div style="background:#f0fdf4"><b style="color:#166534">19</b><small>Arrived</small></div>'
    '<div style="background:#f5f3ff"><b style="color:#5b21b6">2</b><small>Absent</small></div>'
    '<div style="background:#f8fafc"><b style="color:#475569">2</b><small>Not due yet</small></div>'
    '<div style="background:#fef2f2"><b style="color:#991b1b">1</b><small>Needs attention</small></div></div>'
    '<div class="sa-sec-h" style="margin-top:14px">Needs attention</div>'
    '<div class="sa-r" style="border-color:#fcd34d;background:#fffdf5"><span class="av b">L</span><div>Leo<small>Maple Room · expected 9:00 · parents asked 9:15</small></div><em class="ov">Overdue</em></div>'
    '<div class="sa-sec-h">Everyone else today</div>'
    '<div class="sa-r"><span class="av">A</span><div>Ava<small>unwell</small></div><em class="ab">Absent</em></div>'
    '<div class="sa-r"><span class="av c">M</span><div>Mia<small>arrived 8:12</small></div><em class="ok">Arrived</em></div>'
    '</div>'
    '<ul class="sa-list">'
    '<li><b>Needs attention comes first.</b> Overdue children are at the top, with the ones staff have already been alerted about first.</li>'
    '<li><b>Close each case in a tap.</b> Record an absence with a reason, mark “parent contacted” with a note such as “arriving 10:15”, or choose other.</li>'
    '<li><b>Everyone else today.</b> Who has arrived and when, who is absent and why, and who isn’t due yet. The board refreshes every minute.</li>'
    '<li><b>The right view for each role.</b> Educators see their own rooms, directors their centres, and agency admins every centre with a filter.</li>'
    '</ul></div>'
    '<p class="sa-fine" style="color:#5b6b82;text-align:center;margin-top:18px">Illustration. Names and numbers are made up.</p>'
    '</div></section>\n'
    # settings + record
    '<section class="sa-sec alt"><div class="sa-wrap"><div class="sa-center"><span class="sa-eb">Your rules</span>'
    '<h2 class="sa-h2">Settings that match your safe arrival policy</h2>'
    '<p class="sa-lead">Safe Arrival is off until an agency admin turns it on, because it messages parents. Only an agency admin can change it, and every change is written to the audit log.</p></div>'
    '<div class="sa-set">'
    '<div><div><b>Ask parents after</b><span>minutes past the expected time</span></div><em>15 min</em></div>'
    '<div><div><b>Alert staff after</b><span>minutes with no answer from parents</span></div><em>20 min</em></div>'
    '<div><div><b>Default expected time</b><span>for children without their own</span></div><em>9:00 AM</em></div>'
    '<div><div><b>Each child’s drop-off time</b><span>on their record, under usual times</span></div><em>per child</em></div>'
    '</div>'
    '<p class="sa-fine" style="color:#5b6b82;text-align:center;margin-top:16px">Defaults shown. Parents can be asked from 0 to 180 minutes after the expected time, and staff alerted 5 to 240 minutes later.</p>'
    '<div class="sa-two" style="margin-top:40px">'
    '<div class="sa-who"><h3>🧾 A record of every check</h3><ul>'
    '<li>When the parents were asked, and how many were reached.</li>'
    '<li>When staff were alerted, and whom a phone notification didn’t reach.</li>'
    '<li>How it was closed, by whom, and with what note. Closing a case is written to the audit log.</li>'
    '</ul></div>'
    '<div class="sa-who"><h3>🔕 One message, at the right time</h3><ul>'
    '<li>With Safe Arrival on, it replaces KiddieTrac’s fixed morning “has your child arrived?” reminder, so parents get one message, not two.</li>'
    '<li>Nothing is sent on weekends, holidays or days a child isn’t scheduled.</li>'
    '<li>A reported absence counts everywhere: the roster, reminders and the parent app see it.</li>'
    '</ul></div>'
    '</div></div></section>\n'
    # faq
    '<section class="sa-sec"><div class="sa-wrap"><div class="sa-center"><span class="sa-eb">FAQ</span>'
    '<h2 class="sa-h2">Questions about Safe Arrival</h2></div>' + _faq_html() + '</div></section>\n'
    # final
    '<section class="sa-band sa-final"><div class="sa-wrap">' + lockup(46) +
    '<h2>Know who’s missing before anyone has to ask</h2>'
    '<p class="sa-sub" style="margin:0 auto">See Safe Arrival on your own rooms in a short demo. Free 14-day trial, no credit card required.</p>'
    '<div class="sa-hero-cta"><button class="sa-btn sa-btn-p" onclick="showPage(\'contact\')">Book a demo →</button>'
    '<button class="sa-btn sa-btn-g" onclick="showPage(\'pricing\')">See pricing</button></div>'
    '</div></section>\n'
    '</div>\n')

META_EN = ('Safe Arrival: Alerts When a Child Hasn’t Arrived | KiddieTrac',
           'When a child expected today hasn’t been signed in, KiddieTrac asks the parents, then alerts the room’s educators and director. A live board and a record of every check.')

SEO_META = {
    'fr': {'t': 'Safe Arrival : alertes quand un enfant n’est pas arrivé | KiddieTrac',
           'd': 'Quand un enfant attendu aujourd’hui n’a pas été inscrit à l’arrivée, KiddieTrac demande aux parents, puis alerte les éducatrices et la direction. Un tableau en direct et un registre de chaque vérification.'},
    'es': {'t': 'Safe Arrival: alertas cuando un niño no ha llegado | KiddieTrac',
           'd': 'Cuando un niño esperado hoy no ha sido registrado, KiddieTrac pregunta a los padres y luego alerta a los educadores del aula y a la dirección. Un tablero en vivo y un registro de cada verificación.'},
    'hi': {'t': 'Safe Arrival: जब बच्चा नहीं पहुँचा हो तो अलर्ट | KiddieTrac',
           'd': 'जब आज अपेक्षित किसी बच्चे का साइन-इन नहीं हुआ हो, तो KiddieTrac माता-पिता से पूछता है, फिर कक्षा के शिक्षकों और डायरेक्टर को अलर्ट करता है। लाइव बोर्ड और हर जाँच का रिकॉर्ड।'},
}

CHANGELOG = ('<div class="kt-log-item"><div class="kt-log-date">October 2026 <span class="kt-log-new">NEW</span></div><div class="kt-log-body">'
             '<h3>🛡️ Safe Arrival</h3><p>When a child expected today hasn’t been signed in and no absence was reported, KiddieTrac asks the parents, then alerts the room’s educators and the director if nobody answers. '
             'Anyone a phone notification can’t reach gets the alert by email. A live board shows who still needs a call, and every check is recorded. '
             '<a ' + GO + ' style="color:var(--teal);font-weight:800">See Safe Arrival</a></p></div></div>\n        ')

HOME_FAQ_Q = 'What happens when a child doesn’t arrive?'
HOME_FAQ_A = ('With Safe Arrival on, KiddieTrac checks every scheduled child every 5 minutes. If a child hasn’t been signed in by their usual drop-off time plus your grace period, and no absence was reported, the parents are asked once by app notification, email or text. '
              'If nobody answers, the room’s educators and the director are alerted and the Safe Arrival board shows who still needs a call. Every check is recorded.')


def _one(out, o, n):
    assert out.count(o) == 1, ('safearrival_edits', o[:80], out.count(o))
    return out.replace(o, n)


def safearrival(out, seo_meta=None):
    """Apply to a built page (live chain output or index.built.html). seo_meta: the merged
    seo_meta.json dict, to re-embed KT_META_I18N; None leaves the embedded copy alone."""
    assert 'id="page-safe-arrival"' not in out, 'safearrival_edits already applied'
    # styles once, in the head
    out = _one(out, '</head>', CSS + '\n</head>')
    # home showcase after the QR check-in section
    out = _one(out, '<!-- ═══ BENTO ═══ -->', HOME + '<!-- ═══ BENTO ═══ -->')
    # the page, before the blog page
    out = _one(out, '<div class="page" id="page-blog">', PAGE + '<div class="page" id="page-blog">')
    # English META in the router's table
    out = _one(out, '    privacy:{t:"Privacy Policy | KiddieTrac"',
               '    "safe-arrival":{t:%s,d:%s},\n    privacy:{t:"Privacy Policy | KiddieTrac"' % (
                   json.dumps(META_EN[0], ensure_ascii=False), json.dumps(META_EN[1], ensure_ascii=False)))
    # menus: desktop Solutions dropdown, mobile, footer, site search
    out = _one(out, '<a href="/solutions" class="nav-dd-item" onclick="showPage(\'solutions\');return false;">⚡ All Solutions</a>',
               '<a href="/solutions" class="nav-dd-item" onclick="showPage(\'solutions\');return false;">⚡ All Solutions</a>'
               '<a ' + GO.replace('href=', 'class="nav-dd-item" href=') + '>🛡️ Safe Arrival</a>')
    out = _one(out, '<button class="mob-link" onclick="showPage(\'solutions\');closeMobile()" data-i18n="nav_solutions">⚡ Solutions</button>',
               '<button class="mob-link" onclick="showPage(\'solutions\');closeMobile()" data-i18n="nav_solutions">⚡ Solutions</button>'
               '<button class="mob-link" onclick="showPage(\'safe-arrival\');closeMobile()">🛡️ Safe Arrival</button>')
    out = _one(out, '<li onclick="showPage(\'solutions\')">Solutions</li>',
               '<li onclick="showPage(\'solutions\')">Solutions</li><li><a ' + GO + '>Safe Arrival</a></li>')
    out = _one(out, "  {icon:'⚡',t:'Solutions',s:'Billing, attendance, CWELCC, QR check-in',page:'solutions'},",
               "  {icon:'⚡',t:'Solutions',s:'Billing, attendance, CWELCC, QR check-in',page:'solutions'},\n"
               "  {icon:'🛡️',t:'Safe Arrival',s:'Alerts when a child expected today hasn’t arrived',page:'safe-arrival'},")
    # Everything in KiddieTrac: Daily operations
    out = _one(out, '<li>QR check-in &amp; kiosk</li>',
               '<li>QR check-in &amp; kiosk</li><li><a ' + GO + ' style="color:#047857">Safe Arrival alerts</a></li>')
    # changelog, newest first
    i = out.find('<div class="page" id="page-changelog">')
    j = out.find('<div class="kt-log-item">', i)
    assert 0 < i < j
    out = out[:j] + CHANGELOG + out[j:]
    # home FAQ + its JSON-LD
    q_qr = '<div class="k6-fq k6-rv"><button>Is my data secure?</button>'
    out = _one(out, q_qr, '<div class="k6-fq k6-rv"><button>%s</button><div><p>%s <a %s>How Safe Arrival works</a>.</p></div></div>\n      ' % (
        HOME_FAQ_Q, HOME_FAQ_A, GO) + q_qr)
    ld_sec = '{"@type":"Question","name":"Is my data secure?"'
    out = _one(out, ld_sec, json.dumps({'@type': 'Question', 'name': HOME_FAQ_Q, 'acceptedAnswer': {'@type': 'Answer', 'text': HOME_FAQ_A}},
                                       ensure_ascii=False, separators=(',', ':')) + ',' + ld_sec)
    # fr/es/hi titles for the page's own router
    if seo_meta is not None:
        import re
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
    s = safearrival(open(src, encoding='utf-8').read(), meta)
    open(dst, 'w', encoding='utf-8', newline='').write(s)
    print(dst, len(s), 'bytes')
