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

		document.addEventListener('input', function (event) {
			if (event.target.matches?.('[data-yo-money-input]')) {
				event.target.dataset.yoMoneyDirty = '1';
			}
		});

		function syncDepositAmountField() {
			var type = document.getElementById('yo_booking_deposit_type');
			var amount = document.getElementById('yo_booking_deposit_amount');
			if (!type || !amount) return;

			if (type.value === 'fixed') {
				if (amount.dataset.yoMoneyRaw === undefined) {
					amount.dataset.yoMoneyRaw = amount.value;
					delete amount.dataset.yoMoneyDirty;
				}
				amount.type = 'text';
				amount.inputMode = 'decimal';
				amount.setAttribute('data-yo-money-input', '');
				amount.removeAttribute('min');
				amount.removeAttribute('max');
				amount.removeAttribute('step');
				return;
			}

			if (amount.dataset.yoMoneyRaw !== undefined) {
				amount.value = amount.dataset.yoMoneyRaw;
			}
			amount.type = 'number';
			amount.min = '0';
			amount.max = '100';
			amount.step = 'any';
			amount.removeAttribute('inputmode');
			amount.removeAttribute('data-yo-money-input');
			delete amount.dataset.yoMoneyRaw;
			delete amount.dataset.yoMoneyDirty;
		}

		document.getElementById('yo_booking_deposit_type')?.addEventListener('change', syncDepositAmountField);

		document.querySelectorAll('[data-yo-media-field]').forEach(function (field) {
			var input = field.querySelector('[data-yo-media-id]');
			var image = field.querySelector('[data-yo-media-image]');
			var placeholder = field.querySelector('[data-yo-media-placeholder]');
			var selectButton = field.querySelector('[data-yo-media-select]');
			var removeButton = field.querySelector('[data-yo-media-remove]');
			var frame;

			function updatePreview(url, alt) {
				var hasImage = Boolean(url);
				if (image) {
					image.hidden = !hasImage;
					if (hasImage) {
						image.src = url;
						image.alt = alt || field.dataset.previewAlt || '';
					} else {
						image.removeAttribute('src');
					}
				}
				if (placeholder) placeholder.hidden = hasImage;
				if (removeButton) removeButton.hidden = !hasImage;
			}

			selectButton?.addEventListener('click', function () {
				if (!window.wp?.media) return;
				if (!frame) {
					frame = window.wp.media({
						title: field.dataset.frameTitle || 'Choose an image',
						button: { text: field.dataset.frameButton || 'Use this image' },
						library: { type: 'image' },
						multiple: false,
					});
					frame.on('select', function () {
						var attachment = frame.state().get('selection').first()?.toJSON();
						if (!attachment || !input) return;
						var preview = attachment.sizes?.thumbnail || attachment.sizes?.medium;
						input.value = String(attachment.id);
						updatePreview(preview?.url || attachment.url, attachment.alt || attachment.title);
					});
					frame.on('open', function () {
						var attachmentId = Number(input?.value || 0);
						var selection = frame.state().get('selection');
						selection.reset();
						if (attachmentId) {
							var attachment = window.wp.media.attachment(attachmentId);
							attachment.fetch();
							selection.add(attachment);
						}
					});
				}
				frame.open();
			});

			removeButton?.addEventListener('click', function () {
				if (input) input.value = '';
				updatePreview('', '');
			});
		});

		document.querySelectorAll('[data-yo-color-control]').forEach(function (control) {
			var picker = control.querySelector('[data-yo-color-picker]');
			var hex = control.querySelector('[data-yo-color-hex]');
			if (!picker || !hex) return;

			function normalizeHex(value) {
				var match = String(value || '').trim().match(/^#?([0-9a-f]{6})$/i);
				return match ? '#' + match[1].toUpperCase() : '';
			}

			function setValidity(valid) {
				hex.classList.toggle('is-invalid', !valid);
				hex.setCustomValidity(valid ? '' : (window.YoBookingAdmin?.invalidHex || 'Enter a 6-digit HEX color, for example #2563EB.'));
			}

			picker.addEventListener('input', function () {
				hex.value = picker.value.toUpperCase();
				setValidity(true);
			});

			hex.addEventListener('input', function () {
				var normalized = normalizeHex(hex.value);
				if (!normalized) {
					setValidity(false);
					return;
				}

				hex.value = normalized;
				setValidity(true);
				if (picker.value.toUpperCase() !== normalized) {
					picker.value = normalized;
					picker.dispatchEvent(new Event('input', { bubbles: true }));
				}
			});

			hex.addEventListener('blur', function () {
				if (!normalizeHex(hex.value)) {
					hex.value = picker.value.toUpperCase();
					setValidity(true);
				}
			});
		});

		document.querySelectorAll('[data-yo-email-color]').forEach(function (input) {
			var preview = document.querySelector('[data-yo-email-style-preview]');

			input.addEventListener('input', function () {
				if (preview) preview.style.setProperty(input.dataset.yoEmailColor, input.value);
			});
		});

		document.querySelectorAll('[data-yo-email-footer-text]').forEach(function (input) {
			var preview = document.querySelector('[data-yo-email-footer-preview]');

			input.addEventListener('input', function () {
				if (!preview) return;
				preview.replaceChildren();
				input.value.split('Yo Booking').forEach(function (part, index) {
					if (index) {
						var link = document.createElement('a');
						link.href = 'https://yoohw.com';
						link.textContent = 'Yo Booking';
						preview.append(link);
					}
					preview.append(document.createTextNode(part));
				});
			});
		});

		function syncExceptionStaffField() {
			var scope = document.getElementById('yo_booking_exception_owner_type');
			var field = document.querySelector('[data-exception-staff-field]');
			var select = field?.querySelector('select');
			var isStaff = scope?.value === 'staff';
			if (field) field.hidden = !isStaff;
			if (select) {
				select.disabled = !isStaff;
				select.required = isStaff;
			}
		}

		document.getElementById('yo_booking_exception_owner_type')?.addEventListener('change', syncExceptionStaffField);
		syncExceptionStaffField();

		function syncReminderOffsetField() {
			var event = document.getElementById('yo_booking_notification_event');
			var row = document.querySelector('[data-reminder-offset-row]');
			var input = row?.querySelector('input');
			var isReminder = event?.value === 'appointment.reminder' || event?.value === 'payment.balance_reminder';
			if (row) row.hidden = !isReminder;
			if (input) input.disabled = !isReminder;
		}

		document.getElementById('yo_booking_notification_event')?.addEventListener('change', syncReminderOffsetField);
		syncReminderOffsetField();

		document.querySelectorAll('[data-searchable-select]').forEach(function (select) {
			var options = Array.from(select.options);
			var wrapper = document.createElement('div');
			var input = document.createElement('input');
			var list = document.createElement('div');
			var activeIndex = -1;

			wrapper.className = 'yo-search-select';
			input.type = 'search';
			input.className = 'yo-search-select__input';
			input.placeholder = select.dataset.searchPlaceholder || 'Search...';
			input.autocomplete = 'off';
			input.setAttribute('role', 'combobox');
			input.setAttribute('aria-autocomplete', 'list');
			input.setAttribute('aria-expanded', 'false');
			list.className = 'yo-search-select__options';
			list.hidden = true;
			list.setAttribute('role', 'listbox');

			function selectedLabel() {
				return select.selectedOptions[0]?.textContent || '';
			}

			function closeList() {
				list.hidden = true;
				input.setAttribute('aria-expanded', 'false');
				activeIndex = -1;
			}

			function choose(option) {
				select.value = option.value;
				input.value = option.textContent;
				select.dispatchEvent(new Event('change', { bubbles: true }));
				closeList();
			}

			function renderOptions() {
				var query = input.value.trim().toLowerCase();
				var matches = options.filter(function (option) {
					return !query || option.textContent.toLowerCase().includes(query);
				});
				list.replaceChildren();
				matches.forEach(function (option) {
					var item = document.createElement('button');
					item.type = 'button';
					item.className = 'yo-search-select__option';
					item.textContent = option.textContent;
					item.dataset.value = option.value;
					item.setAttribute('role', 'option');
					item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
					item.addEventListener('mousedown', function (event) {
						event.preventDefault();
						choose(option);
					});
					list.appendChild(item);
				});
				if (!matches.length) {
					var empty = document.createElement('span');
					empty.className = 'yo-search-select__empty';
					empty.textContent = select.dataset.noResults || 'No results found';
					list.appendChild(empty);
				}
				list.hidden = false;
				input.setAttribute('aria-expanded', 'true');
				activeIndex = -1;
			}

			input.value = selectedLabel();
			input.addEventListener('focus', function () {
				input.select();
				renderOptions();
			});
			input.addEventListener('input', renderOptions);
			input.addEventListener('keydown', function (event) {
				var items = Array.from(list.querySelectorAll('.yo-search-select__option'));
				if (event.key === 'Escape') {
					closeList();
					input.value = selectedLabel();
					return;
				}
				if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
					event.preventDefault();
					if (list.hidden) renderOptions();
					items = Array.from(list.querySelectorAll('.yo-search-select__option'));
					if (!items.length) return;
					activeIndex = 'ArrowDown' === event.key ? Math.min(activeIndex + 1, items.length - 1) : Math.max(activeIndex - 1, 0);
					items.forEach(function (item, index) {
						item.classList.toggle('is-active', index === activeIndex);
					});
					items[activeIndex].scrollIntoView({ block: 'nearest' });
				}
				if ('Enter' === event.key && activeIndex >= 0 && items[activeIndex]) {
					event.preventDefault();
					var option = options.find(function (candidate) {
						return candidate.value === items[activeIndex].dataset.value;
					});
					if (option) choose(option);
				}
			});
			input.addEventListener('blur', function () {
				window.setTimeout(function () {
					closeList();
					input.value = selectedLabel();
				}, 0);
			});

			select.classList.add('yo-search-select__native');
			select.parentNode.insertBefore(wrapper, select);
			wrapper.appendChild(select);
			wrapper.appendChild(input);
			wrapper.appendChild(list);
		});

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
				var enabled = Boolean(checkbox?.checked);
				row.classList.toggle('is-enabled', enabled);
				row.querySelectorAll('input:not([type="checkbox"]), button').forEach(function (control) {
					control.disabled = !enabled;
				});
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
				if (!row.querySelector('input[type="checkbox"]')?.checked) return;
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
				if (!row.querySelector('input[type="checkbox"]')?.checked) return;
				var sourceRanges = Array.from(row.querySelectorAll('.yo-time-range')).map(function (range) {
					var inputs = range.querySelectorAll('input[type="time"]');
					return { start: inputs[0]?.value || '09:00', end: inputs[1]?.value || '17:00' };
				});
				var sourceInterval = row.querySelector('[name$="[slot_interval_minutes]"]')?.value || '15';

				document.querySelectorAll('[data-weekday-row]').forEach(function (target) {
					if (target === row) return;
					if (!target.querySelector('input[type="checkbox"]')?.checked) return;
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
				form.querySelectorAll('[data-yo-money-input]').forEach(function (input) {
					if (input.dataset.yoMoneyDirty !== '1' && input.dataset.yoMoneyRaw !== undefined) {
						input.value = '__yo_booking_raw__:' + input.dataset.yoMoneyRaw;
					}
				});
				var submit = form.querySelector('button[type="submit"], input[type="submit"]');
				if (submit) {
					submit.classList.add('is-busy');
					submit.setAttribute('aria-busy', 'true');
				}
			});
		});
	});
})();
