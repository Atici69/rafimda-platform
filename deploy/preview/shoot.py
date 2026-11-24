#!/usr/bin/env python3
"""ÖNİZLEME EKRAN GÖRÜNTÜSÜ (geliştirme aracı): HTML dosyalarını Chromium ile masaüstü (1440) ve mobil (390) genişliğinde çeker.
https://test.local/ isteklerini depo köküne eşler (CSS/JS/görsel). Üst bantta "ÖRNEK VERİ" etiketi eklenir.
Kullanım: shoot.py <repo-kökü> <çıktı-dizini> <label> dosya.html [dosya.html ...]   (çıktı: <ad>.desktop.png, <ad>.mobile.png)
Ayrıca konsol hataları ve yatay taşma raporu verir."""
import sys, os, re, json
from playwright.sync_api import sync_playwright

root, out, label, files = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4:]
os.makedirs(out, exist_ok=True)
RIB = "(function(){var d=document.createElement('div');d.textContent=%s;d.style.cssText='position:relative;z-index:2147483647;background:#b45309;color:#fff;font:600 12px/1.2 sans-serif;padding:5px 8px;text-align:center';document.body.insertBefore(d,document.body.firstChild)})()" % json.dumps(label)
report = []
with sync_playwright() as pw:
    b = pw.chromium.launch()
    for f in files:
        name = os.path.splitext(os.path.basename(f))[0]
        for kind, vp, dsf in (("desktop", {"width": 1440, "height": 900}, 1), ("mobile", {"width": 390, "height": 844}, 2)):
            ctx = b.new_context(viewport=vp, device_scale_factor=dsf, is_mobile=(kind == "mobile"), has_touch=(kind == "mobile"))
            pg = ctx.new_page()
            errs = []
            pg.on("console", lambda m, e=errs: e.append(m.text) if m.type == "error" else None)
            pg.on("pageerror", lambda x, e=errs: e.append(str(x)))
            def handle(route):
                u = route.request.url
                if u.startswith("https://test.local/"):
                    p = os.path.join(root, u[len("https://test.local/"):].split("?")[0])
                    if os.path.isfile(p):
                        return route.fulfill(path=p)
                    return route.fulfill(status=404, body="")
                if u.startswith("file://") or u.startswith("data:"):
                    return route.continue_()
                return route.abort()  # dış ağ yok (CDN/font)
            ctx.route("**/*", handle)
            pg.goto("file://" + os.path.abspath(f), wait_until="load")
            pg.evaluate(RIB)
            pg.evaluate("document.querySelectorAll('.nav-accordion').forEach(function(a){a.classList.add('is-open');var t=a.querySelector('.nav-accordion__toggle');if(t)t.setAttribute('aria-expanded','true')})")
            pg.wait_for_timeout(250)
            ow = pg.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth")
            pg.screenshot(path=os.path.join(out, f"{name}.{kind}.png"), full_page=True)
            real = [e for e in errs if "Failed to load resource" not in e and "ERR_FAILED" not in e]
            report.append({"page": name, "view": kind, "h_overflow_px": ow, "js_errors": real[:3]})
            ctx.close()
    b.close()
print(json.dumps(report, ensure_ascii=False, indent=1))
