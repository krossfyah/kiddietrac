# KiddieTrac — Changelog

Versions are tracked from v2.1 onward (portal build marker: `window.KT_VERSION`,
shown in Settings → About and the login footer). The Android APK base is v2.0
(versionCode 11); portal JS/CSS is loaded live from the server, so these changes
reach app users without an APK rebuild.

---

## v2.1 — Production Hardening (2026-07-24)

A reliability, accessibility, and polish release. ~13 shipped updates; service-worker
markers kt-v27 → kt-v39.

### Ship-blockers resolved
- **Mobile navigation for admins/directors/platform** — restored a Menu (☰) drawer on the
  phone bottom bar; Children/Billing/Settings/Centres/Staff/Reports were previously
  unreachable on a phone. (`kt-mobilenav.js`)
- **Invisible / mis-colored elements** — defined ~350 referenced-but-undefined design
  tokens (`--ink-*`, `--brand-*`, `--kt-primary`); elements that rendered
  white-on-transparent or in the wrong color now resolve correctly. (`kt-tokens.css`)
- **601–768px tablet dead-zone** — removed overlapping top+bottom bars; the range now uses
  the desktop layout. (`kt-mobilenav.js` breakpoints 768→600)

### Reliability
- Fixed a startup crash: `role.replace` on a null role in `startApp` is now guarded.
- Fixed Expenses → Bills sort crash (undefined variable ReferenceError).
- Fixed toast back-compat: 2-arg callers no longer render the message as a giant emoji.

### Accessibility (WCAG)
- Secondary-text contrast → AA across 385 sites / 70 files (`#9CA3AF`/`#94A3B8` → `#64748B`;
  token `--ink-400` text → `--ink-500`). Verified against real backgrounds.
- Accessible dialogs: Escape-to-close, focus-trap, `role="dialog"`/`aria-modal`, focus
  restore — on the shared `Shell.Modal`.
- `<html lang>` reflects the stored locale; `viewport-fit=cover` activates safe-area insets.

### Polish & consistency
- Replaced all 47 native `confirm()` popups with the styled `KT.confirm()` dialog.
- Biometric enable-prompt gated to mobile/tablet/APK only (was wrongly firing on desktop
  via Windows Hello / Touch ID).
- Button interaction-consistency layer (uniform disabled/press/cursor). (`kt-buttons.css`)
- Certifications show humanized labels ("First Aid" not "First_Aid").
- Incident empty-states use proper emoji icons (not literal `!` / `-`).
- Role-pill labels corrected (agency_admin no longer shown as "Platform admin";
  home_visitor label added).

### Housekeeping
- Removed dead files (`dashboard.js`, `screen-help.js`, `mobile-v11.css`).
- Added the `KT_VERSION` marker + Settings → About + login-footer version display.

_Base: v2.0 (Android versionCode 11)._
