#!/usr/bin/env python3
"""RAFI AKADEMİ VİDEO STÜDYOSU (geliştirme aracı; canlıda gerekmez).

Satıcı paneli, XML Merkezi ve Pazaryeri ekranlarını GERÇEK şablon/denetleyicilerle ÖRNEK veriyle üretir,
Playwright ile imleç, vurgulama ve Rafi altyazısıyla oynatıp kaydeder, ffmpeg ile MP4 + kapak görseli çıkarır.
Canlı siteye/veritabanına bağlanmaz. Ses yoktur (altyazılı).

Kullanım: python3 deploy/preview/rafi_videos/director.py [video-anahtarı ...]
Çıktı: modules/rafimda-rafi/assets/video/<anahtar>.mp4 / .jpg ve videos.json
"""
import json, os, re, shutil, subprocess, sys, tempfile, time
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
sys.path.insert(0, HERE)
from assets import product_svg, excel_page  # noqa: E402

WORK = os.path.join(tempfile.gettempdir(), 'rafi_studio')
PAGES = os.path.join(WORK, 'p')
OUT = os.path.join(ROOT, 'modules', 'rafimda-rafi', 'assets', 'video')
FFMPEG = shutil.which('ffmpeg') or 'ffmpeg'


# ------------------------------------------------------------------ sayfalar
def vendor(name, get, **cfg):
    cfg.update({'rich': 1, 'get': get})
    drive = os.path.join(ROOT, 'modules', 'rafimda-vendor', 'tests', 'drive.php')
    o = subprocess.run(['php', drive, json.dumps(cfg, ensure_ascii=False)], cwd=os.path.dirname(drive), capture_output=True, text=True).stdout
    html = re.split(r'\n(?:REDIRECT|FLASH|PROBE\d+):', o)[0]
    if '<main class="rv-content">' not in html:
        raise SystemExit('satıcı ekranı üretilemedi: ' + name + '\n' + o[-500:])
    open(os.path.join(PAGES, 'v-' + name + '.html'), 'w', encoding='utf-8').write(html)


def build_pages():
    if os.path.isdir(WORK):
        shutil.rmtree(WORK)
    os.makedirs(PAGES)
    ok = lambda m: {'type': 'success', 'message': m}
    gomlek = [['Keten Gömlek — Bej', 'Kadın > Giyim > Gömlek', 'Moda Evi', 349.90, 25, 'ME-GM-101', 'pending', 'shirt']]
    vendor('dashboard', {'s': 'dashboard'})
    vendor('products', {'s': 'products'})
    vendor('new', {'s': 'products', 'a': 'new'})
    vendor('products-added', {'s': 'products'}, extra=gomlek, flash=ok('Ürün eklendi; yönetici onayından sonra yayına girecek.'))
    vendor('import', {'s': 'products', 'a': 'import'})
    log = "INSERT INTO rafimda_import_log (id_vendor, id_user, file_name, rows_total, rows_created, rows_updated, rows_failed, date_add) VALUES (1, 1, 'urunlerim.xlsx', 3, 3, 0, 0, datetime('now'))"
    vendor('import-done', {'s': 'products', 'a': 'import'}, sql=[log], flash=ok('3 yeni, 0 güncellenen, 0 hatalı satır.'))
    vendor('edit', {'s': 'products', 'a': 'edit', 'id': 4})
    vendor('edit-cover', {'s': 'products', 'a': 'edit', 'id': 4}, sql=['UPDATE images SET cover = 0 WHERE id_product = 4', 'UPDATE images SET cover = 1 WHERE id_image = 7'], flash=ok('Kapak görseli güncellendi'))
    vendor('edit-deleted', {'s': 'products', 'a': 'edit', 'id': 4}, sql=['UPDATE images SET cover = 0 WHERE id_product = 4', 'UPDATE images SET cover = 1 WHERE id_image = 7', 'DELETE FROM images WHERE id_image = 8'], flash=ok('Görsel silindi'))
    vendor('edit-saved', {'s': 'products', 'a': 'edit', 'id': 4}, sql=['UPDATE images SET cover = 0 WHERE id_product = 4', 'UPDATE images SET cover = 1 WHERE id_image = 7', 'DELETE FROM images WHERE id_image = 8'], flash=ok('Ürün güncellendi.'))
    vendor('stock', {'s': 'stock'})
    vendor('stock-saved', {'s': 'stock'}, sql=["UPDATE products SET stock = 20 WHERE stock_code = 'SP-KA-090'"], flash=ok('1 ürün güncellendi.'))
    vendor('pricing', {'s': 'pricing'})
    vendor('pricing-saved', {'s': 'pricing'}, sql=["UPDATE products SET price = 134.91 WHERE stock_code = 'RB-TS-001'", "UPDATE products SET price = 359.91 WHERE stock_code = 'ME-JN-014'"], flash=ok('2 ürün güncellendi.'))
    vendor('support', {'s': 'support'})
    for script, prefix in (('modules/xml-hub/tests/video_screens.php', 'xml-'), ('modules/marketplace-hub/tests/video_screens.php', 'mh-')):
        tmp = os.path.join(WORK, prefix.strip('-'))
        r = subprocess.run(['php', os.path.join(ROOT, script), tmp], cwd=ROOT, capture_output=True, text=True)
        if r.returncode != 0:
            raise SystemExit(r.stdout[-400:] + r.stderr[-400:])
        for f in os.listdir(tmp):
            if f.endswith('.html'):
                shutil.copy(os.path.join(tmp, f), os.path.join(PAGES, f))
    excel = excel_page(['urun_adi', 'kategori', 'marka', 'fiyat', 'stok', 'stok_kodu', 'kdv'], 3, [230, 250, 120, 80, 60, 110, 50])
    open(os.path.join(PAGES, 'excel.html'), 'w', encoding='utf-8').write(excel)
    shutil.copy(os.path.join(HERE, 'stage.html'), os.path.join(WORK, 'stage.html'))
    print(len(os.listdir(PAGES)), 'sayfa hazır')


