"""End-to-end smoke test in real Chromium against a running dev stack.

Fails on ANY console error, uncaught page error, or CSP / Trusted Types violation, which proves
the UI works under the production CSP (script-src 'self', require-trusted-types-for 'script').

    python scripts/e2e_smoke.py [--base http://localhost:8000] [--shots /tmp/shots]
"""
from __future__ import annotations

import argparse
import io
import os
import re
import sys
import time
import uuid

from PIL import Image
from playwright.sync_api import sync_playwright
from sqlalchemy import create_engine, text

XSS = '<img src=x onerror="window.__pwned=1"><script>window.__pwned=2</script> #e2e https://example.com/ok'
PW = "correct horse battery staple 42"


def verify_link(engine, email: str) -> str:
    with engine.connect() as c:
        for _ in range(50):
            body = c.execute(text("SELECT body FROM email_outbox WHERE to_addr=:e AND kind='verify' "
                                  "ORDER BY created_at DESC LIMIT 1"), {"e": email}).scalar()
            if body:
                return re.search(r"(http\S+/verify-email#token=\S+)", body).group(1)
            time.sleep(0.2)
    raise AssertionError("no verification mail")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://localhost:8000")
    ap.add_argument("--shots", default="/tmp/bastion-shots")
    a = ap.parse_args()
    os.makedirs(a.shots, exist_ok=True)
    eng = create_engine(os.environ["DATABASE_URL"])
    problems: list[str] = []
    suffix = uuid.uuid4().hex[:6]
    alice, bob = f"alice_{suffix}", f"bob_{suffix}"

    with sync_playwright() as p:
        exe = os.environ.get("CHROMIUM_PATH") or ("/opt/pw-browsers/chromium" if os.path.exists("/opt/pw-browsers/chromium") else None)
        browser = p.chromium.launch(executable_path=exe) if exe else p.chromium.launch()

        def new_page():
            ctx = browser.new_context(viewport={"width": 1100, "height": 900})
            page = ctx.new_page()
            page.on("console", lambda m: m.type == "error" and problems.append(f"console: {m.text}"))
            page.on("pageerror", lambda e: problems.append(f"pageerror: {e}"))
            page.add_init_script("""document.addEventListener('securitypolicyviolation', e =>
                console.error('CSP violation: ' + e.violatedDirective + ' ' + e.blockedURI));""")
            return page

        def register_and_verify(page, handle):
            page.goto(f"{a.base}/register")
            page.fill("input[autocomplete=username]", handle)
            page.fill("input[type=email]", f"{handle}@example.com")
            page.fill("input[type=password]", PW)
            page.click("button[type=submit]")
            page.wait_for_selector("text=Check your email", timeout=60000)
            page.goto(verify_link(eng, f"{handle}@example.com"))
            page.wait_for_selector("text=Email verified", timeout=10000)
            assert "token=" not in page.url, "token must be scrubbed from the URL"
            page.goto(f"{a.base}/login")
            page.fill("input[autocomplete=username]", handle)
            page.fill("input[type=password]", PW)
            page.click("button[type=submit]")
            page.wait_for_selector("h1:has-text('Home')", timeout=10000)

        pa, pb = new_page(), new_page()
        register_and_verify(pa, alice)
        register_and_verify(pb, bob)
        print("✓ register (PoW) → verify → login for both users")

        # --- XSS payload must render as inert text
        pa.fill("textarea[aria-label='Post text']", XSS)
        pa.click("button:has-text('Post')")
        pa.wait_for_selector("article.post", timeout=10000)
        body = pa.locator("article.post .body").first
        assert body.inner_text().startswith("<img src=x"), body.inner_text()
        assert pa.evaluate("window.__pwned") is None, "XSS executed!"
        assert body.locator("img, script").count() == 0
        assert body.locator("a.ext").get_attribute("href") == "https://example.com/ok"
        assert body.locator("a.ext").get_attribute("rel") == "noopener noreferrer nofollow ugc"
        print("✓ stored XSS payload rendered as plain text; link safe")

        # --- image upload through the isolated worker
        img = io.BytesIO()
        Image.new("RGB", (320, 200), (40, 90, 200)).save(img, "PNG")
        pa.set_input_files("form.composer input[type=file]", {"name": "x.png", "mimeType": "image/png", "buffer": img.getvalue()})
        pa.wait_for_selector("form.composer img.thumb", timeout=30000)
        pa.fill("textarea[aria-label='Post text']", "with a picture")
        pa.click("button:has-text('Post')")
        pa.wait_for_selector("article.post img.media", timeout=10000)
        print("✓ media upload → worker → derivative served")
        pa.screenshot(path=f"{a.shots}/home.png", full_page=True)

        # --- follow + DM with realtime delivery
        pb.goto(f"{a.base}/@{alice}")
        pb.click("button:has-text('Follow')")
        pb.wait_for_selector("button:has-text('Unfollow')")
        pa.goto(f"{a.base}/settings")
        pa.wait_for_selector("h2:has-text('Privacy')")
        pa.select_option("section:has(h2:has-text('Privacy')) select >> nth=0", "everyone")
        pa.click("button:has-text('Save privacy settings')")
        pa.wait_for_selector("text=Privacy settings saved")
        pb.click("button:has-text('Message')")
        pb.wait_for_selector("textarea[aria-label=Message]")
        conv_url = pb.url
        pa.goto(conv_url)
        pa.wait_for_selector(".msglog")
        time.sleep(0.5)
        pb.fill("textarea[aria-label=Message]", "hello over websockets <b>not bold</b>")
        pb.click("button:has-text('Send')")
        pa.wait_for_selector(".msg:has-text('hello over websockets')", timeout=10000)
        assert pa.locator(".msg b").count() == 0
        print("✓ DM delivered in realtime to the other member; markup inert")
        pa.screenshot(path=f"{a.shots}/dm.png")

        # --- notifications
        pa.goto(f"{a.base}/notifications")
        pa.wait_for_selector(f".notif:has-text('followed you')", timeout=10000)
        print("✓ notifications")

        # --- security settings: TOTP enrolment renders a QR canvas; sessions + tokens lists load
        pa.goto(f"{a.base}/settings")
        pa.wait_for_selector("h2:has-text('Active sessions')")
        pa.click("button:has-text('Set up 2FA')")
        pa.wait_for_selector("dialog canvas.qr", timeout=10000)
        pa.screenshot(path=f"{a.shots}/totp.png")
        pa.click("dialog button:has-text('Cancel')")
        print("✓ settings, sessions, 2FA QR (vendored lib) under Trusted Types")

        # --- regular user cannot use the admin console
        pa.goto(f"{a.base}/admin")
        pa.wait_for_selector("text=You are not allowed", timeout=10000)
        print("✓ admin console refuses a regular user (server-side 403)")

        pb.goto(f"{a.base}/explore")
        pb.wait_for_selector("article.post")
        pb.screenshot(path=f"{a.shots}/explore.png", full_page=True)
        browser.close()

    # 403 on /admin/api is expected and logged by the browser as a failed resource; filter that one.
    real = [x for x in problems if "403" not in x]
    if real:
        print("PROBLEMS:\n" + "\n".join(real))
        return 1
    print("✓ no console errors, page errors, or CSP/Trusted-Types violations")
    return 0


if __name__ == "__main__":
    sys.exit(main())
