#!/usr/bin/env python3
"""Convert admin templates from base.html.twig to layout/admin_base.html.twig."""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "templates" / "admin"

FILES = [
    "user_management.html.twig",
    "approved_accreditations.html.twig",
    "audit_logs.html.twig",
    "application_detail.html.twig",
    "activity_logs/list.html.twig",
    "activity_logs/detail.html.twig",
    "activity_logs/reports.html.twig",
    "user_hierarchy/list.html.twig",
    "user_hierarchy/detail.html.twig",
    "user_hierarchy/create.html.twig",
    "user_hierarchy/edit.html.twig",
    "user_hierarchy/statistics.html.twig",
    "user_hierarchy/pending_invitations.html.twig",
    "user_hierarchy/validate_integrity.html.twig",
    "appeals/index.html.twig",
    "appeals/detail.html.twig",
    "broker_transfer/index.html.twig",
    "broker_transfer/detail.html.twig",
    "system_settings/index.html.twig",
    "notification_metrics/dashboard.html.twig",
    "cy_utilization/report.html.twig",
    "terminals/index.html.twig",
    "terminals/new.html.twig",
    "terminals/edit.html.twig",
    "terminals/view.html.twig",
    "container_types/index.html.twig",
    "container_types/new.html.twig",
    "container_types/edit.html.twig",
    "container_sizes/index.html.twig",
    "container_sizes/new.html.twig",
    "container_sizes/edit.html.twig",
]


def extract_breadcrumbs(content: str) -> tuple[str, str | None]:
    pattern = re.compile(
        r"\s*<div class=\"breadcrumbs text-xs mb-2\">\s*<ul>(.*?)</ul>\s*</div>",
        re.DOTALL,
    )
    match = pattern.search(content)
    if not match:
        return content, None

    inner = match.group(1)
    block = (
        "{% block admin_breadcrumbs %}\n"
        "<div class=\"breadcrumbs text-sm mb-2\">\n"
        f"    <ul>{inner}</ul>\n"
        "</div>\n"
        "{% endblock %}\n\n"
    )
    return content[: match.start()] + content[match.end() :], block


def strip_outer_wrappers(content: str) -> str:
    patterns = [
        (
            r"(\{% block content %\}\s*)<div class=\"min-h-screen bg-base-200\">\s*<div class=\"space-y-5 sm:space-y-6\">\s*",
            r'\1<div class="space-y-5 sm:space-y-6">\n',
            2,
        ),
        (
            r"(\{% block content %\}\s*)<div class=\"min-h-screen bg-base-200\">\s*",
            r'\1<div class="space-y-6">\n',
            1,
        ),
        (
            r"(\{% block content %\}\s*)<div class=\"max-w-7xl mx-auto\">\s*",
            r"\1",
            1,
        ),
    ]

    removed = 0
    for pattern, repl, count in patterns:
        new_content, n = re.subn(pattern, repl, content, count=1)
        if n:
            content = new_content
            removed = count
            break

    if removed:
        marker = "{% block content %}"
        idx = content.find(marker)
        if idx != -1:
            rest = content[idx + len(marker) :]
            close_pattern = r"\n(\s*)</div>\s*\n(\s*)</div>\s*\n(\{% endblock %\})"
            match = re.search(close_pattern, rest)
            if match and removed == 2:
                start = idx + len(marker) + match.start()
                end = idx + len(marker) + match.end()
                replacement = f"\n{match.group(2)}</div>\n{match.group(3)}"
                content = content[:start] + replacement + content[end:]
            elif match and removed == 1:
                start = idx + len(marker) + match.start()
                end = idx + len(marker) + match.end()
                replacement = f"\n{match.group(3)}"
                content = content[:start] + replacement + content[end:]

    return content


def convert_file(path: Path) -> bool:
    content = path.read_text(encoding="utf-8")
    if "layout/admin_base.html.twig" in content:
        return False

    content = content.replace(
        "{% extends 'base.html.twig' %}",
        "{% extends 'layout/admin_base.html.twig' %}",
    )
    content = re.sub(
        r"\{% block page_title %\}.*?\{% endblock %\}\r?\n\r?\n?",
        "",
        content,
        flags=re.DOTALL,
    )

    content, breadcrumbs = extract_breadcrumbs(content)
    prefix = breadcrumbs or ""
    content = content.replace("{% block body %}", prefix + "{% block content %}", 1)
    content = strip_outer_wrappers(content)

    path.write_text(content, encoding="utf-8")
    return True


def main() -> int:
    converted = 0
    for rel in FILES:
        path = ROOT / rel
        if not path.exists():
            print(f"SKIP missing: {rel}", file=sys.stderr)
            continue
        if convert_file(path):
            converted += 1
            print(f"OK {rel}")
    print(f"Converted {converted} files")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
