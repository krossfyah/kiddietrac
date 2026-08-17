def rep(fn, old, new, label):
    raw = open(fn, "r", encoding="utf-8", newline="").read()
    nl = "\r\n" if "\r\n" in raw else "\n"
    o = old.replace("\n", nl); n = new.replace("\n", nl)
    if raw.count(o) != 1:
        raise SystemExit("!! %s count=%d" % (label, raw.count(o)))
    open(fn, "w", encoding="utf-8", newline="").write(raw.replace(o, n))
    print("ok", label)

F = "screen-v22p58.js"

rep(F,
'        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Total refunded</div><div class="kt-kpi-value">${fmtMoney(r.total_refunded)}</div></div>\n      </div>',
'        <div class="kt-kpi kt-kpi-danger"><div class="kt-kpi-label">Total refunded</div><div class="kt-kpi-value">${fmtMoney(r.total_refunded)}</div></div>\n'
'        <div class="kt-kpi ${r.days_overdue > 0 ? \'kt-kpi-danger\' : \'kt-kpi-success\'}"><div class="kt-kpi-label">Payment status</div><div class="kt-kpi-value">${r.days_overdue > 0 ? r.days_overdue + (r.days_overdue === 1 ? \' day late\' : \' days late\') : \'On time\'}</div></div>\n      </div>',
    "days-overdue KPI")

rep(F,
"            <td>${esc(row.description)}</td>",
"            <td>${esc(row.description)}${row.days_late > 0 ? ' <span style=\"display:inline-block;margin-left:6px;padding:1px 7px;border-radius:10px;background:#FEE2E2;color:#B91C1C;font-size:11px;font-weight:700;white-space:nowrap;\">' + row.days_late + ' day' + (row.days_late === 1 ? '' : 's') + ' late</span>' : ''}</td>",
    "row days-late badge")
print("LEDGER JS OK")
