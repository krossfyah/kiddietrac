# Records read expanded by default; clicking collapses one you are done with.
#
# The note is the point of an observation - collapsing it by default made the screen
# scannable but hid the thing people come here to read. Expanded is the default now, with
# the row still collapsible.
import io, os, sys
os.chdir(sys.argv[1])
CRLF, LF = chr(13)+chr(10), chr(10)
P = 'parent-portal/js/screen-observations.js'

def sub(old, new, why):
    s = io.open(P, encoding='utf-8', newline='').read()
    o, n = old, new
    if o not in s: o, n = old.replace(LF,CRLF), new.replace(LF,CRLF)
    assert o in s, 'MISSING: ' + why
    assert s.count(o) == 1, 'NOT UNIQUE: ' + why
    io.open(P,'w',encoding='utf-8',newline='').write(s.replace(o,n,1))
    print('  ' + why)

sub("""      // The note itself is one click away rather than always open: the point of columns
      // is to scan many at once, and a paragraph in every row defeats that.
      var body = document.createElement('div');
      body.className = 'kt-obs-body';
      body.hidden = true;""",
    """      // Open by default: the note is what people come to this screen to read, and
      // hiding it behind a click made the list scannable at the cost of being useful.
      // The row still collapses for anyone who wants the dense view.
      var body = document.createElement('div');
      body.className = 'kt-obs-body';""",
    'observation note is expanded by default')

sub("""          ' <span class="kt-obs-chev">\u25be</span>' +""",
    """          ' <span class="kt-obs-chev">\u25b4</span>' +""",
    'chevron starts in the expanded state')
