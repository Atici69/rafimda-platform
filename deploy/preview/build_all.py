#!/usr/bin/env python3
"""ÖNİZLEME DERLEYİCİ (geliştirme aracı): tüm modül ekranlarını ÖRNEK VERİYLE üretir ve Chromium ile masaüstü+mobil ekran görüntüsü alır.
Canlıya/veritabanına bağlanmaz. Kullanım: python3 deploy/preview/build_all.py <çıktı-dizini>"""
import os, subprocess, sys, json, re
root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
out = os.path.abspath(sys.argv[1]); pv = os.path.join(out, 'html'); shots = os.path.join(out, 'shots')
os.makedirs(pv, exist_ok=True); os.makedirs(shots, exist_ok=True)
def php(*a):
    r = subprocess.run(['php', *a], cwd=root, capture_output=True, text=True)
    if r.returncode != 0: print('HATA', a, r.stderr[-400:])
    return r.stdout
php('modules/xml-hub/tests/preview.php', pv)
php('modules/rafimda-vendor/tests/preview.php', pv)
php('modules/rafimda-theme/tests/preview.php', pv)
php('modules/rafimda-campaign/tests/preview.php', pv)
# Satıcı paneli: GERÇEK denetleyici + gerçek menü + SQLite örnek verisiyle (drive.php)
vdrive = os.path.join(root, 'modules/rafimda-vendor/tests/drive.php')
VS = ['dashboard', 'products', 'orders', 'stock', 'pricing', 'finance', 'store', 'customers', 'staff', 'subscription', 'campaigns', 'reports', 'settings']
def vend(name, cfg):
    o = subprocess.run(['php', vdrive, json.dumps(cfg)], cwd=os.path.dirname(vdrive), capture_output=True, text=True).stdout
    o = re.split(r'\n(?:REDIRECT|FLASH|PROBE\d+):', o)[0]
    if '<main class="rv-content">' in o: open(os.path.join(pv, 'vendor-' + name + '.html'), 'w', encoding='utf-8').write(o)
    else: print('UYARI: satıcı ekranı üretilemedi', name)
for sct in VS: vend(sct, {'get': {'s': sct}, 'campaign': 1, 'fee': 1})
vend('product-new', {'get': {'s': 'products', 'a': 'new'}}); vend('product-edit', {'get': {'s': 'products', 'a': 'edit', 'id': 1}}); vend('order-detail', {'get': {'s': 'orders', 'a': 'view', 'id': 1}})
php('deploy/preview/templates_preview.php', pv)
dealer = os.path.join(root, 'modules/rafimda-dealer/tests/drive.php')
def drive(name, *args, trim=True):
    o = subprocess.run(['php', dealer, *args], cwd=os.path.dirname(dealer), capture_output=True, text=True).stdout
    o = re.split(r'\n(?:REDIRECT|DEALERS|REQ|PAUSED|SESSION):', o)[0]
    open(os.path.join(pv, name + '.html'), 'w', encoding='utf-8').write(o)
drive('dealer-login', 'login_get'); drive('dealer-apply', 'apply_get')
for s in ['dashboard', 'customers', 'subdealers', 'earnings', 'subscription', 'reports', 'profile']:
    drive('dealer-panel-' + s, 'panel_section', s, 'top')
for t in ['applications', 'dealers', 'plans', 'subscriptions', 'commissions', 'reports', 'settings']:
    drive('dealer-admin-' + t + '.frag', 'admin', t)
man = []
def add(src, page, title, css):
    man.append({'src': os.path.join(pv, src + '.html'), 'out': os.path.join(pv, 'A-' + src.replace('.frag', '') + '.html'), 'page': page, 'title': title, 'css': css})
for v in ['list', 'new', 'suppliers', 'runs', 'errors', 'changes', 'conn_general', 'conn_mapping', 'conn_history']:
    add('xml-' + v, 'xml-hub', 'XML Hub', ['https://test.local/modules/xml-hub/assets/css/xh.css'])
for v in ['list', 'account', 'errors']:
    add('mh-' + v, 'marketplace-hub', 'Pazaryeri Hub', ['https://test.local/modules/marketplace-hub/assets/css/mh.css'])
add('showcase-admin', 'rafimda-showcase', 'Rafımda Vitrin', [])
add('vendor-admin-vendors', 'rafimda-vendor', 'Satıcılar (Rafımda)', ['https://test.local/modules/rafimda-vendor/assets/css/panel.css'])
add('vendor-admin-plans', 'rafimda-vendor', 'Satıcı paketleri', ['https://test.local/modules/rafimda-vendor/assets/css/panel.css'])
add('theme-admin', 'rafimda-theme', 'Rafımda Tema', [])
for t in ['campaigns', 'campaigns-edit', 'enrollments', 'charges', 'simulator']:
    add('campaign-admin-' + t, 'rafimda-campaign', 'Kampanyalar (Rafımda)', [])
for t in ['applications', 'dealers', 'plans', 'subscriptions', 'commissions', 'reports', 'settings']:
    add('dealer-admin-' + t + '.frag', 'rafimda-dealer', 'Bayiler (Rafımda)', [])
mp = os.path.join(out, 'manifest.json'); json.dump(man, open(mp, 'w'))
print(php('deploy/preview/admin_shell.php', mp).strip().count('ok'), 'yönetici sayfası')
files = sorted(os.path.join(pv, f) for f in os.listdir(pv) if f.startswith('A-') or f in ('home.html', 'home-empty.html', 'home-theme4.html', 'home-theme4-empty.html') or f.startswith('vendor-') and 'admin' not in f or f.startswith('dealer-') and 'admin' not in f)
r = subprocess.run(['python3', os.path.join(root, 'deploy/preview/shoot.py'), root, shots, 'ÖRNEK VERİ — önizleme (canlı veri değil)', *files], capture_output=True, text=True)
open(os.path.join(out, 'shoot-report.json'), 'w').write(r.stdout); print(r.stderr[-500:])
rep = json.loads(r.stdout); print(len(rep), 'görüntü; yatay taşma >0:', [x['page'] + '/' + x['view'] for x in rep if x['h_overflow_px'] > 0], '; JS hatası:', [x['page'] for x in rep if x['js_errors']])
