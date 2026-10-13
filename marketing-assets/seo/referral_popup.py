# -*- coding: utf-8 -*-
"""A small referral popup on every page (2026-10-13, Anthony: "make a popup on the website as
well"). A card that slides in bottom-left (the chat button is bottom-right):
  - once per visitor: after 20 s on the site or half-way down a page, whichever is first;
  - never on the referral page, never while the cookie banner is still asking;
  - closing it, or clicking through, keeps it away for 30 days (localStorage, per visitor);
  - reduced-motion: no slide. Text reuses strings already translated for /referrals.
usage: python referral_popup.py in.html out.html"""
import sys

HTML = """<!-- Referral popup (referral_popup.py) -->
<style>
.k6rp{position:fixed;left:20px;bottom:20px;z-index:9990;width:340px;max-width:calc(100vw - 32px);background:linear-gradient(160deg,#0b1f45,#14306a);
  color:#fff;border-radius:18px;box-shadow:0 24px 60px -18px rgba(5,15,40,.6);border:1px solid rgba(251,191,36,.35);padding:18px 18px 16px;
  font-family:'Nunito',system-ui,sans-serif;transform:translateY(24px);opacity:0;transition:transform .45s cubic-bezier(.16,.84,.44,1),opacity .45s}
.k6rp.on{transform:none;opacity:1}
.k6rp[hidden]{display:none}
.k6rp-x{position:absolute;top:8px;right:8px;width:30px;height:30px;border:0;border-radius:50%;background:rgba(255,255,255,.1);color:#fff;font-size:16px;cursor:pointer;line-height:1}
.k6rp-x:hover{background:rgba(255,255,255,.2)}
.k6rp-eb{font-size:11.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#fbbf24}
.k6rp-h{font-size:24px;font-weight:900;line-height:1.1;margin:6px 34px 8px 0}
.k6rp-h span{background:linear-gradient(120deg,#fbbf24,#f97316);-webkit-background-clip:text;background-clip:text;color:transparent}
.k6rp-p{font-size:14px;line-height:1.5;color:#cbd5e1;margin:0 0 14px}
.k6rp-go{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:12px;background:linear-gradient(120deg,#fbbf24,#f97316);color:#3b1d02;
  font:800 14.5px 'Nunito',sans-serif;text-decoration:none}
.k6rp-coin{position:absolute;right:16px;bottom:14px;width:54px;height:54px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#fde68a,#f59e0b 70%);
  color:#5b3300;font:900 15px 'Nunito',sans-serif;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 14px -4px rgba(245,158,11,.7);transform:rotate(-8deg)}
@media (max-width:640px){.k6rp{left:12px;right:12px;bottom:12px;width:auto;padding:14px 14px 12px}.k6rp-h{font-size:20px}.k6rp-p{font-size:13px;margin-bottom:10px}.k6rp-coin{display:none}}
@media (prefers-reduced-motion:reduce){.k6rp{transition:none;transform:none}}
</style>
<div class="k6rp" id="k6Ref" role="dialog" aria-labelledby="k6RefH" hidden>
  <button type="button" class="k6rp-x" id="k6RefX" aria-label="Close">✕</button>
  <div class="k6rp-eb">🎁 Referral Program</div>
  <div class="k6rp-h" id="k6RefH">Give a month. <span>Get $200.</span></div>
  <p class="k6rp-p">Know a childcare program that would love KiddieTrac? They get their first month free, and you get $200 CAD once they’ve been a paying customer for 60 days. Anyone can refer.</p>
  <a class="k6rp-go" id="k6RefGo" href="/referrals" onclick="showPage('referrals');return false;">Refer a program →</a>
  <div class="k6rp-coin" aria-hidden="true">$200</div>
</div>
<script>
(function(){
  var KEY='kt_ref_pop_until', box=document.getElementById('k6Ref');
  if(!box) return;
  function later(){ try{ localStorage.setItem(KEY, String(Date.now()+30*864e5)); }catch(e){} }
  function snoozed(){ try{ return +(localStorage.getItem(KEY)||0) > Date.now(); }catch(e){ return true; } }
  function onReferrals(){ var p=location.pathname||''; if(/\\/referrals\\/?$/.test(p)) return true; var pg=document.getElementById('page-referrals'); return !!(pg && pg.classList.contains('active')); }
  function consentPending(){ var c=document.getElementById('ktConsent'); return !!(c && !c.hidden && c.offsetParent!==null); }
  var shown=false;
  function show(){
    if(shown || snoozed() || onReferrals()) return;
    if(consentPending()){ setTimeout(show, 4000); return; }
    shown=true; box.hidden=false; requestAnimationFrame(function(){ requestAnimationFrame(function(){ box.classList.add('on'); }); });
    try{ if(window.gtag) gtag('event','referral_popup_shown'); }catch(e){}
  }
  function hide(){ box.classList.remove('on'); setTimeout(function(){ box.hidden=true; }, 450); }
  document.getElementById('k6RefX').addEventListener('click', function(){ later(); hide(); });
  document.getElementById('k6RefGo').addEventListener('click', function(){ later(); hide(); try{ if(window.gtag) gtag('event','referral_popup_click'); }catch(e){} });
  // The page changes inside the site (showPage): hide if they land on the referral page.
  setInterval(function(){ if(shown && !box.hidden && onReferrals()) hide(); }, 1500);
  if(snoozed()) return;
  setTimeout(show, 20000);
  window.addEventListener('scroll', function onS(){
    var se=document.scrollingElement||document.documentElement;
    if(se.scrollTop > (se.scrollHeight - innerHeight) * 0.5){ window.removeEventListener('scroll', onS); show(); }
  }, {passive:true});
})();
</script>
"""

ANCHOR = '<div class="k6c" id="ktConsent"'


def apply(s):
    if 'id="k6Ref"' in s:
        return s
    assert s.count(ANCHOR) == 1, ('anchor', s.count(ANCHOR))
    i = s.index(ANCHOR)
    # Put it just before the consent banner's own comment block if present, else before the div.
    return s[:i] + HTML + s[i:]


if __name__ == '__main__':
    out = apply(open(sys.argv[1], encoding='utf-8').read())
    open(sys.argv[2], 'w', encoding='utf-8', newline='').write(out)
    print('popup added', out.count('id="k6Ref"'))
