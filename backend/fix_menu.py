import io, os, sys
os.chdir(sys.argv[1])
CRLF, LF = chr(13)+chr(10), chr(10)
P = 'app/Http/Controllers/Api/OperationsController.php'
s = io.open(P, encoding='utf-8', newline='').read()

old = """            'items' => 'required|array',"""
new = """            // present, not required: Laravel's `required` rejects an EMPTY array, so
            // starting a week and filling it in later failed with a validation error that
            // named a field the person had not touched. Creating the shell of a menu week
            // is a normal first step, not a mistake.
            'items' => 'present|array',"""
if old not in s:
    old, new = old.replace(LF, CRLF), new.replace(LF, CRLF)
assert old in s and s.count(old) == 1, 'items rule'
s = s.replace(old, new, 1)

old2 = """        $this->assertCentreAccess($request, (int) $data['centre_id']);"""
new2 = """        // An empty DRAFT is fine; an empty PUBLISHED menu is not — families would be
        // shown a blank week, which is worse than an unfinished one they cannot see.
        if ($data['status'] === 'published' && empty($data['items'])) {
            return response()->json([
                'message' => 'Add at least one meal before publishing — families would otherwise see an empty week.',
            ], 422);
        }
        $this->assertCentreAccess($request, (int) $data['centre_id']);"""
if old2 not in s:
    old2, new2 = old2.replace(LF, CRLF), new2.replace(LF, CRLF)
assert old2 in s and s.count(old2) == 1, 'access anchor'
io.open(P, 'w', encoding='utf-8', newline='').write(s.replace(old2, new2, 1))
print('  empty draft menus can be saved; empty published ones cannot')
