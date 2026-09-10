/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — one way to draw a stored secret.

   Every credential in this portal is WRITE-ONLY: the API reports whether one is
   stored and never what it is, so a saved password or API key can never be shown
   back. That is right, and it creates a problem this file exists to solve — the
   field looks exactly the same whether a credential is saved or the box has never
   been filled in.

   Eight such fields had grown six different ways of saying so:

     "•••••••• (saved — leave blank to keep)"      QuickBooks, Social login
     "Saved — leave blank to keep it"              Twilio ×2, Telnyx
     label + " (leave blank to keep)" + "••••••••" Email settings ×2
     "•••••••• (unchanged)" + a hint line          Platform mail
     a green "(saved · leave blank to keep)"       Platform agency SMTP

   FOUR OF THEM SAID IT ONLY IN THE PLACEHOLDER, which disappears the moment the
   field is focused — so the one moment an admin is deciding "is something already
   in here?" is the moment the answer vanishes. Reported as "the API key section
   should show that the key was stored" (Anthony, 2026-09-10).

   So: a pill beside the label that does not move, one wording, everywhere.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var KT = (w.KT = w.KT || {});

  function esc(v) {
    return String(v === null || v === undefined ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /**
   * The pill. Green and definite when something is stored, quiet and grey when not —
   * "Not set" rather than red, because an unconfigured integration is a normal state
   * and not an error to be alarmed about.
   */
  KT.secretPill = function (stored) {
    var c = stored ? ['#ECFDF5', '#A7F3D0', '#065F46'] : ['#F1F5F9', '#E2E8F0', '#64748B'];
    return '<span data-kt-secret-pill="' + (stored ? '1' : '0') + '" style="display:inline-flex;'
      + 'align-items:center;gap:5px;padding:2px 9px;border-radius:999px;font-size:11px;'
      + 'font-weight:800;letter-spacing:.02em;background:' + c[0] + ';color:' + c[2] + ';'
      + 'border:1px solid ' + c[1] + ';vertical-align:middle;">'
      + (stored ? '✓ Stored' : 'Not set') + '</span>';
  };

  /** The sentence under the box. One wording for the whole portal. */
  KT.secretHint = function (stored, whatItIs) {
    var thing = whatItIs || 'value';
    return stored
      ? 'A ' + esc(thing) + ' is saved and encrypted. It is never shown again — leave this blank '
        + 'to keep it, and type here only to replace it.'
      : 'Stored encrypted once saved. It is never sent back to this screen.';
  };

  /**
   * A whole field: label, pill, input, hint.
   *
   * opts:
   *   id           input id                              (required)
   *   label        visible label                         (required)
   *   stored       is a value already saved?
   *   whatItIs     noun for the hint — "API key", "password"
   *   placeholder  overrides the default
   *   hint         overrides the default sentence
   *   inputStyle   inline style for the <input>
   *   labelStyle   inline style for the <label>
   *   extra        extra attributes on the <input>
   */
  KT.secretField = function (opts) {
    var o = opts || {};
    var stored = !!o.stored;
    var labelStyle = o.labelStyle
      || 'display:block;font-size:11.5px;font-weight:800;color:#64748B;text-transform:uppercase;'
       + 'letter-spacing:.4px;margin-bottom:5px;';
    var inputStyle = o.inputStyle
      || 'width:100%;box-sizing:border-box;height:34px;padding:0 11px;border:1px solid #E2E8F0;'
       + 'border-radius:9px;font:inherit;font-size:14px;';

    var placeholder = o.placeholder
      || (stored ? 'Leave blank to keep the stored ' + esc(o.whatItIs || 'value') : 'Paste it here');

    return '<div data-kt-secret="' + esc(o.id) + '">'
      + '<label for="' + esc(o.id) + '" style="' + labelStyle + '">'
      +   esc(o.label) + ' <span style="margin-left:4px;">' + KT.secretPill(stored) + '</span>'
      + '</label>'
      + '<input id="' + esc(o.id) + '" type="password" autocomplete="new-password" '
      +   'placeholder="' + esc(placeholder) + '" style="' + inputStyle + '" ' + (o.extra || '') + '>'
      + '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">'
      +   (o.hint || KT.secretHint(stored, o.whatItIs))
      + '</div>'
      + '</div>';
  };
})(window);
