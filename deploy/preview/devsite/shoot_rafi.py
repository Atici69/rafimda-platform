#!/usr/bin/env python3
"""GELİŞTİRME ÖNİZLEMESİ: Rafi sohbetini gerçek tarayıcıda çalıştırır (karşılama, arama, karşılaştırma, sonuçsuz arama) ve ekran görüntüsü alır."""
import sys, os, json
from playwright.sync_api import sync_playwright
base, out = sys.argv[1].rstrip('/'), sys.argv[2]
os.makedirs(out, exist_ok=True)
RIB = "(function(){var d=document.createElement('div');d.textContent='ÖRNEK VERİ – GELİŞTİRME ÖNİZLEMESİ (SQLite)';d.style.cssText='position:relative;z-index:2147483647;background:#b45309;color:#fff;font:600 12px/1.2 sans-serif;padding:5px 8px;text-align:center';document.body.insertBefore(d,document.body.firstChild)})()"
res = []
with sync_playwright() as pw:
    b = pw.chromium.launch()
    for kind, vp, mob in [("desktop", {"width": 1440, "height": 900}, False), ("mobile", {"width": 390, "height": 844}, True)]:
        ctx = b.new_context(viewport=vp, is_mobile=mob, has_touch=mob)
        pg = ctx.new_page(); errs = []
        pg.on("pageerror", lambda x: errs.append(str(x)))
        pg.on("console", lambda m: errs.append(m.text) if m.type == "error" and "Failed to load resource" not in m.text else None)
        pg.goto(base + "/", wait_until="networkidle")
        if pg.locator('button[name=choice][value=none]').count():
            pg.click('button[name=choice][value=none]'); pg.wait_for_load_state('networkidle')
        pg.evaluate("try{sessionStorage.clear()}catch(e){}"); pg.goto(base + "/", wait_until="networkidle"); pg.evaluate(RIB)
        pg.wait_for_selector('#rafiHello:not([hidden])', timeout=5000)
        pg.screenshot(path=f"{out}/1-karsilama.{kind}.png")
        res.append({"view": kind, "greeting_visible": pg.is_visible('#rafiHello')})
        pg.click('#rafiFab')
        def ask(t, n):
            before = pg.locator('.rafi-msg--bot').count()
            pg.fill('#rafiInput', t); pg.press('#rafiInput', 'Enter')
            pg.wait_for_function("n => document.querySelectorAll('.rafi-msg--bot:not(.rafi-wait)').length > n && !document.querySelector('.rafi-wait')", arg=before, timeout=10000)
            pg.wait_for_timeout(200)
            last = pg.locator('.rafi-msg--bot').last.inner_text()
            pg.screenshot(path=f"{out}/{n}.{kind}.png")
            return last
        r1 = ask('1000 TL altı kulaklık', '2-arama')
        cards = pg.locator('.rafi-card').count()
        r2 = ask('ilk ikisini karşılaştır', '3-karsilastirma')
        rows = pg.locator('.rafi-cmp tbody tr').count()
        r3 = ask('uçan halı', '4-sonucsuz')
        res[-1].update({"arama": r1, "kart": cards, "karsilastirma": r2, "tablo_satir": rows, "sonucsuz": r3, "js_errors": errs[:3]})
        ctx.close()
    b.close()
print(json.dumps(res, ensure_ascii=False, indent=1))
