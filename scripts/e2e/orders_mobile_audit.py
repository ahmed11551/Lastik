"""
Local E2E / Mobile-First visual audit for OrdersView (no external MCP, $0).
Uses Playwright (Chromium) from the hermes-agent venv.

Checks:
  - page renders at 390px viewport
  - no horizontal scroll (scrollWidth <= innerWidth)
  - screenshot saved for visual review
"""
from playwright.sync_api import sync_playwright
import pathlib

URL = "http://localhost:5178/#/orders"
OUT = pathlib.Path(__file__).resolve().parent / "shots"
OUT.mkdir(exist_ok=True)


def main() -> None:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        # iPhone 12/13/14 logical width = 390px
        context = browser.new_context(viewport={"width": 390, "height": 844},
                                       device_scale_factor=2,
                                       is_mobile=True,
                                       has_touch=True)
        page = context.new_page()
        errors: list[str] = []
        page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: errors.append(str(e)))

        page.goto(URL, wait_until="networkidle", timeout=30000)
        page.wait_for_timeout(1500)  # let Vue mount + API attempt settle

        scroll_w = page.evaluate("document.documentElement.scrollWidth")
        inner_w = page.evaluate("window.innerWidth")
        horizontal_overflow = scroll_w > inner_w + 1

        shot = OUT / "orders_390px.png"
        page.screenshot(path=str(shot), full_page=False)

        # Also capture a tall full-page pass to inspect layout stacking
        shot_full = OUT / "orders_390px_full.png"
        page.screenshot(path=str(shot_full), full_page=True)

        browser.close()

        print("VIEWPORT_WIDTH", inner_w)
        print("SCROLL_WIDTH", scroll_w)
        print("HORIZONTAL_OVERFLOW", horizontal_overflow)
        print("CONSOLE_ERRORS", len(errors))
        for e in errors[:10]:
            print("  ERR:", e[:200])
        print("SHOT", shot)
        print("SHOT_FULL", shot_full)
        # Exit non-zero if layout is broken (horizontal overflow on mobile)
        raise SystemExit(1 if horizontal_overflow else 0)


if __name__ == "__main__":
    main()
