(function() {
	'use strict';

	function closeAllDropdowns(except) {
		document.querySelectorAll('.multi-select.open').forEach(function(el) {
			if (el !== except) el.classList.remove('open');
		});
		document.querySelectorAll('.date-condition.open').forEach(function(el) {
			if (el !== except) el.classList.remove('open');
		});
	}

	// ─── Multi-select widget ───
	document.querySelectorAll('.multi-select').forEach(function(ms) {
		var trigger = ms.querySelector('.multi-select-trigger');
		var hidden = ms.querySelector('input[type="hidden"]');
		var checkboxes = ms.querySelectorAll('.multi-select-dropdown input[type="checkbox"]');

		trigger.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			closeAllDropdowns(ms);
			ms.classList.toggle('open');
		});

		checkboxes.forEach(function(cb) {
			cb.addEventListener('change', function() {
				var selected = [];
				var labels = [];
				checkboxes.forEach(function(c) {
					if (c.checked) {
						selected.push(c.value);
						labels.push(c.parentElement.textContent.trim());
					}
				});
				hidden.value = selected.join(',');
				trigger.textContent = labels.length > 0 ? labels.join(', ') : 'All';
				trigger.title = trigger.textContent;
			});
		});
	});

	// ─── Date condition widget ───
	document.querySelectorAll('.date-condition').forEach(function(dc) {
		var trigger = dc.querySelector('.date-condition-trigger');
		var hidden = dc.querySelector('input[type="hidden"]');
		var radios = dc.querySelectorAll('.date-condition-dropdown input[type="radio"]');
		var dateInput = dc.querySelector('.date-condition-date');
		var opLabels = {before: 'Before', on: 'On', after: 'After'};

		trigger.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			closeAllDropdowns(dc);
			dc.classList.toggle('open');
		});

		function sync() {
			var op = '';
			radios.forEach(function(r) { if (r.checked) op = r.value; });
			var date = dateInput.value;
			if (op && date) {
				hidden.value = op + ':' + date;
				trigger.textContent = opLabels[op] + ' ' + date;
			} else {
				hidden.value = '';
				trigger.textContent = 'Any';
			}
			trigger.title = trigger.textContent;
		}

		radios.forEach(function(r) { r.addEventListener('change', sync); });
		dateInput.addEventListener('change', sync);
	});

	// ─── Close dropdowns on outside click ───
	document.addEventListener('click', function(e) {
		if (!e.target.closest('.multi-select') && !e.target.closest('.date-condition')) {
			closeAllDropdowns(null);
		}
	});

	// ─── Delete action handler (supports data-payload for composite keys) ───
	document.addEventListener('click', function(e) {
		var btn = e.target.closest('[data-action="delete"]');
		if (!btn) return;
		e.stopPropagation();
		var endpoint = btn.dataset.endpoint;
		var msg = btn.dataset.confirm || 'Delete this record?';
		if (!confirm(msg)) return;
		var body = btn.dataset.payload
			? btn.dataset.payload
			: JSON.stringify({id: parseInt(btn.dataset.id, 10)});
		fetch(endpoint, {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: body
		}).then(function(resp) { return resp.json(); })
		.then(function(data) {
			if (data.ok) {
				var row = btn.closest('tr');
				if (row) row.remove();
			} else {
				alert(data.error || 'Delete failed');
			}
		}).catch(function() { alert('Network error'); });
	});

	// ─── Global action handler (table-wide actions in footer) ───
	document.addEventListener('click', function(e) {
		var btn = e.target.closest('[data-action="global-action"]');
		if (!btn) return;
		e.stopPropagation();
		var endpoint = btn.dataset.endpoint;
		var msg = btn.dataset.confirm || 'Are you sure?';
		if (!confirm(msg)) return;
		var body = btn.dataset.payload || '{}';
		fetch(endpoint, {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: body
		}).then(function(resp) { return resp.json(); })
		.then(function(data) {
			if (data.ok) {
				window.location.reload();
			} else {
				alert(data.error || 'Action failed');
			}
		}).catch(function() { alert('Network error'); });
	});
})();
