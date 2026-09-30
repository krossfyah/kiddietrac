"""GA4 key events for the marketing site's forms (2026-09-30).

GA4 (G-0R514STGE4, set in the portal's Website -> Analytics) counted visits but not leads.
Every form on the site saves through one of five /marketing-site endpoints, so one fetch
wrapper reports each SUCCESSFUL save as a GA4 recommended event, instead of hooking every
form's own code:

    booking   -> generate_lead  form_type=demo_booking
    contact   -> generate_lead  form_type=contact
    support   -> generate_lead  form_type=support_ticket
    lead      -> generate_lead  form_type=lead, lead_source=<checklist|webinar-waitlist|...>
    subscribe -> sign_up        method=newsletter

It sends nothing unless GA is loaded, which only happens after the visitor accepts
analytics cookies, and it sends no personal data (no names, emails or phone numbers).
"""

SCRIPT = r"""<script>/* GA4 key events (analytics_edits.py): a successful form save becomes an event. */
(function () {
  var f = window.fetch;
  if (!f || window.__ktGaEvents) { return; }
  window.__ktGaEvents = true;
  var MAP = [
    [/\/marketing-site\/booking$/, 'generate_lead', { form_type: 'demo_booking' }],
    [/\/marketing-site\/contact$/, 'generate_lead', { form_type: 'contact' }],
    [/\/marketing-site\/support$/, 'generate_lead', { form_type: 'support_ticket' }],
    [/\/marketing-site\/lead$/, 'generate_lead', { form_type: 'lead' }],
    [/\/marketing-site\/subscribe$/, 'sign_up', { method: 'newsletter' }]
  ];
  window.fetch = function (input, init) {
    var p = f.apply(this, arguments);
    try {
      var url = String((input && input.url) || input || '').split('?')[0];
      var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
      if (method !== 'POST') { return p; }
      for (var i = 0; i < MAP.length; i++) {
        if (!MAP[i][0].test(url)) { continue; }
        var ev = MAP[i];
        var src = '';
        try { src = JSON.parse((init && init.body) || '{}').source || ''; } catch (e) {}
        p.then(function (r) {
          if (!r || !r.ok || typeof window.gtag !== 'function') { return; }
          var params = {};
          for (var k in ev[2]) { params[k] = ev[2][k]; }
          if (src) { params.lead_source = String(src).slice(0, 60); }
          window.gtag('event', ev[1], params);
        }).catch(function () {});
        break;
      }
    } catch (e) {}
    return p;
  };
})();
</script>
"""


def analytics(out):
    assert '__ktGaEvents' not in out
    i = out.rfind('</body>')
    assert i > 0
    return out[:i] + SCRIPT + out[i:]
