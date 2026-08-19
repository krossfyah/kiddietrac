/* The cleanup applied to every captured page.
 *
 * Screenshots in customer help must look like the customer's own portal. Captured as a
 * platform admin they carry furniture no customer has — the PLATFORM and SALES nav
 * groups, a SUPER ADMIN badge, the "View as" pill — and a reader who cannot find those
 * menus concludes the guide is for a different product.
 *
 * Done by walking the DOM rather than by CSS selectors: the class names differ between
 * builds, but "the link whose href is #sales-leads" and "the element whose entire text
 * is SUPER ADMIN" are stable facts about the page.
 */
module.exports = function cleanupInPage() {
  const HIDE_HREFS = [
    '#platform-overview', '#platform-agencies', '#agencies',
    '#sales', '#sales-leads', '#sales-new', '#sales-followups',
    '#sales-plans', '#sales-demo', '#admin-mrr',
  ];
  // Compared on LETTERS ONLY. A nav heading renders as "🌐 PLATFORM ▾", so an exact
  // string match never fires — which is why two passes of this still showed them.
  const LABELS = ['PLATFORM', 'SALES', 'SUPERADMIN', 'PLAT'];
  const letters = (t) => (t || '').replace(/[^A-Za-z]/g, '').toUpperCase();

  const hide = (el) => { if (el && el.style) { el.style.display = 'none'; } };

  // Platform-only nav links, and the group headings above them.
  document.querySelectorAll('.app-sidebar a[href], .sidebar-nav a[href]').forEach((a) => {
    if (HIDE_HREFS.indexOf(a.getAttribute('href')) !== -1) {
      hide(a.closest('li') || a);
    }
  });

  // Elements whose ENTIRE text is a platform-only label. Not leaf-only: a nav group
  // heading is an icon span plus a caret plus the word, so a leaf test misses it. The
  // guard is "contains no link", which keeps this from ever swallowing a whole group
  // that still has navigation in it.
  document.querySelectorAll('.app-sidebar *, .kt-topbar *, header *').forEach((el) => {
    if (el.querySelector('a')) return;
    if (LABELS.indexOf(letters(el.textContent)) === -1) return;
    hide(el);
  });

  // The "View as" pill, the diagnostics chip, and the phone bottom bar — none of which
  // belong in a desktop guide.
  document.querySelectorAll('[id*="viewas" i],[class*="viewas" i],[class*="view-as" i],' +
    '#kt-diag-chip,.kt-diag-chip,[class*="mobilenav" i],[id*="mobilenav" i],' +
    '[class*="mobile-nav" i]').forEach(hide);
  // The "View as" pill is a fixed-position control bottom-left. Matched by what it is —
  // pinned, and its text begins "View as" — rather than by a class name that changes.
  // Only an element that is ITSELF pinned and whose OWN text begins "View as". The
  // previous version climbed to the nearest pinned ancestor, which is the app shell —
  // it hid the entire page and produced 19KB of blank white.
  Array.prototype.forEach.call(document.querySelectorAll('body > *, body > * > *'), (el) => {
    if (el.children.length > 3) return;
    if (!/^View as/i.test((el.textContent || '').trim())) return;
    const cs = getComputedStyle(el);
    if (cs.position === 'fixed') { hide(el); }
  });

  // Welcome toasts pop in a second after load and are not part of any screen.
  document.querySelectorAll('[class*="toast" i],[id*="toast" i],[class*="snackbar" i]').forEach(hide);

  // The greeting names the person who took the screenshot. Make it impersonal.
  document.querySelectorAll('.kt-hero-greet, [class*="greet" i]').forEach((el) => {
    if (/Good (morning|afternoon|evening),/.test(el.textContent || '')) {
      el.textContent = (el.textContent.match(/Good (morning|afternoon|evening)/) || ['Welcome'])[0];
    }
  });

  // Scrollbars differ per machine and add nothing.
  const s = document.createElement('style');
  s.textContent = '*{scrollbar-width:none!important}::-webkit-scrollbar{display:none!important}';
  document.head.appendChild(s);
};
