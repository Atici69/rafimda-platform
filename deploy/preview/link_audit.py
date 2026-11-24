#!/usr/bin/env python3
"""BAĞLANTI DENETİMİ (statik, geliştirme aracı): üretilen önizleme HTML'lerindeki bağlantıları gerçek yönlendirme tablosuna (çekirdek container/front,
Routes::legacyMap, modül $routes) göre çözer. HTTP isteği ATMAZ; sayfa içeriği/DB'ye bağlı dinamik adresleri (kategori/ürün/CMS) doğrulayamaz.
Kullanım: link_audit.py <repo-kökü> <html-dizini>"""
import os, re, sys, glob, json
from html import unescape
root, d = sys.argv[1], sys.argv[2]
core = {os.path.basename(f)[:-4] for f in glob.glob(root + '/container/front/*.php')}
src = open(root + '/core/Routes.php', encoding='utf-8').read()
legacy = set(re.findall(r"'([a-z0-9-]+)' => '[a-z0-9-]+'", src.split('legacyMap')[1].split('resolve')[0]))
mod = {}
for f in glob.glob(root + '/modules/*/*.php'):
    m = os.path.basename(os.path.dirname(f))
    if os.path.basename(f)[:-4] != m: continue
    t = open(f, encoding='utf-8').read()
    blk = re.search(r'\$routes\s*=\s*\[(.*?)\];', t, re.S)
    if blk:
        for s in re.findall(r"'([a-z0-9-]+)'\s*=>\s*'front/", blk.group(1)): mod[s] = m
dyn = {'kategori', 'urun', 'marka', 'sayfa', 'blog'}
stat = {'ok_core': 0, 'ok_module': 0, 'placeholder': 0, 'dynamic': 0, 'external': 0, 'file/asset': 0}
bad = []
seen = {}
for f in sorted(glob.glob(d + '/*.html')):
    h = open(f, encoding='utf-8', errors='ignore').read()
    for href in re.findall(r'<a\b[^>]*?href="([^"]*)"', h):
        href = unescape(href).strip()
        key = (os.path.basename(f), href)
        if key in seen: continue
        seen[key] = 1
        if href in ('', '#') or href.startswith('#') or href.startswith('javascript:') or href.startswith('?'): stat['placeholder'] += 1; continue
        if href.startswith('mailto:') or href.startswith('tel:'): stat['external'] += 1; continue
        if href.startswith('file://'): stat['placeholder'] += 1; continue
        m = re.match(r'^(?:https://test\.local|https://rafimda\.example)?/([^/?#]*)', href)
        if re.match(r'^https?://', href) and not m: stat['external'] += 1; continue
        if not m: bad.append((os.path.basename(f), href, 'çözümlenemedi')); continue
        seg = m.group(1)
        if seg == '' or seg in core or seg in legacy: stat['ok_core'] += 1
        elif seg in mod: stat['ok_module'] += 1
        elif seg in dyn: stat['dynamic'] += 1
        elif re.search(r'\.(css|js|png|jpg|svg|ico)$', seg): stat['file/asset'] += 1
        else: bad.append((os.path.basename(f), href, 'yönlendirme tablosunda yok'))
print(json.dumps({'benzersiz_bağlantı': len(seen), **stat, 'bilinmeyen': len(bad)}, ensure_ascii=False))
for b in bad: print('  YOK:', *b)
sys.exit(1 if bad else 0)
