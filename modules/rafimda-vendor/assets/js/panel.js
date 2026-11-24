(function(){
	var b=document.querySelector('.rv-burger'),s=document.querySelector('.rv-scrim');
	function t(){document.body.classList.toggle('rv-open');}
	if(b){b.addEventListener('click',t);}
	if(s){s.addEventListener('click',t);}
	document.querySelectorAll('[data-confirm]').forEach(function(el){
		el.addEventListener('click',function(e){if(!window.confirm(el.getAttribute('data-confirm'))){e.preventDefault();}});
	});
	var all=document.getElementById('rv-check-all');
	if(all){all.addEventListener('change',function(){document.querySelectorAll('input[name="ids[]"]').forEach(function(c){c.checked=all.checked;});});}
	var add=document.getElementById('rv-add-var'),box=document.getElementById('rv-vars');
	if(add&&box){add.addEventListener('click',function(){
		var n=box.querySelectorAll('.rv-var').length;
		var tpl=document.getElementById('rv-var-tpl').innerHTML.replace(/__N__/g,String(n));
		box.insertAdjacentHTML('beforeend',tpl);
	});}
})();
