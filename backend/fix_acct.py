import io, os, sys
os.chdir(sys.argv[1])
CRLF, LF = chr(13)+chr(10), chr(10)
P = 'app/Http/Controllers/Api/InvoiceController.php'
s = io.open(P, encoding='utf-8', newline='').read()
before = len(s)

# The listing query excluded void unconditionally, so 41 voided invoices had nowhere to be
# seen. The STATS query keeps excluding them — voided money is neither owed nor received,
# and counting it would misstate the totals.
old = """            ->where('ei.agency_id', $agencyId)
            ->where('ei.status', '!=', 'void');

        if ($famFilter = (int) $request->query('family_id', 0)) {"""
new = """            ->where('ei.agency_id', $agencyId);

        // Void is hidden unless it is asked for by name. It is a real record — an invoice
        // that was raised and cancelled — but it is not part of "what is outstanding",
        // which is what this list answers by default.
        $statusFilter = strtolower(trim((string) $request->query('status', '')));
        if ($statusFilter === 'void') {
            $base->where('ei.status', 'void');
        } elseif ($statusFilter !== '' && $statusFilter !== 'all') {
            $base->where('ei.status', $statusFilter)->where('ei.status', '!=', 'void');
        } else {
            $base->where('ei.status', '!=', 'void');
        }

        if ($famFilter = (int) $request->query('family_id', 0)) {"""
if old not in s:
    old, new = old.replace(LF, CRLF), new.replace(LF, CRLF)
assert old in s, 'listing anchor missing'
assert s.count(old) == 1, 'listing anchor not unique'
s = s.replace(old, new, 1)
io.open(P, 'w', encoding='utf-8', newline='').write(s)
print('status filter added (%+d)' % (len(s) - before))
