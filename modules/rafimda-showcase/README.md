# Rafımda Vitrin (v0.1.0)
Ana sayfa vitrin alanları: **Öne Çıkan Ürünler**, **Kampanyalı Ürünler**, **Reklam Alanı (banner)**.
- Yönetici: alanları ve öğeleri yönetir, satıcı taleplerini onaylar/reddeder. Satıcı (rafimda-vendor → "Kampanyalar ve Vitrin"): yalnızca KENDİ onaylı ürünü için ürün alanlarına talep açar.
- Görünürlük: onaylı + tarih penceresi içinde + ürün aktif/stokta + satıcı ürünü moderasyondan onaylı. Banner URL'leri yalnızca https veya site içi yol (`javascript:`/`data:` reddedilir).
- Temada `{$hooks.home_showcase}` (veya yedek `home_bottom`) çıktısı bağlı olmalıdır. **Gerçek tema üzerinde görsel olarak doğrulanmadı.**
- **Yok:** reklam ücreti/ödeme, tıklama/gösterim istatistiği, otomatik kampanya indirimi.
- Testler: `php modules/rafimda-showcase/tests/run.php`, `tests/load_module.php`.
