#!/usr/bin/env python3
import io
p = 'js/screen-admin.js'
s = io.open(p, encoding='utf-8').read()

anchor = '  function showFamilyModal(family, centres, content) {'
assert anchor in s, 'showFamilyModal anchor not found'
assert 'function showFamilyWizard' not in s, 'wizard already present'

wizard = io.open('family_wizard.js', encoding='utf-8').read()
# Insert wizard function immediately before showFamilyModal
s = s.replace(anchor, wizard + anchor, 1)

# Add the branch: new families go through the wizard
branch_anchor = '  function showFamilyModal(family, centres, content) {\n    const isEdit = !!family;\n'
branch_new = '  function showFamilyModal(family, centres, content) {\n    const isEdit = !!family;\n    if (!isEdit) { return showFamilyWizard(centres, content); }\n'
assert branch_anchor in s, 'branch anchor not found'
s = s.replace(branch_anchor, branch_new, 1)

io.open(p, 'w', encoding='utf-8', newline='\n').write(s)
print('OK: wizard inserted, branch added. new length', len(s))
