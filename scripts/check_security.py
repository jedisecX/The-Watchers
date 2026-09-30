"""Project-specific static security gates (run in CI; non-zero exit fails the build).

Frontend:
  * no HTML-parsing / code-evaluating sinks in first-party JS
  * no inline <script>, inline event handlers, style attributes or <style> blocks in HTML
  * vendored files match the SHA-256 recorded in VENDORED.md
  * nginx's generated CSP equals app.middleware.frontend_csp() for the same PUBLIC_BASE_URL
Backend:
  * no SQL built by string interpolation inside sqlalchemy text()
  * no shell=True / os.system / os.popen / eval / exec / pickle / yaml.load
  * every APIRouter uses StrictRoute (except the audited allow-list)
  * no `assert` in application code (stripped under python -O)
"""
from __future__ import annotations

import hashlib
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
errors: list[str] = []


def err(msg: str) -> None:
    errors.append(msg)


# ------------------------------------------------------------------------- frontend
JS_SINKS = [
    (r"\.innerHTML\b", "innerHTML"), (r"\.outerHTML\b", "outerHTML"), (r"insertAdjacentHTML", "insertAdjacentHTML"),
    (r"document\.write", "document.write"), (r"\beval\s*\(", "eval"), (r"new\s+Function\s*\(", "new Function"),
    (r"set(Timeout|Interval)\s*\(\s*['\"`]", "string timer"), (r"\bsrcdoc\b", "srcdoc"),
    (r"createContextualFragment", "createContextualFragment"), (r"DOMParser", "DOMParser"),
    (r"setAttribute\(\s*['\"](on\w+|style|srcdoc)['\"]", "dangerous setAttribute"),
    (r"javascript:", "javascript: URL"), (r"\.cssText\b", "cssText"),
]

for js in (ROOT / "frontend" / "assets" / "js").rglob("*.js"):
    text = js.read_text()
    for pat, name in JS_SINKS:
        for m in re.finditer(pat, text):
            line = text.count("\n", 0, m.start()) + 1
            err(f"{js.relative_to(ROOT)}:{line}: forbidden sink {name}")

for html in (ROOT / "frontend").glob("*.html"):
    text = html.read_text()
    for m in re.finditer(r"<script(?![^>]*\bsrc=)[^>]*>", text, re.I):
        err(f"{html.name}: inline <script> {m.group(0)}")
    if re.search(r"\son[a-z]+\s*=", text, re.I):
        err(f"{html.name}: inline event handler")
    if re.search(r"\sstyle\s*=", text, re.I) or re.search(r"<style", text, re.I):
        err(f"{html.name}: inline style")

vend = ROOT / "frontend" / "assets" / "vendor"
manifest = (vend / "VENDORED.md").read_text()
for f in vend.iterdir():
    if f.suffix == ".js":
        digest = hashlib.sha256(f.read_bytes()).hexdigest()
        if digest not in manifest:
            err(f"vendored file {f.name} hash {digest} not recorded in VENDORED.md (tampered or unreviewed update)")

spa = ROOT / "deploy" / "nginx" / "spa_headers.conf"
if spa.exists():
    m = re.search(r'Content-Security-Policy "([^"]+)"', spa.read_text())
    host = re.search(r"wss://([^ ;]+)", m.group(1)).group(1) if m else ""
    os.environ["PUBLIC_BASE_URL"] = f"https://{host}"
    os.environ.setdefault("ENV", "development")
    sys.path.insert(0, str(ROOT / "backend"))
    from app.middleware import frontend_csp  # noqa: E402
    if not m or m.group(1) != frontend_csp():
        err("deploy/nginx/spa_headers.conf CSP differs from app.middleware.frontend_csp(); run scripts/render_nginx.py")
else:
    err("deploy/nginx/spa_headers.conf missing; run scripts/render_nginx.py")

# ------------------------------------------------------------------------- backend
PY_BANNED = [
    (r"shell\s*=\s*True", "shell=True"), (r"\bos\.system\(", "os.system"), (r"\bos\.popen\(", "os.popen"),
    (r"(?<![\w.])eval\(", "eval"), (r"(?<![\w.])exec\(", "exec"), (r"\bpickle\.loads?\(", "pickle"),
    (r"\byaml\.load\(", "yaml.load"), (r"\bmark_safe\(", "mark_safe"),
]
SQL_INTERP = re.compile(r"""text\(\s*(f["']|["'][^"']*["']\s*(%|\.format|\+))""")
ROUTER_ALLOW = {"misc.py"}  # health + CSP report (browser posts application/csp-report)

app_dir = ROOT / "backend" / "app"
for py in app_dir.rglob("*.py"):
    text = py.read_text()
    rel = py.relative_to(ROOT)
    for pat, name in PY_BANNED:
        for m in re.finditer(pat, text):
            err(f"{rel}:{text.count(chr(10), 0, m.start()) + 1}: banned construct {name}")
    for m in SQL_INTERP.finditer(text):
        err(f"{rel}:{text.count(chr(10), 0, m.start()) + 1}: SQL text() built by string interpolation")
    for m in re.finditer(r"^\s*assert\s", text, re.M):
        err(f"{rel}:{text.count(chr(10), 0, m.start()) + 1}: assert in application code")
    if "APIRouter(" in text and py.name not in ROUTER_ALLOW:
        for m in re.finditer(r"APIRouter\(([^)]*)\)", text):
            if "route_class=StrictRoute" not in m.group(1):
                err(f"{rel}: APIRouter without route_class=StrictRoute")

if errors:
    print("SECURITY CHECKS FAILED:")
    for e in errors:
        print("  -", e)
    sys.exit(1)
print("security checks passed")
