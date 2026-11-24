(function(){
	var b=document.querySelector('.rd-burger'),s=document.querySelector('.rd-scrim');
	function t(){document.body.classList.toggle('rd-open');}
	if(b){b.addEventListener('click',t);}
	if(s){s.addEventListener('click',t);}
	document.querySelectorAll('[data-confirm]').forEach(function(el){
		el.addEventListener('click',function(e){if(!window.confirm(el.getAttribute('data-confirm'))){e.preventDefault();}});
	});
	var all=document.getElementById('rd-check-all');
	if(all){all.addEventListener('change',function(){document.querySelectorAll('input[name="ids[]"]').forEach(function(c){c.checked=all.checked;});});}
})();
