from pathlib import Path

root = Path(r"c:\xampp\htdocs\optimus\templates\admin")
broken = 'class=\\"'
fixed = 'class="'

for path in root.rglob("*.twig"):
    text = path.read_text(encoding="utf-8")
    if broken in text:
        path.write_text(text.replace(broken, fixed), encoding="utf-8")
        print(path.relative_to(root))
