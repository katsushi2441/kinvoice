#!/usr/bin/env python3
"""kappstore 出品用の商品画像(1200x750 / 16:10)。

一覧はカード表示で aspect-ratio 16/10。実画面のスクリーンショットを使い、
「何ができるか」が一目で分かる形にする。

実行: python3 scripts/make_product_image.py
"""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

FONT = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
FONT_R = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"

W, H = 1200, 750
FOAM, PANEL, ABYSS, MUTED = "#f5fbfb", "#e7f3f2", "#12202f", "#55697a"
TEAL, TEAL_DEEP = "#12a99f", "#0a726b"

ROOT = Path(__file__).resolve().parent.parent
SHOTS = Path("/tmp/claude-1000/-home-kojima-work/7230f738-72fc-4ac2-ae71-cd89b6f444a1/scratchpad")
OUT = ROOT / "outputs" / "kinvoice-product.png"

img = Image.new("RGB", (W, H), FOAM)
dr = ImageDraw.Draw(img)
dr.rectangle([(0, 0), (W, 8)], fill=TEAL)

f_eyebrow = ImageFont.truetype(FONT, 22)
f_title = ImageFont.truetype(FONT, 52)
f_lead = ImageFont.truetype(FONT_R, 24)
f_pill = ImageFont.truetype(FONT, 21)

x = 56
dr.text((x, 44), "KURAGE APP STORE", font=f_eyebrow, fill=TEAL_DEEP)
dr.text((x, 84), "領収書をPDFで発行して", font=f_title, fill=ABYSS)
dr.text((x, 148), "メールで送る", font=f_title, fill=TEAL_DEEP)
dr.text((x, 228), "DB不要・外部ライブラリ不要。PHPが動くサーバーだけで動きます。", font=f_lead, fill=MUTED)

px = x
for label in ("インボイス対応", "デモあり", "改変自由"):
    tw = dr.textlength(label, font=f_pill)
    dr.rounded_rectangle([(px, 274), (px + tw + 38, 316)], radius=21, fill=TEAL)
    dr.text((px + 19, 281), label, font=f_pill, fill="#ffffff")
    px += tw + 52

# 実画面を2枚並べる（管理画面を大きく、顧客画面を小さく重ねる）
def rounded(im, r=10):
    mask = Image.new("L", im.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle([(0, 0), im.size], radius=r, fill=255)
    out = Image.new("RGB", im.size, FOAM)
    out.paste(im, (0, 0), mask)
    return out

admin = Image.open(SHOTS / "shot_admin.png").convert("RGB")
admin = admin.crop((0, 0, admin.width, int(admin.height * 0.62)))
admin.thumbnail((760, 420), Image.LANCZOS)
img.paste(rounded(admin), (x, 356))
dr.rounded_rectangle([(x - 1, 355), (x + admin.width + 1, 355 + admin.height + 1)],
                     radius=10, outline="#cde5e2", width=2)

dl = Image.open(SHOTS / "shot_dl.png").convert("RGB")
dl.thumbnail((330, 330), Image.LANCZOS)
px2 = x + admin.width + 26
img.paste(rounded(dl), (px2, 356))
dr.rounded_rectangle([(px2 - 1, 355), (px2 + dl.width + 1, 355 + dl.height + 1)],
                     radius=10, outline="#cde5e2", width=2)

f_cap = ImageFont.truetype(FONT_R, 17)
dr.text((x, 356 + admin.height + 10), "管理画面：発行して送るだけ", font=f_cap, fill=MUTED)
dr.text((px2, 356 + dl.height + 10), "お客様の受け取り画面", font=f_cap, fill=MUTED)

OUT.parent.mkdir(parents=True, exist_ok=True)
img.save(OUT, "PNG", optimize=True)
print(f"wrote {OUT} ({OUT.stat().st_size:,} bytes) {img.size}")
