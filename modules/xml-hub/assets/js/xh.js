(function () {
	var s = document.getElementById('xh-src');
	if (!s) { return; }
	function f() {
		document.getElementById('xh-url').style.display = s.value === 'url' ? '' : 'none';
		document.getElementById('xh-file').style.display = s.value === 'file' ? '' : 'none';
	}
	s.addEventListener('change', f);
	f();
})();
