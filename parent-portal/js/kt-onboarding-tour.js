/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p68 — Onboarding tour
   • First-login welcome dialog with 4-step quick orientation
   • Skippable. Stored as `kt_onboarded` in localStorage
   • Role-aware: shows different welcome for each role
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const TOURS = {
    platform_admin: [
      { icon: '🏛', title: 'Welcome to KiddieTrac', body: 'You are the platform admin. You can manage every agency on KiddieTrac from this account.' },
      { icon: '🔀', title: 'Switch agencies', body: 'Pick any agency from the top-bar dropdown to act as their admin.' },
      { icon: '📊', title: 'Platform metrics', body: 'The Dashboard shows MRR, ARR, active centres, and platform-wide stats.' },
      { icon: '📖', title: 'Help & guide', body: 'New Help & Guide section in the sidebar with 30+ articles + AI Ask. Look for the ? badges on screens for contextual help.' },
    ],
    agency_admin: [
      { icon: '👋', title: 'Welcome to KiddieTrac', body: 'You have full agency control. Manage centres, staff, families, billing, marketing, and compliance from one place.' },
      { icon: '🏫', title: 'Set up centres', body: 'Add your centres and rooms in Administration → Centres. Each centre has its own director, schedule, and roster.' },
      { icon: '👨‍👩‍👧', title: 'Invite families', body: 'Add families in Administration → Families. They get an email invite to set up their account.' },
      { icon: '📖', title: 'Help anywhere', body: 'Sidebar → Help & Guide for full articles. Or click the ? badge on any section for instant context. ✨ AI Ask answers in plain English.' },
    ],
    centre_director: [
      { icon: '👋', title: 'Welcome to KiddieTrac', body: 'You run a centre on KiddieTrac. Track attendance, schedule staff, share photos, message parents, and run compliance.' },
      { icon: '📋', title: 'Today screen', body: 'The Today tab shows sign-ins, ratios, late pickups, and your current room status at a glance.' },
      { icon: '👨‍👩‍👧', title: 'Family communication', body: 'Photos, videos, observations, and chat all flow to families instantly. Voice messages + AI translate built in.' },
      { icon: '📖', title: 'Help', body: 'Sidebar → Help & Guide. Or click any ? badge on screen for the relevant article in a drawer.' },
    ],
    educator: [
      { icon: '👋', title: 'Welcome to KiddieTrac', body: 'You can log daily care, share photos, write observations, and message parents from any device.' },
      { icon: '📋', title: 'Today screen', body: 'See your room, ratios, and today\\'s lesson plan from the Today tab.' },
      { icon: '📷', title: 'Capture moments', body: 'Photo, video, and voice messages all upload from your phone. Tag children + add HDLH domain tags.' },
      { icon: '📖', title: 'Help & Guide', body: 'Sidebar → Help & Guide. Or click a ? badge on any screen for help right there.' },
    ],
    guardian: [
      { icon: '👋', title: 'Welcome to KiddieTrac', body: 'See your child\\'s day in real-time: photos, videos, care logs, milestones, and direct messaging with staff.' },
      { icon: '☀️', title: 'Today', body: 'The Today screen shows your child\\'s sign-in time, room, mood, and updates from staff.' },
      { icon: '💳', title: 'Billing', body: 'Invoices, autopay, and account ledger live under Billing. Add cards or bank accounts in Wallet.' },
      { icon: '📖', title: 'Help anytime', body: 'Sidebar → Help & Guide. Or tap the ✨ button bottom-right to ask a question in plain English.' },
    ],
  };

  function showTour() {
    const user = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
    const role = (user.roles && user.roles[0]) || user.primary_role || 'guardian';
    const steps = TOURS[role] || TOURS.guardian;
    let step = 0;

    const overlay = document.createElement('div');
    overlay.id = 'kt-onboard-overlay';
    overlay.style.cssText = `
      position: fixed; inset: 0; background: rgba(15, 30, 45, 0.72);
      z-index: 12000; display: flex; align-items: center; justify-content: center;
      animation: kt-fade-in 0.3s ease-out; backdrop-filter: blur(4px);
    `;

    if (!document.getElementById('kt-onboard-anim')) {
      const css = document.createElement('style');
      css.id = 'kt-onboard-anim';
      css.textContent = `
        @keyframes kt-fade-in { from { opacity: 0; } to { opacity: 1; } }
        @keyframes kt-pop-in { from { transform: scale(0.92); opacity: 0; } to { transform: scale(1); opacity: 1; } }
      `;
      document.head.appendChild(css);
    }

    const card = document.createElement('div');
    card.style.cssText = `
      background: white; border-radius: 24px; padding: 0; overflow: hidden;
      width: min(560px, 92vw); animation: kt-pop-in 0.32s cubic-bezier(.22,1.4,.36,1);
      box-shadow: 0 24px 64px rgba(0,0,0,0.4);
    `;

    const render = () => {
      card.innerHTML = '';
      const s = steps[step];

      // Hero
      const hero = document.createElement('div');
      hero.style.cssText = `
        background: linear-gradient(135deg, #1F6080 0%, #2D7BA8 60%, #4A90B8 100%);
        color: white; padding: 36px 32px 28px; text-align: center; position: relative;
      `;

      // Skip
      const skip = document.createElement('button');
      skip.textContent = 'Skip';
      skip.style.cssText = `
        position: absolute; top: 16px; right: 18px;
        background: rgba(255,255,255,0.18); color: white;
        border: 1px solid rgba(255,255,255,0.3); padding: 5px 14px; border-radius: 14px;
        font-size: 12px; cursor: pointer; font-weight: 600;
      `;
      skip.addEventListener('click', finish);
      hero.appendChild(skip);

      const icon = document.createElement('div');
      icon.style.cssText = 'font-size: 56px; margin-bottom: 8px;';
      icon.textContent = s.icon;
      hero.appendChild(icon);

      const ttl = document.createElement('h2');
      ttl.style.cssText = 'margin: 0; font-size: 22px; font-weight: 800;';
      ttl.textContent = s.title;
      hero.appendChild(ttl);
      card.appendChild(hero);

      // Body
      const bodyDiv = document.createElement('div');
      bodyDiv.style.cssText = 'padding: 28px 32px; text-align: center;';

      const text = document.createElement('p');
      text.style.cssText = 'margin: 0 0 24px; font-size: 15px; line-height: 1.6; color: #4B5563;';
      text.textContent = s.body;
      bodyDiv.appendChild(text);

      // Dots
      const dots = document.createElement('div');
      dots.style.cssText = 'display: flex; gap: 6px; justify-content: center; margin-bottom: 20px;';
      steps.forEach((_, i) => {
        const dot = document.createElement('div');
        dot.style.cssText = `width: 8px; height: 8px; border-radius: 50%; background: ${i === step ? '#1F6080' : '#E5E7EB'};`;
        dots.appendChild(dot);
      });
      bodyDiv.appendChild(dots);

      // Buttons
      const btns = document.createElement('div');
      btns.style.cssText = 'display: flex; gap: 12px; justify-content: center;';

      if (step > 0) {
        const back = document.createElement('button');
        back.textContent = 'Back';
        back.style.cssText = 'background: white; color: #4B5563; border: 1px solid #D1D5DB; padding: 12px 24px; border-radius: 28px; font-weight: 700; font-size: 14px; cursor: pointer;';
        back.addEventListener('click', () => { step--; render(); });
        btns.appendChild(back);
      }

      const next = document.createElement('button');
      next.textContent = (step === steps.length - 1) ? "Let's go!" : 'Next  →';
      next.style.cssText = 'background: linear-gradient(135deg, #1F6080, #2D7BA8); color: white; border: none; padding: 12px 28px; border-radius: 28px; font-weight: 700; font-size: 14px; cursor: pointer; box-shadow: 0 4px 12px rgba(31, 96, 128, 0.3);';
      next.addEventListener('click', () => {
        if (step === steps.length - 1) finish();
        else { step++; render(); }
      });
      btns.appendChild(next);

      bodyDiv.appendChild(btns);
      card.appendChild(bodyDiv);
    };

    function finish() {
      localStorage.setItem('kt_onboarded', '1');
      overlay.style.animation = 'kt-fade-in 0.25s ease-in reverse';
      setTimeout(() => overlay.remove(), 250);
    }

    overlay.appendChild(card);
    document.body.appendChild(overlay);
    render();
  }

  function checkAndShow() {
    if (localStorage.getItem('kt_onboarded')) return;
    const user = JSON.parse(sessionStorage.getItem('kt_user') || 'null');
    if (!user) return;  // not logged in
    setTimeout(showTour, 800);  // small delay after page ready
  }

  // Hook into login flow + page load
  document.addEventListener('DOMContentLoaded', checkAndShow);
  window.addEventListener('kt:login', checkAndShow);

  window.KT = window.KT || {};
  window.KT.showTour = showTour;
  window.KT.resetTour = () => { localStorage.removeItem('kt_onboarded'); showTour(); };
})(window);
