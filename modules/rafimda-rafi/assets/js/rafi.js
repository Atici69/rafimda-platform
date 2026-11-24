/* Rafi – Rafımda alışveriş asistanı. Sunucu verisi yalnızca textContent ile yazılır (HTML enjekte edilmez). */
(function () {
	'use strict';
	var root = document.getElementById('rafi');
	if (!root) { return; }
	var api = root.getAttribute('data-api');
	var fab = document.getElementById('rafiFab'), panel = document.getElementById('rafiPanel'), log = document.getElementById('rafiLog');
	var form = document.getElementById('rafiForm'), input = document.getElementById('rafiInput'), chips = document.getElementById('rafiChips');
	var hello = document.getElementById('rafiHello'), started = false, busy = false;

	function store(k, v) { try { if (v === undefined) { return sessionStorage.getItem(k); } sessionStorage.setItem(k, v); } catch (e) { return null; } }
	function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
	function safeUrl(u) { u = String(u || ''); return /^(https?:\/\/|\/(?!\/))/i.test(u) ? u : ''; }
	function scroll() { log.scrollTop = log.scrollHeight; }
	function say(text, who) { var b = el('div', 'rafi-msg rafi-msg--' + who, text); log.appendChild(b); scroll(); return b; }

	function card(p, i) {
		var a = el(safeUrl(p.url) ? 'a' : 'div', 'rafi-card');
		if (safeUrl(p.url)) { a.href = safeUrl(p.url); }
		var no = el('span', 'rafi-no', String(i + 1)); a.appendChild(no);
		if (safeUrl(p.image)) { var img = el('img'); img.src = safeUrl(p.image); img.alt = ''; img.loading = 'lazy'; img.width = 56; img.height = 56; a.appendChild(img); } else { a.appendChild(el('span', 'rafi-noimg')); }
		var b = el('span', 'rafi-card-b');
		b.appendChild(el('span', 'rafi-card-n', p.name));
		var pr = el('span', 'rafi-card-p', p.price_text);
		if (p.old_price_text) { var s = el('s', null, p.old_price_text); pr.appendChild(document.createTextNode(' ')); pr.appendChild(s); }
		b.appendChild(pr);
		var meta = (p.stock_text || '') + ' · ' + (p.seller && p.seller.name ? p.seller.name : '') + (p.review_count > 0 ? ' · ★ ' + String(p.rating).replace('.', ',') + ' (' + p.review_count + ')' : '');
		b.appendChild(el('span', 'rafi-card-m' + (p.in_stock ? '' : ' rafi-out'), meta));
		a.appendChild(b);
		return a;
	}

	function table(c) {
		var wrap = el('div', 'rafi-cmp'), t = el('table'), thead = el('thead'), tr = el('tr');
		tr.appendChild(el('th', null, ''));
		c.columns.forEach(function (col) { var th = el('th'); var u = safeUrl(col.url); if (u) { var a = el('a', null, col.name); a.href = u; th.appendChild(a); } else { th.textContent = col.name; } tr.appendChild(th); });
		thead.appendChild(tr); t.appendChild(thead);
		var tb = el('tbody');
		c.rows.forEach(function (r) { var row = el('tr'); row.appendChild(el('th', null, r.label)); r.values.forEach(function (v) { row.appendChild(el('td', null, v)); }); tb.appendChild(row); });
		t.appendChild(tb); wrap.appendChild(t);
		return wrap;
	}

	function setChips(list) {
		chips.textContent = '';
		(list || []).forEach(function (c) { var b = el('button', 'rafi-chip', c.label); b.type = 'button'; b.addEventListener('click', function () { send(c.text); }); chips.appendChild(b); });
	}

	function send(text) {
		text = String(text || '').trim();
		if (!text || busy) { return; }
		busy = true; say(text, 'me'); input.value = '';
		var wait = say('Rafi katalogda arıyor…', 'bot rafi-wait');
		var body = new URLSearchParams(); body.set('message', text); body.set('token', window.csrfToken || '');
		fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: body.toString() })
			.then(function (r) { return r.json().catch(function () { return { success: false, message: 'Beklenmeyen yanıt.' }; }); })
			.then(function (d) {
				wait.remove();
				if (!d || !d.success) { say((d && d.message) || 'Şu anda yanıt veremiyorum.', 'bot'); return; }
				say(d.reply, 'bot');
				if (d.compare) { log.appendChild(table(d.compare)); }
				else if (d.products && d.products.length) { var list = el('div', 'rafi-cards'); d.products.forEach(function (p, i) { list.appendChild(card(p, i)); }); log.appendChild(list); }
				setChips(d.suggestions);
				scroll();
			})
			.catch(function () { wait.remove(); say('Bağlantı hatası; lütfen tekrar deneyin.', 'bot'); })
			.then(function () { busy = false; });
	}

	function open() {
		panel.hidden = false; fab.setAttribute('aria-expanded', 'true'); root.classList.add('rafi--open'); hello.hidden = true;
		if (!started) { started = true; say('Merhaba, ben Rafi! Ne arıyorsunuz? Ürün adı, marka, kategori veya fiyat aralığı yazabilirsiniz.', 'bot'); setChips([{ label: 'Ne yapabilirsin?', text: 'ne yapabilirsin' }, { label: 'İndirimli ürünler', text: 'indirimli ürünler' }]); }
		setTimeout(function () { input.focus(); }, 30);
	}
	function close() { panel.hidden = true; fab.setAttribute('aria-expanded', 'false'); root.classList.remove('rafi--open'); fab.focus(); }

	fab.addEventListener('click', function () { if (panel.hidden) { open(); } else { close(); } });
	document.getElementById('rafiClose').addEventListener('click', close);
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { close(); } });
	form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
	hello.querySelector('.rafi-hello-x').addEventListener('click', function () { hello.hidden = true; store('rafiHelloSeen', '1'); });
	hello.addEventListener('click', function (e) { if (e.target === hello || e.target.tagName === 'P') { store('rafiHelloSeen', '1'); open(); } });

	// Ana sayfada oturum başına bir kez karşılama balonu
	if (root.getAttribute('data-greet') === '1' && store('rafiHelloSeen') !== '1') {
		setTimeout(function () { if (panel.hidden) { hello.hidden = false; store('rafiHelloSeen', '1'); } }, 1200);
	}
})();
