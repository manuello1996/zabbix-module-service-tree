<?php
/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * @var CView $this
 */

?>
<script type="text/javascript">
	const view = {
		host_view_form: null,
		filter: null,
		refresh_url: null,
		refresh_simple_url: null,
		refresh_interval: null,
		refresh_counters: null,
		running: false,
		timeout: null,
		deferred: null,
		_refresh_message_box: null,
		_popup_message_box: null,

		init({filter_options, refresh_url, refresh_interval}) {
			this.refresh_url = new Curl(refresh_url, false);
			this.refresh_interval = refresh_interval;

			const url = new Curl('zabbix.php', false);
			url.setArgument('action', 'treeservice.view.refresh');
			this.refresh_simple_url = url.getUrl();
			expanded_services = this.refresh_url.getArgument('expanded_services');
			if (expanded_services) {
				this.refresh_simple_url += '&expanded_services=' + expanded_services;
			}
			else {
				const saved = this.getCookie('treeservice_expanded');
				if (saved) {
					this.refresh_simple_url += '&expanded_services=' + saved;
				}
			}

			if (this.restoreFilterFromCookie()) {
				return;
			}

			this.initTabFilter(filter_options);
			this.initFilterControls();
			this.initFilterToggle();
			this.initExportCsv();

			this.host_view_form = $('form[name=host_view]');
			this.running = true;
			this.refresh();
		},

		initTabFilter(filter_options) {
			if (!filter_options) {
				return;
			}

			this.filter = new CTabFilter($('#monitoring_services_filter')[0], filter_options);
			this.filter.on(TABFILTER_EVENT_URLSET, () => {
				this.reloadPartialAndTabCounters();
			});
			this.refresh_counters = this.createCountersRefresh(1);
		},

		createCountersRefresh(timeout) {
			if (this.refresh_counters) {
				clearTimeout(this.refresh_counters);
				this.refresh_counters = null;
			}

			return setTimeout(() => this.getFiltersCounters(), timeout);
		},

		getFiltersCounters() {
			if (!this.filter) {
				return;
			}

			return $.post(this.refresh_simple_url, {
				filter_counters: 1
			})
			.done((json) => {
				if (json.filter_counters) {
					this.filter.updateCounters(json.filter_counters);
				}
			})
			.always(() => {
				if (this.refresh_interval > 0) {
					this.refresh_counters = this.createCountersRefresh(this.refresh_interval);
				}
			});
		},

		reloadPartialAndTabCounters() {
			this.refresh_url = new Curl('', false);

			this.unscheduleRefresh();
			this.refresh();

			// Filter is not present in Kiosk mode.
			if (this.filter) {
				const filter_item = this.filter._active_item;

				if (this.filter._active_item.hasCounter()) {
					$.post(this.refresh_simple_url, {
						filter_counters: 1,
						counter_index: filter_item._index
					}).done((json) => {
						if (json.filter_counters) {
							filter_item.updateCounter(json.filter_counters.pop());
						}
					});
				}
			}
		},

		_addRefreshMessage(messages) {
			this._removeRefreshMessage();

			this._refresh_message_box = $($.parseHTML(messages));
			addMessage(this._refresh_message_box);
		},

		_removeRefreshMessage() {
			if (this._refresh_message_box !== null) {
				this._refresh_message_box.remove();
				this._refresh_message_box = null;
			}
		},

		_addPopupMessage(message_box) {
			this._removePopupMessage();

			this._popup_message_box = message_box;
			addMessage(this._popup_message_box);
		},

		_removePopupMessage() {
			if (this._popup_message_box !== null) {
				this._popup_message_box.remove();
				this._popup_message_box = null;
			}
		},

		refresh() {
			this.setLoading();
			const params = this.refresh_url.getArgumentsObject();
			const exclude = ['action', 'filter_src', 'filter_show_counter', 'filter_custom_time', 'filter_name'];
			const post_data = Object.keys(params)
				.filter(key => !exclude.includes(key))
				.reduce((post_data, key) => {
					post_data[key] = (typeof params[key] === 'object')
						? [...params[key]].filter(i => i)
						: params[key];
					return post_data;
				}, {});

			this.deferred = $.ajax({
				url: this.refresh_simple_url,
				data: post_data,
				type: 'post',
				dataType: 'json'
			});
			return this.bindDataEvents(this.deferred);
		},

		setLoading() {
			this.host_view_form.addClass('is-loading is-loading-fadein delayed-15s');
		},

		clearLoading() {
			this.host_view_form.removeClass('is-loading is-loading-fadein delayed-15s');
		},

		bindDataEvents(deferred) {
			deferred
				.done((response) => {
					this.onDataDone.call(this, response);
				})
				.fail((jqXHR) => {
					this.onDataFail.call(this, jqXHR);
				})
				.always(this.onDataAlways.bind(this));

			return deferred;
		},

		onDataDone(response) {
			this.clearLoading();
			this._removeRefreshMessage();
			this.host_view_form.replaceWith(response.body);
			this.host_view_form = $('form[name=host_view]');
			this.applyColumnVisibilityFromForm();

			if ('groupids' in response) {
				this.applied_filter_groupids = response.groupids;
			}

			if ('messages' in response) {
				this._addRefreshMessage(response.messages);
			}
		},

		onDataFail(jqXHR) {
			// Ignore failures caused by page unload.
			if (jqXHR.status == 0) {
				return;
			}

			this.clearLoading();

			const messages = $(jqXHR.responseText).find('.msg-global');

			if (messages.length) {
				this.host_view_form.html(messages);
			}
			else {
				this.host_view_form.html(jqXHR.responseText);
			}
		},

		onDataAlways() {
			if (this.running) {
				this.deferred = null;
				this.scheduleRefresh();
			}
		},

		scheduleRefresh() {
			this.unscheduleRefresh();

			if (this.refresh_interval > 0) {
				this.timeout = setTimeout((function () {
					this.timeout = null;
					this.refresh();
				}).bind(this), this.refresh_interval);
			}
		},

		unscheduleRefresh() {
			if (this.timeout !== null) {
				clearTimeout(this.timeout);
				this.timeout = null;
			}

			if (this.deferred) {
				this.deferred.abort();
			}
		},

		serviceToFromRefreshUrl(serviceid, collapsed) {
			this.refresh_url.unsetArgument('expanded_services');
			const regex = /\&expanded_services=([\d,]+)/g;
			const found = this.refresh_simple_url.match(regex);
			let updated_ids = [];
			if (found !== null) {
				// There is at least one service in expanded_services in URL
				this.refresh_simple_url = this.refresh_simple_url.replace(found[0], ''); // Remove expanded_services from URL
				service_ids = found[0].split('=')[1].split(',');
				idx = service_ids.indexOf(serviceid);
				updated_ids = service_ids.slice(0);
				if (idx == -1) {
					// This service does not exist in expanded_services=
					if (!collapsed){
						updated_ids.push(serviceid);
						this.refresh_simple_url += '&expanded_services=' + updated_ids.join(',');
					} else {
						this.refresh_simple_url += '&expanded_services=' + updated_ids.join(',');
					}
				} else {
					// This service exists in expanded_services=
					if (collapsed) {
						// It's collapsed so remove it from expanded_services=
						updated_ids.splice(idx, 1);
						if (updated_ids.length > 0) {
							this.refresh_simple_url += '&expanded_services=' + updated_ids.join(',');
						}
					}
				}
			} else {
				// There is no expanded_services in URL yet
				if (!collapsed) {
					updated_ids = [serviceid];
					this.refresh_simple_url += '&expanded_services=' + updated_ids.join(',');
				}
			}

			this.setCookie('treeservice_expanded', updated_ids.join(','), 7);

			if (collapsed) {
				this.refresh_url.unsetArgument('page');

			}
		},

		setExpandedServices(serviceIds) {
			this.refresh_url.unsetArgument('expanded_services');
			const regex = /\&expanded_services=([\d,]+)/g;
			const found = this.refresh_simple_url.match(regex);
			if (found !== null) {
				this.refresh_simple_url = this.refresh_simple_url.replace(found[0], '');
			}

			if (serviceIds && serviceIds.length) {
				this.refresh_simple_url += '&expanded_services=' + serviceIds.join(',');
			}

			this.setCookie('treeservice_expanded', serviceIds.join(','), 7);

			this.refresh_url.unsetArgument('page');
		},

		initFilterToggle() {
			const $toggle = $('.js-filter-toggle');
			if (!$toggle.length) {
				return;
			}
			const $container = $toggle.closest('.filter-container');
			$toggle.on('click', (event) => {
				event.preventDefault();
				$container.toggleClass('is-collapsed');
			});
		},

		initExportCsv() {
			const $button = $('.js-export-csv');
			if (!$button.length) {
				return;
			}
			$button.off('click.treeservice_export').on('click.treeservice_export', (event) => {
				event.preventDefault();
				this.exportTableCsv();
			});
		},

		exportTableCsv() {
			const $table = $('.services-tree');
			if (!$table.length) {
				return;
			}
			const rows = [];
			const header = [];
			const keys = [];
			$table.find('thead th').each(function() {
				if (!$(this).is(':visible')) {
					return;
				}
				const key = $(this).data('col') || $(this).text().trim().toLowerCase();
				keys.push(key);
				header.push($(this).text().trim());
			});
			rows.push(header);
			$table.find('tbody tr').each(function() {
				const $row = $(this);
				const row = [];
				let colIndex = 0;
				$row.find('td').each(function() {
					if (!$(this).is(':visible')) {
						return;
					}
					const key = keys[colIndex] || '';
					let value = $(this).text().replace(/\s+/g, ' ').trim();
					if (key === 'name') {
						const path = $row.data('name-path');
						if (path) {
							value = String(path);
						}
					}
					if (key === 'root_cause') {
						const rootCauses = $row.data('root-causes');
						value = rootCauses ? String(rootCauses) : '';
					}
					if (value === '-') {
						value = '';
					}
					row.push(value);
					colIndex++;
				});
				rows.push(row);
			});
			const csv = rows.map(r => r.map(this.csvEscape).join(',')).join('\n');
			const bom = '\ufeff';
			const blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
			const url = URL.createObjectURL(blob);
			const link = document.createElement('a');
			link.href = url;
			link.download = 'services_tree.csv';
			link.style.display = 'none';
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
			URL.revokeObjectURL(url);
		},

		csvEscape(value) {
			const stringValue = String(value ?? '');
			if (/[",\n]/.test(stringValue)) {
				return '"' + stringValue.replace(/"/g, '""') + '"';
			}
			return stringValue;
		},

		initFilterControls() {
			const $filter = $('form[name="filter"]');
			if (!$filter.length) {
				return;
			}

			$filter.on('change', 'input[name="cols[]"]', () => {
				this.applyColumnVisibilityFromForm();
			});

			$filter.on('submit', () => {
				this.storeFilterSelection();
			});

			$filter.on('click', '#filter_reset', () => {
				this.clearFilterCookies();
			});

			this.applyColumnVisibilityFromForm();
		},

		getSelectedStatuses() {
			const statuses = [];
			$('form[name="filter"] input[name="status[]"]:checked').each(function() {
				statuses.push($(this).val());
			});
			return statuses;
		},

		setStatusCheckboxes(statuses) {
			const set = new Set(statuses);
			$('form[name="filter"] input[name="status[]"]').each(function() {
				$(this).prop('checked', set.has($(this).val()));
			});
		},

		restoreFilterFromCookie() {
			const $filter = $('form[name="filter"]');
			if (!$filter.length) {
				return false;
			}
			const params = new URLSearchParams(window.location.search);
			const has_filter = params.has('cols[]') || params.has('status[]')
				|| params.has('only_problems') || params.has('show_path');
			if (has_filter) {
				return false;
			}
			const cols = this.getCookie('treeservice_filter_cols');
			const status = this.getCookie('treeservice_filter_status');
			const onlyProblems = this.getCookie('treeservice_filter_only_problems');
			const showPath = this.getCookie('treeservice_filter_show_path');
			const onlyWithSla = this.getCookie('treeservice_filter_only_with_sla');
			if (!cols && !status && !onlyProblems && !showPath && !onlyWithSla) {
				return false;
			}
			if (cols) {
				this.setColumnCheckboxes(cols.split(',').filter(Boolean));
			}
			if (status) {
				this.setStatusCheckboxes(status.split(',').filter(Boolean));
			}
			if (onlyProblems === '1') {
				$filter.find('input[name="only_problems"]').prop('checked', true);
			}
			if (showPath === '1') {
				$filter.find('input[name="show_path"]').prop('checked', true);
			}
			if (onlyWithSla === '1') {
				$filter.find('input[name="only_with_sla"]').prop('checked', true);
			}
			$filter.trigger('submit');
			return true;
		},

		storeFilterSelection() {
			const cols = this.getSelectedColumns();
			const statuses = this.getSelectedStatuses();
			const onlyProblems = $('form[name="filter"] input[name="only_problems"]').is(':checked') ? '1' : '';
			const showPath = $('form[name="filter"] input[name="show_path"]').is(':checked') ? '1' : '';
			const onlyWithSla = $('form[name="filter"] input[name="only_with_sla"]').is(':checked') ? '1' : '';
			this.setCookie('treeservice_filter_cols', cols.join(','), 30);
			this.setCookie('treeservice_filter_status', statuses.join(','), 30);
			this.setCookie('treeservice_filter_only_problems', onlyProblems, 30);
			this.setCookie('treeservice_filter_show_path', showPath, 30);
			this.setCookie('treeservice_filter_only_with_sla', onlyWithSla, 30);
		},

		getSelectedColumns() {
			const cols = [];
			$('form[name="filter"] input[name="cols[]"]:checked').each(function() {
				cols.push($(this).val());
			});
			return cols;
		},

		getAllColumns() {
			const cols = [];
			$('form[name="filter"] input[name="cols[]"]').each(function() {
				cols.push($(this).val());
			});
			return cols;
		},

		setColumnCheckboxes(cols) {
			const set = new Set(cols);
			$('form[name="filter"] input[name="cols[]"]').each(function() {
				$(this).prop('checked', set.has($(this).val()));
			});
		},

		applyColumnVisibilityFromForm() {
			const cols = this.getSelectedColumns();
			if (!cols.length) {
				this.applyColumnVisibility([]);
				return;
			}
			this.applyColumnVisibility(cols);
		},

		applyColumnVisibility(cols) {
			const $table = $('.services-tree');
			if (!$table.length) {
				return;
			}

			const selected = new Set(cols);
			$table.find('[data-col]').each(function() {
				const col = $(this).data('col');
				if (selected.has(String(col))) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		},

		clearFilterCookies() {
			this.setCookie('treeservice_filter_cols', '', -1);
			this.setCookie('treeservice_filter_status', '', -1);
			this.setCookie('treeservice_filter_only_problems', '', -1);
			this.setCookie('treeservice_filter_show_path', '', -1);
			this.setCookie('treeservice_filter_only_with_sla', '', -1);
		},

		setCookie(name, value, days) {
			let expires = '';
			if (days) {
				const date = new Date();
				date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
				expires = '; expires=' + date.toUTCString();
			}
			document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/';
		},

		getCookie(name) {
			const name_eq = name + '=';
			const parts = document.cookie.split(';');
			for (let i = 0; i < parts.length; i++) {
				let part = parts[i].trim();
				if (part.indexOf(name_eq) === 0) {
					return decodeURIComponent(part.substring(name_eq.length, part.length));
				}
			}
			return '';
		},

		events: {}
	};
</script>
