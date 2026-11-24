#!/usr/bin/env python3
"""GELİŞTİRME ÖNİZLEMESİ: devsite üzerindeki iç bağlantıları tarar (BFS, en fazla N sayfa) ve 4xx/5xx sonuçları raporlar."""
import sys, re, urllib.request, urllib.error, html, json
base = sys.argv[1].rstrip('/'); limit = int(sys.argv[2]) if len(sys.argv) > 2 else 250
seen, queue, bad, ok, titles = set(), [base + '/'], [], 0, {}
SKIP = re.compile(r'(logout|cikis|/api/|\.(jpg|jpeg|png|webp|svg|gif|ico|css|js|pdf|xml|json|woff2?)(\?|$)|#|mailto:|tel:|javascript:)', re.I)
while queue and len(seen) < limit:
    u = queue.pop(0)
    if u in seen: continue
    seen.add(u)
    try:
        r = urllib.request.urlopen(urllib.request.Request(u, headers={'User-Agent': 'rafimda-crawl'}), timeout=20)
        code, body = r.status, r.read().decode('utf-8', 'ignore'); ok += 1
    except urllib.error.HTTPError as e:
        code, body = e.code, ''
    except Exception as e:
        code, body = 0, ''
    if code >= 400 or code == 0:
        bad.append((code, u)); continue
    t = re.search(r'<title>([^<]*)', body); titles[u[len(base):]] = html.unescape(t.group(1)).strip() if t else '(başlık yok)'
    for h in re.findall(r'href="([^"]+)"', body):
        h = html.unescape(h)
        if h.startswith('/'): h = base + h
        if not h.startswith(base) or SKIP.search(h): continue
        h = h.split('#')[0]
        if h not in seen and h not in queue: queue.append(h)
home = titles.get('/')
same = sorted(k for k, v in titles.items() if v == home and k not in ('/', '/index.php'))
print(json.dumps({'checked': len(seen), 'ok': ok, 'broken': bad, 'ana_sayfa_basligi_ile_ayni': same, 'titles': titles}, ensure_ascii=False, indent=1))
