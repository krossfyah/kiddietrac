# -*- coding: utf-8 -*-
"""Make ontario_edits.py and related_edits.py write the 2026-10-16 wording themselves, so a full
rebuild of the chain gives the same page as blog_ratios_edits.py. Run in marketing-assets/seo."""
import io
import os

EDITS = [
    ('ontario_edits.py',
     "family age groups and an RECE in every group. Directors and educators see the same live result.",
     "family age groups, and the share of RECEs each group needs. Directors and educators see the same live result."),
    ('ontario_edits.py',
     "ratios, maximum group sizes and qualified staff, mixed-age groups",
     "ratios, maximum group sizes, mixed-age groups"),
    ('related_edits.py',
     "'ontario-2027-child-care-regulation-changes,safe-arrival-child-care-absence-follow-up,cwelcc-2026-fees-22-dollar-cap-ontario'",
     "'ontario-child-care-ratios,ontario-2027-child-care-regulation-changes,safe-arrival-child-care-absence-follow-up'"),
]
for name, old, new in EDITS:
    s = io.open(name, encoding='utf-8', newline='').read()
    if new in s and old not in s:
        print(name, 'already updated')
        continue
    assert s.count(old) == 1, (name, old[:60], s.count(old))
    io.open(name + '.tmp', 'w', encoding='utf-8', newline='').write(s.replace(old, new))
    os.rename(name + '.tmp', name)
    print(name, 'updated')
