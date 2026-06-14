from pathlib import Path

root = Path(r"c:\xampp\htdocs\optimus\templates\admin")

for path in root.rglob("*.twig"):
    text = path.read_text(encoding="utf-8")
    if '\\">' in text:
        text2 = text.replace('\\">', '">')
        path.write_text(text2, encoding="utf-8")
        print(path.relative_to(root))
