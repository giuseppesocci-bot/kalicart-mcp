/* KaliCart MCP — admin exclusions UI. Data injected via wp_localize_script as window.KCMCP. */
(function(){
	document.querySelectorAll('.kcmcp-copy[data-copy]').forEach(function(btn){
		btn.addEventListener('click', function(){
			var el = document.querySelector(btn.getAttribute('data-copy'));
			if(!el){ return; }
			navigator.clipboard.writeText(el.innerText).then(function(){
				var o = btn.innerText; btn.innerText = '\u2713 ' + o; setTimeout(function(){ btn.innerText = o; }, 1200);
			});
		});
	});

	var KCMCP = window.KCMCP || { base: '', nonce: '', t: {} };

	function kcmcpHiddenBox(create){
		var box = document.getElementById('kcmcp-hidden-posts');
		if (box || !create) { return box; }
		var anchor = document.getElementById('kcmcp-search-results');
		if (!anchor) { return null; }
		var p = document.createElement('p');
		p.className = 'kcmcp-muted'; p.id = 'kcmcp-hidden-label';
		p.style.marginTop = '16px'; p.textContent = KCMCP.t.hiddenLabel;
		box = document.createElement('div');
		box.id = 'kcmcp-hidden-posts'; box.className = 'kcmcp-togglelist';
		anchor.parentNode.insertBefore(p, anchor.nextSibling);
		p.parentNode.insertBefore(box, p.nextSibling);
		return box;
	}

	function kcmcpSync(id, hidden, title, type){
		// Reflect the new state in the "Currently hidden" list.
		var box = document.getElementById('kcmcp-hidden-posts');
		var existing = box ? box.querySelector('.kcmcp-xtoggle[data-id="' + id + '"]') : null;
		if (hidden) {
			if (existing) { existing.checked = true; return; }
			box = kcmcpHiddenBox(true);
			if (box) { box.appendChild(kcmcpRow({ id: id, title: title, type: type, hidden: true })); }
		} else if (existing) {
			var row = existing.closest('.kcmcp-trow');
			if (row) { row.parentNode.removeChild(row); }
			if (box && !box.querySelector('.kcmcp-trow')) {
				var lbl = document.getElementById('kcmcp-hidden-label');
				if (lbl) { lbl.parentNode.removeChild(lbl); }
				box.parentNode.removeChild(box);
			}
		}
	}

	function kcmcpToggle(cb){
		var id = parseInt(cb.getAttribute('data-id'), 10);
		var hidden = cb.checked;
		var row = cb.closest('.kcmcp-trow');
		var titleEl = row ? row.querySelector('.kcmcp-trow-title') : null;
		var title = titleEl ? titleEl.firstChild.textContent.trim() : ('#' + id);
		var typeEl = titleEl ? titleEl.querySelector('.kcmcp-muted') : null;
		var type = typeEl ? typeEl.textContent.replace(/^[\s\u00b7]+/, '') : '';
		cb.disabled = true;
		fetch(KCMCP.base + '/admin/toggle-exclude', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': KCMCP.nonce },
			body: JSON.stringify({ id: id, hidden: hidden })
		}).then(function(r){ return r.ok ? r.json() : Promise.reject(r); })
		.then(function(){
			cb.disabled = false;
			kcmcpSync(id, hidden, title, type);
		})
		.catch(function(){
			cb.checked = !hidden; cb.disabled = false;
			window.alert(KCMCP.t.toggleError);
		});
	}

	function kcmcpRow(item){
		var row = document.createElement('div');
		row.className = 'kcmcp-trow';
		var sw = document.createElement('label');
		sw.className = 'kcmcp-switch';
		var cb = document.createElement('input');
		cb.type = 'checkbox'; cb.className = 'kcmcp-xtoggle';
		cb.setAttribute('data-id', item.id); cb.checked = !!item.hidden;
		var track = document.createElement('span'); track.className = 'kcmcp-tog-track';
		var thumb = document.createElement('span'); thumb.className = 'kcmcp-tog-thumb';
		track.appendChild(thumb);
		var title = document.createElement('span'); title.className = 'kcmcp-trow-title';
		title.textContent = item.title;
		if (item.type) {
			var t = document.createElement('span'); t.className = 'kcmcp-muted';
			t.textContent = ' \u00b7 ' + item.type; title.appendChild(t);
		}
		sw.appendChild(cb); sw.appendChild(track);
		row.appendChild(sw); row.appendChild(title);
		return row;
	}

	var search = document.getElementById('kcmcp-post-search');
	var results = document.getElementById('kcmcp-search-results');
	if (search && results) {
		var timer = null;
		search.addEventListener('input', function(){
			var q = search.value.trim();
			clearTimeout(timer);
			if (q.length < 2) { results.innerHTML = ''; return; }
			results.textContent = KCMCP.t.searching;
			timer = setTimeout(function(){
				fetch(KCMCP.base + '/admin/search-posts?q=' + encodeURIComponent(q), {
					headers: { 'X-WP-Nonce': KCMCP.nonce }
				}).then(function(r){ return r.ok ? r.json() : Promise.reject(r); })
			.then(function(data){
				results.innerHTML = '';
				if (!data.items || !data.items.length) { results.textContent = KCMCP.t.noResults; return; }
				data.items.forEach(function(it){ results.appendChild(kcmcpRow(it)); });
			}).catch(function(){ results.textContent = KCMCP.t.error; });
			}, 280);
		});
	}

	document.addEventListener('change', function(e){
		if (e.target && e.target.classList && e.target.classList.contains('kcmcp-xtoggle')) {
			kcmcpToggle(e.target);
		}
	});
})();

