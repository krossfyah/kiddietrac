def rep(fn, old, new, label):
    raw = open(fn, "r", encoding="utf-8", newline="").read()
    nl = "\r\n" if "\r\n" in raw else "\n"
    o = old.replace("\n", nl); n = new.replace("\n", nl)
    if raw.count(o) != 1:
        raise SystemExit("!! %s count=%d" % (label, raw.count(o)))
    open(fn, "w", encoding="utf-8", newline="").write(raw.replace(o, n))
    print("ok", label)

F = "kt-clockbar.js"

# 1. Make the strip a proper card (icon + text + action chip layout).
rep(F,
r'''    el.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:8px;width:100%;'
      + 'border:none;border-radius:12px;padding:9px 12px;margin:0 0 10px;'
      + 'font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;box-sizing:border-box;';''',
r'''    el.style.cssText = 'display:flex;align-items:center;gap:12px;width:100%;text-align:left;'
      + 'border:none;border-radius:16px;padding:13px 14px;margin:0 0 12px;'
      + 'cursor:pointer;font-family:inherit;box-sizing:border-box;box-shadow:0 8px 20px -10px rgba(15,23,42,.45);';''',
    "clock card shell")

# 2. Redesign the clocked-in / clocked-out contents.
rep(F,
r'''    if (!openPunch) {
      el.innerHTML = '<span>⏱</span><span>Clocked out</span>'
        + '<span style="opacity:.85;font-weight:700;">· tap to clock in</span>';
      el.style.background = '#F59E0B';
      el.style.color = '#fff';
      el.style.border = 'none';
      return;
    }

    var e = elapsed(openPunch.punched_in_at);
    var overdue = e.hours >= LONG_SHIFT_HOURS;
    el.innerHTML = '<span>' + (overdue ? '⚠️' : '⏱') + '</span>'
      + '<span>Clocked in · ' + e.text + '</span>'
      + '<span style="opacity:.85;font-weight:700;">· tap to clock out</span>';
    // "you have been on for 10 hours" doesn't read as business as usual.
    el.style.background = overdue ? '#B45309' : '#16A34A';
    el.style.color = '#fff';
    el.style.border = 'none';''',
r'''    var ICON = 'flex:0 0 auto;width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:22px;';
    var MID = 'flex:1;min-width:0;display:flex;flex-direction:column;line-height:1.25;';
    var CHIP = 'flex:0 0 auto;background:#fff;font-size:13px;font-weight:800;padding:9px 15px;border-radius:11px;white-space:nowrap;';
    if (!openPunch) {
      el.innerHTML =
        '<span style="' + ICON + '">⏱</span>'
        + '<span style="' + MID + '">'
        +   '<span style="font-size:15px;font-weight:800;">You\'re not clocked in</span>'
        +   '<span style="font-size:12px;font-weight:600;opacity:.9;">Tap to start your shift</span>'
        + '</span>'
        + '<span style="' + CHIP + 'color:#B45309;">Clock in</span>';
      el.style.background = '#F59E0B';
      el.style.color = '#fff';
      el.style.border = 'none';
      return;
    }

    var e = elapsed(openPunch.punched_in_at);
    var overdue = e.hours >= LONG_SHIFT_HOURS;
    var inTime = '';
    try { inTime = parseTs(openPunch.punched_in_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (x) {}
    el.innerHTML =
      '<span style="' + ICON + '">' + (overdue ? '⚠️' : '⏱') + '</span>'
      + '<span style="' + MID + '">'
      +   '<span style="font-size:15px;font-weight:800;">Clocked in · ' + e.text + '</span>'
      +   '<span style="font-size:12px;font-weight:600;opacity:.9;">' + (overdue ? 'Long shift — please clock out' : (inTime ? 'Since ' + inTime : 'On the clock')) + '</span>'
      + '</span>'
      + '<span style="' + CHIP + 'color:' + (overdue ? '#B45309' : '#15803D') + ';">Clock out</span>';
    el.style.background = overdue ? '#B45309' : '#16A34A';
    el.style.color = '#fff';
    el.style.border = 'none';''',
    "clock card contents")
print("CLOCK REDESIGN OK")
