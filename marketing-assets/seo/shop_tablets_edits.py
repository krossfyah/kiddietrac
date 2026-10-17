# -*- coding: utf-8 -*-
"""Tablets in the shop (2026-10-16, Anthony: "add to our marketing site under shop ability to buy
samsung or amazon tablets add different sizes but no pricing ... including ios apple ipad").

Adds a "Tablets" filter and three cards: Samsung Galaxy Tab, Amazon Fire, Apple iPad, each with
its screen sizes. Like the rest of the shop there are no prices: "Request a quote" opens the
contact form with the item named. Images are drawn (images/shop/tablet-*-700.jpg, made by
make_tablets.py): a tablet showing the KiddieTrac check-in screen, no maker's photos or logos.
The words are rendered by the shop script, so fr/es/hi go into the dictionaries as runtime keys
(tr_shop_tablets.py), in the same shapes as the other items ('|name', 'A|alt', 'b|Options: …').

usage: python shop_tablets_edits.py in.html out.html
"""
import io
import sys

FILTER_OLD = '<button class="filter-btn" data-cat="office">Office &amp; desk</button>'
FILTER_NEW = FILTER_OLD + '\n    <button class="filter-btn" data-cat="tablets">Tablets</button>'

ITEMS_OLD = """    { id: 'pen', cat: 'office', tag: 'Office & desk', name: 'Metal Pen', desc: 'A weighty black ballpoint with the KiddieTrac wordmark — a small thank-you that gets used every day.', opts: 'Black · blue ink' },
"""
ITEMS_NEW = ITEMS_OLD + """    { id: 'tablet-samsung', cat: 'tablets', tag: 'Tablets', name: 'Samsung Galaxy Tab', desc: 'Android tablets for check-in at the door, in the classroom and in the office. KiddieTrac runs in the tablet’s browser. Ask us about setting them up for your centre.', opts: '8.7″, 11″ or 12.4″ screen' },
    { id: 'tablet-amazon', cat: 'tablets', tag: 'Tablets', name: 'Amazon Fire Tablet', desc: 'Budget-friendly tablets for a check-in station or a classroom, from the Fire 7 to the Fire Max 11. KiddieTrac runs in the tablet’s browser.', opts: '7″, 8″, 10.1″ or 11″ screen' },
    { id: 'tablet-ipad', cat: 'tablets', tag: 'Tablets', name: 'Apple iPad', desc: 'iPad mini, iPad and iPad Air for directors, educators and the front desk. KiddieTrac runs in Safari and can be added to the Home Screen like an app.', opts: '8.3″ (iPad mini), 11″ or 13″ screen' },
"""


def one(s, old, new):
    if s.count(old) != 1:
        sys.exit('expected once, found %d: %r' % (s.count(old), old[:80]))
    return s.replace(old, new)


def main(src, dst):
    s = io.open(src, encoding='utf-8', newline='').read()
    if "id: 'tablet-ipad'" in s:
        sys.exit('shop_tablets_edits already applied')
    s = one(s, FILTER_OLD, FILTER_NEW)
    s = one(s, ITEMS_OLD, ITEMS_NEW)
    io.open(dst, 'w', encoding='utf-8', newline='').write(s)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
