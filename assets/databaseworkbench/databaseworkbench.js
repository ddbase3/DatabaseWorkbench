(function(global) {
	'use strict';

	function translate(config, key, fallback, replacements) {
		var value = String(config && config.strings && config.strings[key] != null ? config.strings[key] : '').trim();
		if (value === '') value = fallback;
		Object.keys(replacements || {}).forEach(function(name) {
			value = value.split('{' + name + '}').join(String(replacements[name]));
		});
		return value;
	}

	function localizeError(config, message) {
		var raw = String(message || '').trim();

		if (raw === 'No insertable values were provided.') return translate(config, 'error_no_insert_values', 'No insertable values were provided.');
		if (raw === 'No update values were provided.') return translate(config, 'error_no_update_values', 'No update values were provided.');
		if (raw === 'SQL must not be empty.') return translate(config, 'error_sql_empty', 'SQL must not be empty.');
		if (raw === 'The configured database connection is not available.') return translate(config, 'error_connection_unavailable', 'The configured database connection is not available.');
		if (raw === 'Table name is required.') return translate(config, 'error_table_required', 'Table name is required.');
		if (raw === 'This operation requires a primary key.') return translate(config, 'error_primary_key_required', 'This operation requires a primary key.');
		if (raw === 'Unable to read the CREATE TABLE statement.') return translate(config, 'error_create_table_read', 'Unable to read the CREATE TABLE statement.');
		if (raw === 'The request token is invalid or the session has expired.') return translate(config, 'error_request_token', 'The request token is invalid or the session has expired.');
		if (raw.indexOf('Unknown table: ') === 0) return translate(config, 'error_unknown_table', 'Unknown table: {table}', {table: raw.substring(15)});
		if (raw.indexOf('Missing primary key value: ') === 0) return translate(config, 'error_primary_key_value', 'Missing primary key value: {column}', {column: raw.substring(27)});
		if (raw.indexOf('Unknown DatabaseWorkbench action: ') === 0) return translate(config, 'error_unknown_action', 'Unknown DatabaseWorkbench action.');
		if (raw.indexOf('Database error ') === 0) return translate(config, 'error_database', 'The database operation failed.');

		return translate(config, 'request_failed', 'DatabaseWorkbench request failed.');
	}

	function element(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined && text !== null) node.textContent = String(text);
		return node;
	}

	function button(label, className, handler) {
		var node = element('button', className || 'databaseworkbench-button', label);
		node.type = 'button';
		if (handler) node.addEventListener('click', handler);
		return node;
	}

	function formatBytes(value) {
		var bytes = Number(value || 0);
		if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var index = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
		return (bytes / Math.pow(1024, index)).toFixed(index === 0 ? 0 : 1) + ' ' + units[index];
	}

	function displayValue(value) {
		if (value === null) return 'NULL';
		if (typeof value === 'object') return JSON.stringify(value);
		return String(value);
	}

	function DatabaseWorkbenchApp(config) {
		this.config = config;
		this.root = document.getElementById(config.containerId);
		this.state = {
			tables: [],
			selectedTable: '',
			page: 1,
			pageSize: Number(config.pageSize || 50),
			orderBy: '',
			orderDirection: 'asc',
			browse: null,
			structure: null
		};
	}

	DatabaseWorkbenchApp.prototype.mount = function() {
		if (!this.root || this.root.dataset.mounted === '1') return;
		this.root.dataset.mounted = '1';
		this.buildShell();
		this.loadTables().then(this.loadOverview.bind(this));
	};

	DatabaseWorkbenchApp.prototype.buildShell = function() {
		var self = this;
		this.root.replaceChildren();

		var header = element('div', 'databaseworkbench-header');
		var title = element('div', 'databaseworkbench-title', this.root.dataset.title || translate(this.config, 'title', 'Database Workbench'));
		var status = element('div', 'databaseworkbench-status', translate(this.config, 'ready', 'Ready'));
		this.status = status;
		header.append(title, status);

		var layout = element('div', 'databaseworkbench-layout');
		var sidebar = element('aside', 'databaseworkbench-sidebar');
		var sidebarHead = element('div', 'databaseworkbench-sidebar-head');
		sidebarHead.append(
			element('strong', '', translate(this.config, 'tables', 'Tables')),
			button(translate(this.config, 'reload', 'Reload'), 'databaseworkbench-button databaseworkbench-button-small', function() {
				self.loadTables();
			})
		);
		var filter = element('input', 'databaseworkbench-filter');
		filter.type = 'search';
		filter.placeholder = translate(this.config, 'filter_tables', 'Filter tables');
		filter.addEventListener('input', function() {
			self.renderTableList(filter.value);
		});
		this.tableFilter = filter;
		this.tableList = element('div', 'databaseworkbench-table-list');
		sidebar.append(sidebarHead, filter, this.tableList);

		var main = element('main', 'databaseworkbench-main');
		this.tabs = element('div', 'databaseworkbench-tabs');
		this.content = element('div', 'databaseworkbench-content');
		main.append(this.tabs, this.content);
		layout.append(sidebar, main);
		this.root.append(header, layout);

		this.setTabs([
			{ id: 'overview', label: translate(this.config, 'overview', 'Overview'), action: this.loadOverview.bind(this) },
			{ id: 'browse', label: translate(this.config, 'browse', 'Browse'), action: this.loadBrowse.bind(this) },
			{ id: 'structure', label: translate(this.config, 'structure', 'Structure'), action: this.loadStructure.bind(this) },
			{ id: 'sql', label: 'SQL', action: this.renderSql.bind(this) }
		], 'overview');
	};

	DatabaseWorkbenchApp.prototype.setTabs = function(tabs, activeId) {
		var self = this;
		this.tabs.replaceChildren();
		tabs.forEach(function(tab) {
			var node = button(tab.label, 'databaseworkbench-tab' + (tab.id === activeId ? ' is-active' : ''), function() {
				self.tabs.querySelectorAll('.databaseworkbench-tab').forEach(function(item) {
					item.classList.remove('is-active');
				});
				node.classList.add('is-active');
				tab.action();
			});
			node.dataset.tabId = tab.id;
			self.tabs.appendChild(node);
		});
	};

	DatabaseWorkbenchApp.prototype.activateTab = function(tabId) {
		this.tabs.querySelectorAll('.databaseworkbench-tab').forEach(function(item) {
			item.classList.toggle('is-active', item.dataset.tabId === tabId);
		});
	};

	DatabaseWorkbenchApp.prototype.setStatus = function(message, type) {
		this.status.textContent = message;
		this.status.className = 'databaseworkbench-status' + (type ? ' is-' + type : '');
	};

	DatabaseWorkbenchApp.prototype.request = async function(action, payload) {
		this.setStatus(translate(this.config, 'loading', 'Loading…'), 'loading');
		var response;
		try {
			response = await fetch(this.config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(Object.assign({}, payload || {}, {
					action: action,
					csrf: this.config.csrf
				}))
			});
			var result = await response.json();
			if (!result.ok) throw new Error(result.error || 'DatabaseWorkbench request failed.');
			this.setStatus(translate(this.config, 'ready', 'Ready'), 'ok');
			return result.data;
		}
		catch (error) {
			var message = localizeError(this.config, error.message || String(error));
			this.setStatus(message, 'error');
			throw new Error(message);
		}
	};

	DatabaseWorkbenchApp.prototype.loadTables = async function() {
		var data = await this.request('tables');
		this.state.tables = data.tables || [];
		if (this.state.selectedTable && !this.state.tables.some(function(item) {
			return item.name === this.state.selectedTable;
		}, this)) {
			this.state.selectedTable = '';
		}
		this.renderTableList(this.tableFilter.value);
	};

	DatabaseWorkbenchApp.prototype.renderTableList = function(filterValue) {
		var self = this;
		var filter = String(filterValue || '').toLowerCase();
		this.tableList.replaceChildren();
		this.state.tables.filter(function(table) {
			return table.name.toLowerCase().includes(filter);
		}).forEach(function(table) {
			var row = button('', 'databaseworkbench-table-item' + (table.name === self.state.selectedTable ? ' is-active' : ''), function() {
				self.state.selectedTable = table.name;
				self.state.page = 1;
				self.state.orderBy = '';
				self.renderTableList(self.tableFilter.value);
				self.activateTab('browse');
				self.loadBrowse();
			});
			row.append(
				element('span', 'databaseworkbench-table-name', table.name),
				element('span', 'databaseworkbench-table-count', table.rows)
			);
			self.tableList.appendChild(row);
		});
	};

	DatabaseWorkbenchApp.prototype.requireSelectedTable = function() {
		if (this.state.selectedTable) return true;
		this.renderNotice(translate(this.config, 'select_table_first', 'Select a table first.'));
		return false;
	};

	DatabaseWorkbenchApp.prototype.loadOverview = async function() {
		var data = await this.request('overview');
		this.renderOverview(data);
	};

	DatabaseWorkbenchApp.prototype.renderOverview = function(data) {
		this.content.replaceChildren();
		var cards = element('div', 'databaseworkbench-cards');
		[
			[translate(this.config, 'database', 'Database'), data.database || '—'],
			[translate(this.config, 'server', 'Server'), data.version || '—'],
			[translate(this.config, 'tables', 'Tables'), data.tables || 0],
			[translate(this.config, 'charset', 'Charset'), data.charset || '—'],
			[translate(this.config, 'collation', 'Collation'), data.collation || '—']
		].forEach(function(item) {
			var card = element('div', 'databaseworkbench-card');
			card.append(element('span', 'databaseworkbench-card-label', item[0]), element('strong', '', item[1]));
			cards.appendChild(card);
		});

		var table = this.createDataTable(
			[translate(this.config, 'table', 'Table'), translate(this.config, 'engine', 'Engine'), translate(this.config, 'rows', 'Rows'), translate(this.config, 'data', 'Data'), translate(this.config, 'indexes', 'Indexes'), translate(this.config, 'collation', 'Collation')],
			this.state.tables.map(function(item) {
				return [item.name, item.engine, item.rows, formatBytes(item.data_length), formatBytes(item.index_length), item.collation];
			})
		);
		this.content.append(cards, table);
	};

	DatabaseWorkbenchApp.prototype.loadBrowse = async function() {
		if (!this.requireSelectedTable()) return;
		var data = await this.request('browse', {
			table: this.state.selectedTable,
			page: this.state.page,
			page_size: this.state.pageSize,
			order_by: this.state.orderBy,
			order_direction: this.state.orderDirection
		});
		this.state.browse = data;
		this.state.orderBy = data.order_by || '';
		this.state.orderDirection = data.order_direction || 'asc';
		this.renderBrowse(data);
	};

	DatabaseWorkbenchApp.prototype.renderBrowse = function(data) {
		var self = this;
		this.content.replaceChildren();
		var toolbar = element('div', 'databaseworkbench-toolbar');
		var summary = element('span', 'databaseworkbench-toolbar-summary', translate(this.config, 'table_rows', '{table} · {count} rows', {table: data.table, count: data.total}));
		toolbar.append(
			summary,
			button(translate(this.config, 'insert_row', 'Insert row'), 'databaseworkbench-button', function() {
				self.openRowEditor('insert', {}, {});
			}),
			button(translate(this.config, 'export_sql', 'Export SQL'), 'databaseworkbench-button', this.exportSelectedTable.bind(this)),
			button(translate(this.config, 'truncate', 'Truncate'), 'databaseworkbench-button databaseworkbench-button-danger', this.truncateSelectedTable.bind(this)),
			button(translate(this.config, 'drop', 'Drop'), 'databaseworkbench-button databaseworkbench-button-danger', this.dropSelectedTable.bind(this))
		);

		var wrap = element('div', 'databaseworkbench-grid-wrap');
		var table = element('table', 'databaseworkbench-grid');
		var head = element('thead');
		var headRow = element('tr');
		headRow.appendChild(element('th', 'databaseworkbench-actions-column', translate(this.config, 'actions', 'Actions')));
		data.columns.forEach(function(column) {
			var name = column.Field;
			var th = element('th', 'databaseworkbench-sortable', name + (data.order_by === name ? (data.order_direction === 'desc' ? ' ↓' : ' ↑') : ''));
			th.addEventListener('click', function() {
				if (self.state.orderBy === name) {
					self.state.orderDirection = self.state.orderDirection === 'asc' ? 'desc' : 'asc';
				}
				else {
					self.state.orderBy = name;
					self.state.orderDirection = 'asc';
				}
				self.state.page = 1;
				self.loadBrowse();
			});
			headRow.appendChild(th);
		});
		head.appendChild(headRow);
		table.appendChild(head);

		var body = element('tbody');
		data.rows.forEach(function(row) {
			var tr = element('tr');
			var actions = element('td', 'databaseworkbench-row-actions');
			if ((data.primary_key || []).length > 0) {
				actions.append(
					button(translate(self.config, 'edit', 'Edit'), 'databaseworkbench-button databaseworkbench-button-small', function() {
						self.openRowEditor('update', row.values, row.key);
					}),
					button(translate(self.config, 'delete', 'Delete'), 'databaseworkbench-button databaseworkbench-button-small databaseworkbench-button-danger', function() {
						self.deleteRow(row.key);
					})
				);
			}
			else {
				actions.appendChild(element('span', 'databaseworkbench-muted', translate(self.config, 'no_primary_key', 'No primary key')));
			}
			tr.appendChild(actions);
			data.columns.forEach(function(column) {
				var value = row.values[column.Field];
				var td = element('td', value === null ? 'is-null' : '', displayValue(value));
				td.title = displayValue(value);
				tr.appendChild(td);
			});
			body.appendChild(tr);
		});
		table.appendChild(body);
		wrap.appendChild(table);

		var pager = element('div', 'databaseworkbench-pager');
		var previous = button(translate(this.config, 'previous', 'Previous'), 'databaseworkbench-button databaseworkbench-button-small', function() {
			if (self.state.page > 1) {
				self.state.page -= 1;
				self.loadBrowse();
			}
		});
		previous.disabled = data.page <= 1;
		var next = button(translate(this.config, 'next', 'Next'), 'databaseworkbench-button databaseworkbench-button-small', function() {
			if (self.state.page < data.total_pages) {
				self.state.page += 1;
				self.loadBrowse();
			}
		});
		next.disabled = data.page >= data.total_pages;
		pager.append(previous, element('span', '', translate(this.config, 'page_status', 'Page {page} of {totalPages}', {page: data.page, totalPages: data.total_pages})), next);
		this.content.append(toolbar, wrap, pager);
	};

	DatabaseWorkbenchApp.prototype.loadStructure = async function() {
		if (!this.requireSelectedTable()) return;
		var data = await this.request('structure', { table: this.state.selectedTable });
		this.state.structure = data;
		this.renderStructure(data);
	};

	DatabaseWorkbenchApp.prototype.renderStructure = function(data) {
		this.content.replaceChildren();
		this.content.appendChild(element('h3', '', translate(this.config, 'columns', 'Columns')));
		this.content.appendChild(this.createObjectTable(data.columns));
		this.content.appendChild(element('h3', '', translate(this.config, 'indexes', 'Indexes')));
		this.content.appendChild(this.createObjectTable(data.indexes));
		this.content.appendChild(element('h3', '', 'CREATE TABLE'));
		var pre = element('pre', 'databaseworkbench-sql-output', data.create_sql);
		this.content.appendChild(pre);
	};

	DatabaseWorkbenchApp.prototype.renderSql = function() {
		var self = this;
		this.content.replaceChildren();
		var editor = element('textarea', 'databaseworkbench-sql-editor');
		editor.spellcheck = false;
		editor.value = this.state.selectedTable ? 'SELECT * FROM `' + this.state.selectedTable.replace(/`/g, '``') + '` LIMIT 100;' : 'SHOW TABLES;';
		var run = button(translate(this.config, 'run_sql', 'Run SQL'), 'databaseworkbench-button databaseworkbench-button-primary', async function() {
			try {
				var data = await self.request('sql', { sql: editor.value });
				self.renderSqlResult(editor, data);
			}
			catch (error) {
				self.renderSqlResult(editor, { error: error.message || String(error) });
			}
		});
		var notice = element('p', 'databaseworkbench-warning', translate(this.config, 'sql_warning', 'SQL is executed with the permissions of the configured database connection.'));
		this.content.append(editor, run, notice, element('div', 'databaseworkbench-sql-result'));
	};

	DatabaseWorkbenchApp.prototype.renderSqlResult = function(editor, data) {
		var result = this.content.querySelector('.databaseworkbench-sql-result');
		result.replaceChildren();
		if (data.error) {
			result.appendChild(element('pre', 'databaseworkbench-error-block', data.error));
			return;
		}
		if (data.type === 'rows') {
			result.appendChild(element('p', 'databaseworkbench-muted', translate(this.config, 'sql_rows', '{count} rows{suffix}', {count: data.row_count, suffix: data.truncated ? translate(this.config, 'truncated_suffix', ' (truncated)') : ''})));
			result.appendChild(this.createObjectTable(data.rows));
			return;
		}
		result.appendChild(element('p', '', translate(this.config, 'affected_rows', 'Affected rows: {count}', {count: data.affected_rows}) + (data.insert_id ? ' · ' + translate(this.config, 'insert_id', 'Insert ID: {id}', {id: data.insert_id}) : '')));
	};

	DatabaseWorkbenchApp.prototype.openRowEditor = function(mode, values, key) {
		var self = this;
		var browse = this.state.browse;
		if (!browse) return;
		var overlay = element('div', 'databaseworkbench-modal-overlay');
		var modal = element('div', 'databaseworkbench-modal');
		var title = element('h3', '', mode === 'insert' ? translate(this.config, 'insert_row', 'Insert row') : translate(this.config, 'edit_row', 'Edit row'));
		var form = element('form', 'databaseworkbench-form');
		var fields = {};
		var nullInputs = {};

		browse.columns.forEach(function(column) {
			var name = column.Field;
			if (String(column.Extra || '').toLowerCase().includes('generated')) return;
			var row = element('label', 'databaseworkbench-field');
			var label = element('span', 'databaseworkbench-field-label', name + ' · ' + column.Type);
			var input = element('textarea', 'databaseworkbench-field-input');
			input.rows = 2;
			input.value = values[name] === null || values[name] === undefined ? '' : displayValue(values[name]);
			if (mode === 'insert' && String(column.Extra || '').includes('auto_increment')) {
				input.placeholder = translate(self.config, 'auto_increment', 'auto increment');
			}
			var nullLabel = element('label', 'databaseworkbench-null-toggle');
			var nullInput = document.createElement('input');
			nullInput.type = 'checkbox';
			nullInput.checked = values[name] === null;
			nullInput.disabled = String(column.Null || '').toUpperCase() !== 'YES';
			nullLabel.append(nullInput, document.createTextNode(' NULL'));
			row.append(label, input, nullLabel);
			form.appendChild(row);
			fields[name] = input;
			nullInputs[name] = nullInput;
		});

		var actions = element('div', 'databaseworkbench-modal-actions');
		actions.append(
			button(translate(this.config, 'cancel', 'Cancel'), 'databaseworkbench-button', function() { overlay.remove(); }),
			button(mode === 'insert' ? translate(this.config, 'insert', 'Insert') : translate(this.config, 'save', 'Save'), 'databaseworkbench-button databaseworkbench-button-primary', async function() {
				var payloadValues = {};
				var nullColumns = [];
				Object.keys(fields).forEach(function(name) {
					payloadValues[name] = fields[name].value;
					if (nullInputs[name].checked) nullColumns.push(name);
				});
				await self.request(mode, {
					table: self.state.selectedTable,
					key: key,
					values: payloadValues,
					null_columns: nullColumns
				});
				overlay.remove();
				self.loadBrowse();
			})
		);
		modal.append(title, form, actions);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
	};

	DatabaseWorkbenchApp.prototype.deleteRow = async function(key) {
		if (!global.confirm(translate(this.config, 'confirm_delete_row', 'Delete this row permanently?'))) return;
		await this.request('delete', { table: this.state.selectedTable, key: key });
		this.loadBrowse();
	};

	DatabaseWorkbenchApp.prototype.truncateSelectedTable = async function() {
		if (!global.confirm(translate(this.config, 'confirm_truncate', 'Delete all rows from {table}?', {table: this.state.selectedTable}))) return;
		await this.request('truncate', { table: this.state.selectedTable });
		this.state.page = 1;
		this.loadBrowse();
		this.loadTables();
	};

	DatabaseWorkbenchApp.prototype.dropSelectedTable = async function() {
		if (!global.confirm(translate(this.config, 'confirm_drop', 'Drop table {table} permanently?', {table: this.state.selectedTable}))) return;
		await this.request('drop', { table: this.state.selectedTable });
		this.state.selectedTable = '';
		await this.loadTables();
		this.activateTab('overview');
		this.loadOverview();
	};

	DatabaseWorkbenchApp.prototype.exportSelectedTable = async function() {
		var data = await this.request('export', { table: this.state.selectedTable });
		var blob = new Blob([data.content], { type: 'application/sql;charset=utf-8' });
		var url = URL.createObjectURL(blob);
		var link = document.createElement('a');
		link.href = url;
		link.download = data.filename;
		document.body.appendChild(link);
		link.click();
		link.remove();
		URL.revokeObjectURL(url);
	};

	DatabaseWorkbenchApp.prototype.createDataTable = function(headers, rows) {
		var wrap = element('div', 'databaseworkbench-grid-wrap');
		var table = element('table', 'databaseworkbench-grid');
		var thead = element('thead');
		var headRow = element('tr');
		headers.forEach(function(header) { headRow.appendChild(element('th', '', header)); });
		thead.appendChild(headRow);
		table.appendChild(thead);
		var body = element('tbody');
		rows.forEach(function(row) {
			var tr = element('tr');
			row.forEach(function(value) { tr.appendChild(element('td', value === null ? 'is-null' : '', displayValue(value))); });
			body.appendChild(tr);
		});
		table.appendChild(body);
		wrap.appendChild(table);
		return wrap;
	};

	DatabaseWorkbenchApp.prototype.createObjectTable = function(rows) {
		if (!rows || rows.length === 0) return element('div', 'databaseworkbench-notice', translate(this.config, 'no_data', 'No data.'));
		var headers = [];
		rows.forEach(function(row) {
			Object.keys(row).forEach(function(key) {
				if (!headers.includes(key)) headers.push(key);
			});
		});
		return this.createDataTable(headers, rows.map(function(row) {
			return headers.map(function(header) { return row[header] === undefined ? '' : row[header]; });
		}));
	};

	DatabaseWorkbenchApp.prototype.renderNotice = function(message) {
		var notice = element('div', 'databaseworkbench-notice', message);
		this.content.replaceChildren(notice);
		return notice;
	};

	global.DatabaseWorkbench = {
		mount: function(config) {
			new DatabaseWorkbenchApp(config).mount();
		}
	};
})(window);
