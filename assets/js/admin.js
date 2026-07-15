(function () {
	'use strict';

	function ready(callback) {
		if (document.readyState !== 'loading') {
			callback();
			return;
		}
		document.addEventListener('DOMContentLoaded', callback);
	}

	ready(function () {
		document.querySelectorAll('.yo-booking-admin .button-link-delete').forEach(function (button) {
			button.closest('form')?.addEventListener('submit', function (event) {
				if (!window.confirm(window.YoBookingAdmin?.confirmDelete || 'Delete this record?')) {
					event.preventDefault();
				}
			});
		});

		document.querySelectorAll('[data-yo-tabs]').forEach(function (tabSet) {
			var buttons = tabSet.querySelectorAll('[data-yo-tab]');
			var panels = document.querySelectorAll('[data-yo-panel]');
			var initial = window.location.hash.replace('#', '') || tabSet.dataset.defaultTab || buttons[0]?.dataset.yoTab;

			function activate(tabName, updateHash) {
				buttons.forEach(function (button) {
					var selected = button.dataset.yoTab === tabName;
					button.classList.toggle('is-active', selected);
					button.setAttribute('aria-selected', selected ? 'true' : 'false');
				});
				panels.forEach(function (panel) {
					panel.hidden = panel.dataset.yoPanel !== tabName;
				});
				if (updateHash && window.history.replaceState) {
					window.history.replaceState(null, '', '#' + tabName);
				}
			}

			buttons.forEach(function (button) {
				button.addEventListener('click', function () {
					activate(button.dataset.yoTab, true);
				});
			});
			activate(initial, false);
		});

		function syncPaymentFields() {
			var enabled = document.querySelector('[name="payments_enabled"]');
			var mode = document.querySelector('[name="payment_collection_mode"]');
			document.querySelectorAll('[data-payment-field]').forEach(function (row) {
				var visible = enabled?.checked && (row.dataset.paymentField !== 'deposit' || mode?.value === 'deposit');
				row.hidden = !visible;
			});
		}

		document.querySelector('[name="payments_enabled"]')?.addEventListener('change', syncPaymentFields);
		document.querySelector('[name="payment_collection_mode"]')?.addEventListener('change', syncPaymentFields);
		syncPaymentFields();

		function copyToClipboard(value) {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				return navigator.clipboard.writeText(value);
			}

			var field = document.createElement('textarea');
			field.value = value;
			field.setAttribute('readonly', '');
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild(field);
			field.select();
			var copied = document.execCommand('copy');
			field.remove();
			return copied ? Promise.resolve() : Promise.reject();
		}

		function showCopyFeedback(button, succeeded, status) {
			var message = succeeded ? (window.YoBookingAdmin?.copied || 'Copied') : (window.YoBookingAdmin?.copyFailed || 'Copy failed');
			var icon = button.querySelector('.fi');
			var originalLabel = button.dataset.copyOriginalLabel || button.getAttribute('aria-label') || 'Copy';
			var originalTitle = button.dataset.copyOriginalTitle || button.getAttribute('title') || originalLabel;

			button.dataset.copyOriginalLabel = originalLabel;
			button.dataset.copyOriginalTitle = originalTitle;
			button.dataset.copyFeedback = message;
			button.classList.toggle('is-copied', succeeded);
			button.classList.toggle('has-copy-error', !succeeded);
			button.setAttribute('aria-label', message);
			button.setAttribute('title', message);
			if (icon) {
				icon.classList.remove('fi-rr-copy', 'fi-rr-check', 'fi-rr-cross');
				icon.classList.add(succeeded ? 'fi-rr-check' : 'fi-rr-cross');
			}
			if (status) status.textContent = message;

			window.clearTimeout(button._yoCopyFeedbackTimer);
			button._yoCopyFeedbackTimer = window.setTimeout(function () {
				button.classList.remove('is-copied', 'has-copy-error');
				button.removeAttribute('data-copy-feedback');
				button.setAttribute('aria-label', originalLabel);
				button.setAttribute('title', originalTitle);
				if (icon) {
					icon.classList.remove('fi-rr-check', 'fi-rr-cross');
					icon.classList.add('fi-rr-copy');
				}
				if (status) status.textContent = '';
			}, 1800);
		}

		document.querySelectorAll('[data-copy-value]').forEach(function (button) {
			button.addEventListener('click', function () {
				var status = button.parentElement?.querySelector('[data-copy-status]');
				copyToClipboard(button.dataset.copyValue || '').then(function () {
					showCopyFeedback(button, true, status);
				}).catch(function () {
					showCopyFeedback(button, false, status);
				});
			});
		});

		document.querySelectorAll('[data-yo-auto-submit]').forEach(function (field) {
			field.addEventListener('change', function () {
				field.form?.submit();
			});
		});

		document.querySelectorAll('[data-yo-table-search]').forEach(function (field) {
			var table = document.getElementById(field.dataset.yoTableSearch);
			field.addEventListener('input', function () {
				var query = field.value.trim().toLowerCase();
				table?.querySelectorAll('tbody tr').forEach(function (row) {
					row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
				});
			});
		});

		document.querySelectorAll('.yo-weekly-schedule tbody tr').forEach(function (row) {
			var checkbox = row.querySelector('input[type="checkbox"]');
			function syncRow() {
				row.classList.toggle('is-enabled', Boolean(checkbox?.checked));
			}
			checkbox?.addEventListener('change', syncRow);
			syncRow();
		});

		function renumberRanges(row) {
			var weekday = row.dataset.weekdayRow;
			row.querySelectorAll('.yo-time-range').forEach(function (range, index) {
				var inputs = range.querySelectorAll('input[type="time"]');
				if (inputs[0]) inputs[0].name = 'rules[' + weekday + '][ranges][' + index + '][start_time]';
				if (inputs[1]) inputs[1].name = 'rules[' + weekday + '][ranges][' + index + '][end_time]';
			});
		}

		function bindRangeButtons(row) {
			row.querySelectorAll('[data-remove-range]').forEach(function (button) {
				button.onclick = function () {
					var ranges = row.querySelectorAll('.yo-time-range');
					if (ranges.length <= 1) return;
					button.closest('.yo-time-range')?.remove();
					renumberRanges(row);
				};
			});
		}

		document.querySelectorAll('[data-weekday-row]').forEach(function (row) {
			bindRangeButtons(row);
			row.querySelector('[data-add-range]')?.addEventListener('click', function () {
				var container = row.querySelector('[data-time-ranges]');
				var source = container.querySelector('.yo-time-range');
				if (!source) return;
				var range = source.cloneNode(true);
				var inputs = range.querySelectorAll('input[type="time"]');
				if (inputs[0]) inputs[0].value = '13:00';
				if (inputs[1]) inputs[1].value = '17:00';
				container.appendChild(range);
				renumberRanges(row);
				bindRangeButtons(row);
			});

			row.querySelector('[data-copy-day]')?.addEventListener('click', function (event) {
				var sourceRanges = Array.from(row.querySelectorAll('.yo-time-range')).map(function (range) {
					var inputs = range.querySelectorAll('input[type="time"]');
					return { start: inputs[0]?.value || '09:00', end: inputs[1]?.value || '17:00' };
				});
				var sourceInterval = row.querySelector('[name$="[slot_interval_minutes]"]')?.value || '15';
				var sourceEnabled = Boolean(row.querySelector('input[type="checkbox"]')?.checked);

				document.querySelectorAll('[data-weekday-row]').forEach(function (target) {
					if (target === row) return;
					var container = target.querySelector('[data-time-ranges]');
					var template = container.querySelector('.yo-time-range');
					if (!template) return;
					container.replaceChildren();
					sourceRanges.forEach(function (values) {
						var range = template.cloneNode(true);
						var inputs = range.querySelectorAll('input[type="time"]');
						if (inputs[0]) inputs[0].value = values.start;
						if (inputs[1]) inputs[1].value = values.end;
						container.appendChild(range);
					});
					var checkbox = target.querySelector('input[type="checkbox"]');
					if (checkbox) {
						checkbox.checked = sourceEnabled;
						checkbox.dispatchEvent(new Event('change'));
					}
					var interval = target.querySelector('[name$="[slot_interval_minutes]"]');
					if (interval) interval.value = sourceInterval;
					renumberRanges(target);
					bindRangeButtons(target);
				});
				showCopyFeedback(event.currentTarget, true);
			});
		});

		document.querySelectorAll('.yo-booking-admin form').forEach(function (form) {
			form.addEventListener('submit', function () {
				if (form.hasAttribute('data-yo-async')) return;
				var submit = form.querySelector('button[type="submit"], input[type="submit"]');
				if (submit) {
					submit.classList.add('is-busy');
					submit.setAttribute('aria-busy', 'true');
				}
			});
		});
	});
})();
