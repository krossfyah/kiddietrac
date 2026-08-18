# -*- coding: utf-8 -*-
import io
def edit(p, old, new, n=1):
    s = io.open(p, 'r', encoding='utf-8', newline='').read()
    nl = '\r\n' if '\r\n' in s else '\n'
    o = old.replace('\n', nl); ne = new.replace('\n', nl)
    c = s.count(o)
    assert c == n, '%s expected %d got %d :: %s' % (p, n, c, old[:60])
    io.open(p, 'w', encoding='utf-8', newline='').write(s.replace(o, ne))
    print('patched', p, '(%d)' % c)

AC = 'app/Http/Controllers/Api/AuthController.php'
AD = 'app/Http/Controllers/Api/AdminController.php'
IM = 'app/Http/Controllers/Api/ImpersonationController.php'

# 1) AuthController::pickPrimaryRole — resolve sales_rep (else shell hangs)
edit(AC,
 "            in_array('home_visitor', $roles, true) => 'home_visitor',\n            in_array('auditor', $roles, true) => 'auditor',",
 "            in_array('home_visitor', $roles, true) => 'home_visitor',\n            in_array('sales_rep', $roles, true) => 'sales_rep',\n            in_array('auditor', $roles, true) => 'auditor',")

# 2) AdminController — both create/edit role validation lists (2 occurrences)
edit(AD,
 "'in:agency_admin,centre_director,educator,auditor,platform_admin,home_visitor'",
 "'in:agency_admin,centre_director,educator,auditor,platform_admin,home_visitor,sales_rep'", 2)

# 3) AdminController — sales_rep is centre-less (exempt from the centre requirement)
edit(AD,
 "if (! in_array($data['role'], ['agency_admin', 'platform_admin'], true) && empty($data['centre_id'])) {",
 "if (! in_array($data['role'], ['agency_admin', 'platform_admin', 'sales_rep'], true) && empty($data['centre_id'])) {")

# 4) AdminController — and never stamp a centre on a sales_rep assignment
edit(AD,
 "$assignmentCentreId = (! in_array($data['role'], ['agency_admin', 'platform_admin'], true))",
 "$assignmentCentreId = (! in_array($data['role'], ['agency_admin', 'platform_admin', 'sales_rep'], true))")

# 5) ImpersonationController::primaryRole — resolve sales_rep for view-as
edit(IM,
 "=> 'auditor',\n            default",
 "=> 'auditor',\n            in_array('sales_rep', $roles, true)        => 'sales_rep',\n            default")

print('ROLE PLUMBING DONE')
