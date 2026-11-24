#!/usr/bin/env python3
"""Slider davranış testi (Chromium): otomatik geçiş, ileri/geri, nokta, klavye, dokunmatik kaydırma, hover'da duraklama, mobil taşma.
Kullanım: slider_test.py <repo-kökü> <home-theme4.html> [çıktı.png]"""
import sys, os, json
from playwright.sync_api import sync_playwright
root, html = sys.argv[1], os.path.abspath(sys.argv[2])
res = []
def ok(c, n):
    res.append((bool(c), n)); print(("  ✓ " if c else "  ✗ ") + n)
def handle(route):
    u = route.request.url
    if u.startswith("https://test.local/"):
        p = os.path.join(root, u[len("https://test.local/"):].split("?")[0])
        return route.fulfill(path=p) if os.path.isfile(p) else route.fulfill(status=404, body="")
    return route.continue_() if u.startswith(("file://", "data:")) else route.abort()
with sync_playwright() as pw:
    b = pw.chromium.launch()
    for kind, vp, touch in (("masaüstü", {"width": 1440, "height": 900}, False), ("mobil", {"width": 390, "height": 844}, True)):
        print("==", kind)
        ctx = b.new_context(viewport=vp, is_mobile=touch, has_touch=touch)
        ctx.route("**/*", handle)
        pg = ctx.new_page(); errs = []
        pg.on("pageerror", lambda x, e=errs: e.append(str(x)))
        pg.goto("file://" + html, wait_until="load"); pg.wait_for_timeout(300)
        act = lambda: pg.evaluate("[].findIndex.call(document.querySelectorAll('.rt-slide'), s => s.classList.contains('is-active'))")
        n = pg.evaluate("document.querySelectorAll('.rt-slide').length")
        ok(n == 3, f"3 slayt var ({n})")
        ok(pg.evaluate("document.querySelector('[data-rt-slider]').classList.contains('is-js')"), "JS slider başlatıldı")
        ok(act() == 0, "ilk slayt aktif")
        # sadece aktif slayt görünür
        vis = pg.evaluate("[].map.call(document.querySelectorAll('.rt-slide'), s => getComputedStyle(s).visibility)")
        ok(vis == ["visible", "hidden", "hidden"], f"yalnız aktif slayt görünür {vis}")
        pg.click(".rt-nav--next"); ok(act() == 1, "ileri → 2. slayt")
        pg.click(".rt-nav--next"); pg.click(".rt-nav--next"); ok(act() == 0, "ileri döngü: sondan başa")
        pg.click(".rt-nav--prev"); ok(act() == 2, "geri döngü: baştan sona")
        pg.click(".rt-dot:nth-child(2)"); ok(act() == 1, "nokta ile slayt seçilir")
        ok(pg.evaluate("document.querySelectorAll('.rt-dot')[1].getAttribute('aria-current')") == "true", "aktif noktada aria-current")
        hid = pg.evaluate("document.querySelectorAll('.rt-slide')[0].getAttribute('aria-hidden')")
        ok(hid == "true", "pasif slayt aria-hidden")
        # otomatik geçiş (3 sn)
        pg.evaluate("document.querySelector('[data-rt-slider]').setAttribute('data-interval','3')")
        ctx2 = b.new_context(viewport=vp, is_mobile=touch, has_touch=touch); ctx2.route("**/*", handle)
        p2 = ctx2.new_page()
        txt = open(html, encoding="utf-8").read().replace('data-interval="6"', 'data-interval="3"')
        tmp = html + ".t3.html"; open(tmp, "w", encoding="utf-8").write(txt)
        p2.goto("file://" + tmp, wait_until="load"); p2.wait_for_timeout(300)
        a0 = p2.evaluate("[].findIndex.call(document.querySelectorAll('.rt-slide'), s => s.classList.contains('is-active'))")
        p2.wait_for_timeout(3600)
        a1 = p2.evaluate("[].findIndex.call(document.querySelectorAll('.rt-slide'), s => s.classList.contains('is-active'))")
        ok(a0 == 0 and a1 == 1, f"3 sn'de otomatik geçiş ({a0}→{a1})")
        if not touch:
            p2.hover(".rt-slider"); p2.wait_for_timeout(3600)
            a2 = p2.evaluate("[].findIndex.call(document.querySelectorAll('.rt-slide'), s => s.classList.contains('is-active'))")
            ok(a2 == a1, "fare üzerindeyken otomatik geçiş durur")
            p2.focus(".rt-slider"); p2.keyboard.press("ArrowRight"); a3 = p2.evaluate("[].findIndex.call(document.querySelectorAll('.rt-slide'), s => s.classList.contains('is-active'))")
            ok(a3 == (a1 + 1) % 3, "klavye → tuşu ile ileri")
        else:
            # dokunmatik kaydırma (sola: ileri)
            p2.evaluate("""(() => { const r = document.querySelector('.rt-slider');
              const mk = (t, x) => new TouchEvent(t, {bubbles: true, touches: t === 'touchend' ? [] : [new Touch({identifier: 1, target: r, clientX: x, clientY: 100})], changedTouches: [new Touch({identifier: 1, target: r, clientX: x, clientY: 100})]});
              r.dispatchEvent(mk('touchstart', 300)); r.dispatchEvent(mk('touchend', 120)); })()""")
            a3 = p2.evaluate("[].findIndex.call(document.querySelectorAll('.rt-slide'), s => s.classList.contains('is-active'))")
            ok(a3 == (a1 + 1) % 3, f"sola kaydırma ile ileri ({a1}→{a3})")
        ow = pg.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth")
        ok(ow <= 0, f"yatay taşma yok ({ow}px)")
        ok(not errs, f"JS hatası yok {errs[:2]}")
        # gerçek theme4 bileşenleri
        ok(pg.evaluate("!!document.querySelector('header.t4-header') && !!document.querySelector('.t4-home') && !!document.querySelector('footer, .t4-footer, .sm-footer')"), "gerçek theme4 header + home + footer çizildi")
        if len(sys.argv) > 3:
            pg.screenshot(path=sys.argv[3].replace(".png", f".{'mobile' if touch else 'desktop'}.png"), full_page=False)
        os.remove(tmp); ctx.close(); ctx2.close()
    b.close()
bad = [n for c, n in res if not c]
print(f"\nSLIDER: {len(res)-len(bad)} geçti, {len(bad)} başarısız")
sys.exit(1 if bad else 0)
