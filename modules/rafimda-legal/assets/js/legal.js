/* Rafımda çerez bildirimi: geri dönüş adresini doldurur. Zorunlu olmayan çerezler sunucu tarafında izin yoksa hiç yüklenmez. */
(function () {
	var b = document.querySelector('[data-rl-back]');
	if (b) { b.value = location.pathname + location.search; }
})();