# ------------------------------------------------------------------ yardımcılar
class Shot:
    def __init__(self, page):
        self.p = page

    def js(self, fn, *args):
        return self.p.evaluate('([f,a]) => S[f](...a)', [fn, list(args)])

    def go(self, page, scroll=None):
        self.js('go', 'https://studio.local/p/' + page + '.html', scroll)

    def say(self, text, step='', hold=None):
        self.js('say', text, step)
        # okuma süresi: ~19 karakter/sn (altyazı yazılırken de okunur), en az 1.7 sn
        self.wait(hold if hold is not None else max(1700, len(text) * 52))

    def wait(self, ms):
        self.p.wait_for_timeout(ms)

    def __getattr__(self, name):
        return lambda *a: self.js(name, *a)


def record(key, title, sub, fn):
    vids = os.path.join(WORK, 'rec_' + key)
    os.makedirs(vids, exist_ok=True)
    imgmap = json.load(open(os.path.join(tempfile.gettempdir(), 'rv_video_images.json')))
    with sync_playwright() as pw:
        b = pw.chromium.launch(args=['--lang=tr-TR'])
        ctx = b.new_context(viewport={'width': 1280, 'height': 720}, record_video_dir=vids, record_video_size={'width': 1280, 'height': 720}, locale='tr-TR')

        def handle(route):
            u = route.request.url
            if u.startswith('https://studio.local/img/products/'):
                iid = re.sub(r'\D', '', u.rsplit('/', 1)[1])
                return route.fulfill(status=200, body=product_svg(imgmap.get(iid, '')), headers={'Content-Type': 'image/svg+xml'})
            if u.startswith('https://studio.local/'):
                path = u[len('https://studio.local/'):].split('?')[0]
                f = os.path.join(WORK, path)
                return route.fulfill(path=f) if os.path.isfile(f) else route.fulfill(status=404, body='')
            if u.startswith('https://test.local/'):
                f = os.path.join(ROOT, u[len('https://test.local/'):].split('?')[0])
                return route.fulfill(path=f) if os.path.isfile(f) else route.fulfill(status=404, body='')
            return route.abort()
        ctx.route('**/*', handle)
        page = ctx.new_page()
        page.on('pageerror', lambda e: print('  JS hatası:', e))
        t0 = time.time()
        page.goto('https://studio.local/stage.html', wait_until='load')
        s = Shot(page)
        s.title(title, sub)
        s.wait(3200)
        fn(s)
        page.wait_for_timeout(3600)
        dur = time.time() - t0
        vpath = page.video.path()
        page.close(); ctx.close(); b.close()
    os.makedirs(OUT, exist_ok=True)
    mp4 = os.path.join(OUT, key + '.mp4')
    subprocess.run([FFMPEG, '-y', '-loglevel', 'error', '-ss', '0.25', '-i', vpath, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '27', '-pix_fmt', 'yuv420p', '-r', '25', '-movflags', '+faststart', '-an', mp4], check=True)
    subprocess.run([FFMPEG, '-y', '-loglevel', 'error', '-ss', '1.6', '-i', mp4, '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '4', os.path.join(OUT, key + '.jpg')], check=True)
    secs = float(subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', mp4], capture_output=True, text=True).stdout.strip() or dur)
    print(f'{key}: {secs:.1f} sn, {os.path.getsize(mp4) / 1e6:.1f} MB')
    return round(secs)


# ------------------------------------------------------------------ senaryolar
def v_urun(s):
    s.go('v-dashboard'); s.untitle()
    s.say('Merhaba, ben Rafi! Birlikte mağazanıza ilk ürününüzü ekleyelim.', hold=2600)
    s.ring('.rv-nav a::text(Ürün Yönetimi)'); s.click('.rv-nav a::text(Ürün Yönetimi)')
    s.say('Sol menüden “Ürün Yönetimi”ne girin.', '1. adım'); s.unring()
    s.go('v-products')
    s.ring('a.rv-btn::text(+ Yeni ürün)'); s.click('a.rv-btn::text(+ Yeni ürün)')
    s.say('Sağ üstteki turuncu “+ Yeni ürün” düğmesine tıklayın.', '2. adım'); s.unring()
    s.go('v-new')
    s.click('input[name=product_name]'); s.type('input[name=product_name]', 'Keten Gömlek — Bej')
    s.say('Ürün adını, müşterinin arama kutusuna yazacağı gibi açık ve net yazın.', '3. adım')
    s.ring('select[name=id_category]'); s.click('select[name=id_category]'); s.select('select[name=id_category]', 'Gömlek')
    s.say('Kategoriyi seçin. Doğru kategori, ürününüzün aramada ve filtrede bulunmasını sağlar.', '4. adım')
    s.ring('select[name=id_brand]'); s.click('select[name=id_brand]'); s.select('select[name=id_brand]', 'Moda Evi')
    s.say('Markayı seçin.', '4. adım'); s.unring()
    s.click('input[name=price]'); s.type('input[name=price]', '349,90')
    s.click('input[name=vat]'); s.type('input[name=vat]', '10')
    s.click('input[name=stock]'); s.type('input[name=stock]', '25')
    s.click('input[name=stock_code]'); s.type('input[name=stock_code]', 'ME-GM-101')
    s.say('Satış fiyatını KDV dahil yazın; KDV oranını, stok adedini ve kendi stok kodunuzu girin.', '5. adım')
    s.scrollTo('h3::text(Görseller)', 200)
    s.ring('.fakefile'); s.click('.fakefile'); s.pick('.fakefile', 'gomlek-on.jpg, gomlek-arka.jpg, gomlek-detay.jpg')
    s.say('“Görseller”den ürün fotoğraflarınızı seçin: en fazla 10 görsel, açık zemin ve net ışık en iyisidir.', '6. adım'); s.unring()
    s.ring('button.rv-btn::text(Kaydet)'); s.click('button.rv-btn::text(Kaydet)')
    s.say('Son olarak “Kaydet”e basın.', '7. adım'); s.unring()
    s.go('v-products-added')
    s.ring('.rv-alert')
    s.say('Ürününüz eklendi! Yönetici onayından sonra mağazada yayına girer.', hold=3000); s.unring()
    s.end('Tebrikler, ilk ürününüz hazır!', 'Takıldığınız her yerde sayfanın altındaki Rafi\'ye tıklayın.')


def v_excel(s):
    s.go('v-products'); s.untitle()
    s.say('Çok sayıda ürününüz mü var? Excel ile hepsini tek seferde yükleyelim.', hold=2600)
    s.ring('a.rv-btn::text(Excel / CSV yükle)'); s.click('a.rv-btn::text(Excel / CSV yükle)')
    s.say('Ürün Yönetimi’nde “Excel / CSV yükle” düğmesine tıklayın.', '1. adım'); s.unring()
    s.go('v-import')
    s.ring('a.rv-btn::text(Excel şablonunu indir)'); s.click('a.rv-btn::text(Excel şablonunu indir)')
    s.say('“Excel şablonunu indir”e tıklayın; doğru sütunlar hazır gelir.', '2. adım'); s.unring()
    s.go('excel')
    s.say('Her satıra bir ürün yazın. Kategoriyi numara değil, ADIYLA yazabilirsiniz.', '3. adım', hold=1800)
    rows = [['Keten Gömlek — Bej', 'Kadın > Giyim > Gömlek', 'Moda Evi', '349,90', '25', 'ME-GM-101', '10'],
            ['Seramik Kupa Seti', 'Ev & Yaşam > Mutfak > Bardak', 'EvGüzeli', '219,00', '40', 'EG-KP-022', '20'],
            ['Silikon Telefon Kılıfı', 'Telefon Aksesuarları > Kılıf', 'TeknoPlus', '89,90', '150', 'TP-KL-210', '20']]
    for r, row in enumerate(rows):
        for c, v in enumerate(row):
            s.move(f'#c{r}_{c}', None, None, 260 if c else 450)
            s.type(f'#c{r}_{c}', v, 40)
    s.ring('#c2_1', 4)
    s.say('“Telefon Aksesuarları > Kılıf” gibi kendi tedarikçi adlarınızı da yazabilirsiniz; Rafımda doğru kategoriye otomatik eşler.', '3. adım'); s.unring()
    s.say('Sitede olmayan bir marka yazarsanız otomatik oluşturulur. Dosyayı kaydedin.', '4. adım')
    s.go('v-import')
    s.ring('.fakefile'); s.click('.fakefile'); s.pick('.fakefile', 'urunlerim.xlsx')
    s.say('Kaydettiğiniz dosyayı seçin…', '5. adım'); s.unring()
    s.ring('button.rv-btn::text(Yükle ve işle)'); s.click('button.rv-btn::text(Yükle ve işle)')
    s.say('…ve “Yükle ve işle”ye basın.', '5. adım'); s.unring()
    s.go('v-import-done')
    s.ring('.rv-alert')
    s.say('Bitti: 3 yeni ürün eklendi. Hatalı satır olursa satır numarasıyla burada yazar.', hold=3000); s.unring()
    s.scrollTo('h3::text(Son yüklemeler)', 260); s.ring('h3::text(Son yüklemeler)')
    s.say('Tüm yüklemelerinizi “Son yüklemeler” bölümünden takip edebilirsiniz.'); s.unring()
    s.end('Excel ile toplu yükleme bu kadar kolay!', 'Aynı stok koduyla tekrar yüklerseniz fiyat ve stok güncellenir.')


def v_xml(s):
    s.go('v-products'); s.untitle()
    s.say('Tedarikçinizin XML adresi mi var? Ürünleri otomatik çekip güncel tutalım.', hold=2600)
    s.ring('.rv-nav a::text(XML Merkezi)'); s.click('.rv-nav a::text(XML Merkezi)')
    s.say('Sol menüden “XML Merkezi”ne girin.', '1. adım'); s.unring()
    s.go('xml-list-empty')
    s.ring('a::text(Yeni XML)'); s.click('a::text(Yeni XML)')
    s.say('“Yeni XML” sekmesine tıklayın.', '2. adım'); s.unring()
    s.go('xml-new')
    s.click('input[name=name]'); s.type('input[name=name]', 'Örnek Tedarikçi — Ürün XML')
    s.click('input[name=url]'); s.type('input[name=url]', 'https://xml.ornek-tedarikci.com/urunler.xml', 26)
    s.say('Bağlantıya bir ad verin ve tedarikçinizin size verdiği XML adresini yapıştırın.', '3. adım')
    s.ring('select[name=interval_minutes]'); s.click('select[name=interval_minutes]'); s.select('select[name=interval_minutes]', '6 saatte bir')
    s.say('Otomatik güncelleme sıklığını seçin; fiyat ve stoklar bu aralıkla yenilenir.', '4. adım'); s.unring()
    s.scrollTo('label::text(Kâr oranı)', 170)
    s.click('input[name="opt[price_markup_percent]"]'); s.type('input[name="opt[price_markup_percent]"]', '40')
    s.ring('select[name="opt[vat_mode]"]'); s.click('select[name="opt[vat_mode]"]'); s.select('select[name="opt[vat_mode]"]', 'KDV ekle')
    s.say('Kâr oranınızı yazın. XML fiyatları KDV hariçse “KDV hariç geliyor: KDV ekle”yi seçin.', '5. adım'); s.unring()
    s.ring('p.muted::text(Hesap sırası)')
    s.say('Örnek: KDV hariç 100 ₺, %40 kâr ve %20 KDV ile satış fiyatı 168 ₺ olur.', '5. adım'); s.unring()
    s.ring('button::text(Oluştur ve test et)'); s.click('button::text(Oluştur ve test et)')
    s.say('“Oluştur ve test et”e basın; Rafımda XML’i hemen okur.', '6. adım'); s.unring()
    s.go('xml-mapping-suggest')
    s.ring('.alert, .xh-alert, [class*=alert]')
    s.say('Bağlantı başarılı: 8 ürün bulundu.', hold=2200); s.unring()
    s.scrollTo('h3::text(Alanlar)', 140); s.ring('input[name="fields[name]"]')
    s.say('Alanları sizin için tahmin ettim: ürün adı, fiyat, stok, marka, kategori, görsel… Kontrol etmeniz yeterli.', '7. adım'); s.unring()
    s.scrollTo('button::text(Eşleştirmeyi kaydet)', 420); s.ring('button::text(Eşleştirmeyi kaydet)'); s.click('button::text(Eşleştirmeyi kaydet)')
    s.say('“Eşleştirmeyi kaydet”e basın.', '7. adım'); s.unring()
    s.go('xml-preview')
    s.ring('a::text(Önizleme ve içe aktarma)')
    s.say('“Önizleme ve içe aktarma” sekmesinde ürünlerin sitede nasıl görüneceğini görürsünüz.', '8. adım'); s.unring()
    s.ring('th::text(Fiyat)')
    s.say('Fiyatlar kâr ve KDV eklenmiş hâliyle gelir. Hatalı satırlar kırmızıyla işaretlenir.', '8. adım'); s.unring()
    s.ring('button::text(Tümünü içe aktar)'); s.click('button::text(Tümünü içe aktar)')
    s.say('“Tümünü içe aktar”a basın.', '9. adım'); s.unring()
    s.go('xml-history')
    s.ring('tbody tr')
    s.say('7 ürün eklendi, 1 ürünün kategorisi netleşmedi. Bunu “Kategori eşleştirme” videosunda tek tıkla çözüyoruz.', hold=3600); s.unring()
    s.end('XML bağlantınız hazır!', 'Fiyat ve stoklar seçtiğiniz aralıkta otomatik güncellenir.')


def v_kategori(s):
    s.go('xml-preview'); s.untitle()
    s.say('İçe aktarmada bir ürün “Kategori eşleşmedi” hatası mı verdi? Birlikte çözelim.', hold=2600)
    s.ring('tr::text(Unisex Mont)')
    s.say('Tedarikçi bu ürünün kategorisini yalnızca “Mont” yazmış. Kadın mı, erkek mi belli değil.', hold=3200); s.unring()
    s.say('Rafımda kategorilerin çoğunu otomatik eşler; emin olamadığında ürünü yanlış yere koymaz, size sorar.', hold=3800)
    s.ring('a::text(Marka/Kategori)'); s.click('a::text(Marka/Kategori)')
    s.say('“Marka/Kategori” sekmesine geçin.', '1. adım'); s.unring()
    s.go('xml-maps')
    s.ring('.pill::text(öneri)')
    s.say('Eşleşmeyen her kategori, en iyi önerimle seçili gelir.', '2. adım'); s.unring()
    s.ring('select[name^="map_category"]'); s.click('select[name^="map_category"]'); s.select('select[name^="map_category"]', 'Erkek > Giyim > Mont')
    s.say('Öneriyi kontrol edin; gerekirse doğru kategoriyi seçin. Burada “Erkek > Giyim > Mont”.', '2. adım'); s.unring()
    s.ring('button::text(Eşleştirmeleri kaydet)'); s.click('button::text(Eşleştirmeleri kaydet)')
    s.say('“Eşleştirmeleri kaydet”e basın. Bu eşleme kalıcıdır; bir dahaki sefere sormam.', '3. adım'); s.unring()
    s.go('xml-maps-saved')
    s.ring('.alert, [class*=alert]')
    s.say('Kaydedildi. Şimdi hatalı satırı yeniden deneyelim.', hold=2400); s.unring()
    s.go('xml-general-after')
    s.scrollTo('button::text(Hatalıları yeniden dene)', 300); s.ring('button::text(Hatalıları yeniden dene)'); s.click('button::text(Hatalıları yeniden dene)')
    s.say('“Genel” sekmesinde “Hatalıları yeniden dene”ye basın; XML tekrar indirilmez.', '4. adım'); s.unring()
    s.go('xml-history-retry')
    s.ring('tbody tr')
    s.say('Hazır: kalan ürün de doğru kategoriyle eklendi.', hold=2800); s.unring()
    s.end('Kategoriler artık otomatik!', 'Excel yüklemelerinde de aynı akıllı eşleştirme çalışır.')


def v_gorsel(s):
    s.go('v-products'); s.untitle()
    s.say('Ürün görsellerini eklemeyi, kapak seçmeyi ve silmeyi gösteriyorum.', hold=2600)
    s.ring('tr::text(Pamuklu Basic) a.rv-btn'); s.click('tr::text(Pamuklu Basic) a.rv-btn')
    s.say('Ürün Yönetimi’nde ürünün yanındaki “Düzenle” düğmesine tıklayın.', '1. adım'); s.unring()
    s.go('v-edit')
    s.scrollTo('h3::text(Mevcut görseller)', 120); s.ring('.rv-imgs')
    s.say('“Mevcut görseller”de ürünün tüm fotoğrafları görünür. Turuncu çerçeveli olan kapak görselidir.', '2. adım'); s.unring()
    s.ring('.rv-imgs figure:nth-child(2) button::text(Kapak)'); s.click('.rv-imgs figure:nth-child(2) button::text(Kapak)')
    s.say('Başka bir görseli ana görsel yapmak için altındaki “Kapak”a basın.', '3. adım'); s.unring()
    s.go('v-edit-cover', 0); s.scrollTo('h3::text(Mevcut görseller)', 120)
    s.say('Kapak görseli güncellendi; listede ve mağazada artık bu görünür.', hold=2600)
    s.ring('.rv-imgs figure:last-child button::text(Sil)'); s.click('.rv-imgs figure:last-child button::text(Sil)')
    s.say('İstemediğiniz görseli “Sil” ile kaldırın.', '4. adım'); s.unring()
    s.go('v-edit-deleted', 0); s.scrollTo('h3::text(Mevcut görseller)', 120)
    s.say('Görsel silindi.', hold=1800)
    s.go('v-edit-deleted', 0); s.scrollTo('h3::text(Görseller)', 200)
    s.ring('.fakefile'); s.click('.fakefile'); s.pick('.fakefile', 'tisort-yeni-1.jpg, tisort-yeni-2.jpg')
    s.say('Yeni görsel eklemek için “Görseller”den dosyaları seçin (JPG, PNG veya WEBP; her biri en fazla 5 MB).', '5. adım'); s.unring()
    s.ring('button.rv-btn::text(Kaydet)'); s.click('button.rv-btn::text(Kaydet)')
    s.say('“Kaydet”e basın.', '5. adım'); s.unring()
    s.go('v-edit-saved')
    s.ring('.rv-alert')
    s.say('Görselleriniz güncellendi. XML ürünlerinde görseller XML Merkezi ayarlarındaki “Görsel modu”na göre gelir.', hold=3600); s.unring()
    s.end('Görselleriniz hazır!', 'İpucu: açık zemin ve net ışıklı fotoğraflar daha çok satar.')


def v_fiyat(s):
    s.go('v-dashboard'); s.untitle()
    s.say('Stok bitti ya da fiyat değişti mi? Toplu ve tek tek güncellemeyi gösteriyorum.', hold=2600)
    s.ring('.rv-nav a::text(Stok ve Depo)'); s.click('.rv-nav a::text(Stok ve Depo)')
    s.say('Sol menüden “Stok ve Depo”ya girin.', '1. adım'); s.unring()
    s.go('v-stock')
    s.ring('tr::text(Hafif Koşu)')
    s.say('Stokta olmayan ürünler 0 ile görünür.', hold=2000); s.unring()
    s.click('tr::text(Hafif Koşu) input[name*=stock]'); s.type('tr::text(Hafif Koşu) input[name*=stock]', '20')
    s.say('“Yeni stok” sütununa yeni adedi yazın. Boş bıraktığınız satırlar değişmez.', '2. adım')
    s.ring('button::text(Girilen stokları kaydet)'); s.click('button::text(Girilen stokları kaydet)')
    s.say('“Girilen stokları kaydet”e basın.', '2. adım'); s.unring()
    s.go('v-stock-saved'); s.ring('.rv-alert')
    s.say('Stok güncellendi.', hold=1800); s.unring()
    s.ring('.rv-nav a::text(Fiyat Yönetimi)'); s.click('.rv-nav a::text(Fiyat Yönetimi)')
    s.say('Fiyatlar için “Fiyat Yönetimi”ne geçin.', '3. adım'); s.unring()
    s.go('v-pricing')
    for name in ('Pamuklu Basic', 'Mom Jean'):
        s.click(f'tr::text({name}) input[type=checkbox]', True)
    s.say('Toplu değişiklik için ürünleri işaretleyin…', '4. adım', hold=1800)
    s.click('input[name=percent]'); s.type('input[name=percent]', '-10')
    s.ring('button::text(Uygula)'); s.click('button::text(Uygula)')
    s.say('…yüzdeyi yazıp “Uygula”ya basın. Örneğin -10 yazarsanız %10 indirim yapılır, 5 yazarsanız %5 zam.', '4. adım'); s.unring()
    s.go('v-pricing-saved'); s.ring('.rv-alert')
    s.say('İki ürünün fiyatı güncellendi. Tek bir ürün için “Yeni fiyat” sütununa yazıp kaydetmeniz yeterli.', hold=3400); s.unring()
    s.say('XML’den gelen ürünlerin fiyat ve stoğu tedarikçi akışıyla otomatik güncellenir; pazaryerlerine de aktarılır.', hold=3600)
    s.end('Fiyat ve stok güncel!', 'Stoğu azalan ürünler Genel Bakış’ta uyarı olarak görünür.')


def v_trendyol(s):
    s.go('v-dashboard'); s.untitle()
    s.say('Trendyol mağazanızı Rafımda’ya bağlayıp ürünlerinizi eşleştirelim.', hold=2600)
    s.ring('.rv-nav a::text(Pazaryerleri)'); s.click('.rv-nav a::text(Pazaryerleri)')
    s.say('Sol menüden “Pazaryerleri”ne girin.', '1. adım'); s.unring()
    s.go('mh-list-empty')
    s.ring('summary::text(Trendyol)'); s.click('summary::text(Trendyol)', True)
    s.say('“Yeni pazaryeri hesabı” altında Trendyol’u açın.', '2. adım'); s.unring()
    f = 'details[open] '
    s.click(f + 'input[name=label]'); s.type(f + 'input[name=label]', 'Trendyol — Moda Evi')
    s.click(f + 'input[name="cred[supplier_id]"]'); s.type(f + 'input[name="cred[supplier_id]"]', '123456')
    s.click(f + 'input[name="cred[api_key]"]'); s.type(f + 'input[name="cred[api_key]"]', 'xxxxxxxxxxxx', 22)
    s.click(f + 'input[name="cred[api_secret]"]'); s.type(f + 'input[name="cred[api_secret]"]', 'xxxxxxxxxxxx', 22)
    s.say('Satıcı ID, API Key ve API Secret bilgilerini Trendyol satıcı panelinizdeki entegrasyon bilgileri sayfasından kopyalayın.', '3. adım', hold=4200)
    s.say('Bu bilgiler şifreli saklanır ve bir daha gösterilmez.', '3. adım', hold=2200)
    s.ring(f + 'button::text(Hesabı ekle)'); s.click(f + 'button::text(Hesabı ekle)')
    s.say('“Hesabı ekle”ye basın.', '3. adım'); s.unring()
    s.go('mh-account')
    s.ring('button::text(Bağlantıyı test et)'); s.click('button::text(Bağlantıyı test et)')
    s.say('“Bağlantıyı test et” ile bilgilerin doğru olduğunu kontrol edin.', '4. adım'); s.unring()
    s.ring('a::text(Kategori eşleme)'); s.click('a::text(Kategori eşleme)')
    s.say('Şimdi “Kategori eşleme” sekmesine geçin.', '5. adım'); s.unring()
    s.go('mh-categories')
    s.say('Ürünlerinizin bulunduğu kategoriler listelenir. Her birinin karşısına Trendyol’daki kategori numarasını yazın.', '5. adım', hold=3800)
    codes = [('Bluetooth Kulaklık', '1083'), ('Telefon Kılıfı', '1041'), ('Şarj Cihazı', '1047'), ('Kadın > Giyim > Tişört', '411')]
    for name, code in codes:
        sel = f'.row::text({name}) input[name^=remote]:not([name^=remote_name])'
        s.click(sel); s.type(sel, code, 10)
    s.say('(Numaralar örnektir; kendi Trendyol panelinizdeki numarayı yazın.)', '5. adım', hold=2400)
    s.ring('button::text(Eşlemeleri kaydet)'); s.click('button::text(Eşlemeleri kaydet)')
    s.say('“Eşlemeleri kaydet”e basın.', '5. adım'); s.unring()
    s.go('mh-categories-saved'); s.ring('.alert')
    s.say('Kategori eşlemeleri kaydedildi.', hold=1800); s.unring()
    s.ring('a::text(Ürünler)'); s.click('a::text(Ürünler)')
    s.say('“Ürünler” sekmesinde Trendyol’la eşleştireceğiniz ürünleri seçin.', '6. adım'); s.unring()
    s.go('mh-products')
    for name in ('Telefon Kılıfı', 'Bluetooth Kulaklık', 'Şarj Adaptörü'):
        s.click(f'label.chk::text({name}) input', True)
    s.ring('button::text(Seçilenlerin stok/fiyatını gönder)')
    s.say('“Seçilenlerin stok/fiyatını gönder” ile Trendyol’daki ürünlerinizin stok ve fiyatı güncellenir.', '6. adım', hold=3600); s.unring()
    s.ring('button::text(gönderim kuyruğuna al)')
    s.say('“Gönderim kuyruğuna al” ile seçtikleriniz sıraya girer; Trendyol’da yeni ürün açma özelliği etkinleştiğinde otomatik aktarılır.', '6. adım', hold=4600); s.unring()
    s.end('Trendyol bağlantınız hazır!', 'Hepsiburada ve N11 için de aynı adımları izleyin.')


VIDEOS = {
    'urun-ekleme': ('Tek tek ürün nasıl eklenir?', 'Satıcı Paneli · 1 dakikadan kısa', v_urun),
    'excel-yukleme': ('Excel ile toplu ürün yükleme', 'Satıcı Paneli · 1 dakikadan kısa', v_excel),
    'xml-yukleme': ('XML ile ürün yükleme', 'XML Merkezi · adım adım', v_xml),
    'kategori-eslestirme': ('Kategori eşleştirme nasıl yapılır?', 'XML Merkezi · tek tıkla düzeltme', v_kategori),
    'gorsel': ('Görseller nasıl eklenir ve güncellenir?', 'Satıcı Paneli · Ürün düzenleme', v_gorsel),
    'fiyat-stok': ('Fiyat ve stok nasıl güncellenir?', 'Satıcı Paneli · Stok ve Fiyat Yönetimi', v_fiyat),
    'trendyol': ('Trendyol ile ürün eşleştirme', 'Pazaryerleri · Trendyol', v_trendyol),
}

if __name__ == '__main__':
    keys = sys.argv[1:] or list(VIDEOS)
    build_pages()
    meta_path = os.path.join(OUT, 'videos.json')
    meta = json.load(open(meta_path)) if os.path.isfile(meta_path) else {}
    for k in keys:
        title, sub, fn = VIDEOS[k]
        secs = record(k, title, sub, fn)
        meta[k] = {'seconds': secs, 'v': time.strftime('%Y%m%d%H%M')}
        json.dump(meta, open(meta_path, 'w'), ensure_ascii=False, indent=1)
    print('bitti:', ', '.join(keys))
