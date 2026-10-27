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
		service_graph: null,
		graph_state: null,
		graph_search_timer: null,
		graph_open: false,
		expanded_services: [],
		expandable_serviceids: [],
		filter_refresh_timer: null,
		_refresh_message_box: null,
		_popup_message_box: null,

		init({filter_options, refresh_url, refresh_interval, filter_values}) {
			// Initialize refresh URL, filters, and auto-refresh.
			// PHP CUrl uses indexed arrays; JS Curl cannot parse those as arrays.
			// Pass normalized filters directly so column changes replace the full selection.
			this.refresh_url = new Curl('zabbix.php', false);
			Object.entries(filter_values).forEach(([key, value]) => this.refresh_url.setArgument(key, value));
			this.refresh_interval = refresh_interval;

			const url = new Curl('zabbix.php', false);
			url.setArgument('action', 'treeservice.view.refresh');
			this.refresh_simple_url = url.getUrl();
			const args = new Curl(refresh_url, false).getArgumentsObject();
			const expanded = Object.prototype.hasOwnProperty.call(args, 'expanded_services')
				? args.expanded_services : this.getCookie('treeservice_expanded');
			this.expanded_services = String(expanded || '').split(',').filter(id => /^\d+$/.test(id));
			this.restoreFilterFromCookie();

			this.initTabFilter(filter_options);
			this.initFilterControls();
			this.initFilterToggle();
			this.initExportCsv();
			this.host_view_form = $('form[name=host_view]');
			this.running = true;
			this.refresh();
		},

		// Initialize optional tab filter (when enabled).
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

		// Schedule periodic counters refresh.
		createCountersRefresh(timeout) {
			if (this.refresh_counters) {
				clearTimeout(this.refresh_counters);
				this.refresh_counters = null;
			}

			return setTimeout(() => this.getFiltersCounters(), timeout);
		},

		// Fetch filter counters for tab filter.
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

		// Refresh the table and update counters.
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

		// Load the tree partial via AJAX.
		refresh() {
			this.unscheduleRefresh();
			clearTimeout(this.filter_refresh_timer);
			this.filter_refresh_timer = null;
			this.setLoading();
			const post_data = this.getRefreshPostData();

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
			deferred.done((response) => {
				if (this.deferred === deferred) {
					this.onDataDone(response);
				}
			}).fail((jqXHR) => {
				if (this.deferred === deferred) {
					this.onDataFail(jqXHR);
				}
			}).always(() => {
				if (this.deferred === deferred) {
					this.onDataAlways();
				}
			});
			return deferred;
		},

		onDataDone(response) {
			if (typeof response.body !== 'string') {
				this.clearLoading();
				if (response.messages) {
					this._addRefreshMessage(response.messages);
				}
				return;
			}
			this.clearLoading();
			this._removeRefreshMessage();
			this.destroyServiceGraph();
			this.host_view_form.replaceWith(response.body);
			this.host_view_form = $('form[name=host_view]');
			this.expandable_serviceids = response.expandable_serviceids || [];
			this.applyColumnVisibilityFromForm();
			this.initTreeEvents();
			this.initServiceGraphCollapse();
			if (this.graph_open) {
				this.initServiceGraph();
			}
			else {
				$('.service-graph-canvas').removeData('graph').removeAttr('data-graph');
			}
			if (response.last_refreshed) {
				$('.last-refreshed').text(<?= json_encode(_('Last refreshed: '), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> + response.last_refreshed);
			}

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
				const previous = this.deferred;
				this.deferred = null;
				previous.abort();
			}
		},

		initTreeEvents() {
			this.host_view_form.on('click', '.js-toggle', (event) => {
				event.preventDefault();
				const $toggle = $(event.currentTarget);
				this.serviceToFromRefreshUrl(String($toggle.attr('data-serviceid')),
					$toggle.attr('aria-expanded') === 'true');
				this.refresh();
			}).on('click', '.js-expand-all', (event) => {
				event.preventDefault();
				this.setExpandedServices(this.expandable_serviceids);
				this.refresh();
			}).on('click', '.js-collapse-all', (event) => {
				event.preventDefault();
				this.setExpandedServices([]);
				this.refresh();
			});
		},

		serviceToFromRefreshUrl(serviceid, collapsed) {
			const ids = new Set(this.expanded_services);
			if (collapsed) {
				ids.delete(serviceid);
			}
			else {
				ids.add(serviceid);
			}
			this.setExpandedServices([...ids]);
		},

		setExpandedServices(serviceIds) {
			this.expanded_services = [...new Set(serviceIds.map(String).filter(id => /^\d+$/.test(id)))];
			this.refresh_url.unsetArgument('expanded_services');
			this.refresh_url.unsetArgument('page');
			this.setCookie('treeservice_expanded', this.expanded_services.join(','), 7);
		},

		// Handle filter collapse/expand toggle.
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

		// Build POST payload for refresh based on current filters.
		getRefreshPostData() {
			const params = this.refresh_url.getArgumentsObject();
			const exclude = ['action', 'filter_src', 'filter_show_counter', 'filter_custom_time', 'filter_name'];
			const post_data = Object.keys(params)
				.filter(key => !exclude.includes(key) && params[key] !== null && params[key] !== undefined)
				.reduce((data, key) => {
					data[key] = Array.isArray(params[key])
						? params[key].filter(i => i !== '' && i !== null)
						: params[key];
					return data;
				}, {});

			post_data.expanded_services = this.expanded_services.join(',');
			post_data.cols = Array.isArray(params.cols) && params.cols.length ? params.cols : [''];
			post_data.graph_open = this.graph_open ? 1 : 0;
			post_data.graph_root_causes = 0;

			return post_data;
		},

		getExpandedServiceIds() {
			return this.expanded_services.slice();
		},

		// Bind CSV export button.
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

		// Export current visible table, using data attributes for full paths and root causes.
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
				const key = $(this).data('col') || (keys.length === 0 ? 'name' : 'status');
				keys.push(key);
				header.push($(this).text().trim());
			});
			rows.push(header);
			$table.find('tbody tr').each(function() {
				const $row = $(this);
				if (!$row.is(':visible')) {
					return;
				}
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

		// Escape values for CSV output.
		csvEscape(value) {
			const stringValue = String(value ?? '');
			if (/[",\r\n]/.test(stringValue)) {
				return '"' + stringValue.replace(/"/g, '""') + '"';
			}
			return stringValue;
		},

		// Wire filter controls and column visibility.
		initFilterControls() {
			const $filter = $('form[name="filter"]');
			if (!$filter.length) {
				return;
			}

			$filter.on('change', 'input[name="cols[]"]', () => {
				const cols = this.getSelectedColumns();
				this.refresh_url.setArgument('cols', cols.length ? cols : ['']);
				this.applyColumnVisibilityFromForm();
				clearTimeout(this.filter_refresh_timer);
				this.filter_refresh_timer = setTimeout(() => this.refresh(), 180);
			});

			$filter.on('submit', () => {
				this.storeFilterSelection();
				$filter.find('input[name="expanded_services"]').remove();
				$('<input>').attr({type: 'hidden', name: 'expanded_services'})
					.val(this.expanded_services.join(',')).appendTo($filter);
			});

			$filter.on('click', '#filter_reset', () => {
				this.clearFilterCookies();
			});

			this.applyColumnVisibilityFromForm();
		},

		// Collect selected status values from filter.
		getSelectedStatuses() {
			const statuses = [];
			$('form[name="filter"] input[name="status[]"]:checked').each(function() {
				statuses.push($(this).val());
			});
			return statuses;
		},

		// Apply status selection to checkboxes.
		setStatusCheckboxes(statuses) {
			const set = new Set(statuses);
			$('form[name="filter"] input[name="status[]"]').each(function() {
				$(this).prop('checked', set.has($(this).val()));
			});
		},

		// Restore filter selections from cookies.
		restoreFilterFromCookie() {
			const $filter = $('form[name="filter"]');
			if (!$filter.length) {
				return false;
			}
			const params = new URLSearchParams(window.location.search);
			const has_filter = [...params.keys()].some(key => /^(cols|status)(\[.*\])?$/.test(key))
				|| ['name', 'only_problems', 'show_path', 'only_with_sla', 'filter_set'].some(key => params.has(key));
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
				this.setColumnCheckboxes(cols === 'none' ? [] : cols.split(',').filter(Boolean));
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
			const selected = this.getSelectedColumns();
			this.refresh_url.setArgument('cols', selected.length ? selected : ['']);
			this.refresh_url.setArgument('status', this.getSelectedStatuses());
			['only_problems', 'show_path', 'only_with_sla'].forEach(name => {
				this.refresh_url.setArgument(name, $filter.find('input[name="' + name + '"]').is(':checked') ? 1 : 0);
			});
			return false;
		},

		// Persist filter selections in cookies for next visit.
		storeFilterSelection() {
			const cols = this.getSelectedColumns();
			const statuses = this.getSelectedStatuses();
			const onlyProblems = $('form[name="filter"] input[name="only_problems"]').is(':checked') ? '1' : '';
			const showPath = $('form[name="filter"] input[name="show_path"]').is(':checked') ? '1' : '';
			const onlyWithSla = $('form[name="filter"] input[name="only_with_sla"]').is(':checked') ? '1' : '';
			this.setCookie('treeservice_filter_cols', cols.length ? cols.join(',') : 'none', 30);
			this.setCookie('treeservice_filter_status', statuses.join(','), 30);
			this.setCookie('treeservice_filter_only_problems', onlyProblems, 30);
			this.setCookie('treeservice_filter_show_path', showPath, 30);
			this.setCookie('treeservice_filter_only_with_sla', onlyWithSla, 30);
		},

		getSelectedColumns() {
			if (!$('form[name="filter"]').length) {
				return this.refresh_url.getArgumentsObject().cols || [];
			}
			const cols = [];
			$('form[name="filter"] input[name="cols[]"]:checked').each(function() {
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

		destroyServiceGraph() {
			clearTimeout(this.graph_search_timer);
			this.graph_search_timer = null;
			if (this.service_graph) {
				this.graph_state = this.service_graph.getState();
				this.service_graph.destroy();
				this.service_graph = null;
			}
			$('.service-graph-canvas').empty().removeData('graph').removeAttr('data-graph');
		},

		initServiceGraph() {
			if (!this.graph_open) {
				return;
			}
			const $canvas = $('.service-graph-canvas');
			if (!$canvas.length) {
				return;
			}

			let graph;
			try {
				graph = $canvas.data('graph');
			}
			catch (error) {
				return;
			}

			if (!graph || !Array.isArray(graph.nodes)) {
				return;
			}

			const svg = $canvas.find('svg.service-graph-svg')[0];
			if (!svg) {
				return;
			}

			this.service_graph = this.createServiceGraph(svg, $canvas, graph);
			this.service_graph.render();
			this.service_graph.fit();

			$('.js-service-graph-orientation').off('click.service_graph').on('click.service_graph', (event) => {
				event.preventDefault();
				this.service_graph.setOrientation($(event.currentTarget).data('orientation'));
			});
			$('.js-service-graph-problems').off('click.service_graph').on('click.service_graph', (event) => {
				event.preventDefault();
				this.service_graph.toggleProblemsOnly();
			});
			$('.js-service-graph-search').off('input.service_graph').on('input.service_graph', (event) => {
				const value = event.currentTarget.value;
				if (this.graph_search_timer !== null) {
					clearTimeout(this.graph_search_timer);
				}
				this.graph_search_timer = setTimeout(() => {
					this.service_graph.setSearch(value);
					this.graph_search_timer = null;
				}, 180);
			});
			$('.js-service-graph-fit').off('click.service_graph').on('click.service_graph', (event) => {
				event.preventDefault();
				this.service_graph.fit();
			});
			$('.js-service-graph-zoom-in').off('click.service_graph').on('click.service_graph', (event) => {
				event.preventDefault();
				this.service_graph.zoom(1.2);
			});
			$('.js-service-graph-zoom-out').off('click.service_graph').on('click.service_graph', (event) => {
				event.preventDefault();
				this.service_graph.zoom(0.8);
			});
			$('.js-service-graph-reset').off('click.service_graph').on('click.service_graph', (event) => {
				event.preventDefault();
				this.service_graph.resetCollapsed();
			});
		},

		initServiceGraphCollapse() {
			const $panel = $('.service-graph-panel');
			const $toggle = $('.js-service-graph-toggle');
			const setCollapsed = (collapsed) => {
				$panel.toggleClass('is-collapsed', collapsed);
				$toggle.attr('aria-expanded', collapsed ? 'false' : 'true');
				$toggle.find('.arrow-down, .arrow-right')
					.toggleClass('arrow-down', !collapsed).toggleClass('arrow-right', collapsed);
			};
			setCollapsed(!this.graph_open);
			$toggle.off('click.service_graph_collapse').on('click.service_graph_collapse', (event) => {
				event.preventDefault();
				this.graph_open = !this.graph_open;
				setCollapsed(!this.graph_open);
				if (this.graph_open) {
					this.refresh();
				}
				else {
					this.destroyServiceGraph();
					// A pending table refresh may finish; onDataDone uses graph_open before rendering.
					// Keeping it avoids losing a branch expansion when the graph is closed mid-refresh.
					if (!this.deferred) {
						this.scheduleRefresh();
					}
				}
			});
		},

		createServiceGraph(svg, $canvas, graph) {
			const namespace = 'http://www.w3.org/2000/svg';
			const nodes = new Map(graph.nodes.map(node => [String(node.id), node]));
			const requests = new Map();
			const listenerController = new AbortController();
			let disposed = false;
			const children = new Map();
			const parents = new Map();
			graph.edges.forEach(edge => {
				const from = String(edge.from);
				const to = String(edge.to);
				if (!children.has(from)) {
					children.set(from, []);
				}
				if (!parents.has(to)) {
					parents.set(to, []);
				}
				children.get(from).push(to);
				parents.get(to).push(from);
			});

			const savedState = this.graph_state || {};
			const state = {
				scale: savedState.scale || 1,
				x: Number.isFinite(savedState.x) ? savedState.x : 24,
				y: Number.isFinite(savedState.y) ? savedState.y : 24,
				selected: savedState.selected || null,
				orientation: this.getCookie('treeservice_graph_orientation') === 'top-down' ? 'top-down' : 'left-right',
				collapsed: new Set(),
				dragging: false,
				lastPointer: null,
				clickTimer: null,
				rootCauseExpanded: new Set(),
				search: savedState.search || '',
				problemsOnly: this.getCookie('treeservice_graph_problems_only') === '1'
			};
			$('.js-service-graph-search').val(state.search);
			graph.nodes.forEach(node => {
				if (node.is_collapsed && node.has_children) {
					state.collapsed.add(String(node.id));
				}
			});

			const makeSvg = (tag, attrs = {}) => {
				const element = document.createElementNS(namespace, tag);
				Object.keys(attrs).forEach(key => element.setAttribute(key, attrs[key]));
				return element;
			};

			const collectRelated = (ids, targetSet, relations) => {
				const queue = [...ids];
				const seen = new Set(ids);
				for (let i = 0; i < queue.length; i++) {
					(relations.get(queue[i]) || []).forEach(related => {
						if (!seen.has(related)) {
							seen.add(related);
							targetSet.add(related);
							queue.push(related);
						}
					});
				}
			};

			const getFocusIds = () => {
				const focused = new Set();
				const search = state.search.trim().toLowerCase();
				if (!search && !state.problemsOnly) {
					return focused;
				}
				nodes.forEach((node, id) => {
					if ((!search || String(node.name || '').toLowerCase().includes(search))
							&& (!state.problemsOnly || Number(node.status) !== -1)) {
						focused.add(id);
					}
				});
				const matches = [...focused];
				collectRelated(matches, focused, parents);
				if (search && !state.problemsOnly) {
					collectRelated(matches, focused, children);
				}
				return focused;
			};

			const getVisibleIds = () => {
				const visible = new Set();
				const focusIds = getFocusIds();
				const hasFocus = Boolean(state.search.trim()) || state.problemsOnly;
				const roots = (graph.root_services || []).filter(id => nodes.has(String(id)));
				const queue = roots.length ? roots.map(String) : [...nodes.keys()].filter(id => !parents.has(id));
				for (let i = 0; i < queue.length; i++) {
					const id = queue[i];
					if (visible.has(id) || !nodes.has(id) || (hasFocus && !focusIds.has(id))) {
						continue;
					}
					visible.add(id);
					if (hasFocus || !state.collapsed.has(id)) {
						(children.get(id) || []).forEach(childId => queue.push(childId));
					}
				}
				return visible;
			};

			const layout = () => {
				const visible = getVisibleIds();
				if (visible.size > 500) {
					return {visible, positions: new Map(), tooLarge: true};
				}
				const depth = new Map();
				const roots = (graph.root_services || []).filter(id => visible.has(String(id))).map(String);
				let queue = roots.length ? roots : [...visible].filter(id => !parents.has(id));
				queue.forEach(id => depth.set(id, 0));
				for (let i = 0; i < queue.length; i++) {
					const id = queue[i];
					const nextDepth = (depth.get(id) || 0) + 1;
					(children.get(id) || []).forEach(childId => {
						if (!visible.has(childId)) {
							return;
						}
						if (!depth.has(childId) || nextDepth < depth.get(childId)) {
							depth.set(childId, nextDepth);
							queue.push(childId);
						}
					});
				}

				const ordered = [];
				const seen = new Set();
				const walk = (id) => {
					if (!visible.has(id) || seen.has(id)) {
						return;
					}
					seen.add(id);
					ordered.push(id);
					(children.get(id) || []).forEach(walk);
				};
				(roots.length ? roots : queue).forEach(walk);
				[...visible].forEach(id => {
					if (!seen.has(id)) {
						ordered.push(id);
					}
				});

				const positions = state.orientation === 'top-down'
					? layoutTopDown(visible, roots.length ? roots : queue, depth)
					: layoutLeftRight(ordered, depth);

				return {visible, positions};
			};

			const layoutLeftRight = (ordered, depth) => {
				const positions = new Map();
				ordered.forEach((id, index) => {
					positions.set(id, {
						x: (depth.get(id) || 0) * 250,
						y: index * 78
					});
				});

				return positions;
			};

			const layoutTopDown = (visible, roots, depth) => {
				const positions = new Map();
				const assigned = new Set();
				const visiting = new Set();
				let nextLeaf = 0;

				const place = (id) => {
					if (!visible.has(id) || !nodes.has(id)) {
						return null;
					}
					if (positions.has(id)) {
						return positions.get(id).x;
					}
					if (visiting.has(id)) {
						return null;
					}

					visiting.add(id);
					const childXs = [];
					(children.get(id) || [])
						.slice()
						.sort((a, b) => {
							const nodeA = nodes.get(a) || {};
							const nodeB = nodes.get(b) || {};
							return String(nodeA.name || '').localeCompare(String(nodeB.name || ''));
						})
						.forEach(childId => {
						const childX = place(childId);
						if (childX !== null) {
							childXs.push(childX);
						}
					});
					visiting.delete(id);

					const x = childXs.length
						? (Math.min(...childXs) + Math.max(...childXs)) / 2
						: nextLeaf++ * 260;
					positions.set(id, {
						x,
						y: (depth.get(id) || 0) * 120
					});
					assigned.add(id);

					return x;
				};

				roots.forEach(place);
				[...visible].forEach(id => {
					if (!assigned.has(id)) {
						place(id);
					}
				});

				return positions;
			};

			const applyTransform = (viewport) => {
				viewport.setAttribute('transform', 'translate(' + state.x + ' ' + state.y + ') scale(' + state.scale + ')');
			};

			const updateDetails = (node) => {
				const $details = $canvas.find('.service-graph-details');
				if (!node) {
					$details.text(<?= json_encode(_('No service selected'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
					return;
				}
				if (node.root_causes_error) {
					$details.empty().append($('<strong>').text(node.name))
						.append($('<span>').text(<?= json_encode(_('Cannot load root causes.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>))
						.append($('<button>').attr('type', 'button').addClass('btn-alt')
							.text(<?= json_encode(_('Retry'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>).on('click', () => {
								node.root_causes_error = false;
								updateDetails(node);
							}));
					return;
				}
				if (Number(node.status) !== -1 && !node.root_causes_loaded) {
					$details.empty()
						.append($('<strong>').text(node.name))
						.append($('<span>').addClass('service-graph-empty').text(<?= json_encode(_('Loading'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> + '...'));
					loadRootCauses(node);
					return;
				}
				const hasChildren = node.has_children;
				const branchLabel = state.collapsed.has(String(node.id))
					? <?= json_encode(_('Show branch'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
					: <?= json_encode(_('Hide branch'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
				$details.empty()
					.append($('<strong>').text(node.name))
					.append($('<span>').addClass(node.status_class).addClass('service-graph-status-label').text(node.status_text))
					.append($('<span>').text('SLA: ' + node.sla + ' / SLO: ' + node.slo))
					.append($('<a>')
						.attr('href', node.url)
						.attr('target', '_blank')
						.attr('rel', 'noopener noreferrer')
						.text(<?= json_encode(_('Open service'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>));

				const rootCauses = Array.isArray(node.root_causes) ? node.root_causes : [];
				const $rootCauseWrap = $('<div>').addClass('service-graph-root-causes');
				$rootCauseWrap.append(
					$('<div>').addClass('service-graph-root-cause-header')
						.append($('<span>').addClass('service-graph-root-cause-title').text(<?= json_encode(_('Root cause'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>))
				);
				if (rootCauses.length) {
					const rootCauseLimit = 5;
					const isExpanded = state.rootCauseExpanded.has(String(node.id));
					const visibleRootCauses = isExpanded ? rootCauses : rootCauses.slice(0, rootCauseLimit);
					const $list = $('<ul>');
					visibleRootCauses.forEach((problem) => {
						const $item = $('<li>').addClass('service-graph-root-cause-item');
						if (problem.url) {
							$item.append($('<a>')
								.attr('href', problem.url)
								.attr('target', '_blank')
								.attr('rel', 'noopener noreferrer')
								.text(problem.name));
						}
						else {
							$item.text(problem.name);
						}
						$list.append($item);
					});
					$rootCauseWrap.append($list);
					if (rootCauses.length > rootCauseLimit) {
						const hiddenCount = rootCauses.length - rootCauseLimit;
						$rootCauseWrap.append(
							$('<button>')
								.attr('type', 'button')
								.addClass('btn-alt service-graph-root-cause-more')
								.text(isExpanded
									? <?= json_encode(_('Show less'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
									: <?= json_encode(_('Load more'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> + ' (' + hiddenCount + ')')
								.on('click', () => {
									const nodeId = String(node.id);
									if (state.rootCauseExpanded.has(nodeId)) {
										state.rootCauseExpanded.delete(nodeId);
									}
									else {
										state.rootCauseExpanded.add(nodeId);
									}
									updateDetails(node);
								})
						);
					}
				}
				else {
					$rootCauseWrap.append($('<span>').addClass('service-graph-empty').text(<?= json_encode(_('None'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>));
				}
				$details.append($rootCauseWrap);

				if (hasChildren) {
					$details.append(
						$('<button>')
							.attr('type', 'button')
							.addClass('btn-alt service-graph-branch-toggle')
							.text(branchLabel)
							.on('click', () => toggleBranch(String(node.id)))
					);
				}
			};

			const loadRootCauses = (node) => {
				const serviceid = String(node.id);
				if (disposed || requests.has(serviceid)) {
					return;
				}
				const url = new Curl('zabbix.php', false);
				url.setArgument('action', 'treeservice.rootcauses');
				const request = $.ajax({
					url: url.getUrl(), data: {serviceid}, type: 'post', dataType: 'json'
				});
				requests.set(serviceid, request);
				request.done((response) => {
					if (disposed) {
						return;
					}
					if (!Array.isArray(response.root_causes)) {
						node.root_causes_error = true;
					}
					else {
						node.root_causes = response.root_causes;
						node.root_causes_loaded = true;
					}
					if (state.selected === serviceid) {
						render();
					}
				}).fail(() => {
					if (!disposed) {
						node.root_causes_error = true;
						if (state.selected === serviceid) {
							updateDetails(node);
						}
					}
				}).always(() => requests.delete(serviceid));
			};

			const toggleBranch = (id) => {
				if (!nodes.get(id)?.has_children) {
					return;
				}
				const expanded = new Set(this.getExpandedServiceIds());
				if (state.collapsed.has(id)) {
					state.collapsed.delete(id);
					expanded.add(id);
				}
				else {
					state.collapsed.add(id);
					expanded.delete(id);
				}
				state.selected = id;
				this.setExpandedServices([...expanded]);
				this.refresh();
			};

			const render = () => {
				if (disposed) {
					return;
				}
				svg.innerHTML = '';
				const viewport = makeSvg('g', {'class': 'service-graph-viewport'});
				const edgeLayer = makeSvg('g', {'class': 'service-graph-edges'});
				const nodeLayer = makeSvg('g', {'class': 'service-graph-nodes'});
				viewport.appendChild(edgeLayer);
				viewport.appendChild(nodeLayer);
				svg.appendChild(viewport);

				const {visible, positions, tooLarge} = layout();
				if (tooLarge) {
					const notice = makeSvg('text', {x: 24, y: 32, 'class': 'service-graph-large-notice'});
					notice.textContent = <?= json_encode(_('Too many services to render. Use search or Problems only.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
					nodeLayer.appendChild(notice);
					applyTransform(viewport);
					return;
				}
				graph.edges.forEach(edge => {
					const from = String(edge.from);
					const to = String(edge.to);
					if (!visible.has(from) || !visible.has(to) || !positions.has(from) || !positions.has(to)) {
						return;
					}
					const start = positions.get(from);
					const end = positions.get(to);
					const path = state.orientation === 'top-down'
						? 'M' + (start.x + 95) + ' ' + (start.y + 50) + ' C' + (start.x + 95) + ' ' + (start.y + 78) + ', ' + (end.x + 95) + ' ' + (end.y - 28) + ', ' + (end.x + 95) + ' ' + end.y
						: 'M' + (start.x + 190) + ' ' + (start.y + 25) + ' C' + (start.x + 220) + ' ' + (start.y + 25) + ', ' + (end.x - 30) + ' ' + (end.y + 25) + ', ' + end.x + ' ' + (end.y + 25);
					edgeLayer.appendChild(makeSvg('path', {d: path, 'class': 'service-graph-edge'}));
				});

				positions.forEach((position, id) => {
					const node = nodes.get(id);
					const group = makeSvg('g', {
						'class': 'service-graph-node service-graph-status-' + node.status + (state.selected === id ? ' is-selected' : ''),
						'transform': 'translate(' + position.x + ' ' + position.y + ')',
						'tabindex': '0'
					});
					group.appendChild(makeSvg('rect', {width: 190, height: 50, rx: 3, ry: 3, 'class': 'service-graph-node-box'}));
					group.appendChild(makeSvg('text', {x: 14, y: 21, 'class': 'service-graph-node-name'}));
					group.lastChild.textContent = node.name.length > 24 ? node.name.substring(0, 21) + '...' : node.name;
					group.appendChild(makeSvg('text', {x: 14, y: 39, 'class': 'service-graph-node-meta'}));
					group.lastChild.textContent = node.status_text + ' | SLA ' + node.sla;
					if (node.has_children) {
						const marker = makeSvg('text', {x: 172, y: 30, 'class': 'service-graph-node-toggle'});
						marker.textContent = state.collapsed.has(id) ? '+' : '-';
						group.appendChild(marker);
					}
					const rootCauseCount = Array.isArray(node.root_causes) ? node.root_causes.length : 0;
					if (rootCauseCount > 0) {
						const countText = String(rootCauseCount);
						const pillWidth = Math.max(22, 14 + countText.length * 7);
						group.appendChild(makeSvg('rect', {
							x: 190 - pillWidth - 8,
							y: -6,
							width: pillWidth,
							height: 18,
							rx: 9,
							ry: 9,
							'class': 'service-graph-node-root-cause-pill'
						}));
						const pillText = makeSvg('text', {
							x: 190 - 8 - (pillWidth / 2),
							y: 4,
							'class': 'service-graph-node-root-cause-count'
						});
						pillText.textContent = countText;
						group.appendChild(pillText);
					}
					group.addEventListener('click', () => {
						if (state.clickTimer !== null) {
							clearTimeout(state.clickTimer);
						}
						state.clickTimer = setTimeout(() => {
							state.selected = id;
							updateDetails(node);
							render();
							state.clickTimer = null;
						}, 180);
					});
					group.addEventListener('dblclick', (event) => {
						event.preventDefault();
						event.stopPropagation();
						if (state.clickTimer !== null) {
							clearTimeout(state.clickTimer);
							state.clickTimer = null;
						}
						toggleBranch(id);
					});
					nodeLayer.appendChild(group);
				});

				applyTransform(viewport);
				updateDetails(state.selected ? nodes.get(state.selected) : null);
			};

			const graphApi = {
				render,
				destroy: () => {
					disposed = true;
					clearTimeout(state.clickTimer);
					listenerController.abort();
					requests.forEach(request => request.abort());
					requests.clear();
					svg.innerHTML = '';
				},
				setSearch: (value) => {
					state.search = String(value || '');
					render();
					graphApi.fit();
				},
				toggleProblemsOnly: () => {
					state.problemsOnly = !state.problemsOnly;
					this.setCookie('treeservice_graph_problems_only', state.problemsOnly ? '1' : '', 30);
					render();
					graphApi.fit();
				},
				setOrientation: (orientation) => {
					state.orientation = orientation === 'top-down' ? 'top-down' : 'left-right';
					this.setCookie('treeservice_graph_orientation', state.orientation, 30);
					render();
					graphApi.fit();
				},
				fit: () => {
					const viewport = svg.querySelector('.service-graph-viewport');
					if (!viewport) {
						return;
					}
					let box;
					try {
						box = viewport.getBBox();
					}
					catch (error) {
						return;
					}
					const width = svg.clientWidth || 800;
					const height = svg.clientHeight || 360;
					state.scale = Math.max(0.35, Math.min(1.4, Math.min((width - 48) / Math.max(box.width, 1), (height - 48) / Math.max(box.height, 1))));
					state.x = 24 - box.x * state.scale;
					state.y = 24 - box.y * state.scale;
					applyTransform(viewport);
					$('.js-service-graph-orientation').removeClass('is-selected');
					$('.js-service-graph-orientation[data-orientation="' + state.orientation + '"]').addClass('is-selected');
					$('.js-service-graph-problems').toggleClass('is-selected', state.problemsOnly);
				},
				zoom: (factor) => {
					state.scale = Math.max(0.3, Math.min(2.5, state.scale * factor));
					const viewport = svg.querySelector('.service-graph-viewport');
					if (viewport) {
						applyTransform(viewport);
					}
				},
				resetCollapsed: () => {
					state.selected = null;
					state.search = '';
					state.problemsOnly = false;
					$('.js-service-graph-search').val('');
					this.setCookie('treeservice_graph_problems_only', '', 30);
					state.scale = 1;
					state.x = 24;
					state.y = 24;
					render();
					graphApi.fit();
				},
				getState: () => {
					return {
						selected: state.selected,
						search: state.search,
						scale: state.scale,
						x: state.x,
						y: state.y
					};
				}
			};

			svg.addEventListener('pointerdown', (event) => {
				if ($(event.target).closest('.service-graph-node').length) {
					return;
				}
				state.dragging = true;
				state.lastPointer = {x: event.clientX, y: event.clientY};
				svg.setPointerCapture(event.pointerId);
			}, {signal: listenerController.signal});
			svg.addEventListener('pointermove', (event) => {
				if (!state.dragging || !state.lastPointer) {
					return;
				}
				state.x += event.clientX - state.lastPointer.x;
				state.y += event.clientY - state.lastPointer.y;
				state.lastPointer = {x: event.clientX, y: event.clientY};
				applyTransform(svg.querySelector('.service-graph-viewport'));
			}, {signal: listenerController.signal});
			svg.addEventListener('pointerup', () => {
				state.dragging = false;
				state.lastPointer = null;
			}, {signal: listenerController.signal});
			svg.addEventListener('pointercancel', () => {
				state.dragging = false;
				state.lastPointer = null;
			}, {signal: listenerController.signal});
			svg.addEventListener('wheel', (event) => {
				if (!event.ctrlKey) {
					return;
				}
				event.preventDefault();
				graphApi.zoom(event.deltaY < 0 ? 1.08 : 0.92);
			}, {passive: false, signal: listenerController.signal});

			return graphApi;
		},

		// Clear persisted filter cookies.
		clearFilterCookies() {
			this.setCookie('treeservice_filter_cols', '', -1);
			this.setCookie('treeservice_filter_status', '', -1);
			this.setCookie('treeservice_filter_only_problems', '', -1);
			this.setCookie('treeservice_filter_show_path', '', -1);
			this.setCookie('treeservice_filter_only_with_sla', '', -1);
		},

		// Write a cookie value.
		setCookie(name, value, days) {
			let expires = '';
			if (days) {
				const date = new Date();
				date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
				expires = '; expires=' + date.toUTCString();
			}
			document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/';
		},

		// Read a cookie value.
		getCookie(name) {
			const name_eq = name + '=';
			const parts = document.cookie.split(';');
			for (let i = 0; i < parts.length; i++) {
				let part = parts[i].trim();
				if (part.indexOf(name_eq) === 0) {
					try {
						return decodeURIComponent(part.substring(name_eq.length));
					}
					catch (error) {
						return '';
					}
				}
			}
			return '';
		},

		events: {}
	};
</script>
