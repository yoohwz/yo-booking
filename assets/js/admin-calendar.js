(function () {
	'use strict';

	var config = window.YoBookingCalendar || {};
	var drawer = null;
	var drawerBody = null;
	var calendar = null;

	function api(path, options) {
		var request = options || {};
		request.headers = Object.assign({}, request.headers || {}, {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce
		});

		return window.fetch(config.restRoot + path, request).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) {
					throw new Error(data.message || config.messages.error);
				}
				return data;
			});
		});
	}

	function element(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined && text !== null) node.textContent = text;
		return node;
	}

	function toast(message, type) {
		var notice = element('div', 'yo-toast yo-toast--' + (type || 'success'), message);
		document.body.appendChild(notice);
		window.setTimeout(function () { notice.classList.add('is-visible'); }, 10);
		window.setTimeout(function () { notice.remove(); }, 3500);
	}

	function updateStatusBadge(row, status) {
		var badge = row?.querySelector('[data-booking-status] .yo-status');
		if (!badge) return;
		badge.className = 'yo-status yo-status--' + status;
		badge.textContent = config.statuses[status] || status;
	}

	function isoDate(value) {
		var date = new Date(value);
		return new Intl.DateTimeFormat(undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
			timeZone: config.timezone || 'UTC'
		}).format(date);
	}

	function localDateTime(date) {
		function pad(value) { return String(value).padStart(2, '0'); }
		return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':00';
	}

	function statusSelect(values, selected, ariaLabel) {
		var select = element('select');
		select.setAttribute('aria-label', ariaLabel);
		Object.keys(values || {}).forEach(function (value) {
			var option = element('option', '', values[value]);
			option.value = value;
			option.selected = value === selected;
			select.appendChild(option);
		});
		return select;
	}

	function detailRow(label, value) {
		var wrapper = element('div', 'yo-detail-row');
		wrapper.appendChild(element('dt', '', label));
		wrapper.appendChild(element('dd', '', value || '-'));
		return wrapper;
	}

	function renderTimeline(title, rows, renderer) {
		var section = element('section', 'yo-drawer-section');
		section.appendChild(element('h3', '', title));
		if (!rows || !rows.length) {
			section.appendChild(element('p', 'description', 'No activity yet.'));
			return section;
		}
		var list = element('ol', 'yo-timeline');
		rows.forEach(function (row) { list.appendChild(renderer(row)); });
		section.appendChild(list);
		return section;
	}

	function renderDrawer(data) {
		var appointment = data.appointment;
		drawerBody.replaceChildren();

		var heading = element('div', 'yo-drawer-heading');
		var title = element('div');
		title.appendChild(element('h2', '', appointment.customer.name || 'Unknown customer'));
		title.appendChild(element('p', '', appointment.service.name + ' - ' + (appointment.start_display || isoDate(appointment.start))));
		heading.appendChild(title);
		var edit = element('a', 'button', 'Edit');
		edit.href = config.editUrl + appointment.id;
		heading.appendChild(edit);
		drawerBody.appendChild(heading);

		var details = element('dl', 'yo-detail-grid');
		details.appendChild(detailRow('Staff', appointment.staff.name || 'Unassigned'));
		details.appendChild(detailRow('Time', (appointment.start_display || isoDate(appointment.start)) + ' - ' + (appointment.end_display || isoDate(appointment.end))));
		details.appendChild(detailRow('Email', appointment.customer.email));
		details.appendChild(detailRow('Phone', appointment.customer.phone));
		details.appendChild(detailRow('Reference', appointment.payment_reference));
		details.appendChild(detailRow('Total', appointment.total_display));
		details.appendChild(detailRow('Paid', appointment.paid_display));
		details.appendChild(detailRow('Refunded', appointment.refunded_display));
		details.appendChild(detailRow('Remaining', appointment.balance_display));
		details.appendChild(detailRow('Payment method', data.payment && data.payment.provider_title ? data.payment.provider_title : appointment.payment_method_title));
		details.appendChild(detailRow('Timezone', appointment.timezone));
		drawerBody.appendChild(details);

		var actions = element('section', 'yo-drawer-section');
		actions.appendChild(element('h3', '', 'Quick actions'));
		var actionGrid = element('div', 'yo-drawer-actions');
		var bookingStatus = statusSelect(config.statuses, appointment.status, 'Booking status');
		var saveStatus = element('button', 'button', 'Update status');
		saveStatus.type = 'button';
		saveStatus.addEventListener('click', function () {
			saveStatus.disabled = true;
			api('appointments/' + appointment.id + '/status', { method: 'POST', body: JSON.stringify({ status: bookingStatus.value }) })
				.then(function () { toast(config.messages.updated); calendar?.refetchEvents(); return openDrawer(appointment.id); })
				.catch(function (error) { toast(error.message, 'error'); })
				.finally(function () { saveStatus.disabled = false; });
		});
		actionGrid.appendChild(bookingStatus);
		actionGrid.appendChild(saveStatus);

		var paymentStatus = statusSelect(config.paymentActions, 'paid', 'Payment action');
		var paymentAmount = element('input');
		paymentAmount.type = 'number';
		paymentAmount.min = '0';
		paymentAmount.step = 'any';
		paymentAmount.value = appointment.balance_amount || appointment.total_amount;
		paymentAmount.setAttribute('aria-label', 'Transaction amount');
		var savePayment = element('button', 'button', 'Record transaction');
		savePayment.type = 'button';
		savePayment.addEventListener('click', function () {
			savePayment.disabled = true;
			api('appointments/' + appointment.id + '/payment', { method: 'POST', body: JSON.stringify({ status: paymentStatus.value, amount: paymentAmount.value, idempotency_key: 'admin-calendar:' + appointment.id + ':' + Date.now() }) })
				.then(function () { toast(config.messages.updated); return openDrawer(appointment.id); })
				.catch(function (error) { toast(error.message, 'error'); })
				.finally(function () { savePayment.disabled = false; });
		});
		actionGrid.appendChild(paymentStatus);
		actionGrid.appendChild(paymentAmount);
		actionGrid.appendChild(savePayment);
		actions.appendChild(actionGrid);
		drawerBody.appendChild(actions);

		var notes = element('section', 'yo-drawer-section');
		notes.appendChild(element('h3', '', 'Internal note'));
		var note = element('textarea');
		note.rows = 4;
		note.value = appointment.internal_note || '';
		notes.appendChild(note);
		var saveNote = element('button', 'button button-primary', 'Save note');
		saveNote.type = 'button';
		saveNote.addEventListener('click', function () {
			saveNote.disabled = true;
			api('appointments/' + appointment.id + '/note', { method: 'POST', body: JSON.stringify({ note: note.value }) })
				.then(function () { toast(config.messages.updated); })
				.catch(function (error) { toast(error.message, 'error'); })
				.finally(function () { saveNote.disabled = false; });
		});
		notes.appendChild(saveNote);
		drawerBody.appendChild(notes);

		drawerBody.appendChild(renderTimeline('Payment history', data.payments, function (payment) {
			var item = element('li');
			item.appendChild(element('strong', '', (payment.kind || 'payment') + ' · ' + payment.status + ' · ' + payment.currency + ' ' + payment.amount));
			item.appendChild(element('span', '', payment.method || 'Manual'));
			if (payment.transaction_id) item.appendChild(element('span', '', payment.transaction_id));
			item.appendChild(element('time', '', payment.created_at));
			return item;
		}));

		drawerBody.appendChild(renderTimeline('Notification history', data.notifications, function (notification) {
			var item = element('li');
			item.appendChild(element('strong', '', notification.subject || notification.event));
			item.appendChild(element('span', '', notification.status + ' - ' + notification.recipient));
			item.appendChild(element('time', '', notification.created_at));
			return item;
		}));
	}

	function openDrawer(id) {
		if (!drawer || !drawerBody) return Promise.resolve();
		drawer.hidden = false;
		document.body.classList.add('yo-drawer-open');
		drawerBody.replaceChildren(element('p', 'yo-drawer-loading', config.messages.loading));
		return api('appointments/' + id).then(renderDrawer).catch(function (error) {
			drawerBody.replaceChildren(element('div', 'notice notice-error', error.message));
		});
	}

	function closeDrawer() {
		if (!drawer) return;
		drawer.hidden = true;
		document.body.classList.remove('yo-drawer-open');
	}

	function calendarParams(info) {
		var params = new URLSearchParams({ start: localDateTime(info.start), end: localDateTime(info.end), timezone: config.timezone || 'UTC' });
		document.querySelectorAll('[data-calendar-filter]').forEach(function (field) {
			if (field.value) params.set(field.name, field.value);
		});
		return params.toString();
	}

	function initCalendar() {
		var calendarElement = document.getElementById('yo-booking-calendar');
		if (!calendarElement || !window.FullCalendar) return;

		calendar = new window.FullCalendar.Calendar(calendarElement, {
			initialView: window.innerWidth < 783 ? 'listWeek' : 'timeGridWeek',
			timeZone: 'local',
			headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
			buttonText: { month: 'Month', week: 'Week', day: 'Day', list: 'Agenda' },
			nowIndicator: true,
			eventTimeFormat: { hour: 'numeric', minute: '2-digit', hour12: !!config.hour12 },
			selectable: true,
			editable: true,
			eventDurationEditable: true,
			events: function (info, success, failure) {
				api('appointments?' + calendarParams(info)).then(success).catch(failure);
			},
			select: function (info) {
				var selected = new Date(info.start);
				var local = localDateTime(selected);
				var date = local.slice(0, 10);
				var time = local.slice(11, 16);
				window.location.href = config.addUrl + '&date=' + encodeURIComponent(date) + '&start_time=' + encodeURIComponent(time);
			},
			eventClick: function (info) {
				info.jsEvent.preventDefault();
				openDrawer(info.event.id);
			},
			eventDrop: persistCalendarMove,
			eventResize: persistCalendarMove,
			height: 'auto'
		});
		calendar.render();

		document.querySelectorAll('[data-calendar-filter]').forEach(function (field) {
			field.addEventListener('change', function () { calendar.refetchEvents(); });
		});
	}

	function persistCalendarMove(info) {
		if (!window.confirm(config.messages.confirmReschedule)) {
			info.revert();
			return;
		}

		api('appointments/' + info.event.id + '/reschedule', {
			method: 'POST',
			body: JSON.stringify({ local_start: localDateTime(info.event.start), local_end: info.event.end ? localDateTime(info.event.end) : null, timezone: config.timezone || 'UTC' })
		}).then(function () {
			toast(config.messages.updated);
		}).catch(function (error) {
			info.revert();
			toast(error.message, 'error');
		});
	}

	function initQuickActions() {
		document.querySelectorAll('.yo-appointment-status-form').forEach(function (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				var id = form.dataset.appointmentId;
				var status = form.querySelector('[name="status"]').value;
				api('appointments/' + id + '/status', { method: 'POST', body: JSON.stringify({ status: status }) })
					.then(function () { updateStatusBadge(form.closest('tr'), status); toast(config.messages.updated); })
					.catch(function (error) { toast(error.message, 'error'); });
			});
		});

		var selectAll = document.getElementById('yo-booking-select-all');
		selectAll?.addEventListener('change', function () {
			document.querySelectorAll('[name="appointment_ids[]"]').forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
		});

		var bulk = document.getElementById('yo-booking-bulk-status-form');
		bulk?.addEventListener('submit', function (event) {
			event.preventDefault();
			var ids = Array.from(document.querySelectorAll('[name="appointment_ids[]"]:checked')).map(function (field) { return Number(field.value); });
			if (!ids.length) { toast(config.messages.selectRows, 'error'); return; }
			var status = bulk.querySelector('[name="status"]').value;
			api('appointments/bulk-status', { method: 'POST', body: JSON.stringify({ ids: ids, status: status }) })
				.then(function (result) {
					result.updated.forEach(function (id) {
						var row = document.querySelector('[data-appointment-row="' + id + '"]');
						updateStatusBadge(row, status);
						var checkbox = row?.querySelector('[name="appointment_ids[]"]');
						if (checkbox) checkbox.checked = false;
					});
					if (selectAll) selectAll.checked = false;
					toast(config.messages.updated);
				})
				.catch(function (error) { toast(error.message, 'error'); });
		});

		document.querySelectorAll('[data-appointment-drawer]').forEach(function (button) {
			button.addEventListener('click', function () { openDrawer(button.dataset.appointmentDrawer); });
		});
	}

	function initCustomerSearch() {
		var input = document.querySelector('[data-customer-autocomplete]');
		var hidden = document.getElementById('yo_booking_appointment_customer_id');
		var list = document.getElementById('yo-booking-customer-options');
		if (!input || !hidden || !list) return;

		var timer;
		var customerMap = {};
		input.addEventListener('input', function () {
			hidden.value = '0';
			window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				api('customers?search=' + encodeURIComponent(input.value)).then(function (data) {
					list.replaceChildren();
					customerMap = {};
					data.customers.forEach(function (customer) {
						var label = customer.name + (customer.email ? ' - ' + customer.email : '') + (customer.phone ? ' - ' + customer.phone : '');
						customerMap[label] = customer;
						var option = element('option');
						option.value = label;
						list.appendChild(option);
					});
				});
			}, 250);
		});
		input.addEventListener('change', function () {
			var customer = customerMap[input.value];
			if (!customer) return;
			hidden.value = customer.id;
			document.getElementById('yo_booking_appointment_customer_name').value = customer.name || '';
			document.getElementById('yo_booking_appointment_customer_email').value = customer.email || '';
			var phoneInput = document.getElementById('yo_booking_appointment_customer_phone');
			if (window.YoBookingPhone) window.YoBookingPhone.setNumber(phoneInput, customer.phone || '');
			else phoneInput.value = customer.phone || '';
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		drawer = document.getElementById('yo-appointment-drawer');
		drawerBody = document.getElementById('yo-appointment-drawer-body');
		document.querySelectorAll('[data-close-drawer]').forEach(function (button) { button.addEventListener('click', closeDrawer); });
		document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeDrawer(); });
		initCalendar();
		initQuickActions();
		initCustomerSearch();
	});
})();
