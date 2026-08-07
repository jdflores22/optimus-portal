from PIL import Image
from pathlib import Path

paths = [
    Path(r'c:\xampp\htdocs\optimus\optimus-v2\frontend\optimus-web\src\assets\optimus-logo.png'),
    Path(r'c:\xampp\htdocs\optimus\optimus-v2\frontend\optimus-web\public\optimus-logo.png'),
    Path(r'c:\xampp\htdocs\optimus\public\images\optimus-logo.png'),
]

src = paths[0]
img = Image.open(src).convert('RGBA')
pixels = img.load()
w, h = img.size

# Make near-black background transparent. Keep navy text and colored artwork.
threshold = 28
changed = 0
for y in range(h):
    for x in range(w):
        r, g, b, a = pixels[x, y]
        if r <= threshold and g <= threshold and b <= threshold:
            pixels[x, y] = (0, 0, 0, 0)
            changed += 1

bbox = img.getbbox()
if bbox:
    img = img.crop(bbox)

for p in paths:
    img.save(p, 'PNG')
    print(f'saved {p} size={img.size} cleared={changed}')
