<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $agency->name }} — Book a tour</title>
<meta name="description" content="Book a tour or learn more about {{ $agency->name }}, a licensed childcare provider.">
@php $brand = $agency->brand_primary_color ?? '#1F6080'; @endphp
<style>
  :root { --brand: {{ $brand }}; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; color:#1F2937; background:#F9FAFB; line-height:1.55; }
  .hero { background: linear-gradient(135deg, var(--brand), color-mix(in oklab, var(--brand) 70%, black)); color:#fff; padding: 80px 24px; text-align:center; }
  .hero img.logo { max-width: 180px; max-height: 80px; margin-bottom: 22px; }
  .hero h1 { font-size: 38px; margin: 0 0 12px; font-weight: 700; letter-spacing:-0.02em; }
  .hero p { font-size: 17px; max-width: 640px; margin: 0 auto; opacity: 0.92; }
  .container { max-width: 980px; margin: 0 auto; padding: 0 24px; }
  section { padding: 48px 0; }
  h2 { font-size: 26px; margin-bottom: 22px; color: var(--brand); }
  .centres { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; }
  .centre { background: #fff; padding: 22px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
  .centre h3 { margin: 0 0 8px; font-size: 18px; }
  .centre p { margin: 4px 0; color: #4B5563; font-size: 14px; }
  .book-section { background: #fff; padding: 48px 24px; }
  form { max-width: 520px; margin: 0 auto; background: #F9FAFB; padding: 28px; border-radius: 12px; }
  label { display: block; font-size: 13px; font-weight: 600; margin-top: 14px; color: #374151; }
  input, select, textarea { width: 100%; padding: 11px 13px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 15px; margin-top: 4px; font-family: inherit; background: #fff; }
  button { background: var(--brand); color: #fff; border: 0; padding: 13px 24px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 22px; width: 100%; }
  button:hover { filter: brightness(0.92); }
  .msg { padding: 12px; border-radius: 6px; margin-top: 16px; font-size: 14px; }
  .msg.ok { background: #DCFCE7; color: #166534; }
  .msg.err { background: #FEE2E2; color: #991B1B; }
  footer { padding: 40px 24px; color: #9CA3AF; text-align: center; font-size: 13px; }
</style>
</head>
<body>
  <header class="hero">
    @if (!empty($agency->brand_logo_url))
      <img class="logo" src="{{ $agency->brand_logo_url }}" alt="{{ $agency->name }}">
    @endif
    <h1>{{ $agency->name }}</h1>
    <p>Licensed childcare. Book a tour and see what makes us different.</p>
  </header>

  <section class="container">
    <h2>Our centres</h2>
    <div class="centres">
      @forelse ($centres as $c)
        <div class="centre">
          <h3>{{ $c->name }}</h3>
          @if ($c->address) <p>📍 {{ $c->address }}@if($c->city), {{ $c->city }}@endif</p> @endif
          @if ($c->phone) <p>📞 {{ $c->phone }}</p> @endif
        </div>
      @empty
        <p>No centre listings yet — please contact us directly.</p>
      @endforelse
    </div>
  </section>

  <section class="book-section">
    <div class="container">
      <h2 style="text-align:center;">Book a tour</h2>
      <form id="tour-form">
        <label>Centre <select name="centre_id" required>
          @foreach ($centres as $c)
            <option value="{{ $c->id }}">{{ $c->name }}</option>
          @endforeach
        </select></label>
        <label>Parent name <input name="parent_name" required></label>
        <label>Email <input type="email" name="parent_email" required></label>
        <label>Phone <input name="parent_phone"></label>
        <label>Child name <input name="child_name"></label>
        <label>Child age <input name="child_age"></label>
        <label>Preferred date/time <input type="datetime-local" name="tour_at" required></label>
        <label>Notes <textarea name="notes" rows="3"></textarea></label>
        <button type="submit">Request a tour</button>
        <div id="form-msg"></div>
      </form>
    </div>
  </section>

  <footer>
    <p>© {{ date('Y') }} {{ $agency->name }}. Powered by KiddieTrac.</p>
  </footer>

<script>
document.getElementById('tour-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('button');
  btn.disabled = true; btn.textContent = 'Sending…';
  const msg = document.getElementById('form-msg');
  msg.className = ''; msg.textContent = '';
  const fd = new FormData(e.target);
  const payload = Object.fromEntries(fd.entries());
  payload.agency_slug = {!! json_encode($agency->slug) !!};
  try {
    const r = await fetch('/api/v1/public/tours', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
    });
    const j = await r.json();
    if (r.ok) {
      msg.className = 'msg ok';
      msg.textContent = 'Thanks — we will contact you shortly to confirm.';
      e.target.reset();
    } else {
      msg.className = 'msg err';
      msg.textContent = j.message || 'Sorry, please try again.';
    }
  } catch (err) {
    msg.className = 'msg err';
    msg.textContent = 'Network error — please try again.';
  } finally {
    btn.disabled = false; btn.textContent = 'Request a tour';
  }
});
</script>
</body></html>
