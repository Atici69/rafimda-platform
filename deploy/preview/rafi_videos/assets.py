"""Rafi Akademi çekimi için ÖRNEK ürün görselleri (özgün, basit SVG çizimler) ve Excel benzeri tablo sayfası."""

BG = {'tshirt': '#eef3fb', 'tshirt-back': '#eef3fb', 'tshirt-detail': '#f6efe6', 'jeans': '#e9eef7', 'case': '#eaf6f2',
      'headphones': '#f3eefb', 'mug': '#fbf1ea', 'sneaker': '#eef7fb', 'shirt': '#f7f1e6', 'shirt-back': '#f7f1e6'}


def _wrap(name, body):
    bg = BG.get(name, '#f1f4f9')
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400">'
            f'<rect width="400" height="400" fill="{bg}"/><ellipse cx="200" cy="350" rx="120" ry="14" fill="#000" opacity=".06"/>{body}</svg>')


def product_svg(name):
    shirt = ('<path d="M120 90l50-24h60l50 24 46 52-40 34-24-22v170H138V154l-24 22-40-34z" fill="{c}" stroke="#c9d3e3" stroke-width="3"/>'
             '<path d="M170 66q30 30 60 0" fill="none" stroke="#c9d3e3" stroke-width="4"/>')
    if name in ('tshirt', 'tshirt-back'):
        b = shirt.format(c='#ffffff')
        if name == 'tshirt':
            b += '<rect x="176" y="150" width="48" height="12" rx="6" fill="#ff7a00" opacity=".8"/>'
        return _wrap(name, b)
    if name == 'tshirt-detail':
        return _wrap(name, '<circle cx="200" cy="190" r="120" fill="#fff" stroke="#d8cdbf" stroke-width="3"/>'
                     + ''.join(f'<path d="M{90+i*14} 120 l60 140" stroke="#eadfd2" stroke-width="5"/>' for i in range(12))
                     + '<rect x="150" y="300" width="100" height="22" rx="11" fill="#0b2545"/><text x="200" y="316" font-family="Arial" font-size="13" fill="#fff" text-anchor="middle">%100 pamuk</text>')
    if name in ('shirt', 'shirt-back'):
        return _wrap(name, shirt.format(c='#e8dcc6') + '<path d="M200 70v250" stroke="#cdbb9b" stroke-width="3"/>'
                     + ''.join(f'<circle cx="200" cy="{110+i*40}" r="5" fill="#b9a37c"/>' for i in range(5)))
    if name == 'jeans':
        return _wrap(name, '<path d="M130 70h140l14 270h-62l-22-180-22 180h-62z" fill="#5b7db8" stroke="#3f5f96" stroke-width="3"/>'
                     '<path d="M130 100h140" stroke="#3f5f96" stroke-width="4"/><path d="M200 100v60" stroke="#c9d6ea" stroke-width="2" stroke-dasharray="6 5"/>')
    if name == 'case':
        return _wrap(name, '<rect x="130" y="50" width="140" height="290" rx="30" fill="#d9f1ea" stroke="#9fd6c4" stroke-width="5"/>'
                     '<rect x="150" y="70" width="58" height="58" rx="16" fill="#fff" stroke="#9fd6c4" stroke-width="4"/>'
                     '<circle cx="168" cy="90" r="9" fill="#0b2545"/><circle cx="190" cy="110" r="9" fill="#0b2545"/>')
    if name == 'headphones':
        return _wrap(name, '<path d="M110 220v-30a90 90 0 0 1 180 0v30" fill="none" stroke="#0b2545" stroke-width="16" stroke-linecap="round"/>'
                     '<rect x="90" y="200" width="50" height="90" rx="22" fill="#ff7a00"/><rect x="260" y="200" width="50" height="90" rx="22" fill="#ff7a00"/>')
    if name == 'mug':
        return _wrap(name, '<rect x="120" y="120" width="140" height="170" rx="18" fill="#fff" stroke="#e1cbb9" stroke-width="4"/>'
                     '<path d="M260 160h22a30 30 0 0 1 0 60h-22" fill="none" stroke="#e1cbb9" stroke-width="14"/>'
                     '<path d="M150 150h80" stroke="#ff7a00" stroke-width="6" stroke-linecap="round"/>')
    if name == 'sneaker':
        return _wrap(name, '<path d="M70 260q0-60 60-70l60-40q40 40 90 50l60 20q20 10 20 40v20H70z" fill="#fff" stroke="#9cb3c9" stroke-width="4"/>'
                     '<path d="M70 280h290v18H70z" fill="#0b2545"/><path d="M180 175l40 30M200 165l40 30" stroke="#ff7a00" stroke-width="6" stroke-linecap="round"/>')
    return _wrap(name, '<rect x="130" y="130" width="140" height="140" rx="20" fill="#dfe6f1"/>')


EXCEL = r'''<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>urunlerim.xlsx</title><style>
*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Roboto,Arial,sans-serif;background:#f3f3f3;color:#222}
.top{height:46px;background:#1d6f42;color:#fff;display:flex;align-items:center;padding:0 18px;font-size:15px;gap:14px}.top b{font-size:16px}
.rib{height:56px;background:#fff;border-bottom:1px solid #d6d6d6;display:flex;align-items:center;gap:22px;padding:0 18px;font-size:13px;color:#444}
.fx{height:32px;background:#fff;border-bottom:1px solid #d6d6d6;display:flex;align-items:center;font-size:13px;padding:0 10px;gap:10px;color:#666}
table{border-collapse:collapse;background:#fff;font-size:14px;margin:0}td,th{border:1px solid #dadada;height:34px;padding:0 8px;white-space:nowrap}
th{background:#f2f2f2;font-weight:500;color:#555;text-align:center}td.h{font-weight:700;background:#e8f2ec}th.r{width:38px}
td input{border:0;outline:0;font:inherit;width:100%;background:transparent}
</style></head><body>
<div class="top"><b>Excel</b> urunlerim.xlsx — Kaydedildi</div>
<div class="rib"><span>Giriş</span><span>Ekle</span><span>Sayfa Düzeni</span><span>Formüller</span><span>Veri</span><span>Gözden Geçir</span></div>
<div class="fx"><b>fx</b><span id="fxv"></span></div>
<table><tr><th class="r"></th>{HEAD}</tr>{ROWS}</table>
</body></html>'''


def excel_page(cols, rows, widths):
    letters = 'ABCDEFGHIJ'
    head = ''.join(f'<th style="width:{w}px">{letters[i]}</th>' for i, w in enumerate(widths))
    body = '<tr><th class="r">1</th>' + ''.join(f'<td class="h">{c}</td>' for c in cols) + '</tr>'
    for r in range(rows):
        body += f'<tr><th class="r">{r + 2}</th>' + ''.join(f'<td><input id="c{r}_{i}"></td>' for i in range(len(cols))) + '</tr>'
    for r in range(rows, rows + 8):
        body += f'<tr><th class="r">{r + 2}</th>' + '<td></td>' * len(cols) + '</tr>'
    return EXCEL.replace('{HEAD}', head).replace('{ROWS}', body)
