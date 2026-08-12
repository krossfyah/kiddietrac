/* ============================================================
   KIDDIETRAC v22p12 — inline SVG illustration library
   Self-contained — no external dependencies, no network calls.
   Each function returns an SVG string so it can be dropped into
   innerHTML or used as the kids of a hero element.
   ============================================================ */
(function (window) {
  'use strict';

  // Childcare-themed accents — building blocks, balloon, clouds + stars,
  // a friendly bear, a family silhouette. Stroke + fill use brand tokens
  // (KT blue #1F6080, accent green #8EC73C, warm coral #FF8A65).

  function blocks() {
    return `<svg viewBox="0 0 200 160" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <defs>
        <linearGradient id="kt-blkA" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#FF8A65"/><stop offset="100%" stop-color="#FFC07A"/>
        </linearGradient>
        <linearGradient id="kt-blkB" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#8EC73C"/><stop offset="100%" stop-color="#6BAA2B"/>
        </linearGradient>
        <linearGradient id="kt-blkC" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#2C8AAC"/><stop offset="100%" stop-color="#1F6080"/>
        </linearGradient>
      </defs>
      <rect x="30"  y="90" width="50" height="50" rx="8" fill="url(#kt-blkA)" transform="rotate(-6 55 115)"/>
      <rect x="86"  y="78" width="50" height="50" rx="8" fill="url(#kt-blkB)" transform="rotate(4 111 103)"/>
      <rect x="60"  y="32" width="50" height="50" rx="8" fill="url(#kt-blkC)" transform="rotate(-3 85 57)"/>
      <text x="55" y="124" font-family="system-ui,sans-serif" font-weight="900" font-size="28" fill="white" text-anchor="middle" transform="rotate(-6 55 115)">A</text>
      <text x="111" y="113" font-family="system-ui,sans-serif" font-weight="900" font-size="28" fill="white" text-anchor="middle" transform="rotate(4 111 103)">B</text>
      <text x="85" y="68" font-family="system-ui,sans-serif" font-weight="900" font-size="28" fill="white" text-anchor="middle" transform="rotate(-3 85 57)">C</text>
      <circle cx="158" cy="40" r="6" fill="#FFC07A"/>
      <circle cx="174" cy="58" r="4" fill="#8EC73C"/>
      <circle cx="20"  cy="50" r="5" fill="#2C8AAC"/>
    </svg>`;
  }

  function cloudsAndStars() {
    return `<svg viewBox="0 0 240 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <!-- Cloud 1 -->
      <g opacity="0.9">
        <ellipse cx="60" cy="60" rx="40" ry="22" fill="white"/>
        <ellipse cx="90" cy="55" rx="32" ry="20" fill="white"/>
        <ellipse cx="40" cy="58" rx="22" ry="14" fill="white"/>
      </g>
      <!-- Cloud 2 -->
      <g opacity="0.75">
        <ellipse cx="170" cy="125" rx="50" ry="28" fill="white"/>
        <ellipse cx="200" cy="118" rx="32" ry="22" fill="white"/>
        <ellipse cx="145" cy="122" rx="22" ry="16" fill="white"/>
      </g>
      <!-- Stars -->
      <path d="M 30 130 l 4 12 l 12 0 l -10 8 l 4 12 l -10 -8 l -10 8 l 4 -12 l -10 -8 l 12 0 z" fill="#FFC07A" opacity="0.95"/>
      <path d="M 195 30 l 3 8 l 8 0 l -7 5 l 3 8 l -7 -5 l -7 5 l 3 -8 l -7 -5 l 8 0 z" fill="#FFC07A" opacity="0.85"/>
      <circle cx="115" cy="170" r="3" fill="white" opacity="0.9"/>
      <circle cx="55"  cy="170" r="2" fill="white" opacity="0.8"/>
      <circle cx="220" cy="60" r="3" fill="white" opacity="0.9"/>
    </svg>`;
  }

  function bear() {
    return `<svg viewBox="0 0 200 180" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <!-- Ears -->
      <circle cx="62" cy="48" r="20" fill="#A07555"/>
      <circle cx="138" cy="48" r="20" fill="#A07555"/>
      <circle cx="62" cy="48" r="10" fill="#C99A77"/>
      <circle cx="138" cy="48" r="10" fill="#C99A77"/>
      <!-- Head -->
      <circle cx="100" cy="95" r="60" fill="#B98B68"/>
      <!-- Snout -->
      <ellipse cx="100" cy="115" rx="32" ry="24" fill="#E7D2B8"/>
      <!-- Eyes -->
      <circle cx="78" cy="82" r="6" fill="#2C1810"/>
      <circle cx="122" cy="82" r="6" fill="#2C1810"/>
      <circle cx="80" cy="80" r="2" fill="white"/>
      <circle cx="124" cy="80" r="2" fill="white"/>
      <!-- Nose -->
      <ellipse cx="100" cy="106" rx="8" ry="6" fill="#2C1810"/>
      <!-- Mouth -->
      <path d="M 100 116 Q 88 128 80 124" stroke="#2C1810" stroke-width="2.5" fill="none" stroke-linecap="round"/>
      <path d="M 100 116 Q 112 128 120 124" stroke="#2C1810" stroke-width="2.5" fill="none" stroke-linecap="round"/>
      <!-- Cheeks -->
      <circle cx="68" cy="108" r="6" fill="#FF8A9A" opacity="0.6"/>
      <circle cx="132" cy="108" r="6" fill="#FF8A9A" opacity="0.6"/>
    </svg>`;
  }

  function familyGroup() {
    return `<svg viewBox="0 0 240 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <!-- Adult 1 (parent) -->
      <circle cx="70" cy="60" r="22" fill="#FFC07A"/>
      <rect x="46" y="84" width="48" height="70" rx="20" fill="#2C8AAC"/>
      <!-- Adult 2 (parent) -->
      <circle cx="170" cy="60" r="22" fill="#FFC07A"/>
      <rect x="146" y="84" width="48" height="70" rx="20" fill="#8EC73C"/>
      <!-- Child -->
      <circle cx="120" cy="100" r="16" fill="#FFC07A"/>
      <rect x="104" y="116" width="32" height="48" rx="14" fill="#FF8A65"/>
      <!-- Heart between -->
      <path d="M 120 50 c -2 -8 -14 -8 -14 0 c 0 6 6 10 14 16 c 8 -6 14 -10 14 -16 c 0 -8 -12 -8 -14 0 z" fill="#FF6B7A"/>
    </svg>`;
  }

  function emptyChildren() {
    return `<svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <!-- Tree -->
      <ellipse cx="100" cy="100" rx="55" ry="60" fill="#8EC73C" opacity="0.5"/>
      <ellipse cx="100" cy="90" rx="48" ry="50" fill="#8EC73C" opacity="0.7"/>
      <rect x="92" y="140" width="16" height="40" rx="2" fill="#A07555"/>
      <!-- Apples -->
      <circle cx="78" cy="78" r="6" fill="#DC2626"/>
      <circle cx="125" cy="92" r="6" fill="#DC2626"/>
      <circle cx="100" cy="110" r="6" fill="#DC2626"/>
      <!-- Birds -->
      <path d="M 30 50 q 5 -6 10 0 q 5 -6 10 0" stroke="#2C8AAC" stroke-width="3" fill="none" stroke-linecap="round"/>
      <path d="M 160 60 q 5 -6 10 0 q 5 -6 10 0" stroke="#2C8AAC" stroke-width="3" fill="none" stroke-linecap="round"/>
    </svg>`;
  }

  function emptyFamilies() {
    return `<svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <!-- House -->
      <polygon points="100,40 40,90 40,160 160,160 160,90" fill="#FFC07A"/>
      <polygon points="100,40 40,90 160,90" fill="#FF8A65"/>
      <rect x="80" y="115" width="40" height="45" fill="#A07555"/>
      <rect x="55" y="110" width="20" height="22" fill="#2C8AAC"/>
      <rect x="125" y="110" width="20" height="22" fill="#2C8AAC"/>
      <!-- Heart on door -->
      <path d="M 100 132 c -1.5 -5 -10 -5 -10 0 c 0 4 4 7 10 11 c 6 -4 10 -7 10 -11 c 0 -5 -8.5 -5 -10 0 z" fill="#FF6B7A"/>
      <!-- Sun -->
      <circle cx="160" cy="40" r="14" fill="#FFC07A"/>
      <g stroke="#FFC07A" stroke-width="2.5" stroke-linecap="round">
        <line x1="160" y1="18" x2="160" y2="10"/>
        <line x1="180" y1="40" x2="188" y2="40"/>
        <line x1="174" y1="26" x2="180" y2="20"/>
        <line x1="174" y1="54" x2="180" y2="60"/>
      </g>
    </svg>`;
  }

  function balloon() {
    return `<svg viewBox="0 0 120 160" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <ellipse cx="60" cy="55" rx="40" ry="48" fill="#FF6B7A"/>
      <ellipse cx="48" cy="38" rx="8" ry="14" fill="white" opacity="0.4"/>
      <polygon points="55,100 65,100 60,108" fill="#FF6B7A"/>
      <path d="M 60 108 Q 65 130 55 150" stroke="#A07555" stroke-width="1.5" fill="none"/>
    </svg>`;
  }

  // ── Expose ──────────────────────────────────────────────────
  window.KT = window.KT || {};
  window.KT.Illustrations = {
    blocks: blocks,
    cloudsAndStars: cloudsAndStars,
    bear: bear,
    familyGroup: familyGroup,
    emptyChildren: emptyChildren,
    emptyFamilies: emptyFamilies,
    balloon: balloon,
  };

  // Helper: pick the greeting for current time of day.
  window.KT.greetingForNow = function (firstName) {
    var h = new Date().getHours();
    var greet = h < 5 ? 'Good night' :
                h < 12 ? 'Good morning' :
                h < 17 ? 'Good afternoon' :
                h < 22 ? 'Good evening' :
                'Good night';
    return firstName ? greet + ', ' + firstName : greet;
  };
})(window);
