"""Shop images for the tablet cards: a tablet showing the KiddieTrac check-in screen, drawn
(no third-party product photos or logos). 1400x1400 masters -> 700 JPG + 700 WebP."""
import io
import os
import urllib.request

from PIL import Image, ImageDraw, ImageFilter, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'img')
os.makedirs(OUT, exist_ok=True)
F = 'C:/Windows/Fonts/'
def font(bold, size):
    return ImageFont.truetype(F + ('segoeuib.ttf' if bold else 'segoeui.ttf'), size)

wm = Image.open(io.BytesIO(urllib.request.urlopen('https://api.kiddietrac.com/email/wordmark-tagline-navy.png').read())).convert('RGBA')

NAVY = (8, 28, 65)
TEAL = (31, 96, 128)
TILES = [((255, 237, 213), (194, 65, 12), 'Check in'), ((224, 242, 254), (3, 105, 161), 'Daily report'),
         ((220, 252, 231), (21, 128, 61), 'Messages'), ((237, 233, 254), (109, 40, 217), 'Photos')]


def screen(w, h):
    s = Image.new('RGB', (w, h), (246, 249, 251))
    d = ImageDraw.Draw(s)
    bar = int(h * 0.2)
    d.rectangle([0, 0, w, bar], fill=NAVY)
    lw = int(min(w * 0.36, bar * 0.78 * wm.width / wm.height))   # fits the bar, tagline included
    logo = wm.resize((lw, int(wm.height * lw / wm.width)), Image.LANCZOS)
    s.paste(logo, ((w - logo.width) // 2, (bar - logo.height) // 2), logo)
    # gradient strip
    for x in range(w):
        t = x / max(1, w - 1)
        c = tuple(int(a + (b - a) * t) for a, b in zip((34, 193, 195), (124, 58, 237)))
        d.line([(x, bar), (x, bar + max(3, h // 90))], fill=c)
    pad = int(w * 0.06)
    y = bar + int(h * 0.07)
    d.text((pad, y), 'Good morning!', font=font(True, max(14, int(h * 0.075))), fill=NAVY)
    d.text((pad, y + int(h * 0.09)), 'Tap a child to check in', font=font(False, max(11, int(h * 0.045))), fill=(91, 107, 124))
    gy = y + int(h * 0.19)
    gap = int(w * 0.03)
    tw = (w - 2 * pad - 3 * gap) // 4
    th = h - gy - pad
    for i, (bg, fg, label) in enumerate(TILES):
        x0 = pad + i * (tw + gap)
        d.rounded_rectangle([x0, gy, x0 + tw, gy + th], radius=int(tw * 0.14), fill=bg)
        r = int(tw * 0.22)
        cx, cy = x0 + tw // 2, gy + int(th * 0.38)
        d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=fg)
        f = font(True, max(10, int(tw * 0.13)))
        tw_ = d.textlength(label, font=f)
        d.text((cx - tw_ / 2, gy + int(th * 0.68)), label, font=f, fill=fg)
    return s


def tablet(name, ratio, frame, bezel, radius, sizes, bg):
    W = H = 1400
    img = Image.new('RGB', (W, H), bg[0])
    d = ImageDraw.Draw(img)
    for y in range(H):  # soft vertical background gradient
        t = y / (H - 1)
        d.line([(0, y), (W, y)], fill=tuple(int(a + (b - a) * t) for a, b in zip(bg[0], bg[1])))
    dw = 1120
    dh = int(dw / ratio)
    x0, y0 = (W - dw) // 2, int(H * 0.44) - dh // 2
    sh = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(sh).rounded_rectangle([x0 + 10, y0 + 40, x0 + dw + 10, y0 + dh + 40], radius=radius, fill=(15, 30, 55, 110))
    img.paste(sh.filter(ImageFilter.GaussianBlur(34)), (0, 0), sh.filter(ImageFilter.GaussianBlur(34)))
    d.rounded_rectangle([x0, y0, x0 + dw, y0 + dh], radius=radius, fill=frame)
    sx0, sy0 = x0 + bezel, y0 + bezel
    sw, shh = dw - 2 * bezel, dh - 2 * bezel
    scr = screen(sw, shh)
    mask = Image.new('L', (sw, shh), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, sw, shh], radius=max(8, radius - bezel), fill=255)
    img.paste(scr, (sx0, sy0), mask)
    # camera dot on the long top edge
    d.ellipse([x0 + dw // 2 - 7, y0 + bezel // 2 - 7, x0 + dw // 2 + 7, y0 + bezel // 2 + 7], fill=(60, 64, 72))
    # size chips
    f = font(True, 46)
    chips = [s for s in sizes]
    widths = [d.textlength(c, font=f) + 64 for c in chips]
    total = sum(widths) + 22 * (len(chips) - 1)
    cx = (W - total) / 2
    cy = y0 + dh + 110
    for c, wdt in zip(chips, widths):
        d.rounded_rectangle([cx, cy, cx + wdt, cy + 84], radius=42, fill=(255, 255, 255), outline=(203, 213, 225), width=3)
        d.text((cx + 32, cy + 12), c, font=f, fill=TEAL)
        cx += wdt + 22
    small = img.resize((700, 700), Image.LANCZOS)
    small.save(os.path.join(OUT, name + '-700.jpg'), quality=86, optimize=True, progressive=True)
    small.save(os.path.join(OUT, name + '-700.jpg.webp'), quality=80)
    print(name)


tablet('tablet-samsung', 16 / 10, (30, 32, 36), 46, 70, ['8.7″', '11″', '12.4″'], [(232, 240, 248), (214, 226, 238)])
tablet('tablet-amazon', 16 / 10, (20, 22, 26), 60, 56, ['7″', '8″', '10.1″', '11″'], [(236, 244, 240), (214, 232, 224)])
tablet('tablet-ipad', 4 / 3, (214, 218, 224), 40, 64, ['8.3″', '11″', '13″'], [(241, 238, 250), (225, 220, 242)])
