#!/usr/bin/env python3
"""GELİŞTİRME ÖNİZLEMESİ: devsite (php -S) üzerindeki GERÇEK sayfaları Chromium ile masaüstü/tablet/mobil çeker; konsol hatası,
HTTP durum kodu, yatay taşma ve kırık iç bağlantıları raporlar. Kullanım: shoot_live.py BASE_URL OUT_DIR path1 path2 ...
Üst bantta 'ÖRNEK VERİ – GELİŞTİRME ÖNİZLEMESİ' etiketi bulunur (veriler gerçek mağaza verisi değildir)."""
import sys, os, json, re
from playwright.sync_api import sync_playwright
args = sys.argv[1:]
login = None
if args and args[0].startswith('--login='):
    login = args.pop(0).split('=', 1)[1]  # front:EMAIL:PASS veya admin:EMAIL:PASS (yalnızca devsite TEST hesapları)
base, out, paths = args[0].rstrip('/'), args[1], args[2:]
os.makedirs(out, exist_ok=True)
VIEWS = [("desktop", {"width": 1440, "height": 900}, False), ("tablet", {"width": 820, "height": 1180}, True), ("mobile", {"width": 390, "height": 844}, True)]
RIB = "(function(){var d=document.createElement('div');d.textContent='ÖRNEK VERİ – GELİŞTİRME ÖNİZLEMESİ (SQLite)';d.style.cssText='position:relative;z-index:2147483647;background:#b45309;color:#fff;font:600 12px/1.2 sans-serif;padding:5px 8px;text-align:center';document.body.insertBefore(d,document.body.firstChild)})()"
report = []
with sync_playwright() as pw:
    b = pw.chromium.launch()
    for p in paths:
        name = re.sub(r'[^a-z0-9]+', '-', p.strip('/').lower()) or 'home'
        for kind, vp, mob in VIEWS:
            ctx = b.new_context(viewport=vp, device_scale_factor=1, is_mobile=mob, has_touch=mob)
            pg = ctx.new_page()
            errs, bad = [], []
            pg.on("console", lambda m, e=errs: e.append(m.text) if m.type == "error" else None)
            pg.on("pageerror", lambda x, e=errs: e.append(str(x)))
            pg.on("response", lambda r, bb=bad: bb.append(f"{r.status} {r.url}") if r.status >= 400 and base in r.url else None)
            ctx.route(re.compile(r'^(?!http://127\.0\.0\.1).*'), lambda r: r.abort())  # dış ağ yok
            if login:
                kind_l, em, pw_ = login.split(':', 2)
                if kind_l == 'admin':
                    pg.goto(base + '/admin/login'); pg.fill('#adminLoginEmail', em); pg.fill('#adminLoginPassword', pw_)
                    pg.click('.admin-login-form [type=submit]')
                else:
                    pg.goto(base + '/login'); pg.fill('#loginPagePhone', em); pg.fill('#loginPagePassword', pw_)
                    pg.click('#loginPageForm [type=submit]')
                pg.wait_for_load_state('networkidle')
                errs.clear(); bad.clear()
            resp = pg.goto(base + p, wait_until="networkidle")
            if os.environ.get('RF_COOKIE') == 'none' and pg.locator('button[name=choice][value=none]').count():
                pg.click('button[name=choice][value=none]'); pg.wait_for_load_state('networkidle')
                resp = pg.goto(base + p, wait_until="networkidle")  # çerez tercihi kaydedildikten sonra sayfa yeniden
            pg.evaluate(RIB)
            pg.wait_for_timeout(400)
            ow = pg.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth")
            pg.screenshot(path=os.path.join(out, f"{name}.{kind}.png"), full_page=True)
            real = [e for e in errs if "Failed to load resource" not in e and "ERR_FAILED" not in e and "ServiceWorker" not in e]
            report.append({"page": p, "view": kind, "status": resp.status if resp else 0, "h_overflow_px": ow, "js_errors": real[:3], "bad_requests": sorted(set(bad))[:5]})
            ctx.close()
    b.close()
print(json.dumps(report, ensure_ascii=False, indent=1))
