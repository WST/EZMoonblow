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

	// ─── Delete action handler ───
	document.addEventListener('click', function(e) {
		var btn = e.target.closest('[data-action="delete"]');
		if (!btn) return;
		e.stopPropagation();
		var id = btn.dataset.id;
		var endpoint = btn.dataset.endpoint;
		var msg = btn.dataset.confirm || 'Delete this record?';
		if (!confirm(msg)) return;
		fetch(endpoint, {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({id: parseInt(id, 10)})
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
})();
