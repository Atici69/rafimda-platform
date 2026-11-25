/* Rafi Akademi: video penceresi + yüzen "Yardımcı olmamı ister misin?" balonu. Bağımlılık yok. */
(function () {
	'use strict';
	var dock = document.getElementById('rafi-yardim');
	if (!dock) { return; }
	var modal = document.querySelector('.rafih-modal');
	var video = modal ? modal.querySelector('video') : null;
	var lastFocus = null;
	var store = {
		get: function (k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } },
		set: function (k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* özel kip */ } }
	};

	function open(btn) {
		if (!modal || !video) { return; }
		lastFocus = btn;
		modal.querySelector('h3').textContent = btn.getAttribute('data-title') || '';
		var ol = modal.querySelector('.rafih-steps');
		ol.innerHTML = '';
		var steps = [];
		try { steps = JSON.parse(btn.getAttribute('data-steps') || '[]'); } catch (e) { steps = []; }
		steps.forEach(function (s) { var li = document.createElement('li'); li.textContent = s; ol.appendChild(li); });
		video.setAttribute('poster', btn.getAttribute('data-poster') || '');
		video.src = btn.getAttribute('data-src') || '';
		modal.hidden = false;
		document.documentElement.style.overflow = 'hidden';
		var p = video.play();
		if (p && p.catch) { p.catch(function () { /* otomatik oynatma engellenirse kullanıcı başlatır */ }); }
		modal.querySelector('.rafih-close').focus();
	}

	function close() {
		if (!modal || modal.hidden) { return; }
		video.pause();
		video.removeAttribute('src');
		video.load();
		modal.hidden = true;
		document.documentElement.style.overflow = '';
		if (lastFocus) { lastFocus.focus(); }
	}

	document.addEventListener('click', function (e) {
		var card = e.target.closest ? e.target.closest('[data-rafih-video]') : null;
		if (card) { e.preventDefault(); open(card); return; }
		if (modal && (e.target === modal || (e.target.closest && e.target.closest('.rafih-close')))) { close(); }
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });

	// yüzen Rafi: sayfaya girdikten birkaç saniye sonra bir kez seslenir (oturum başına, kapatılırsa bir daha değil)
	var fab = document.querySelector('.rafih-fab');
	if (!fab) { return; }
	var bubble = fab.querySelector('.rafih-bubble');
	var visible = false;
	if ('IntersectionObserver' in window) {
		new IntersectionObserver(function (en) {
			visible = en[0].isIntersecting;
			fab.hidden = visible;
		}).observe(dock);
	}
	fab.hidden = false;
	bubble.hidden = true;
	if (store.get('rafih-dismissed') !== '1') {
		setTimeout(function () { if (!visible) { bubble.hidden = false; } }, 4000);
	}
	function goDock(e) {
		if (e) { e.preventDefault(); }
		bubble.hidden = true;
		dock.scrollIntoView({ behavior: 'smooth', block: 'start' });
		var first = dock.querySelector('[data-rafih-video]');
		if (first) { setTimeout(function () { first.focus({ preventScroll: true }); }, 500); }
	}
	fab.querySelector('.rafih-fab-btn').addEventListener('click', goDock);
	var link = bubble.querySelector('a');
	if (link) { link.addEventListener('click', goDock); }
	fab.querySelector('.rafih-x').addEventListener('click', function () {
		bubble.hidden = true;
		store.set('rafih-dismissed', '1');
	});
})();
