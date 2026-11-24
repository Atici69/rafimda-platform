/* Rafımda hero slider: otomatik geçiş, ileri/geri, noktalar, klavye, dokunmatik kaydırma. Bağımlılık yok. */
(function () {
	'use strict';
	function init(root) {
		var slides = [].slice.call(root.querySelectorAll('.rt-slide'));
		if (slides.length < 2) { return; }
		var dots = [].slice.call(root.querySelectorAll('.rt-dot'));
		var prev = root.querySelector('.rt-nav--prev');
		var next = root.querySelector('.rt-nav--next');
		var secs = parseInt(root.getAttribute('data-interval'), 10);
		var interval = (secs >= 3 && secs <= 15 ? secs : 6) * 1000;
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var cur = 0, timer = null, paused = false;

		root.classList.add('is-js');

		function show(i) {
			cur = (i + slides.length) % slides.length;
			slides.forEach(function (s, k) {
				var on = k === cur;
				s.classList.toggle('is-active', on);
				s.setAttribute('aria-hidden', on ? 'false' : 'true');
				[].forEach.call(s.querySelectorAll('a'), function (a) { a.tabIndex = on ? 0 : -1; });
			});
			dots.forEach(function (d, k) {
				d.classList.toggle('is-active', k === cur);
				d.setAttribute('aria-current', k === cur ? 'true' : 'false');
			});
		}
		function stop() { if (timer) { clearInterval(timer); timer = null; } }
		function start() {
			stop();
			if (reduce || paused) { return; }
			timer = setInterval(function () { show(cur + 1); }, interval);
		}
		function go(i) { show(i); start(); }

		if (prev) { prev.addEventListener('click', function () { go(cur - 1); }); }
		if (next) { next.addEventListener('click', function () { go(cur + 1); }); }
		dots.forEach(function (d, k) { d.addEventListener('click', function () { go(k); }); });

		root.addEventListener('mouseenter', function () { paused = true; stop(); });
		root.addEventListener('mouseleave', function () { paused = false; start(); });
		root.addEventListener('focusin', function () { paused = true; stop(); });
		root.addEventListener('focusout', function () { paused = false; start(); });
		root.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowLeft') { go(cur - 1); } else if (e.key === 'ArrowRight') { go(cur + 1); }
		});

		var x0 = null;
		root.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
		root.addEventListener('touchend', function (e) {
			if (x0 === null) { return; }
			var dx = e.changedTouches[0].clientX - x0;
			x0 = null;
			if (Math.abs(dx) > 40) { go(cur + (dx < 0 ? 1 : -1)); }
		}, { passive: true });

		document.addEventListener('visibilitychange', function () { if (document.hidden) { stop(); } else { start(); } });
		show(0);
		start();
	}
	function boot() { [].forEach.call(document.querySelectorAll('[data-rt-slider]'), init); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
