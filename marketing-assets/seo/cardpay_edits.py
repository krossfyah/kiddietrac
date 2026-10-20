# -*- coding: utf-8 -*-
"""Coming soon: card payments from the parent Wallet (2026-10-20).

Anthony: "add to marketing site coming soon being able to take card payments (visa, mc,
interac) through your wallet and PCI compliant with data handling through our payment
partner (make this sound better) add an image to make this exciting."

One homepage section, after "New in KiddieTrac": the pitch, three short points (certified
security, no card numbers on file, paid = reconciled), the card brands, and a drawn phone
wallet (inline SVG, no card-company logos -- names only). The security wording is what the
platform does: the wallet keeps the partner's token, brand, last four and expiry, never a
full card number. The partner is not named here, as asked. The SVG is skipped by the page
translator, so only the section text needs fr/es/hi (tr_cardpay.py).

usage: python cardpay_edits.py in.html out.html
"""
import io
import os
import sys

ANCHOR = '<!-- ═══ INTEGRATIONS ═══ -->'

SECTION = """<!-- ═══ COMING SOON: CARD PAYMENTS FROM THE WALLET (2026-10-20) ═══ -->
<section class="k6-sec cpw-sec" id="wallet-payments">
  <style>
    .cpw-sec{background:radial-gradient(900px 420px at 85% 20%,rgba(19,183,204,.16),transparent 60%),radial-gradient(700px 380px at 10% 90%,rgba(233,30,140,.14),transparent 60%),linear-gradient(135deg,#071a33 0%,#0e2a4f 55%,#2a0f4f 100%);color:#fff;overflow:hidden}
    .cpw-g{display:grid;grid-template-columns:1.05fr .95fr;gap:48px;align-items:center}
    @media (max-width:880px){.cpw-g{grid-template-columns:1fr;gap:28px}.cpw-art{order:-1;max-width:440px;margin:0 auto}}
    .cpw-pill{display:inline-flex;align-items:center;gap:8px;background:rgba(251,191,36,.14);border:1px solid rgba(251,191,36,.45);color:#fde68a;padding:7px 15px;border-radius:999px;font:800 12px 'Nunito',sans-serif;letter-spacing:.18em;text-transform:uppercase}
    .cpw-pill i{width:8px;height:8px;border-radius:50%;background:#fbbf24;box-shadow:0 0 12px #fbbf24}
    .cpw-sec .k6-h2{color:#fff}
    .cpw-lead{font-size:18px;line-height:1.6;color:rgba(255,255,255,.86);margin:0 0 18px;max-width:560px}
    .cpw-brands{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 22px}
    .cpw-brands span{background:#fff;color:#0b1f3a;border-radius:10px;padding:7px 13px;font:900 14px 'Nunito',sans-serif}
    .cpw-pts{list-style:none;margin:0 0 26px;padding:0;display:grid;gap:12px;max-width:580px}
    .cpw-pts li{display:flex;gap:13px;align-items:flex-start;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:14px 16px;font-size:15.5px;line-height:1.5;color:rgba(255,255,255,.86)}
    .cpw-pts li b{color:#fff}
    .cpw-pts .cpw-i{font-size:22px;line-height:1;flex:0 0 auto;margin-top:1px}
    .cpw-tm{font-size:12px;color:rgba(255,255,255,.5);margin:16px 0 0}
  </style>
  <div class="k6-wrap">
    <div class="cpw-g">
      <div class="k6-rv">
        <span class="cpw-pill"><i></i>Coming soon</span>
        <h2 class="k6-h2">Tap, pay, done — <span class="k6-grad-t">right from the Wallet</span></h2>
        <p class="cpw-lead">Families will be able to pay invoices by Visa, Mastercard or Interac Debit, straight from the Wallet in the KiddieTrac parent app. Save a card once, pay in seconds, and watch the invoice settle on its own.</p>
        <div class="cpw-brands" translate="no"><span>VISA</span><span>Mastercard</span><span>Interac Debit</span></div>
        <ul class="cpw-pts">
          <li><span class="cpw-i">🔒</span><span><b>Security that's certified, not just promised.</b> Payments run through our PCI DSS–compliant payment partner, so card details are encrypted and handled on their certified systems.</span></li>
          <li><span class="cpw-i">🛡️</span><span><b>No card numbers on file.</b> KiddieTrac keeps only a secure token and the last four digits, never a full card number.</span></li>
          <li><span class="cpw-i">⚡</span><span><b>Paid means reconciled.</b> Every Wallet payment is matched to its invoice automatically, so there is nothing to chase and nothing to key in.</span></li>
        </ul>
        <button class="k6-btn k6-btn-p" onclick="showPage('contact')">Book a demo →</button>
        <p class="cpw-tm">Visa, Mastercard and Interac are trademarks of their respective owners.</p>
      </div>
      <div class="cpw-art k6-rv k6-d1">
__SVG__
      </div>
    </div>
  </div>
</section>

"""


def main(src, dst):
    s = io.open(src, encoding='utf-8', newline='').read()
    if 'id="wallet-payments"' in s:
        sys.exit('cardpay_edits already applied')
    if s.count(ANCHOR) != 1:
        sys.exit('anchor found %d times' % s.count(ANCHOR))
    svg = io.open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'wallet.svg'), encoding='utf-8').read().strip()
    s = s.replace(ANCHOR, SECTION.replace('__SVG__', svg) + ANCHOR)
    io.open(dst, 'w', encoding='utf-8', newline='').write(s)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
