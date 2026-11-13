/*
 * LAKA Network – módulo de red para Zabbix 7.0 – 8.0
 * © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3
 *
 * Todos los elementos interactivos declaran data-action="..."; un único manejador de clics los despacha (ver _actions()).
 * Los datos llegan como JSON en nodos ocultos [data-json]: un conjunto de topología para todo el widget y uno
 * por equipo. Ventanas, tooltips, gráficas y mapas se arman bajo demanda a partir de esos datos.
 */
class CWidgetLakaNetworkSwitch extends CWidget {

	/*
	 * Ciclo de vida. Todo lo privado de este widget usa el prefijo "_lk" para no chocar nunca con miembros de la clase
	 * base CWidget de Zabbix, y los manejadores de eventos viven en su propio mapa (this._lkHandlers) en lugar del objeto
	 * "_events" de la clase base. Los manejadores se enlazan al elemento de la consola en cada dibujo, y un MutationObserver
	 * reinicia la consola cuando Zabbix reemplaza el contenido del widget, sea cual sea el hook que use la versión de Zabbix.
	 */
	onStart() {
		super.onStart?.();
		this._lkSetup();
	}

	onActivate() {
		super.onActivate?.();
		this._lkSetup();
		this._lkWatchContents(true);
		this._lkInit();
	}

	onDeactivate() {
		super.onDeactivate?.();
		this._lkWatchContents(false);
		this._lkResizeObserver?.disconnect();
		this._lkCloseModal();
		this._lkHideTooltip();
	}

	onClearContents() {
		super.onClearContents?.();
		this._lkCloseModal();
		this._lkHideTooltip();
	}

	processUpdateResponse(response) {
		super.processUpdateResponse(response);
		this._lkInit();
	}

	_lkSetup() {
		if (this._lkHandlers) return;
		this._lkHandlers = {
			click: event => this._lkSafe(() => this._lkOnClick(event)),
			mouseover: event => this._lkSafe(() => this._lkOnHover(event, true)),
			mouseout: event => this._lkSafe(() => this._lkOnHover(event, false)),
			input: event => this._lkSafe(() => this._lkOnInput(event)),
			keyup: event => this._lkSafe(() => this._lkOnKeyUp(event)),
			pointerdown: event => this._lkSafe(() => this._lkOnPointerDown(event)),
			pointermove: event => this._lkSafe(() => this._lkOnPointerMove(event)),
			pointerup: event => this._lkSafe(() => this._lkOnPointerUp(event)),
			pointercancel: event => this._lkSafe(() => this._lkOnPointerUp(event)),
			wheel: event => this._lkSafe(() => this._lkOnWheel(event))
		};
		this._lkModalHandlers = {
			click: event => this._lkSafe(() => this._lkOnModalClick(event)),
			keydown: event => {
				if (event.key === 'Escape') this._lkCloseModal();
				else if (event.key === 'Enter' && event.target?.dataset?.route) {
					event.preventDefault();
					this._lkSafe(() => this._lkRunRoute());
				}
				else if (event.key === 'Enter' && event.target?.dataset?.locate === 'input') {
					event.preventDefault();
					this._lkSafe(() => this._lkRunLocate());
				}
			}
		};
		this._lkViews = new Map();
		this._lkDeviceCache = new Map();
		this._lkPaneCache = new Map();
		this._lkPaneTime = new Map();
		this._lkModal = null;
		this._lkTooltip = null;
		this._lkDrag = null;
	}

	_lkLocale() {
		return this._lkSettings?.locale || undefined;
	}

	_lkSafe(callback) {
		try {
			return callback();
		}
		catch (error) {
			console.error('[LAKA Network]', error);
		}
	}

	/** Enlaza los manejadores delegados al elemento actual de la consola (una vez por elemento). */
	_lkBind(console) {
		if (!console || console._lkBound) return;
		for (const [type, handler] of Object.entries(this._lkHandlers)) {
			console.addEventListener(type, handler, type === 'wheel' ? {passive: false} : false);
		}
		console._lkBound = true;
	}

	_lkWatchContents(on) {
		this._lkMutationObserver?.disconnect();
		if (!on || typeof MutationObserver === 'undefined' || !this._target) return;
		this._lkMutationObserver = new MutationObserver(() => {
			const console = this._target.querySelector('.laka-console');
			if (console && console !== this._lkConsole) this._lkInit();
		});
		this._lkMutationObserver.observe(this._target, {childList: true, subtree: true});
	}

	// ================================================================== arranque

	_lkInit() {
		this._lkSetup();
		const console = this._target?.querySelector('.laka-console');
		if (!console) return;
		this._lkConsole = console;
		this._lkBind(console);
		this._lkSafe(() => this._lkApplyTheme());
		try {
			this._lkInitConsole();
			console.classList.add('laka-js-ready');
		}
		catch (error) {
			console.classList.add('laka-js-error');
			window.console.error('[LAKA Network] initialisation failed', error);
		}
	}

	_lkInitConsole() {
		this._lkI18n = this._lkJson(this._lkConsole.dataset.i18n, {});
		this._lkSettings = this._lkJson(this._lkConsole.dataset.settings, {});
		this._lkCanManage = this._lkConsole.dataset.canManage === '1';
		this._lkTopo = this._lkJson(this._lkConsole.querySelector('.laka-topology-data')?.dataset.json,
			{hosts: [], links: [], endpoints: [], endpoint_groups: []});
		this._lkConnectedMap = null;
		this._lkPaneCache ??= new Map();
		this._lkDeviceCache.clear();
		this._lkHideTooltip();
		this._lkSyncViewTools();
		// El refresco del widget reemplaza la consola: en modo NOC se vuelve a aplicar sobre la nueva.
		if (this._lkNoc) {
			this._lkConsole.classList.add('laka-noc');
			for (const stale of this._lkConsole.querySelectorAll('.laka-noc-bar')) stale.remove();
			(this._lkConsole.querySelector('.laka-main') || this._lkConsole).prepend(this._lkNoc.bar);
			this._lkNocRender();
		}

		this._lkSetNavCollapsed(this._lkGet('nav') === 'collapsed');
		// Redes grandes: las regiones empiezan contraídas (salvo la del equipo seleccionado) hasta que el usuario lo cambie.
		const storedCollapse = this._lkJson(this._lkGet('regions-collapsed'), null);
		const groups = [...this._lkConsole.querySelectorAll('.laka-region-group')];
		const many = this._lkConsole.querySelectorAll('.laka-device').length > 40;
		const selectedRegion = this._lkConsole.querySelector(`.laka-device[data-hostid="${CSS.escape(this._lkGet('selected') || '')}"]`)
			?.dataset.region;
		const collapsedRegions = new Set(Array.isArray(storedCollapse) ? storedCollapse
			: many ? groups.map(group => group.dataset.region).filter(region => region !== selectedRegion) : []);
		for (const group of this._lkConsole.querySelectorAll('.laka-region-group')) {
			this._lkSetRegionCollapsed(group, collapsedRegions.has(group.dataset.region));
		}
		for (const pane of this._lkConsole.querySelectorAll('.laka-pane:not(.laka-pane-lazy)')) this._lkInitPane(pane);
		const global = this._lkConsole.querySelector('.laka-global-topology .laka-topology');
		if (global) this._lkInitStage(global);
		this._lkObserveStages();

		this._lkFleetData = null;
		const selected = this._lkGet('selected');
		const large = this._lkMultiRegion() || this._lkConsole.querySelectorAll('.laka-device').length > 30;
		if (selected === '__fleet__' || (!selected && large)) this._lkShowFleet(false);
		else if (selected === '__topology__') this._lkShowTopology(false);
		else if (selected && this._lkConsole.querySelector(`.laka-device[data-hostid="${CSS.escape(selected)}"]`)) {
			this._lkSelectDevice(selected, false);
		}
		else {
			const active = this._lkConsole.querySelector('.laka-device.active');
			if (active) this._lkSelectDevice(active.dataset.hostid, false);
			else this._lkRefreshVisible();
		}
	}

	/** Restaura el estado por equipo de un panel (pestaña, modo de mapa, alto del mapa). */
	_lkInitPane(pane) {
		const root = pane.querySelector('.laka-sw');
		if (!root) return;
		const tab = this._lkGet(`tab:${root.dataset.hostid}`);
		if (tab && root.querySelector(`[data-action="tab"][data-value="${CSS.escape(tab)}"]`)) this._lkSetTab(root, tab, false);
		const panel = root.querySelector('.laka-tab-panel[data-tab="topology"] .laka-topology');
		if (panel) {
			const mode = this._lkGet(`map-mode:${panel.dataset.scope}`) || 'both';
			panel.dataset.mode = mode;
			for (const button of panel.querySelectorAll('[data-action="map-mode"]')) button.classList.toggle('active', button.dataset.value === mode);
			this._lkInitStage(panel);
		}
	}

	_lkInitStage(panel) {
		const stage = panel.querySelector('.laka-topology-stage');
		const height = Number(this._lkGet(`stage:${panel.dataset.scope}`));
		if (stage && height >= 260 && height <= 2000) stage.style.height = `${height}px`;
		if (stage && this._lkResizeObserver) this._lkResizeObserver.observe(stage);
	}

	// ================================================================== detalle de equipo bajo demanda

	/** Se resuelve cuando el panel del equipo está disponible (lo carga en modo resumen). */
	_lkEnsureDevice(hostid) {
		hostid = String(hostid);
		const pane = this._lkConsole?.querySelector(`.laka-pane[data-hostid="${CSS.escape(hostid)}"]`);
		if (!pane) return Promise.reject(new Error('unknown host'));
		if (!pane.classList.contains('laka-pane-lazy')) return Promise.resolve(pane);
		const cached = this._lkPaneCache.get(hostid);
		if (cached) {
			this._lkFillPane(pane, hostid, cached);
			// Se actualiza en segundo plano para que la copia en caché nunca oculte datos nuevos por mucho tiempo (no si se acaba de precargar).
			if (!pane._refreshing && Date.now() - (this._lkPaneTime.get(hostid) || 0) > 30000) {
				pane._refreshing = this._lkFetchPane(hostid).then(html => {
					if (pane.isConnected && html !== cached) {
						this._lkFillPane(pane, hostid, html);
						if (pane.classList.contains('active')) this._lkRefreshVisible();
					}
				}).catch(() => {}).finally(() => { pane._refreshing = null; });
			}
			return Promise.resolve(pane);
		}
		if (!pane._loading) {
			pane._loading = this._lkFetchPane(hostid)
				.then(html => {
					this._lkFillPane(pane, hostid, html);
					return pane;
				})
				.catch(error => {
					pane.innerHTML = `<div class="laka-empty">${this._lkEsc(this._lkT('Could not load the equipment detail.'))}
						<div class="laka-muted">${this._lkEsc(error.message || '')}</div>
						<button type="button" class="laka-btn" data-action="retry-pane">${this._lkEsc(this._lkT('Retry'))}</button></div>`;
					throw error;
				})
				.finally(() => { pane._loading = null; });
		}
		return pane._loading;
	}

	_lkFetchPane(hostid) {
		const base = typeof this.getUpdateRequestData === 'function' ? this.getUpdateRequestData()
			: typeof this._getUpdateRequestData === 'function' ? this._getUpdateRequestData() : {};
		const url = new Curl('zabbix.php');
		url.setArgument('action', 'widget.laka_network_switch.view');
		return fetch(url.getUrl(), {
			method: 'POST', credentials: 'same-origin', cache: 'no-store',
			headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
			body: JSON.stringify({...base, laka_detail: hostid})
		})
			.then(response => response.json())
			.then(response => {
				if (response.error) {
					throw new Error([response.error.title, ...(response.error.messages || [])].filter(Boolean).join(' '));
				}
				const template = document.createElement('template');
				template.innerHTML = response.body || '';
				const pane = template.content.querySelector('.laka-pane');
				if (!pane) throw new Error(this._lkT('Could not load the equipment detail.'));
				return pane.innerHTML;
			});
	}

	/** Pide el panel de un equipo sin mostrarlo (uno a la vez), para que abra al instante al hacer clic. */
	_lkPrefetch(hostid) {
		hostid = String(hostid || '');
		if (!hostid || this._lkPrefetching || this._lkPaneCache.has(hostid)) return;
		const pane = this._lkConsole?.querySelector(`.laka-pane[data-hostid="${CSS.escape(hostid)}"]`);
		if (!pane || !pane.classList.contains('laka-pane-lazy') || pane._loading) return;
		this._lkPrefetching = this._lkFetchPane(hostid)
			.then(html => {
				this._lkPaneCache.set(hostid, html);
				this._lkPaneTime.set(hostid, Date.now());
				if (this._lkPaneCache.size > 12) this._lkPaneCache.delete(this._lkPaneCache.keys().next().value);
			})
			.catch(() => {})
			.finally(() => { this._lkPrefetching = null; });
	}

	_lkFillPane(pane, hostid, html) {
		pane.innerHTML = html;
		pane.classList.remove('laka-pane-lazy');
		this._lkPaneCache.set(hostid, html);
		this._lkPaneTime.set(hostid, Date.now());
		if (this._lkPaneCache.size > 12) this._lkPaneCache.delete(this._lkPaneCache.keys().next().value);
		this._lkDeviceCache.delete(hostid);
		this._lkInitPane(pane);
	}

	_lkApplyTheme() {
		const html = document.documentElement;
		const theme = (html.getAttribute('theme') || '').toLowerCase();
		const scheme = (html.getAttribute('color-scheme') || '').toLowerCase();
		let dark;
		// Las versiones nuevas de Zabbix publican color-scheme (cubre los temas dark-blue/dark-classic de 8.0); las anteriores solo el nombre del tema.
		if (scheme === 'dark' || scheme === 'light') dark = scheme === 'dark';
		else if (theme !== '') dark = /dark/.test(theme);
		else {
			const color = getComputedStyle(this._target.closest('.dashboard-widget') || document.body).backgroundColor;
			const [r, g, b] = (color.match(/[\d.]+/g) || [255, 255, 255]).map(Number);
			dark = (r * .2126 + g * .7152 + b * .0722) / 255 < .5;
		}
		this._lkThemeClasses = ['laka-theme-' + (dark ? 'dark' : 'light')];
		if (/^hc-/.test(theme)) this._lkThemeClasses.push('laka-theme-hc');
		this._target.classList.remove('laka-theme-dark', 'laka-theme-light', 'laka-theme-hc');
		// "laka-scope" eleva la especificidad de cada regla del widget por encima de los estilos globales de botones/inputs de Zabbix.
		this._target.classList.add('laka-scope', ...this._lkThemeClasses);
	}

	_lkRefreshVisible() {
		const pane = this._lkConsole?.querySelector('.laka-pane.active');
		const root = pane?.querySelector('.laka-sw');
		if (root?.dataset.activeTab === 'topology') this._lkRenderTopology(root.querySelector('.laka-topology'));
		if (root?.dataset.activeTab === 'consumption') {
			this._lkLoadHeatmap(root);
			this._lkRenderFeatured(root);
		}
		if (root?.dataset.activeTab === 'interfaces') this._lkLoadSparks(root);
		const global = this._lkConsole?.querySelector('.laka-global-topology.active .laka-topology');
		if (global) this._lkRenderTopology(global);
	}

	// ================================================================== utilidades

	_lkT(key, ...args) {
		let text = this._lkI18n?.[key] ?? key;
		args.forEach((value, index) => { text = text.replace(`%${index + 1}$s`, String(value)); });
		return text;
	}

	_lkJson(text, fallback) {
		try { return text ? JSON.parse(text) : fallback; }
		catch (error) { return fallback; }
	}

	_lkStorageKey(name) {
		return `laka-net:${this._widgetid || this._lkConsole?.dataset.consoleKey || 'widget'}:${name}`;
	}

	_lkGet(name) {
		try { return localStorage.getItem(this._lkStorageKey(name)); }
		catch (error) { return null; }
	}

	_lkSet(name, value) {
		try {
			if (value === null) localStorage.removeItem(this._lkStorageKey(name));
			else localStorage.setItem(this._lkStorageKey(name), String(value));
		}
		catch (error) {}
	}

	_lkEsc(value) {
		return String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'})[char]);
	}

	_lkClip(value, max) {
		const text = String(value ?? '');
		return text.length > max ? `${text.slice(0, max - 1)}…` : text;
	}

	_lkRate(value) {
		const number = Math.abs(Number(value) || 0);
		for (const [size, unit] of [[1e12, 'Tbps'], [1e9, 'Gbps'], [1e6, 'Mbps'], [1e3, 'Kbps']]) {
			if (number >= size) return `${(number / size).toFixed(number / size >= 10 ? 0 : 1)} ${unit}`;
		}
		return `${number.toFixed(0)} bps`;
	}

	_lkNum(value) {
		if (value === null || value === undefined || value === '') return '—';
		const number = Number(value);
		if (!Number.isFinite(number)) return String(value);
		return number === 0 ? '0' : number < 10 ? String(Math.round(number * 100) / 100) : Math.round(number).toLocaleString(this._lkLocale());
	}

	_lkDevice(hostid) {
		if (!this._lkDeviceCache.has(hostid)) {
			const node = this._lkConsole?.querySelector(`.laka-pane[data-hostid="${CSS.escape(String(hostid))}"] .laka-device-data`);
			const data = this._lkJson(node?.dataset.json, {ports: [], sensors: [], endpoints: []});
			data.loaded = !!node;
			if (!node) return data;
			this._lkDeviceCache.set(hostid, data);
		}
		return this._lkDeviceCache.get(hostid);
	}

	_lkPort(hostid, index) {
		return this._lkDevice(hostid).ports.find(port => String(port.index) === String(index)) || null;
	}

	_lkStatusLabel(status) {
		return this._lkSettings.status_labels?.[status] || status;
	}

	_lkSeverityName(severity) {
		return this._lkSettings.severity_names?.[severity] || '';
	}

	_lkRequest(action, params, json = false) {
		const url = new Curl('zabbix.php');
		url.setArgument('action', action);
		return fetch(url.getUrl(), {
			method: 'POST', credentials: 'same-origin', cache: 'no-store',
			headers: {
				'Content-Type': json ? 'application/json' : 'application/x-www-form-urlencoded; charset=UTF-8',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: json ? JSON.stringify(params) : new URLSearchParams(params).toString()
		}).then(async result => {
			let response = JSON.parse((await result.text()).replace(/^﻿/, '').trim());
			if (response.body && typeof response.body === 'object') response = response.body;
			if (typeof response.main_block === 'string') response = JSON.parse(response.main_block);
			if (!response.ok) throw new Error(response.error || this._lkT('Invalid response (HTTP %1$s)', result.status));
			return response;
		});
	}

	// ================================================================== eventos

	_lkActions() {
		return {
			'nav-toggle': () => this._lkSetNavCollapsed(!this._lkConsole.classList.contains('nav-collapsed'), true),
			'show-topology': () => this._lkShowTopology(true),
			'show-fleet': () => this._lkShowFleet(true),
			'fleet-filter': el => {
				const state = this._lkFleetState();
				state.filter = state.filter === el.dataset.value && el.classList.contains('laka-kpi') ? 'all' : el.dataset.value;
				this._lkSaveFleetState();
				this._lkRenderFleet();
			},
			'fleet-sort': el => {
				const state = this._lkFleetState();
				state.dir = state.sort === el.dataset.value && state.dir === 'asc' ? 'desc' : 'asc';
				state.sort = el.dataset.value;
				this._lkSaveFleetState();
				this._lkRenderFleet();
			},
			'fleet-group': () => {
				const state = this._lkFleetState();
				state.grouped = !state.grouped;
				this._lkSaveFleetState();
				this._lkRenderFleet();
			},
			'fleet-region': el => {
				const state = this._lkFleetState();
				const name = el.dataset.region;
				state.collapsed = state.collapsed.includes(name) ? state.collapsed.filter(item => item !== name) : [...state.collapsed, name];
				this._lkSaveFleetState();
				this._lkRenderFleet();
			},
			'fleet-open': el => this._lkSelectDevice(el.dataset.hostid, true),
			'fleet-csv': () => this._lkFleetCsv(),
			'topology-audit': () => this._lkOpenAudit(),
			'fleet-report': () => this._lkFleetReport(),
			'noc-start': () => this._lkStartNoc(),
			'noc-stop': () => this._lkStopNoc(),
			'unused-ports': el => this._lkUnusedPorts(el),
			'heat-all': el => {
				const target = el.closest('.laka-heatmap');
				target.dataset.all = '1';
				this._lkLoadHeatmap(el.closest('.laka-sw'), true);
			},
			'featured-compare': el => {
				const panel = el.closest('.laka-featured');
				const on = panel.dataset.compare !== '1';
				panel.dataset.compare = on ? '1' : '0';
				el.classList.toggle('active', on);
				el.setAttribute('aria-pressed', String(on));
				this._lkRenderFeatured(el.closest('.laka-sw'), true);
			},
			'locate-open': () => this._lkOpenLocator(),
			'device-filter': el => this._lkFilterDevices(el),
			'select-device': el => this._lkSelectDevice(el.dataset.hostid, true),
			'tab': el => this._lkSetTab(el.closest('.laka-sw'), el.dataset.value, true),
			'filter-ports': el => this._lkFilterPorts(el),
			'sort': el => this._lkSortTable(el),
			'open-port': el => this._lkOpenPortModal(el.closest('.laka-sw').dataset.hostid, el.dataset.index),
			'open-sensor': el => this._lkOpenSensorModal(el.closest('.laka-sw').dataset.hostid, el.dataset.itemid),
			'export-csv': el => this._lkExportCsv(el.closest('.laka-tab-panel')),
			'topology-endpoints': el => {
				this._lkSet('endpoints-on', this._lkEndpointsOn() ? '0' : '1');
				this._lkRenderTopology(el.closest('.laka-topology'), true);
			},
			'region-up': el => this._lkDrillRegion(el.closest('.laka-topology'), ''),
			'open-region': el => this._lkOpenRegion(el.dataset.region),
			'region-toggle': el => this._lkToggleRegion(el.closest('.laka-region-group')),
			'retry-pane': el => this._lkEnsureDevice(el.closest('.laka-pane').dataset.hostid).then(() => this._lkRefreshVisible()).catch(() => {}),
			'map-mode': el => {
				const panel = el.closest('.laka-topology');
				panel.dataset.mode = el.dataset.value;
				this._lkSet(`map-mode:${panel.dataset.scope}`, el.dataset.value);
				for (const button of panel.querySelectorAll('[data-action="map-mode"]')) button.classList.toggle('active', button === el);
				this._lkRenderTopology(panel, true);
			},
			'zoom': el => this._lkZoom(el.closest('.laka-topology'), el.dataset.value),
			'topology-anim': el => {
				const off = this._lkGet('topology-anim') !== '0';
				this._lkSet('topology-anim', off ? '0' : '1');
				for (const panel of this._lkConsole.querySelectorAll('.laka-topology')) panel.classList.toggle('lt-no-anim', off);
				this._lkSyncViewTools();
			},
			'topology-minimap': el => {
				const off = this._lkGet('topology-minimap') !== '0';
				this._lkSet('topology-minimap', off ? '0' : '1');
				for (const panel of this._lkConsole.querySelectorAll('.laka-topology')) panel.classList.toggle('lt-no-minimap', off);
				this._lkSyncViewTools();
			},
			'topology-export': el => this._lkOpenExport(el.closest('.laka-topology')),
			'topology-route': () => this._lkOpenRoute(),
			'minimap': (el, event) => this._lkMinimapClick(el.closest('.laka-topology'), el, event),
			'layout-reset': el => {
				const panel = el.closest('.laka-topology');
				const key = this._lkScopeKey(panel);
				this._lkSet(`positions:${key}`, null);
				this._lkSet(`view:${key}`, null);
				this._lkSet(`expanded:${key}`, null);
				this._lkViews.delete(key);
				this._lkRenderTopology(panel, true);
			},
			'link-add': el => this._lkOpenLinkForm(el.closest('.laka-topology'), null),
			'link-edit': el => {
				const link = this._lkSelectedLink(el.closest('.laka-topology'));
				if (link) this._lkOpenLinkForm(el.closest('.laka-topology'), link);
			},
			'link-delete': el => {
				const link = this._lkSelectedLink(el.closest('.laka-topology'));
				if (link) this._lkConfirmDeleteLink(link);
			},
			'node': (el, event) => this._lkOnNodeClick(el, event),
			'edge': el => this._lkOnEdgeClick(el),
			'featured-period': el => {
				const panel = el.closest('.laka-featured');
				panel.dataset.period = el.dataset.value;
				for (const button of panel.querySelectorAll('[data-action="featured-period"]')) button.classList.toggle('active', button === el);
				this._lkRenderFeatured(el.closest('.laka-sw'), true);
			},
			'featured-config': el => {
				const panel = el.closest('.laka-featured');
				panel.classList.toggle('config-open');
				el.classList.toggle('active', panel.classList.contains('config-open'));
			},
			'featured-clear': el => this._lkSetFeaturedSelection(el.closest('.laka-sw'), []),
			'featured-toggle': el => {
				const root = el.closest('.laka-sw');
				const selection = this._lkFeaturedSelection(root);
				const index = String(el.dataset.index);
				const max = Number(root.querySelector('.laka-featured')?.dataset.maxCharts || 4);
				const next = selection.includes(index) ? selection.filter(value => value !== index)
					: [...selection, index].slice(-max);
				this._lkSetFeaturedSelection(root, next);
			}
		};
	}

	_lkOnClick(event) {
		if (this._lkSuppressClick) {
			this._lkSuppressClick = false;
			return;
		}
		const element = event.target.closest('[data-action]');
		if (element === null || !this._target.contains(element) || element.disabled) return;
		const handler = this._lkActions()[element.dataset.action];
		if (handler) {
			event.preventDefault();
			handler(element, event);
		}
	}

	_lkOnInput(event) {
		const kind = event.target.dataset?.input;
		if (kind === 'device-search') this._lkFilterDevices();
		else if (kind === 'interface-search') this._lkApplyTableFilters(event.target.closest('.laka-tab-panel'));
		else if (kind === 'topology-search') this._lkSearchTopology(event.target.closest('.laka-topology'), false);
		else if (kind === 'fleet-search') {
			this._lkFleetState().query = event.target.value;
			this._lkRenderFleet();
		}
	}

	_lkOnKeyUp(event) {
		if (event.key === 'Enter' && event.target.dataset?.input === 'topology-search') {
			this._lkSearchTopology(event.target.closest('.laka-topology'), true);
		}
	}

	_lkOnHover(event, entering) {
		// Precarga del detalle: al detener el cursor sobre un equipo (menú, lista o mapa) se pide su panel en segundo plano.
		const device = event.target.closest('.laka-device[data-hostid], .laka-fleet-row[data-hostid], .lt-node[data-node^="h:"]');
		if (device && this._target.contains(device)) {
			clearTimeout(this._lkPrefetchTimer);
			const hostid = device.dataset.hostid || device.dataset.node.slice(2);
			if (entering) this._lkPrefetchTimer = setTimeout(() => this._lkPrefetch(hostid), 180);
		}
		const target = event.target.closest('.laka-port, .laka-row, .laka-attention-row, .lt-node');
		if (!target || !this._target.contains(target) || target.contains(event.relatedTarget)) return;
		if (target.classList.contains('lt-node')) {
			if (!this._lkDrag) this._lkFocusNode(target, entering);
			return;
		}
		if (target.classList.contains('laka-port')) {
			if (entering) this._lkShowPortTooltip(target);
			else this._lkHideTooltip();
		}
		this._lkLinkPort(target.closest('.laka-sw'), target.dataset.index, entering);
	}

	_lkLinkPort(root, index, on) {
		if (!root) return;
		const selector = `[data-index="${CSS.escape(String(index))}"]`;
		for (const element of root.querySelectorAll(`.laka-port${selector}, .laka-row${selector}`)) {
			element.classList.toggle('linked', on);
		}
	}

	// ================================================================== navegación

	_lkSetNavCollapsed(collapsed, persist = false) {
		this._lkConsole.classList.toggle('nav-collapsed', collapsed);
		const toggle = this._lkConsole.querySelector('.laka-nav-toggle');
		if (toggle) toggle.title = collapsed ? this._lkT('Expand equipment menu') : this._lkT('Collapse equipment menu');
		if (persist) {
			this._lkSet('nav', collapsed ? 'collapsed' : 'expanded');
			setTimeout(() => this._lkRefreshVisible(), 50);
		}
	}

	_lkFilterDevices(button = null) {
		if (button) {
			for (const other of this._lkConsole.querySelectorAll('[data-action="device-filter"]')) other.classList.toggle('active', other === button);
		}
		const filter = this._lkConsole.querySelector('[data-action="device-filter"].active')?.dataset.value || 'all';
		const query = (this._lkConsole.querySelector('.laka-nav-search')?.value || '').trim().toLocaleLowerCase();
		for (const device of this._lkConsole.querySelectorAll('.laka-device')) {
			const health = device.dataset.health;
			const byFilter = filter === 'all'
				|| filter === 'issues' && ['warning', 'unavailable'].includes(health)
				|| filter === 'unknown' && health === 'unknown'
				|| filter === 'maintenance' && health === 'maintenance';
			const bySearch = query === '' || (device.dataset.search || '').includes(query);
			device.classList.toggle('hidden', !(byFilter && bySearch));
		}
		for (const group of this._lkConsole.querySelectorAll('.laka-region-group')) {
			const visible = group.querySelectorAll('.laka-device:not(.hidden)').length;
			group.classList.toggle('hidden', visible === 0);
			group.classList.toggle('searching', query !== '' || filter !== 'all');
		}
	}

	_lkSetRegionCollapsed(group, collapsed) {
		group.classList.toggle('collapsed', collapsed);
		group.querySelector('.laka-region-head')?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
	}

	_lkToggleRegion(group) {
		if (!group) return;
		this._lkSetRegionCollapsed(group, !group.classList.contains('collapsed'));
		const collapsed = [...this._lkConsole.querySelectorAll('.laka-region-group.collapsed')].map(item => item.dataset.region);
		this._lkSet('regions-collapsed', JSON.stringify(collapsed));
	}

	_lkShowTopology(persist, region = null) {
		this._lkHideFleet();
		for (const device of this._lkConsole.querySelectorAll('.laka-device')) device.classList.remove('active');
		for (const pane of this._lkConsole.querySelectorAll('.laka-pane')) pane.classList.remove('active');
		this._lkConsole.querySelector('.laka-nav-topology:not(.laka-nav-fleet)')?.classList.add('active');
		const section = this._lkConsole.querySelector('.laka-global-topology');
		section?.classList.add('active');
		if (persist) this._lkSet('selected', '__topology__');
		const panel = section?.querySelector('.laka-topology');
		if (panel && region !== null) {
			panel.dataset.region = region;
			this._lkSet('region', region);
		}
		this._lkRenderTopology(panel);
	}

	_lkSelectDevice(hostid, persist) {
		const button = this._lkConsole.querySelector(`.laka-device[data-hostid="${CSS.escape(String(hostid))}"]`);
		if (!button) return;
		for (const device of this._lkConsole.querySelectorAll('.laka-device')) device.classList.toggle('active', device === button);
		this._lkHideFleet();
		this._lkConsole.querySelector('.laka-nav-topology:not(.laka-nav-fleet)')?.classList.remove('active');
		this._lkConsole.querySelector('.laka-global-topology')?.classList.remove('active');
		for (const pane of this._lkConsole.querySelectorAll('.laka-pane')) pane.classList.toggle('active', pane.dataset.hostid === String(hostid));
		if (persist) this._lkSet('selected', hostid);
		const group = button.closest('.laka-region-group');
		if (group?.classList.contains('collapsed')) this._lkSetRegionCollapsed(group, false);
		if (persist) button.scrollIntoView?.({block: 'nearest'});
		this._lkEnsureDevice(hostid).then(() => this._lkRefreshVisible()).catch(() => {});
	}

	_lkSetTab(root, tab, persist) {
		if (!root) return;
		root.dataset.activeTab = tab;
		for (const button of root.querySelectorAll('[data-action="tab"]')) {
			const active = button.dataset.value === tab;
			button.classList.toggle('active', active);
			button.setAttribute('aria-selected', active ? 'true' : 'false');
		}
		if (persist) this._lkSet(`tab:${root.dataset.hostid}`, tab);
		this._lkHideTooltip();
		if (root.closest('.laka-pane')?.classList.contains('active')) {
			if (tab === 'topology') this._lkRenderTopology(root.querySelector('.laka-tab-panel[data-tab="topology"] .laka-topology'));
			if (tab === 'consumption') {
				this._lkLoadHeatmap(root);
				this._lkRenderFeatured(root);
			}
			if (tab === 'interfaces') this._lkLoadSparks(root);
		}
	}

	// ================================================================== lista de equipos (vista de flota)

	_lkShowFleet(persist) {
		for (const device of this._lkConsole.querySelectorAll('.laka-device')) device.classList.remove('active');
		for (const pane of this._lkConsole.querySelectorAll('.laka-pane')) pane.classList.remove('active');
		this._lkConsole.querySelector('.laka-global-topology')?.classList.remove('active');
		this._lkConsole.querySelector('.laka-nav-topology:not(.laka-nav-fleet)')?.classList.remove('active');
		this._lkConsole.querySelector('.laka-nav-fleet')?.classList.add('active');
		this._lkConsole.querySelector('.laka-fleet')?.classList.add('active');
		if (persist) this._lkSet('selected', '__fleet__');
		this._lkRenderFleet();
	}

	_lkHideFleet() {
		this._lkConsole.querySelector('.laka-fleet')?.classList.remove('active');
		this._lkConsole.querySelector('.laka-nav-fleet')?.classList.remove('active');
	}

	_lkFleetState() {
		if (!this._lkFleetStateCache) {
			const stored = this._lkJson(this._lkGet('fleet'), {});
			this._lkFleetStateCache = {sort: 'status', dir: 'asc', filter: 'all', grouped: this._lkMultiRegion(), collapsed: [],
				...stored, query: ''};
		}
		return this._lkFleetStateCache;
	}

	_lkSaveFleetState() {
		const {sort, dir, filter, grouped, collapsed} = this._lkFleetState();
		this._lkSet('fleet', JSON.stringify({sort, dir, filter, grouped, collapsed}));
	}

	_lkFleetRows() {
		if (!this._lkFleetData) {
			this._lkFleetData = this._lkJson(this._lkConsole.querySelector('.laka-fleet-data')?.dataset.json, []);
		}
		return this._lkFleetData;
	}

	_lkFleetMatches(row, filter) {
		switch (filter) {
			case 'unavailable': return row.health === 'unavailable';
			case 'problems': return row.problems > 0 || row.health === 'warning';
			case 'uplink': return row.ul_total > row.ul_up;
			case 'cpu': return row.cpu !== null && row.cpu >= 85;
			case 'mem': return row.mem !== null && row.mem >= 85;
			case 'stale': return row.stale || row.age === null;
			case 'maintenance': return row.health === 'maintenance';
			case 'noping': return row.ping === 0 || (row.loss !== null && row.loss !== undefined && row.loss >= 50);
			default: return true;
		}
	}

	_lkFleetRank(row) {
		// Lo más urgente primero: no disponibles, luego por severidad de problema, mantenimiento, sin datos, disponibles.
		if (row.health === 'unavailable') return 0;
		if (row.health === 'warning') return 10 - Math.max(0, Number(row.severity));
		if (row.health === 'maintenance') return 20;
		if (row.health === 'unknown') return 21;
		return 30;
	}

	_lkRenderFleet() {
		const section = this._lkConsole?.querySelector('.laka-fleet');
		if (!section || !section.classList.contains('active')) return;
		const state = this._lkFleetState();
		const rows = this._lkFleetRows();
		const t = (key, ...args) => this._lkEsc(this._lkT(key, ...args));

		// Las tarjetas KPI reflejan el filtro activo.
		for (const card of section.querySelectorAll('[data-action="fleet-filter"]')) {
			card.classList.toggle('selected', card.dataset.value === state.filter && state.filter !== 'all');
		}
		const toggle = section.querySelector('.laka-fleet-group-toggle');
		if (toggle) {
			toggle.classList.toggle('active', state.grouped);
			toggle.setAttribute('aria-pressed', state.grouped ? 'true' : 'false');
		}

		const chips = [['all', 'All'], ['problems', 'With problems'], ['unavailable', 'Unavailable'], ['uplink', 'Uplink down'],
			['cpu', 'High CPU'], ['mem', 'High memory'], ['noping', 'No ICMP response'], ['stale', 'Outdated data'], ['maintenance', 'Maintenance']];
		section.querySelector('.laka-fleet-filters').innerHTML = chips.map(([value, label]) => {
			const count = rows.filter(row => this._lkFleetMatches(row, value)).length;
			if (count === 0 && value !== 'all') return '';
			return `<button type="button" class="laka-chip${state.filter === value ? ' active' : ''}" data-action="fleet-filter" data-value="${value}">${t(label)} <span class="laka-count">${count}</span></button>`;
		}).join('');

		const query = state.query.trim().toLocaleLowerCase();
		let list = rows.filter(row => this._lkFleetMatches(row, state.filter) && (query === ''
			|| `${row.name} ${row.ip} ${row.region} ${row.model} ${row.location} ${row.role} ${row.os || ''} ${row.serial || ''} ${row.template || ''}`.toLocaleLowerCase().includes(query)));
		const value = (row, key) => ({
			status: this._lkFleetRank(row), name: row.name, region: row.region, model: row.model, location: row.location || '\uffff',
			uptime: row.uptime_s ?? -1, software: row.os || '\uffff',
			icmp: row.ping === 0 ? 1e9 : row.rtt ?? -1,
			cpu: row.cpu ?? -1, mem: row.mem ?? -1, ports: row.total ? row.up / row.total : -1, uplinks: row.ul_total ? row.ul_up / row.ul_total : 2,
			problems: row.problems, age: row.age ?? Infinity, connected: this._lkConnected(row.id).total
		})[key];
		const factor = state.dir === 'asc' ? 1 : -1;
		list.sort((a, b) => {
			const x = value(a, state.sort), y = value(b, state.sort);
			const result = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, {numeric: true});
			return result * factor || String(a.name).localeCompare(String(b.name), undefined, {numeric: true});
		});

		const hasIcmp = rows.some(row => row.ping !== null && row.ping !== undefined || row.rtt !== null && row.rtt !== undefined);
		const hasSoftware = rows.some(row => row.os);
		const hasConnected = rows.some(row => this._lkConnected(row.id).total > 0);
		const columns = [['status', 'Status'], ['name', 'Equipment'], ['region', 'Region'], ['model', 'Model'], ['software', 'Software'],
			['location', 'Location'], ['uptime', 'Uptime'], ['icmp', 'Latency'], ['cpu', 'CPU'], ['mem', 'Memory'], ['ports', 'Ports'], ['uplinks', 'Uplinks'],
			['connected', 'Connected'], ['problems', 'Problems'], ['age', 'Data']]
			.filter(([key]) => key !== 'region' || (this._lkMultiRegion() && !state.grouped))
			.filter(([key]) => (key !== 'icmp' || hasIcmp) && (key !== 'software' || hasSoftware) && (key !== 'connected' || hasConnected));
		const head = columns.map(([key, label]) => `<th class="fc-${key}"><button type="button" class="laka-sort${state.sort === key ? ' active' : ''}"
			data-action="fleet-sort" data-value="${key}"${state.sort === key ? ` data-direction="${state.dir}"` : ''}>${t(label)}</button></th>`).join('');
		const meter = (number, warn, crit) => {
			if (number === null || number === undefined) return '<span class="laka-muted">—</span>';
			const level = number >= crit ? 'high' : number >= warn ? 'warn' : 'ok';
			return `<span class="laka-meter ${level}"><span style="--value:${Math.min(100, number)}%"></span></span><span class="laka-num ${level}">${number}%</span>`;
		};
		const age = row => row.age === null ? `<span class="laka-muted">${t('No data')}</span>`
			: row.age < 60 ? t('just now') : t('%1$s ago', row.age < 3600 ? `${Math.floor(row.age / 60)}m`
				: row.age < 86400 ? `${Math.floor(row.age / 3600)}h` : `${Math.floor(row.age / 86400)}d`);
		const icmp = row => {
			if (row.ping === 0) return `<span class="bad">${t('No response')}</span>`;
			if (row.rtt === null || row.rtt === undefined) return '<span class="laka-muted">—</span>';
			const loss = row.loss ? ` <small class="${row.loss >= 20 ? 'bad' : 'warn'}">${row.loss}% ${t('loss')}</small>` : '';
			return `<span class="laka-num${row.rtt >= 150 ? ' warn' : ''}">${row.rtt} ms</span>${loss}`;
		};
		const rowHtml = row => {
			const sev = row.health === 'unavailable' ? 5 : Number(row.severity);
			const pill = `<span class="laka-pill health-${this._lkEsc(row.health)}${sev >= 0 ? ` sev-${sev}` : ''}">${this._lkEsc(this._lkSettings.health_labels?.[row.health] || row.health)}</span>`;
			const ports = row.total ? `<span class="laka-meter ${row.up / row.total < .2 ? 'warn' : 'ok'}"><span style="--value:${Math.round(row.up / row.total * 100)}%"></span></span><span class="laka-num">${row.up}/${row.total}</span>` : '—';
			const uplinks = row.ul_total ? `<span class="${row.ul_up < row.ul_total ? 'bad' : 'good'}">${row.ul_up}/${row.ul_total}</span>` : '<span class="laka-muted">—</span>';
			const problems = row.problems > 0 ? `<span class="laka-badge sev-${Math.max(0, sev)}">${row.problems}</span>${row.unacked ? `<span class="laka-muted"> · ${row.unacked} ${t('unacked')}</span>` : ''}` : '<span class="laka-muted">—</span>';
			const cells = {
				status: pill,
				name: `<b>${this._lkEsc(row.name)}</b><small>${this._lkEsc(row.ip || '')}${row.role ? ' · ' + this._lkEsc(row.role) : ''}</small>`,
				region: this._lkEsc(row.region),
				model: `${this._lkEsc(row.model || '—')}${row.serial ? `<small>${t('S/N')} ${this._lkEsc(row.serial)}</small>` : ''}`,
				software: row.os ? `<span title="${this._lkEsc(row.os_full || row.os)}">${this._lkEsc(row.os)}</span>` : '<span class="laka-muted">—</span>',
				icmp: icmp(row),
				location: row.location ? this._lkEsc(row.location) : '<span class="laka-muted">—</span>',
				uptime: this._lkEsc(row.uptime || '—'),
				cpu: meter(row.cpu, 70, 85), mem: meter(row.mem, 70, 85), ports, uplinks, problems,
				connected: this._lkConnectedHtml(row.id),
				age: `<span class="${row.stale ? 'warn' : ''}">${age(row)}</span>`
			};
			return `<tr class="laka-fleet-row" data-action="fleet-open" data-hostid="${this._lkEsc(row.id)}">${columns.map(([key]) =>
				`<td class="fc-${key}">${cells[key]}</td>`).join('')}</tr>`;
		};

		let body = '';
		if (state.grouped && this._lkMultiRegion()) {
			const groups = new Map();
			for (const row of list) {
				if (!groups.has(row.region)) groups.set(row.region, []);
				groups.get(row.region).push(row);
			}
			const names = [...groups.keys()].sort((a, b) => a.localeCompare(b, undefined, {numeric: true}));
			for (const name of names) {
				const items = groups.get(name);
				const collapsed = state.collapsed.includes(name) && query === '';
				const unavailable = items.filter(row => row.health === 'unavailable').length;
				const problems = items.reduce((sum, row) => sum + row.problems, 0);
				const worst = items.reduce((max, row) => Math.max(max, row.health === 'unavailable' ? 5 : Number(row.severity)), -1);
				body += `<tr class="laka-fleet-group${collapsed ? ' collapsed' : ''}" data-action="fleet-region" data-region="${this._lkEsc(name)}">
					<td colspan="${columns.length}"><span class="laka-region-chevron">▾</span><b>${this._lkEsc(name)}</b>
					<span class="laka-count">${items.length}</span>
					${unavailable ? `<span class="laka-badge sev-5">${t('Unavailable')}: ${unavailable}</span>` : ''}
					${problems ? `<span class="laka-badge sev-${Math.max(0, worst)}">${t('Problems')}: ${problems}</span>` : ''}
					<button type="button" class="laka-link" data-action="open-region" data-region="${this._lkEsc(name)}">${t('Open region map')}</button></td></tr>`;
				if (!collapsed) body += items.map(rowHtml).join('');
			}
		}
		else body = list.map(rowHtml).join('');

		section.querySelector('.laka-fleet-table').innerHTML = list.length === 0
			? `<div class="laka-empty-inline">${t('No equipment matches this filter.')}</div>`
			: `<div class="laka-table-scroll laka-fleet-scroll"><table class="laka-table laka-fleet-list"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>
				<div class="laka-fleet-foot laka-muted">${t('%1$s of %2$s equipment', list.length, rows.length)}</div>`;
	}

	_lkFleetCsv() {
		const table = this._lkConsole.querySelector('.laka-fleet-list');
		if (!table) return;
		const quote = value => `"${String(value).replace(/\s+/g, ' ').trim().replace(/"/g, '""')}"`;
		const lines = [[...table.tHead.rows[0].cells].map(cell => quote(cell.textContent)).join(';')];
		for (const row of table.tBodies[0].rows) {
			if (!row.classList.contains('laka-fleet-row')) continue;
			lines.push([...row.cells].map(cell => quote(cell.innerText || cell.textContent)).join(';'));
		}
		const blob = new Blob(['﻿' + lines.join('\r\n')], {type: 'text/csv;charset=utf-8'});
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'network-equipment.csv';
		document.body.append(link);
		link.click();
		setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 0);
	}

	// ================================================================== localizador MAC / IP

	_lkOpenLocator(query = null) {
		const t = (...args) => this._lkT(...args);
		const last = query ?? this._lkGet('locate-last') ?? '';
		const box = this._lkOpenModal(`${this._lkModalHead(this._lkEsc(t('Locate equipment by MAC or IP')),
			t('Searches the MAC tables collected by Zabbix (SNMP) and follows CDP/LLDP from the core to the access port.'))}
			<div class="laka-modal-body">
				<div class="laka-locate-form">
					<input type="search" class="laka-input laka-locate-input" data-locate="input" autocomplete="off" spellcheck="false"
						placeholder="00:1A:2B:3C:4D:5E · 001a.2b3c.4d5e · 10.0.10.45 · SW-ACC-01" value="${this._lkEsc(last)}"
						aria-label="${this._lkEsc(t('MAC address, IP address or equipment name'))}">
					<button type="button" class="laka-btn primary" data-modal="locate">${this._lkEsc(t('Locate'))}</button>
				</div>
				<div class="laka-locate-result"><div class="laka-muted laka-locate-hint">${this._lkEsc(t('Any MAC notation is accepted (colons, dashes, Cisco dots or plain). An IP address is resolved with the ARP tables of the core. The name or IP of a monitored equipment opens it directly.'))}</div></div>
			</div>`);
		box.classList.add('laka-locate-box');
		const input = box.querySelector('.laka-locate-input');
		input.focus({preventScroll: true});
		input.select();
		if (query) this._lkRunLocate();
	}

	_lkRunLocate() {
		const box = this._lkModal?.querySelector('.laka-locate-box');
		if (!box) return;
		const query = box.querySelector('.laka-locate-input').value.trim();
		const target = box.querySelector('.laka-locate-result');
		if (query === '') return;
		this._lkSet('locate-last', query);
		const button = box.querySelector('[data-modal="locate"]');
		button.disabled = true;
		target.innerHTML = `<div class="laka-empty-inline"><span class="laka-spinner"></span>${this._lkEsc(this._lkT('Searching the MAC tables…'))}</div>`;
		this._lkFetchLocate(query)
			.then(result => {
				if (!this._lkModal?.contains(target)) return;
				this._lkLocateResult = result;
				target.innerHTML = this._lkLocateHtml(result);
			})
			.catch(error => {
				if (!this._lkModal?.contains(target)) return;
				target.innerHTML = `<div class="laka-locate-msg bad">${this._lkEsc(this._lkT('The search failed.'))} ${this._lkEsc(error?.message || '')}</div>`;
			})
			.finally(() => { button.disabled = false; });
	}

	_lkFetchLocate(query) {
		const base = typeof this.getUpdateRequestData === 'function' ? this.getUpdateRequestData()
			: typeof this._getUpdateRequestData === 'function' ? this._getUpdateRequestData() : {};
		const url = new Curl('zabbix.php');
		url.setArgument('action', 'widget.laka_network_switch.view');
		return fetch(url.getUrl(), {
			method: 'POST', credentials: 'same-origin', cache: 'no-store',
			headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
			body: JSON.stringify({...base, laka_mac: query})
		})
			.then(response => response.json())
			.then(response => {
				if (response.error) {
					throw new Error([response.error.title, ...(response.error.messages || [])].filter(Boolean).join(' '));
				}
				const template = document.createElement('template');
				template.innerHTML = response.body || '';
				const node = template.content.querySelector('.laka-mac-data');
				if (!node) {
					const message = template.content.querySelector('.laka-empty')?.textContent;
					throw new Error(message || this._lkT('Unexpected answer from the server.'));
				}
				return this._lkJson(node.dataset.json, {});
			});
	}

	_lkAgo(seconds) {
		if (seconds === null || seconds === undefined) return '';
		if (seconds < 60) return this._lkT('just now');
		const value = seconds < 3600 ? `${Math.floor(seconds / 60)}m` : seconds < 86400 ? `${Math.floor(seconds / 3600)}h`
			: `${Math.floor(seconds / 86400)}d`;
		return this._lkT('%1$s ago', value);
	}

	_lkLocateHtml(r) {
		const t = (...args) => this._lkT(...args);
		const esc = value => this._lkEsc(value);
		const coverage = r.coverage || {};
		const pending = (coverage.pending || 0) + (coverage.arp_pending || 0);
		const coverageLine = `<div class="laka-locate-coverage laka-muted">${esc(t('%1$s MAC tables searched on %2$s equipment', coverage.tables || 0, coverage.hosts || 0))}
			· ${esc(t('%1$s ARP tables', coverage.arp_tables || 0))}${coverage.errors ? ` · <span class="warn">${esc(t('%1$s tables with collection errors', coverage.errors))}</span>` : ''}${pending
			? ` · <span class="warn">${esc(t('%1$s tables still without data (first poll pending)', pending))}</span>` : ''}${this._lkLocateErrorsHtml(r)}</div>`;
		const neighbors = this._lkLocateNeighborsHtml(r);
		const hostCards = (r.hosts || []).map(host => `<div class="laka-locate-host">
				<div><b>${esc(host.name)}</b><div class="laka-muted">${esc([host.ip, host.role, this._lkMultiRegion() ? host.region : ''].filter(Boolean).join(' · '))}</div></div>
				<div class="laka-locate-host-actions">
					<button type="button" class="laka-btn" data-modal="host-map" data-hostid="${esc(host.hostid)}" data-region="${esc(host.region || '')}">${esc(t('Show on map'))}</button>
					<button type="button" class="laka-btn primary" data-modal="open-host" data-hostid="${esc(host.hostid)}">${esc(t('Open equipment'))}</button>
				</div></div>`).join('');
		const message = (kind, text, extra = '') => `<div class="laka-locate-msg ${kind}">${esc(text)}${extra}</div>`;
		switch (r.status) {
			case 'neighbor':
				return neighbors + (r.hosts?.length ? `<div class="laka-modal-section-title">${esc(t('Monitored equipment'))}</div>${hostCards}` : '')
					+ (r.kind !== 'name' ? message('neutral', t('Not found in the MAC / ARP tables; located by the CDP / LLDP neighbors of the switches.')) : '')
					+ coverageLine;
			case 'invalid':
				return message('warn', r.kind === 'name' ? t('No monitored equipment matches this name.')
					: t('Enter a MAC address (any notation), an IPv4 address or the name of an equipment.'));
			case 'host':
				return neighbors + `<div class="laka-modal-section-title">${esc(r.kind === 'ip' ? t('Monitored equipment with this IP') : t('Monitored equipment'))}</div>
					${hostCards}
					${r.kind === 'ip' ? `<div class="laka-locate-msg neutral">${esc(t('The IP is not in the ARP tables: a switch management address is usually a loopback or a routed interface, so it is located by the equipment itself and not by the MAC tables.'))}</div>` : ''}
					${coverageLine}`;
			case 'pending':
				return message('warn', t('The MAC table template is linked, but its items still have no data. Wait for the first poll (up to 15 minutes) or use "Execute now" on the items.')) + coverageLine;
			case 'no_data':
				return message('warn', t('No equipment of this widget has MAC tables yet. Link the template "LAKA Network MAC table by SNMP" to the switches (folder extras/mac-table of the module).'));
			case 'no_arp':
				return message('warn', t('No ARP table has been collected, so an IP cannot be resolved. Link the template to the core, router or firewall, or search by MAC.'));
			case 'not_found': {
				const reasons = [t('The equipment is off or has not sent traffic recently (the switch forgets idle MACs after about 5 minutes).'),
					t('It is connected to a switch without the MAC table template or outside this widget.'),
					t('The address belongs to another VLAN excluded from the collection.')];
				const arp = (r.arp || []).length ? `<p>${esc(t('It is known in ARP:'))} ${r.arp.map(row => `${esc(row.ip)} (${esc(row.host)}, ${esc(this._lkAgo(row.age))})`).join(', ')}</p>` : '';
				const id = r.mac ? `<div class="laka-locate-id"><div><div class="laka-locate-mac">${esc(r.formats?.cisco || r.mac)}</div>
					<div class="laka-muted">${esc([r.formats?.colon, r.vendor, r.ips?.length ? 'IP ' + r.ips.join(', ') : ''].filter(Boolean).join(' · '))}</div></div></div>` : '';
				const history = this._lkLocateHistoryHtml(r);
				const fromHistory = r.notes?.includes('ip_from_history')
					? message('warn', t('The IP is no longer in ARP: the last MAC address it had in the last 24 hours is used.')) : '';
				return id + fromHistory + message('neutral', r.kind === 'ip' && !r.mac ? t('This IP address is not in the ARP tables.') : t('This MAC address is not in the current MAC tables.'),
					`${arp}${history ? '' : `<ul>${reasons.map(reason => `<li>${esc(reason)}</li>`).join('')}</ul>`}`) + history + neighbors + coverageLine;
			}
		}
		const final = r.final || {};
		const formats = r.formats || {};
		const confidence = {
			high: [t('High'), t('Access port without a switch behind it.')],
			medium: [t('Medium'), t('Access port with several MAC addresses or with an access point, IP phone or hypervisor behind it.')],
			low: [t('Low'), t('Only seen on uplinks: the next equipment has no MAC table or the entry already expired.')],
			multiple: [t('Multiple'), t('Seen on more than one access port: device that moved, duplicate MAC or outdated data.')]
		}[r.confidence] || ['', ''];
		const special = {hsrp: t('Virtual gateway (HSRP)'), vrrp: t('Virtual gateway (VRRP)'), glbp: t('Virtual gateway (GLBP)'),
			multicast: t('Multicast address'), broadcast: t('Broadcast address')}[r.special];
		const badges = [
			`<span class="laka-conf ${esc(r.confidence)}" title="${esc(confidence[1])}">${esc(t('Confidence'))}: ${esc(confidence[0])}</span>`,
			special ? `<span class="laka-pill neutral">${esc(special)}</span>` : '',
			r.local ? `<span class="laka-pill neutral" title="${esc(t('Locally administered: usually a random / private MAC of phones and laptops, or a virtual machine.'))}">${esc(t('Random / private MAC'))}</span>` : '',
			final.freshness && final.freshness !== 'current'
				? `<span class="laka-pill health-warning">${esc(final.freshness === 'stale' ? t('Possibly outdated') : t('Expired'))}</span>` : ''
		].join('');
		// Claves i18n: t('IP phone') t('Access point') t('Server / hypervisor') t('Camera') t('Printer') t('Switch') t('Router') t('Firewall') t('UPS') t('Device')
		const neighborType = type => t({phone: 'IP phone', access_point: 'Access point', server: 'Server / hypervisor', camera: 'Camera',
			printer: 'Printer', switch: 'Switch', router: 'Router', firewall: 'Firewall', ups: 'UPS'}[type] || 'Device');
		const portStatus = final.status ? this._lkStatusLabel(final.status) : '';
		const facts = [
			[t('Equipment'), `${final.host || '—'}${final.ip ? ` · ${final.ip}` : ''}`],
			[t('Vendor'), r.vendor || ''],
			[t('Interface'), `${final.port || '—'}${final.members?.length ? ` (${final.members.join(', ')})` : ''}`],
			[t('Description'), final.alias || ''],
			[t('VLAN'), final.vlan === '0' ? '' : final.vlan],
			[t('Status'), [portStatus, final.speed && final.speed !== '—' ? final.speed : ''].filter(Boolean).join(' · ')],
			[t('MACs on the port'), String(final.macs_on_port ?? '')],
			[t('Behind the port'), final.neighbor && final.kind === 'access' ? `${neighborType(final.neighbor.type)}: ${final.neighbor.name}` : ''],
			[t('Region'), this._lkMultiRegion() ? final.region || '' : ''],
			[t('Seen'), this._lkAgo(final.age)]
		];
		const notes = [];
		if (r.notes?.includes('outdated')) notes.push(t('This MAC table was collected %1$s: the device may have moved since then.', this._lkAgo(final.age)));
		if (r.notes?.includes('next_without_table') && final.neighbor) notes.push(t('The MAC continues towards %1$s, which has no MAC table: link the template to that equipment to reach the access port.', final.neighbor.name));
		if (r.notes?.includes('ip_from_history')) notes.push(t('The IP is no longer in ARP: the last MAC address it had in the last 24 hours is used.'));
		if (r.notes?.includes('ip_multiple_macs')) notes.push(t('This IP has several MAC addresses in ARP (duplicate IP or different VRF); the most recent one is shown.'));
		if (r.special && ['hsrp', 'vrrp', 'glbp'].includes(r.special)) notes.push(t('This is the virtual MAC of a gateway, not an endpoint: it is answered by the active router or L3 switch.'));

		const path = (r.path || []).map((step, index) => {
			const last = index === r.path.length - 1;
			const via = step.kind === 'uplink' && step.neighbor ? ` <span class="laka-muted">→ ${esc(step.neighbor.name)}${step.neighbor.protocol ? ` (${esc(step.neighbor.protocol)})` : ''}</span>` : '';
			const members = step.members?.length ? ` <span class="laka-muted">[${esc(step.members.join(', '))}]</span>` : '';
			return `<li class="laka-locate-step ${step.kind === 'access' ? 'access' : 'uplink'}${step.gap ? ' gap' : ''}">
				<button type="button" class="laka-link" data-modal="open-host" data-hostid="${esc(step.hostid)}">${esc(step.host)}</button>
				<span class="laka-locate-port">${esc(step.port)}</span>${members}${via}
				${step.gap ? `<span class="laka-muted"> · ${esc(t('link not confirmed by CDP/LLDP'))}</span>` : ''}
				${last && step.kind === 'access' ? `<b class="laka-locate-here">← ${esc(t('connected here'))}</b>` : ''}</li>`;
		}).join('');

		const sightings = (r.sightings || []).map(row => `<tr><td>${esc(row.host)}</td><td>${esc(row.port)}</td><td>${esc(row.vlan === '0' ? '—' : row.vlan)}</td>
			<td>${esc(row.kind === 'access' ? t('Access') : t('Uplink'))}${row.neighbor ? ` <span class="laka-muted">· ${esc(row.neighbor.name)}</span>` : ''}</td>
			<td class="num">${esc(row.macs_on_port)}</td><td>${esc(this._lkAgo(row.age))}</td></tr>`).join('');
		const arp = (r.arp || []).map(row => `${esc(row.ip)} <span class="laka-muted">(${esc(row.host)})</span>`).join(', ');

		const hostNote = (r.hosts || []).length ? `<div class="laka-modal-section"><div class="laka-modal-section-title">${esc(t('Monitored equipment with this IP'))}</div>${hostCards}</div>` : '';
		return `${hostNote}<div class="laka-locate-card">
				<div class="laka-locate-id">
					<div><div class="laka-locate-mac">${esc(formats.cisco || r.mac)}</div>
						<div class="laka-muted">${esc([formats.colon, r.vendor, r.ips?.length ? 'IP ' + r.ips.join(', ') : ''].filter(Boolean).join(' · '))}</div></div>
					<div class="laka-locate-badges">${badges}</div>
				</div>
				<div class="laka-muted laka-locate-conf-text">${esc(confidence[1])}</div>
				${this._lkFacts(facts)}
				${notes.map(note => `<div class="laka-locate-msg warn">${esc(note)}</div>`).join('')}
				<div class="laka-modal-actions">
					<button type="button" class="laka-btn" data-modal="locate-map">${esc(t('Show on map'))}</button>
					${final.port_index ? `<button type="button" class="laka-btn" data-modal="open-port" data-hostid="${esc(final.hostid)}" data-index="${esc(final.port_index)}">${esc(t('Interface detail'))}</button>` : ''}
					<button type="button" class="laka-btn primary" data-modal="open-host" data-hostid="${esc(final.hostid)}">${esc(t('Open equipment'))}</button>
				</div>
			</div>
			<div class="laka-modal-section"><div class="laka-modal-section-title">${esc(t('Path from the core'))}</div>
				<ol class="laka-locate-path">${path}</ol></div>
			${arp ? `<div class="laka-modal-section"><div class="laka-modal-section-title">${esc(t('ARP'))}</div><div>${arp}</div></div>` : ''}
			<details class="laka-modal-section laka-locate-sightings"><summary>${esc(t('All sightings (%1$s)', (r.sightings || []).length))}</summary>
				<div class="laka-table-scroll"><table class="laka-table"><thead><tr><th>${esc(t('Equipment'))}</th><th>${esc(t('Interface'))}</th><th>VLAN</th>
				<th>${esc(t('Type'))}</th><th class="num">MAC</th><th>${esc(t('Seen'))}</th></tr></thead><tbody>${sightings}</tbody></table></div></details>
			${coverageLine}`;
	}

	/** Equipos encontrados como vecinos CDP/LLDP (nombre, IP o MAC anunciados). */
	_lkLocateNeighborsHtml(r) {
		const t = (...args) => this._lkT(...args);
		const esc = value => this._lkEsc(value);
		const rows = r.neighbors || [];
		if (!rows.length) return '';
		return `<div class="laka-modal-section-title">${esc(t('Seen by CDP / LLDP'))}</div>` + rows.map(row => `<div class="laka-locate-host">
			<div><b>${esc(row.host)} · ${esc(row.port || '—')}</b> <span class="laka-pill neutral">${esc(row.protocol)}</span>
				<div>${esc(row.name)}${row.ip ? ` · ${esc(row.ip)}` : ''}${row.mac ? ` · ${esc(row.mac.replace(/(.{4})(?=.)/g, '$1.'))}` : ''}</div>
				<div class="laka-muted">${esc([row.platform, row.remote_port ? t('remote port %1$s', row.remote_port) : '', this._lkAgo(row.age)].filter(Boolean).join(' · '))}</div></div>
			<div class="laka-locate-host-actions">
				<button type="button" class="laka-btn" data-modal="host-map" data-hostid="${esc(row.hostid)}" data-region="">${esc(t('Show on map'))}</button>
				<button type="button" class="laka-btn primary" data-modal="open-host" data-hostid="${esc(row.hostid)}">${esc(t('Open equipment'))}</button>
			</div></div>`).join('');
	}

	/** Errores de recolección más frecuentes, desplegables bajo la línea de cobertura. */
	_lkLocateErrorsHtml(r) {
		const samples = Object.entries(r.error_samples || {});
		if (!samples.length) return '';
		return `<details class="laka-locate-errors"><summary>${this._lkEsc(this._lkT('Show collection errors'))}</summary><ul>${samples
			.map(([text, count]) => `<li><code>${this._lkEsc(text)}</code> × ${this._lkEsc(count)}</li>`).join('')}</ul></details>`;
	}

	/** Últimas ubicaciones conocidas (historial de 24 h de las tablas MAC). */
	_lkLocateHistoryHtml(r) {
		const t = (...args) => this._lkT(...args);
		const esc = value => this._lkEsc(value);
		const rows = r.history || [];
		if (!rows.length) return '';
		const last = rows[0];
		const list = rows.map(row => `<tr><td><button type="button" class="laka-link" data-modal="open-host" data-hostid="${esc(row.hostid)}">${esc(row.host)}</button></td>
			<td>${esc(row.port)}</td><td>${esc(row.vlan === '0' ? '—' : row.vlan)}</td><td class="num">${esc(row.macs_on_port)}</td><td>${esc(this._lkAgo(row.age))}</td></tr>`).join('');
		return `<div class="laka-modal-section laka-locate-history"><div class="laka-modal-section-title">${esc(t('Last known location (24 h)'))}</div>
			<div class="laka-locate-msg neutral"><b>${esc(last.host)} · ${esc(last.port)}</b>${last.vlan && last.vlan !== '0' ? ` · VLAN ${esc(last.vlan)}` : ''}
				· ${esc(this._lkAgo(last.age))}<div class="laka-muted">${esc(t('The device was seen there and then disappeared from the MAC tables: it is off, disconnected or idle.'))}</div></div>
			<div class="laka-table-scroll"><table class="laka-table"><thead><tr><th>${esc(t('Equipment'))}</th><th>${esc(t('Interface'))}</th><th>VLAN</th>
			<th class="num">MAC</th><th>${esc(t('Seen'))}</th></tr></thead><tbody>${list}</tbody></table></div></div>`;
	}

	/** Abre la topología (región del switch de acceso) con el recorrido resaltado. */
	_lkShowLocatedPath() {
		const r = this._lkLocateResult;
		if (!r?.final) return;
		const ids = (r.path || []).map(step => String(step.hostid));
		this._lkCloseModal();
		this._lkHighlightHosts([...ids, String(r.final.hostid)], String(r.final.hostid), r.final.region || '');
	}

	/** Abre la topología (región del equipo foco) y resalta los equipos indicados y los enlaces entre ellos. */
	_lkHighlightHosts(hostids, focus, region) {
		const ids = new Set(hostids.map(id => `h:${id}`));
		this._lkShowTopology(true, this._lkMultiRegion() ? region : null);
		const panel = this._lkConsole.querySelector('.laka-global-topology .laka-topology');
		const svg = panel?.querySelector('.laka-topology-svg');
		if (!svg || !panel._graph) return;
		svg.classList.add('focusing', 'laka-path-focus');
		for (const node of svg.querySelectorAll('.lt-node')) node.classList.toggle('hl', ids.has(node.dataset.node));
		for (const edge of svg.querySelectorAll('.lt-edge, .lt-port, .lt-badge-g, .lt-util-g, .lt-flow')) {
			edge.classList.toggle('hl', ids.has(edge.dataset.a) && ids.has(edge.dataset.b));
		}
		const node = panel._graph.nodes.get(`h:${focus}`);
		const stage = panel.querySelector('.laka-topology-stage');
		const view = this._lkViews.get(this._lkScopeKey(panel));
		if (node && stage && view) {
			view.k = Math.max(view.k, 1);
			view.x = node.x + node.w / 2 - stage.clientWidth / view.k / 2;
			view.y = node.y + node.h / 2 - stage.clientHeight / view.k / 2;
			this._lkApplyView(panel);
		}
	}

	_lkSyncViewTools() {
		const anim = this._lkGet('topology-anim') !== '0', mini = this._lkGet('topology-minimap') !== '0';
		for (const button of this._lkConsole.querySelectorAll('[data-action="topology-anim"]')) {
			button.setAttribute('aria-pressed', String(anim));
			button.classList.toggle('active', anim);
		}
		for (const button of this._lkConsole.querySelectorAll('[data-action="topology-minimap"]')) {
			button.setAttribute('aria-pressed', String(mini));
			button.classList.toggle('active', mini);
		}
	}

	_lkMinimapClick(panel, mini, event) {
		const view = this._lkViews.get(this._lkScopeKey(panel));
		const stage = panel?.querySelector('.laka-topology-stage');
		const matrix = mini.getScreenCTM?.();
		if (!view || !stage || !matrix) return;
		const point = new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse());
		view.x = point.x - stage.clientWidth / view.k / 2;
		view.y = point.y - stage.clientHeight / view.k / 2;
		this._lkApplyView(panel);
	}

	// ---------------------------------------------------------------- exportar mapa

	_lkOpenExport(panel) {
		const t = (...args) => this._lkT(...args);
		this._lkExportPanel = panel;
		this._lkOpenModal(`${this._lkModalHead(this._lkEsc(t('Export image (PNG / SVG)')), t('The whole map is exported, also the parts outside the visible area.'))}
			<div class="laka-modal-body"><div class="laka-modal-actions">
				<button type="button" class="laka-btn" data-modal="export-svg">SVG</button>
				<button type="button" class="laka-btn primary" data-modal="export-png">PNG</button>
			</div></div>`);
	}

	_lkExportTopology(panel, format) {
		const svg = panel?.querySelector('.laka-topology-svg');
		if (!svg || !panel._graph) return;
		const b = this._lkBounds(panel._graph);
		const pad = 30, width = Math.ceil(b.maxX - b.minX + pad * 2), height = Math.ceil(b.maxY - b.minY + pad * 2);
		const clone = svg.cloneNode(true);
		// Los estilos vienen de la hoja CSS del módulo: se copian en línea para que la imagen se vea igual fuera de Zabbix.
		const props = ['fill', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-dasharray', 'stroke-opacity', 'stroke-linecap',
			'opacity', 'font-family', 'font-size', 'font-weight', 'display'];
		const source = [svg, ...svg.querySelectorAll('*')], target = [clone, ...clone.querySelectorAll('*')];
		source.forEach((element, i) => {
			const style = getComputedStyle(element);
			target[i].setAttribute('style', props.map(prop => `${prop}:${style.getPropertyValue(prop)}`).join(';'));
		});
		clone.querySelectorAll('.lt-flows, .lt-hit, title').forEach(element => element.remove());
		clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
		clone.setAttribute('viewBox', `${b.minX - pad} ${b.minY - pad} ${width} ${height}`);
		clone.setAttribute('width', width);
		clone.setAttribute('height', height);
		const background = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
		Object.entries({x: b.minX - pad, y: b.minY - pad, width, height}).forEach(([key, value]) => background.setAttribute(key, value));
		background.setAttribute('fill', getComputedStyle(this._lkConsole).getPropertyValue('--l-surface').trim() || '#ffffff');
		clone.insertBefore(background, clone.firstChild);
		const text = new XMLSerializer().serializeToString(clone);
		const name = `topologia-${new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-')}`;
		if (format === 'svg') {
			this._lkDownload(new Blob([text], {type: 'image/svg+xml'}), `${name}.svg`);
			return;
		}
		const image = new Image();
		const url = URL.createObjectURL(new Blob([text], {type: 'image/svg+xml'}));
		image.onload = () => {
			const scale = Math.min(2, 8000 / Math.max(width, height));
			const canvas = document.createElement('canvas');
			canvas.width = Math.round(width * scale);
			canvas.height = Math.round(height * scale);
			const context = canvas.getContext('2d');
			context.scale(scale, scale);
			context.drawImage(image, 0, 0, width, height);
			URL.revokeObjectURL(url);
			canvas.toBlob(blob => blob && this._lkDownload(blob, `${name}.png`), 'image/png');
		};
		image.src = url;
	}

	_lkDownload(blob, filename) {
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = filename;
		document.body.append(link);
		link.click();
		setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 0);
	}

	// ---------------------------------------------------------------- ruta entre dos equipos

	_lkOpenRoute() {
		const t = (...args) => this._lkT(...args);
		const hosts = [...(this._lkTopo.hosts || [])].sort((a, b) => String(a.name).localeCompare(String(b.name)));
		const options = hosts.map(host => `<option value="${this._lkEsc(host.name)}">`).join('');
		this._lkOpenModal(`${this._lkModalHead(this._lkEsc(t('Path between two equipment')), t('Shortest path over the CDP/LLDP and manual links.'))}
			<div class="laka-modal-body">
				<datalist id="laka-route-hosts">${options}</datalist>
				<div class="laka-route-form">
					<input class="laka-input" data-route="a" list="laka-route-hosts" placeholder="${this._lkEsc(t('Origin'))}" value="${this._lkEsc(this._lkGet('route-a') || '')}">
					<span class="laka-muted">⇄</span>
					<input class="laka-input" data-route="b" list="laka-route-hosts" placeholder="${this._lkEsc(t('Destination'))}" value="${this._lkEsc(this._lkGet('route-b') || '')}">
					<button type="button" class="laka-btn primary" data-modal="route">${this._lkEsc(t('Trace'))}</button>
				</div>
				<div class="laka-route-result"></div>
			</div>`, 'wide');
	}

	_lkRunRoute() {
		const t = (...args) => this._lkT(...args);
		const box = this._lkModal;
		const target = box?.querySelector('.laka-route-result');
		if (!target) return;
		const byName = new Map((this._lkTopo.hosts || []).map(host => [String(host.name).toLowerCase(), host]));
		const pick = key => byName.get(String(box.querySelector(`[data-route="${key}"]`).value).trim().toLowerCase());
		const a = pick('a'), b = pick('b');
		if (!a || !b) {
			target.innerHTML = `<div class="laka-locate-msg warn">${this._lkEsc(t('Choose two monitored equipment from the list.'))}</div>`;
			return;
		}
		this._lkSet('route-a', a.name);
		this._lkSet('route-b', b.name);
		// Búsqueda en anchura sobre los enlaces activos primero; si no hay camino, también con enlaces caídos.
		const route = this._lkShortestPath(String(a.id), String(b.id), true) || this._lkShortestPath(String(a.id), String(b.id), false);
		if (!route) {
			target.innerHTML = `<div class="laka-locate-msg warn">${this._lkEsc(t('There is no known link path between these equipment.'))}</div>`;
			return;
		}
		const hosts = new Map((this._lkTopo.hosts || []).map(host => [String(host.id), host]));
		const esc = value => this._lkEsc(value);
		// Puerto de un enlace del lado de un equipo.
		const portOf = (link, id) => link ? (String(link.a) === id ? (link.an || link.ai) : (link.bn || link.bi)) || '—' : '';
		const steps = route.map((hop, index) => {
			const host = hosts.get(hop.id);
			const link = hop.link;
			const previous = index > 0 ? route[index - 1].link : null;
			const state = link ? this._lkLinkClass(link) : '';
			const linkInfo = link ? [link.speed_bps ? this._lkRate(link.speed_bps) : '', link.protocol ? String(link.protocol).toUpperCase() : ''].filter(Boolean).join(' · ') : '';
			return `<tr class="${index === route.length - 1 ? 'last' : ''}">
				<td class="num"><span class="laka-route-num">${index + 1}</span></td>
				<td><button type="button" class="laka-link" data-modal="open-host" data-hostid="${esc(hop.id)}">${esc(host?.name || hop.id)}</button>
					<div class="laka-muted">${esc([host?.ip, host?.model].filter(Boolean).join(' · '))}</div></td>
				<td class="laka-locate-port">${esc(portOf(previous, hop.id) || '—')}</td>
				<td class="laka-locate-port">${esc(portOf(link, hop.id) || '—')}</td>
				<td>${link ? `<span class="laka-route-link ${esc(state || 'up')}">${esc(link.state === 'down' ? t('Down') : link.util !== null && link.util !== undefined ? `${Math.round(link.util)}%` : '—')}</span>
					<span class="laka-muted">${esc(linkInfo)}</span>` : `<span class="laka-muted">${esc(t('Destination'))}</span>`}</td></tr>`;
		}).join('');
		this._lkRoute = route;
		target.innerHTML = `<div class="laka-muted laka-route-summary">${esc(t('%1$s hops', route.length - 1))} · ${esc(route.map(hop => hosts.get(hop.id)?.name || hop.id).join(' → '))}</div>
			<div class="laka-table-scroll"><table class="laka-table laka-route-table"><thead><tr><th>#</th><th>${esc(t('Equipment'))}</th>
			<th>${esc(t('In'))}</th><th>${esc(t('Out'))}</th><th>${esc(t('Link to next'))}</th></tr></thead><tbody>${steps}</tbody></table></div>
			<div class="laka-modal-actions"><button type="button" class="laka-btn primary" data-modal="route-map">${this._lkEsc(t('Show on map'))}</button></div>`;
	}

	/** Camino más corto (en saltos) entre dos equipos: [{id, link hacia el siguiente}]. */
	_lkShortestPath(from, to, upOnly) {
		const adjacency = new Map();
		for (const link of this._lkTopo.links || []) {
			if (upOnly && link.state === 'down') continue;
			const a = String(link.a), b = String(link.b);
			if (!adjacency.has(a)) adjacency.set(a, []);
			if (!adjacency.has(b)) adjacency.set(b, []);
			adjacency.get(a).push([b, link]);
			adjacency.get(b).push([a, link]);
		}
		const previous = new Map([[from, null]]);
		const queue = [from];
		while (queue.length) {
			const current = queue.shift();
			if (current === to) break;
			for (const [next, link] of adjacency.get(current) || []) {
				if (previous.has(next)) continue;
				previous.set(next, [current, link]);
				queue.push(next);
			}
		}
		if (!previous.has(to)) return null;
		const route = [{id: to, link: null}];
		let cursor = to;
		while (previous.get(cursor)) {
			const [prev, link] = previous.get(cursor);
			route.unshift({id: prev, link});
			cursor = prev;
		}
		return route;
	}

	// ================================================================== tabla de interfaces

	/** Potencia óptica "-2.3 / -1.9 dBm" con el estado (bajo, crítico, sin luz). */
	_lkOptic(port) {
		const dbm = value => value === null || value === undefined ? '—' : Number(value).toFixed(1);
		const state = {warn: this._lkT('low'), high: this._lkT('critical'), dark: this._lkT('No light')}[port.optic_level];
		return `${dbm(port.optic_rx)} / ${dbm(port.optic_tx)} dBm${state ? ' · ' + state : ''}`;
	}

	_lkFilterPorts(button) {
		const root = button.closest('.laka-sw');
		const value = button.dataset.value || 'all';
		if (root.dataset.activeTab !== 'interfaces') this._lkSetTab(root, 'interfaces', true);
		const panel = root.querySelector('.laka-tab-panel[data-tab="interfaces"]');
		if (!panel) return;
		const target = panel.querySelector(`[data-action="filter-ports"][data-value="${CSS.escape(value)}"]`);
		for (const other of panel.querySelectorAll('[data-action="filter-ports"]')) other.classList.toggle('active', other === target);
		panel.querySelector('.laka-unused-btn')?.classList.remove('active');
		panel.dataset.filter = target ? value : 'all';
		this._lkApplyTableFilters(panel);
	}

	_lkApplyTableFilters(panel) {
		if (!panel) return;
		const filter = panel.dataset.filter || 'all';
		const query = (panel.querySelector('.laka-interface-search')?.value || '').trim().toLocaleLowerCase();
		let visible = 0;
		for (const row of panel.querySelectorAll('.laka-row')) {
			const d = row.dataset;
			const byFilter = filter === 'all'
				|| ['up', 'down', 'disabled', 'unknown'].includes(filter) && d.status === filter
				|| filter === 'uplink' && d.type === 'uplink'
				|| filter === 'problem' && d.problem === '1'
				|| filter === 'error' && d.error === '1'
				|| filter === 'util' && d.high === '1'
				|| filter === 'unused' && d.unused === '1'
				|| filter === 'optic' && d.optic === '1';
			const show = byFilter && (query === '' || (d.search || '').includes(query));
			row.classList.toggle('hidden', !show);
			if (show) visible++;
		}
		const result = panel.querySelector('.laka-interface-result');
		if (result) result.textContent = this._lkT('%1$s interfaces', visible);
		panel.classList.toggle('filter-empty', visible === 0);
	}

	_lkSortTable(button) {
		const table = button.closest('table');
		const body = table?.tBodies[0];
		if (!body) return;
		const key = button.dataset.value;
		const direction = button.classList.contains('active') && button.dataset.direction === 'asc' ? 'desc' : 'asc';
		for (const other of table.querySelectorAll('.laka-sort')) {
			other.classList.toggle('active', other === button);
			other.dataset.direction = other === button ? direction : '';
		}
		const numeric = ['speed', 'in', 'out', 'util', 'errors', 'discards', 'poe', 'optic', 'status'].includes(key);
		const attribute = `s${key.charAt(0).toUpperCase()}${key.slice(1)}`;
		const rows = [...body.rows];
		rows.sort((a, b) => {
			const av = a.dataset[attribute] ?? '', bv = b.dataset[attribute] ?? '';
			const result = numeric ? Number(av) - Number(bv) : String(av).localeCompare(String(bv), undefined, {numeric: true});
			return direction === 'asc' ? result : -result;
		});
		body.append(...rows);
	}

	_lkExportCsv(panel) {
		const table = panel?.querySelector('table');
		if (!table) return;
		const quote = value => `"${String(value).replace(/\s+/g, ' ').trim().replace(/"/g, '""')}"`;
		const lines = [[...table.tHead.rows[0].cells].map(cell => quote(cell.textContent)).join(';')];
		for (const row of table.tBodies[0].rows) {
			if (row.classList.contains('hidden')) continue;
			lines.push([...row.cells].map(cell => quote(cell.textContent)).join(';'));
		}
		const name = (panel.closest('.laka-pane')?.querySelector('.laka-head-name')?.textContent || 'interfaces')
			.replace(/[^\w.-]+/g, '_');
		const blob = new Blob(['﻿' + lines.join('\r\n')], {type: 'text/csv;charset=utf-8'});
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = `${name}-interfaces.csv`;
		document.body.append(link);
		link.click();
		setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 0);
	}

	// ================================================================== tooltip

	_lkShowPortTooltip(element) {
		const hostid = element.closest('.laka-sw')?.dataset.hostid;
		const port = this._lkPort(hostid, element.dataset.index);
		if (!port) return;
		if (!this._lkTooltip) {
			this._lkTooltip = document.createElement('div');
			this._lkTooltip.className = 'laka-tooltip';
			this._lkTooltip.setAttribute('role', 'tooltip');
		}
		this._lkTooltip.className = 'laka-tooltip';
		this._lkTooltipHost ??= document.createElement('div');
		this._lkTooltipHost.className = `laka-scope ${(this._lkThemeClasses || []).join(' ')}`;
		const rows = [
			[this._lkT('Status'), this._lkStatusLabel(port.status) + (port.status_inferred ? ` (${this._lkT('inferred from traffic')})` : '')],
			[this._lkT('Speed'), port.speed_text + (port.duplex ? ` · ${port.duplex.replace(/\s*\(\d+\)$/, '')}` : '')],
			[this._lkT('In / Out'), `${port.in_text} / ${port.out_text}`],
			[this._lkT('Use'), port.util === null ? '—' : `${port.util}%`]
		];
		if (port.errors_total > 0 || port.discards_total > 0) {
			rows.push([this._lkT('Errors / discards'), `${this._lkNum(port.errors_total)} / ${this._lkNum(port.discards_total)}`]);
		}
		if (port.vlan) rows.push(['VLAN', port.vlan]);
		if (port.poe_power) rows.push(['PoE', `${this._lkNum(port.poe_power)} ${port.poe_units}`]);
		if (port.optic_rx !== null && port.optic_rx !== undefined) rows.push([this._lkT('Optical Rx / Tx'), this._lkOptic(port)]);
		if (port.neighbor) rows.push([this._lkT('Neighbor'), `${port.neighbor.name}${port.neighbor.port ? ' · ' + port.neighbor.port : ''}`]);
		if (port.lastchange) rows.push([this._lkT('Last change'), port.lastchange]);
		const problems = (port.problems || []).slice(0, 2).map(problem =>
			`<div class="laka-tooltip-problem sev-${problem.severity}">${this._lkEsc(problem.name)}</div>`).join('');
		this._lkTooltip.innerHTML = `<div class="laka-tooltip-title"><span class="laka-dot st-${this._lkEsc(port.status)}"></span>${this._lkEsc(port.name)}</div>
			<div class="laka-tooltip-sub">${this._lkEsc(port.alias || this._lkT('No description'))}</div>
			<dl>${rows.map(([label, value]) => `<dt>${this._lkEsc(label)}</dt><dd>${this._lkEsc(value)}</dd>`).join('')}</dl>${problems}`;
		this._lkTooltipHost.append(this._lkTooltip);
		document.body.append(this._lkTooltipHost);
		const box = element.getBoundingClientRect();
		const tip = this._lkTooltip.getBoundingClientRect();
		let left = box.left + box.width / 2 - tip.width / 2;
		left = Math.max(8, Math.min(window.innerWidth - tip.width - 8, left));
		let top = box.top - tip.height - 8;
		if (top < 8) top = box.bottom + 8;
		this._lkTooltip.style.left = `${left}px`;
		this._lkTooltip.style.top = `${top}px`;
	}

	_lkHideTooltip() {
		this._lkTooltip?.remove();
		this._lkTooltipHost?.remove();
	}

	// ================================================================== ventanas

	/** size: false (normal), true (angosta) o 'wide' (tablas anchas: auditoría, ruta). */
	_lkOpenModal(html, narrow = false) {
		this._lkCloseModal();
		this._lkHideTooltip();
		this._lkModal = document.createElement('div');
		this._lkModal.className = `laka-scope laka-modal ${(this._lkThemeClasses || []).join(' ')}`;
		this._lkModal.innerHTML = `<div class="laka-modal-box${narrow === 'wide' ? ' wide' : narrow ? ' narrow' : ''}" role="dialog" aria-modal="true">${html}</div>`;
		this._lkModal.addEventListener('click', this._lkModalHandlers.click);
		document.addEventListener('keydown', this._lkModalHandlers.keydown);
		document.body.append(this._lkModal);
		this._lkModal.querySelector('.laka-modal-close')?.focus({preventScroll: true});
		return this._lkModal.querySelector('.laka-modal-box');
	}

	_lkCloseModal() {
		if (this._lkModal) {
			this._lkModal.removeEventListener('click', this._lkModalHandlers.click);
			this._lkModal.remove();
			this._lkModal = null;
		}
		document.removeEventListener('keydown', this._lkModalHandlers.keydown);
	}

	_lkModalHead(title, subtitle, extra = '') {
		return `<div class="laka-modal-head"><div><div class="laka-modal-title">${title}${extra}</div>
			<div class="laka-modal-subtitle">${this._lkEsc(subtitle)}</div></div>
			<button type="button" class="laka-modal-close" data-modal="close" aria-label="${this._lkEsc(this._lkT('Close'))}">×</button></div>`;
	}

	_lkFacts(items) {
		return `<div class="laka-facts">${items.filter(([, value]) => value !== null && value !== undefined && value !== '')
			.map(([label, value, full]) => `<div class="laka-fact${full ? ' full' : ''}"><span class="laka-fact-label">${this._lkEsc(label)}</span>
				<span class="laka-fact-value">${this._lkEsc(value)}</span></div>`).join('')}</div>`;
	}

	_lkOnModalClick(event) {
		const action = event.target.closest('[data-modal]');
		if (event.target === this._lkModal || action?.dataset.modal === 'close') {
			this._lkCloseModal();
			return;
		}
		if (!action) return;
		const box = this._lkModal.querySelector('.laka-modal-box');
		switch (action.dataset.modal) {
			case 'period':
				for (const button of box.querySelectorAll('[data-modal="period"]')) button.classList.toggle('active', button === action);
				box.querySelector('.laka-chart-box')._lkUnzoom = null;
				this._lkLoadChart(box.querySelector('.laka-chart-box'), box.querySelector('.laka-chart-stats'), {
					hostid: box.dataset.hostid, in_itemid: box.dataset.inItemid, out_itemid: box.dataset.outItemid
				}, action.dataset.value);
				break;
			case 'chart-compare': {
				const stage = box.querySelector('.laka-chart-box');
				const on = stage.dataset.compare !== '1';
				stage.dataset.compare = on ? '1' : '0';
				action.classList.toggle('active', on);
				action.setAttribute('aria-pressed', String(on));
				this._lkLoadChart(stage, box.querySelector('.laka-chart-stats'), stage._lkParams, stage._lkPeriod || '1h');
				break;
			}
			case 'sensor-period':
				for (const button of box.querySelectorAll('[data-modal="sensor-period"]')) button.classList.toggle('active', button === action);
				this._lkLoadSensorImage(box, action.dataset.value);
				break;
			case 'locate':
				this._lkRunLocate();
				break;
			case 'locate-map':
				this._lkShowLocatedPath();
				break;
			case 'export-svg':
			case 'export-png':
				this._lkCloseModal();
				this._lkExportTopology(this._lkExportPanel, action.dataset.modal === 'export-svg' ? 'svg' : 'png');
				break;
			case 'route':
				this._lkRunRoute();
				break;
			case 'audit-map': {
				const host = (this._lkTopo.hosts || []).find(item => String(item.id) === String(action.dataset.a));
				this._lkCloseModal();
				this._lkHighlightHosts([action.dataset.a, action.dataset.b], action.dataset.a, host?.region || '');
				break;
			}
			case 'route-map': {
				const route = this._lkRoute || [];
				const last = route[route.length - 1];
				const host = (this._lkTopo.hosts || []).find(item => String(item.id) === last?.id);
				this._lkCloseModal();
				if (last) this._lkHighlightHosts(route.map(hop => hop.id), last.id, host?.region || '');
				break;
			}
			case 'host-map':
				this._lkLocateResult = {final: {hostid: action.dataset.hostid, region: action.dataset.region || ''}, path: []};
				this._lkShowLocatedPath();
				break;
			case 'open-host':
				this._lkCloseModal();
				this._lkSelectDevice(action.dataset.hostid, true);
				break;
			case 'open-port': {
				const hostid = action.dataset.hostid;
				const index = action.dataset.index;
				this._lkCloseModal();
				this._lkSelectDevice(hostid, true);
				this._lkEnsureDevice(hostid).then(() => this._lkOpenPortModal(hostid, index)).catch(() => {});
				break;
			}
			case 'link-edit':
				this._lkOpenLinkForm(this._lkConsole.querySelector('.laka-global-topology .laka-topology'),
					this._lkTopo.links.find(link => String(link.id) === action.dataset.id));
				break;
			case 'link-delete': {
				const link = this._lkTopo.links.find(candidate => String(candidate.id) === action.dataset.id);
				if (link) this._lkConfirmDeleteLink(link);
				break;
			}
			case 'save-link':
				this._lkSaveLinkForm();
				break;
		}
	}

	_lkOpenPortModal(hostid, index) {
		const port = this._lkPort(hostid, index);
		if (!port) return;
		const device = this._lkDevice(hostid);
		const neighbor = port.neighbor;
		const facts = this._lkFacts([
			[this._lkT('Status'), this._lkStatusLabel(port.status) + (port.status_inferred ? ` (${this._lkT('inferred from traffic')})` : '')],
			[this._lkT('Speed'), port.speed_text], [this._lkT('Duplex'), port.duplex], ['VLAN', port.vlan || (port.trunk ? `trunk · ${port.trunk}` : '')],
			[this._lkT('In'), port.in_text], [this._lkT('Out'), port.out_text], [this._lkT('Use'), port.util === null ? '—' : `${port.util}%`],
			[this._lkT('Errors in / out'), `${this._lkNum(port.errors_in)} / ${this._lkNum(port.errors_out)}`],
			[this._lkT('Discards in / out'), `${this._lkNum(port.discards_in)} / ${this._lkNum(port.discards_out)}`],
			['PoE', port.poe_power ? `${this._lkNum(port.poe_power)} ${port.poe_units}${port.poe_status ? ' · ' + port.poe_status : ''}` : port.poe_status],
			[this._lkT('Optical Rx / Tx'), port.optic_rx !== null && port.optic_rx !== undefined ? this._lkOptic(port) : ''],
			[this._lkT('Last change'), port.lastchange], ['ifIndex', port.index],
			[this._lkT('Neighbor'), neighbor ? `${neighbor.protocol} · ${neighbor.name}${neighbor.port ? ' · ' + neighbor.port : ''}${neighbor.ip ? ' · ' + neighbor.ip : ''}` : '', true]
		]);
		const problems = (port.problems || []).map(problem => `<div class="laka-problem sev-${problem.severity}">
			<span class="laka-badge sev-${problem.severity}">${this._lkEsc(this._lkSeverityName(problem.severity))}</span>
			<span>${this._lkEsc(problem.name)}</span>
			<small>${problem.acknowledged ? this._lkEsc(this._lkT('Acknowledged')) : ''}${problem.clock ? ' · ' + new Date(problem.clock * 1000).toLocaleString(this._lkLocale()) : ''}</small></div>`).join('');
		const details = (port.detail_rows || []).map(([metric, clock, value, change]) => `<tr>
			<td>${this._lkEsc(this._lkSettings.detail_labels?.[metric] || metric)}</td>
			<td>${clock > 0 ? this._lkEsc(new Date(clock * 1000).toLocaleString(this._lkLocale())) : '—'}</td><td>${this._lkEsc(value)}</td>
			<td>${this._lkEsc(change || '—')}</td></tr>`).join('');
		const periods = ['1h', '6h', '24h', '7d'].map(period =>
			`<button type="button" class="${period === '1h' ? 'active' : ''}" data-modal="period" data-value="${period}">${period}</button>`).join('')
			+ `<button type="button" data-modal="chart-compare" aria-pressed="false" title="${this._lkEsc(this._lkT('Compare with the same period of the previous week'))}">⇆ ${this._lkEsc(this._lkT('Previous week'))}</button>`;
		const openNeighbor = neighbor?.hostid
			? `<button type="button" class="laka-btn" data-modal="open-host" data-hostid="${this._lkEsc(neighbor.hostid)}">${this._lkEsc(this._lkT('Open neighbor'))}</button>` : '';
		const box = this._lkOpenModal(`${this._lkModalHead(this._lkEsc(port.name), `${port.alias || this._lkT('No description')} · ${device.name || ''}`,
			` <span class="laka-pill">${this._lkEsc(this._lkStatusLabel(port.status))}</span>`)}
			<div class="laka-modal-body">
				<div class="laka-modal-section">${facts}</div>
				${problems ? `<div class="laka-modal-section"><div class="laka-modal-section-title">${this._lkEsc(this._lkT('Active problems'))}</div>${problems}</div>` : ''}
				<div class="laka-modal-section"><div class="laka-modal-section-title"><span>${this._lkEsc(this._lkT('Bandwidth'))}</span>
					<span class="laka-segmented">${periods}</span></div>
					<div class="laka-chart-box"></div><div class="laka-chart-stats"></div></div>
				${details ? `<div class="laka-modal-section"><div class="laka-modal-section-title">${this._lkEsc(this._lkT('Latest data'))}</div>
					<table class="laka-detail-table"><thead><tr><th>${this._lkEsc(this._lkT('Name'))}</th><th>${this._lkEsc(this._lkT('Last check'))}</th>
					<th>${this._lkEsc(this._lkT('Last value'))}</th><th>${this._lkEsc(this._lkT('Change'))}</th></tr></thead><tbody>${details}</tbody></table></div>` : ''}
				${openNeighbor ? `<div class="laka-modal-actions">${openNeighbor}</div>` : ''}
			</div>`);
		box.dataset.hostid = hostid;
		box.dataset.inItemid = port.in_itemid || '';
		box.dataset.outItemid = port.out_itemid || '';
		this._lkLoadChart(box.querySelector('.laka-chart-box'), box.querySelector('.laka-chart-stats'),
			{hostid, in_itemid: port.in_itemid, out_itemid: port.out_itemid}, '1h');
	}

	_lkOpenSensorModal(hostid, itemid) {
		const sensor = this._lkDevice(hostid).sensors.find(candidate => String(candidate.itemid) === String(itemid));
		if (!sensor) return;
		const state = {ok: this._lkT('Normal'), warning: this._lkT('Warning'), critical: this._lkT('Critical'), unknown: this._lkT('No data')};
		const problems = (sensor.problems || []).map(problem => `<div class="laka-problem sev-${problem.severity}">
			<span class="laka-badge sev-${problem.severity}">${this._lkEsc(this._lkSeverityName(problem.severity))}</span><span>${this._lkEsc(problem.name)}</span></div>`).join('');
		const periods = ['1h', '6h', '24h', '7d'].map(period =>
			`<button type="button" class="${period === '24h' ? 'active' : ''}" data-modal="sensor-period" data-value="${period}">${period}</button>`).join('');
		const box = this._lkOpenModal(`${this._lkModalHead(this._lkEsc(sensor.name), sensor.key)}
			<div class="laka-modal-body">
				<div class="laka-modal-section">${this._lkFacts([[this._lkT('Current value'), sensor.value || '—'], [this._lkT('State'), state[sensor.health]],
					[this._lkT('Last update'), sensor.clock ? new Date(sensor.clock * 1000).toLocaleString(this._lkLocale()) : '—']])}</div>
				${problems ? `<div class="laka-modal-section">${problems}</div>` : ''}
				${sensor.numeric ? `<div class="laka-modal-section"><div class="laka-modal-section-title"><span>${this._lkEsc(this._lkT('History'))}</span>
					<span class="laka-segmented">${periods}</span></div><div class="laka-sensor-image"></div></div>` : ''}
			</div>`, true);
		box.dataset.itemid = sensor.itemid;
		if (sensor.numeric) this._lkLoadSensorImage(box, '24h');
	}

	_lkLoadSensorImage(box, period) {
		const stage = box.querySelector('.laka-sensor-image');
		if (!stage || !/^\d+$/.test(box.dataset.itemid || '')) return;
		const url = new Curl('chart.php');
		url.setArgument('itemids', [box.dataset.itemid]);
		url.setArgument('from', `now-${period}`);
		url.setArgument('to', 'now');
		url.setArgument('type', '0');
		url.setArgument('legend', '1');
		url.setArgument('width', String(Math.max(400, Math.floor(stage.clientWidth || 580) - 90)));
		url.setArgument('height', '200');
		stage.innerHTML = `<img alt="" style="display:block;max-width:100%" src="${this._lkEsc(url.getUrl())}">`;
	}

	// ================================================================== gráficas

	_lkLoadChart(stage, stats, params, period) {
		if (!stage) return;
		stage._lkParams = params;
		stage._lkStats = stats;
		stage._lkPeriod = period;
		const ids = [params.in_itemid, params.out_itemid].filter(id => /^\d+$/.test(String(id || '')));
		if (ids.length === 0) {
			stage.innerHTML = `<div class="laka-chart-msg">${this._lkEsc(this._lkT('No traffic IN/OUT items for this interface.'))}</div>`;
			return;
		}
		stage.innerHTML = `<div class="laka-chart-msg">${this._lkEsc(this._lkT('Loading history…'))}</div>`;
		const request = {hostid: params.hostid, period: typeof period === 'string' ? period : '1h'};
		if (typeof period === 'object' && period) {
			request.from = Math.floor(period.from);
			request.till = Math.floor(period.till);
		}
		if (stage.dataset.compare === '1') request.compare = 1;
		if (params.in_itemid) request.in_itemid = params.in_itemid;
		if (params.out_itemid) request.out_itemid = params.out_itemid;
		const token = `${period}-${Date.now()}-${Math.random()}`;
		stage.dataset.token = token;
		this._lkRequest('widget.laka_network_switch.history', request)
			.then(response => {
				if (stage.dataset.token !== token) return;
				this._lkDrawChart(stage, response.data || {}, period);
				if (stats) this._lkDrawStats(stats, response.data?.stats || {});
			})
			.catch(error => {
				if (stage.dataset.token !== token) return;
				stage.innerHTML = `<div class="laka-chart-msg">${this._lkEsc(this._lkT('Could not load history: %1$s', error.message || ''))}</div>`;
			});
	}

	_lkDrawStats(element, stats) {
		const part = (direction, label) => {
			const s = stats[direction];
			if (!s || s.avg === null) return '';
			return `<div class="laka-chart-stat-row"><span class="${direction}">● ${this._lkEsc(label)}</span>
				<span>${this._lkEsc(this._lkT('avg'))} <b>${this._lkRate(s.avg)}</b></span>
				<span>p95 <b>${this._lkRate(s.p95)}</b></span>
				<span>${this._lkEsc(this._lkT('peak'))} <b>${this._lkRate(s.max)}</b></span></div>`;
		};
		element.innerHTML = part('in', this._lkT('In')) + part('out', this._lkT('Out'));
	}

	_lkDrawChart(stage, data, period) {
		const series = {in: Array.isArray(data.in) ? data.in : [], out: Array.isArray(data.out) ? data.out : []};
		const previous = {in: Array.isArray(data.in_prev) ? data.in_prev : [], out: Array.isArray(data.out_prev) ? data.out_prev : []};
		const zoomed = typeof stage._lkPeriod === 'object' && stage._lkPeriod !== null;
		const all = [...series.in, ...series.out];
		if (all.length === 0) {
			stage.innerHTML = `<div class="laka-chart-msg">${this._lkEsc(this._lkT('No history for this period.'))}</div>`;
			return;
		}
		const width = Math.max(320, Math.floor(stage.clientWidth || 800));
		const height = Math.max(140, Math.floor(stage.clientHeight || 240));
		const m = {top: 24, right: 14, bottom: 26, left: 62};
		const pw = width - m.left - m.right, ph = height - m.top - m.bottom;
		const t0 = Number(data.time_from) || Math.min(...all.map(p => p[0]));
		const t1 = Number(data.time_till) > t0 ? Number(data.time_till) : Math.max(...all.map(p => p[0]));
		const raw = Math.max(1, ...all.map(p => Number(p[2] ?? p[1]) || 0), ...[...previous.in, ...previous.out].map(p => Number(p[1]) || 0));
		const mag = 10 ** Math.floor(Math.log10(raw));
		const top = (raw / mag <= 1 ? 1 : raw / mag <= 2 ? 2 : raw / mag <= 5 ? 5 : 10) * mag;
		const x = t => m.left + (t - t0) / Math.max(1, t1 - t0) * pw;
		const y = v => m.top + ph - Math.max(0, Number(v) || 0) / top * ph;
		const line = (points, i) => points.map((p, n) => `${n ? 'L' : 'M'}${x(p[0]).toFixed(1)},${y(p[i]).toFixed(1)}`).join('');
		const band = points => points.length < 2 ? '' : `${line(points, 2)}${[...points].reverse().map(p => `L${x(p[0]).toFixed(1)},${y(p[1]).toFixed(1)}`).join('')}Z`;
		let grid = '';
		for (let i = 0; i <= 4; i++) {
			const gy = m.top + ph * i / 4;
			grid += `<line class="grid" x1="${m.left}" x2="${width - m.right}" y1="${gy}" y2="${gy}"/><text class="axis" x="${m.left - 8}" y="${gy + 3}" text-anchor="end">${this._lkRate(top * (1 - i / 4))}</text>`;
		}
		const ticks = Math.max(2, Math.min(8, Math.floor(pw / 90)));
		for (let i = 0; i <= ticks; i++) {
			const t = t0 + (t1 - t0) * i / ticks;
			const date = new Date(t * 1000);
			const span = t1 - t0;
			const label = span > 2 * 86400 ? date.toLocaleDateString(this._lkLocale(), {day: '2-digit', month: '2-digit'})
				: span > 20 * 3600 ? date.toLocaleString(this._lkLocale(), {day: '2-digit', hour: '2-digit', minute: '2-digit'})
				: date.toLocaleTimeString(this._lkLocale(), {hour: '2-digit', minute: '2-digit'});
			const anchor = i === 0 ? 'start' : i === ticks ? 'end' : 'middle';
			grid += `<text class="axis" x="${x(t)}" y="${height - 8}" text-anchor="${anchor}">${this._lkEsc(label)}</text>`;
		}
		const p95 = direction => {
			const value = data.stats?.[direction]?.p95;
			return value ? `<line class="p95 ${direction}" x1="${m.left}" x2="${width - m.right}" y1="${y(value)}" y2="${y(value)}"/>` : '';
		};
		// Problemas del equipo dentro de la ventana: marca vertical con triángulo del color de la severidad.
		const events = (Array.isArray(data.events) ? data.events : []).filter(event => event[0] >= t0 && event[0] <= t1);
		const marks = events.map(([clock, severity, name]) => {
			const ex = x(clock).toFixed(1);
			return `<g class="ev sev-${Number(severity)}"><line x1="${ex}" x2="${ex}" y1="${m.top}" y2="${m.top + ph}"/>
				<path d="M${ex},${m.top} l-4,-7 h8 z"/><title>${this._lkEsc(`${new Date(clock * 1000).toLocaleString(this._lkLocale())} · ${this._lkSeverityName(severity)}\n${name}`)}</title></g>`;
		}).join('');
		const prevLines = previous.in.length || previous.out.length
			? `<path class="line prev in" d="${line(previous.in, 1)}"/><path class="line prev out" d="${line(previous.out, 1)}"/>` : '';
		const legendPrev = prevLines ? `<text x="${width - m.right}" y="14" text-anchor="end">${this._lkEsc(this._lkT('dotted = previous week'))}</text>` : '';
		stage.innerHTML = `<svg class="laka-chart-svg" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none">
			${grid}<path class="band in" d="${band(series.in)}"/><path class="band out" d="${band(series.out)}"/>
			${p95('in')}${p95('out')}${prevLines}
			<path class="line in" d="${line(series.in, 1)}"/><path class="line out" d="${line(series.out, 1)}"/>${marks}
			<rect class="zoom-sel" x="0" y="${m.top}" width="0" height="${ph}"/>
			<line class="cursor" x1="0" x2="0" y1="${m.top}" y2="${m.top + ph}"/>
			<g class="legend"><circle cx="${m.left + 4}" cy="10" r="4" style="fill:var(--lc-in)"/><text x="${m.left + 12}" y="14">${this._lkEsc(this._lkT('In'))}</text>
			<circle cx="${m.left + 64}" cy="10" r="4" style="fill:var(--lc-out)"/><text x="${m.left + 72}" y="14">${this._lkEsc(this._lkT('Out'))}</text>
			<text x="${m.left + 124}" y="14">${this._lkEsc(this._lkT('line = average · band = peak · dashed = p95'))}</text>${legendPrev}</g>
		</svg><div class="laka-chart-tip"></div>
		${zoomed ? `<button type="button" class="laka-btn laka-chart-unzoom" data-chart="unzoom">↺ ${this._lkEsc(this._lkT('Reset zoom'))}</button>` : ''}
		<div class="laka-chart-hint">${this._lkEsc(this._lkT('Drag over the chart to zoom'))}</div>`;
		const svg = stage.querySelector('svg');
		const cursor = svg.querySelector('.cursor');
		const tip = stage.querySelector('.laka-chart-tip');
		const nearest = (points, t) => points.reduce((best, p) => !best || Math.abs(p[0] - t) < Math.abs(best[0] - t) ? p : best, null);
		svg.addEventListener('mousemove', event => {
			const box = svg.getBoundingClientRect();
			const px = Math.max(m.left, Math.min(width - m.right, (event.clientX - box.left) / box.width * width));
			const t = t0 + (px - m.left) / pw * (t1 - t0);
			const a = nearest(series.in, t), b = nearest(series.out, t);
			cursor.setAttribute('x1', px);
			cursor.setAttribute('x2', px);
			cursor.classList.add('on');
			const fmt = p => p ? `${this._lkRate(p[1])} (${this._lkT('peak')} ${this._lkRate(p[2] ?? p[1])})` : '—';
			tip.innerHTML = `${this._lkEsc(new Date(t * 1000).toLocaleString(this._lkLocale()))}<br><span style="color:var(--lc-in)">●</span> ${this._lkEsc(this._lkT('In'))} ${this._lkEsc(fmt(a))}<br><span style="color:var(--lc-out)">●</span> ${this._lkEsc(this._lkT('Out'))} ${this._lkEsc(fmt(b))}`;
			tip.classList.add('on');
			const left = event.clientX - box.left + 14;
			tip.style.left = `${Math.min(stage.clientWidth - tip.offsetWidth - 4, left)}px`;
		});
		svg.addEventListener('mouseleave', () => {
			cursor.classList.remove('on');
			tip.classList.remove('on');
		});
		// Zoom arrastrando: se pide el rango elegido al servidor con más detalle.
		const selection = svg.querySelector('.zoom-sel');
		const toPlot = event => {
			const box = svg.getBoundingClientRect();
			return Math.max(m.left, Math.min(width - m.right, (event.clientX - box.left) / box.width * width));
		};
		let dragStart = null;
		svg.addEventListener('pointerdown', event => {
			if (event.button !== 0) return;
			dragStart = toPlot(event);
			svg.setPointerCapture?.(event.pointerId);
		});
		svg.addEventListener('pointermove', event => {
			if (dragStart === null) return;
			const px = toPlot(event);
			selection.setAttribute('x', Math.min(px, dragStart));
			selection.setAttribute('width', Math.abs(px - dragStart));
			selection.classList.add('on');
		});
		svg.addEventListener('pointerup', event => {
			if (dragStart === null) return;
			const px = toPlot(event);
			const a = Math.min(px, dragStart), b = Math.max(px, dragStart);
			dragStart = null;
			selection.classList.remove('on');
			if (b - a < 10 || !stage._lkParams) return;
			const from = t0 + (a - m.left) / pw * (t1 - t0), till = t0 + (b - m.left) / pw * (t1 - t0);
			if (till - from < 120) return;
			stage._lkUnzoom ??= stage._lkPeriod;
			this._lkLoadChart(stage, stage._lkStats, stage._lkParams, {from, till});
		});
		stage.querySelector('[data-chart="unzoom"]')?.addEventListener('click', () => {
			const period = stage._lkUnzoom || '1h';
			stage._lkUnzoom = null;
			this._lkLoadChart(stage, stage._lkStats, stage._lkParams, period);
		});
	}

	/** Consulta por lote al endpoint de historial (mini-gráficas, mapa de calor, puertos sin uso). */
	_lkBatch(hostid, mode, itemids) {
		const params = new URLSearchParams();
		params.append('hostid', hostid);
		params.append('mode', mode);
		for (const itemid of itemids) params.append('itemids[]', itemid);
		return this._lkRequest('widget.laka_network_switch.history', params).then(response => response.data || {});
	}

	_lkPortItems(root) {
		const rows = [...root.querySelectorAll('.laka-tab-panel[data-tab="interfaces"] .laka-row')];
		const ids = rows.flatMap(row => [row.dataset.inItem, row.dataset.outItem]).filter(id => /^\d+$/.test(id || ''));
		return {rows, ids: [...new Set(ids)]};
	}

	/** Mini-gráficas de 6 h en la tabla de interfaces (una sola consulta por equipo, se refresca cada 5 min). */
	_lkLoadSparks(root) {
		const panel = root?.querySelector('.laka-tab-panel[data-tab="interfaces"]');
		if (!panel || !panel.querySelector('.laka-spark')) return;
		if (Date.now() - Number(panel.dataset.sparksAt || 0) < 300000 || panel._sparksLoading) return;
		const {rows, ids} = this._lkPortItems(root);
		if (!ids.length) return;
		panel._sparksLoading = this._lkBatch(root.dataset.hostid, 'sparks', ids)
			.then(data => {
				panel.dataset.sparksAt = String(Date.now());
				const series = data.series || {};
				for (const row of rows) {
					const target = row.querySelector('.laka-spark');
					if (!target) continue;
					const a = series[row.dataset.inItem] || [], b = series[row.dataset.outItem] || [];
					target.innerHTML = this._lkSparkSvg(a, b);
					const peak = values => Math.max(0, ...values.filter(v => v !== null));
					target.title = `${this._lkT('In')} ${this._lkT('peak')} ${this._lkRate(peak(a))} · ${this._lkT('Out')} ${this._lkT('peak')} ${this._lkRate(peak(b))}`;
				}
			})
			.catch(() => {})
			.finally(() => { panel._sparksLoading = null; });
	}

	_lkSparkSvg(a, b) {
		const n = Math.max(a.length, b.length);
		if (!n || ![...a, ...b].some(v => v !== null && v > 0)) return '<svg class="laka-spark-svg" viewBox="0 0 96 22"><line class="flat" x1="0" x2="96" y1="20" y2="20"/></svg>';
		const top = Math.max(1, ...[...a, ...b].filter(v => v !== null));
		const path = (values, area) => {
			let d = '', started = false;
			values.forEach((v, i) => {
				if (v === null) return;
				const px = (i / Math.max(1, n - 1) * 96).toFixed(1), py = (21 - v / top * 19).toFixed(1);
				d += `${started ? 'L' : 'M'}${px},${py}`;
				started = true;
			});
			return area && d ? `${d}L96,21L0,21Z` : d;
		};
		return `<svg class="laka-spark-svg" viewBox="0 0 96 22" preserveAspectRatio="none"><path class="area in" d="${path(a, true)}"/>
			<path class="in" d="${path(a, false)}"/><path class="out" d="${path(b, false)}"/></svg>`;
	}

	/** Puertos sin tráfico en los últimos 30 días (máximo horario de trends por debajo de 1 kbps). */
	_lkUnusedPorts(button) {
		const root = button.closest('.laka-sw');
		const panel = root?.querySelector('.laka-tab-panel[data-tab="interfaces"]');
		if (!panel) return;
		const {rows, ids} = this._lkPortItems(root);
		if (!ids.length) return;
		button.disabled = true;
		const label = button.textContent;
		button.textContent = this._lkT('Analyzing…');
		this._lkBatch(root.dataset.hostid, 'unused', ids)
			.then(data => {
				const series = data.series || {};
				const now = Number(data.time_till) || Date.now() / 1000;
				let count = 0, since = now;
				for (const row of rows) {
					const a = series[row.dataset.inItem], b = series[row.dataset.outItem];
					if (!a && !b) {
						row.dataset.unused = '';
						continue;
					}
					const max = Math.max(a?.max || 0, b?.max || 0);
					since = Math.min(since, a?.since || now, b?.since || now);
					row.dataset.unused = max < 1000 ? '1' : '0';
					if (max < 1000) count++;
				}
				const days = Math.max(1, Math.round((now - since) / 86400));
				button.textContent = `${label.replace(/\s*\(\d+\)$/, '')} (${count})`;
				button.title = this._lkT('%1$s interfaces without traffic in the last %2$s days', count, Math.min(30, days));
				for (const other of panel.querySelectorAll('[data-action="filter-ports"]')) other.classList.remove('active');
				button.classList.add('active');
				panel.dataset.filter = 'unused';
				this._lkApplyTableFilters(panel);
			})
			.catch(error => { button.textContent = label; button.title = error.message || ''; })
			.finally(() => { button.disabled = false; });
	}

	/** Mapa de calor puertos × horas (últimas 24 h) con el máximo horario de los trends. */
	_lkLoadHeatmap(root, force = false) {
		const target = root?.querySelector('.laka-heatmap');
		if (!target) return;
		if (!force && Date.now() - Number(target.dataset.loadedAt || 0) < 300000) return;
		const ports = (this._lkDevice(root.dataset.hostid).ports || [])
			.filter(port => (Number(port.speed_bps) || 0) > 0 && (/^\d+$/.test(port.in_itemid || '') || /^\d+$/.test(port.out_itemid || '')));
		if (!ports.length) {
			target.innerHTML = `<div class="laka-empty-inline">${this._lkEsc(this._lkT('No interfaces with traffic items and speed.'))}</div>`;
			return;
		}
		if (!target.dataset.loadedAt) target.innerHTML = `<div class="laka-empty-inline"><span class="laka-spinner"></span>${this._lkEsc(this._lkT('Loading history…'))}</div>`;
		const ids = [...new Set(ports.flatMap(port => [port.in_itemid, port.out_itemid]).filter(id => /^\d+$/.test(id || '')))];
		this._lkBatch(root.dataset.hostid, 'heat', ids)
			.then(data => {
				target.dataset.loadedAt = String(Date.now());
				const series = data.series || {};
				const from = Number(data.time_from) || (Math.floor(Date.now() / 3600000) - 24) * 3600;
				const warn = this._lkUtilWarning(), crit = this._lkUtilCritical();
				const rows = ports.map(port => {
					const a = series[port.in_itemid] || [], b = series[port.out_itemid] || [];
					const cells = Array.from({length: 24}, (_, i) => {
						const v = Math.max(a[i] ?? -1, b[i] ?? -1);
						return v < 0 ? null : Math.min(100, v / Number(port.speed_bps) * 100);
					});
					return {port, cells, peak: Math.max(-1, ...cells.map(c => c ?? -1))};
				}).filter(row => row.peak >= 0);
				if (!rows.length) {
					target.innerHTML = `<div class="laka-empty-inline">${this._lkEsc(this._lkT('No trend data yet for the last 24 hours (trends are written every hour).'))}</div>`;
					return;
				}
				rows.sort((p, q) => q.peak - p.peak);
				const level = v => v === null ? 'na' : v >= crit ? 4 : v >= warn ? 3 : v >= 30 ? 2 : v >= 10 ? 1 : 0;
				const hours = Array.from({length: 24}, (_, i) => new Date((from + i * 3600) * 1000));
				const head = hours.map((date, i) => `<span class="laka-heat-hour">${i % 3 === 0 ? this._lkEsc(date.toLocaleTimeString(this._lkLocale(), {hour: '2-digit'})) : ''}</span>`).join('');
				const limit = target.dataset.all === '1' ? rows.length : 32;
				const body = rows.slice(0, limit).map(({port, cells, peak}) => `<div class="laka-heat-row" data-action="open-port" data-index="${this._lkEsc(port.index)}">
					<span class="laka-heat-name" title="${this._lkEsc(port.alias || '')}">${this._lkEsc(port.name)}</span>
					${cells.map((v, i) => `<span class="laka-heat-cell lv-${level(v)}" title="${this._lkEsc(`${port.name} · ${hours[i].toLocaleString(this._lkLocale(), {day: '2-digit', hour: '2-digit', minute: '2-digit'})} · ${v === null ? this._lkT('No data') : Math.round(v) + '%'}`)}"></span>`).join('')}
					<span class="laka-heat-peak">${Math.round(peak)}%</span></div>`).join('');
				const more = rows.length > limit ? `<button type="button" class="laka-btn laka-heat-more" data-action="heat-all">${this._lkEsc(this._lkT('Show all (%1$s)', rows.length))}</button>` : '';
				target.innerHTML = `<div class="laka-heat-grid"><div class="laka-heat-row head"><span class="laka-heat-name"></span>${head}<span class="laka-heat-peak">${this._lkEsc(this._lkT('Peak'))}</span></div>${body}</div>${more}`;
			})
			.catch(error => {
				target.innerHTML = `<div class="laka-empty-inline">${this._lkEsc(this._lkT('Could not load history: %1$s', error.message || ''))}</div>`;
			});
	}

	// ================================================================== gráficas destacadas

	_lkFeaturedSelection(root) {
		const panel = root.querySelector('.laka-featured');
		if (!panel) return [];
		const stored = this._lkJson(this._lkGet(`featured:${root.dataset.hostid}`), null);
		const selection = Array.isArray(stored) ? stored : this._lkJson(panel.dataset.defaultSelection, []);
		const valid = new Set(this._lkDevice(root.dataset.hostid).ports.map(port => String(port.index)));
		return selection.map(String).filter(index => valid.has(index));
	}

	_lkSetFeaturedSelection(root, selection) {
		this._lkSet(`featured:${root.dataset.hostid}`, JSON.stringify(selection));
		this._lkRenderFeatured(root, true);
	}

	_lkRenderFeatured(root, force = false) {
		const panel = root?.querySelector('.laka-featured');
		if (!panel) return;
		const selection = this._lkFeaturedSelection(root);
		const period = panel.dataset.period || '12h';
		for (const chip of panel.querySelectorAll('[data-action="featured-toggle"]')) {
			chip.classList.toggle('selected', selection.includes(String(chip.dataset.index)));
		}
		panel.classList.toggle('no-selection', selection.length === 0);
		const list = panel.querySelector('.laka-featured-list');
		const signature = `${period}|${selection.join(',')}`;
		if (!force && list.dataset.signature === signature) return;
		list.dataset.signature = signature;
		list.innerHTML = '';
		const hostid = root.dataset.hostid;
		for (const index of selection) {
			const port = this._lkPort(hostid, index);
			if (!port) continue;
			const card = document.createElement('div');
			card.className = 'laka-featured-card';
			card.innerHTML = `<div class="laka-featured-card-head"><div><div class="laka-featured-name">${this._lkEsc(port.name)}</div>
				<div class="laka-muted">${this._lkEsc(port.alias || this._lkT('No description'))} · ${this._lkEsc(port.speed_text)}</div></div>
				<div><span class="laka-pill">${this._lkEsc(this._lkStatusLabel(port.status))}</span>
				<span class="laka-pill">${port.util === null ? '—' : this._lkEsc(port.util + '%')}</span></div></div>
				<div class="laka-featured-chart"></div><div class="laka-chart-stats"></div>`;
			list.append(card);
			card.querySelector('.laka-featured-chart').dataset.compare = panel.dataset.compare === '1' ? '1' : '0';
			this._lkLoadChart(card.querySelector('.laka-featured-chart'), card.querySelector('.laka-chart-stats'),
				{hostid, in_itemid: port.in_itemid, out_itemid: port.out_itemid}, period);
		}
	}

	// ================================================================== topología: datos + distribución

	_lkScopeKey(panel) {
		const scope = panel?.dataset.scope || 'global';
		return scope === 'global' && panel.dataset.region ? `region:${panel.dataset.region}` : scope;
	}

	/** Equipos finales en el mapa global: activados por defecto en redes pequeñas de un sitio, desactivados en regionales/grandes. */
	_lkEndpointsOn() {
		const stored = this._lkGet('endpoints-on');
		return stored === null ? !this._lkMultiRegion() && !this._lkSettings.lazy : stored === '1';
	}

	_lkMultiRegion() {
		return (this._lkSettings.regions || []).length > 1;
	}

	/** Equipos finales de un equipo cuando la lista completa está disponible (modo embebido o detalle ya cargado). */
	_lkEndpointsOf(hostid) {
		if (!this._lkSettings.lazy) {
			return (this._lkTopo.endpoints || []).filter(endpoint => String(endpoint.parent) === String(hostid));
		}
		const device = this._lkDevice(hostid);
		return device.loaded ? (device.endpoints || []) : null;
	}

	_lkTopologyGraph(panel) {
		const scope = panel.dataset.scope;
		const global = scope === 'global';
		const mode = panel.dataset.mode || 'both';
		const regions = this._lkSettings.regions || [];
		const region = global && this._lkMultiRegion() ? (panel.dataset.region || '') : '';
		const allHosts = this._lkTopo.hosts || [];
		const allLinks = this._lkTopo.links || [];
		const hostRegion = new Map(allHosts.map(host => [String(host.id), host.region || '']));
		const graph = {scope, key: this._lkScopeKey(panel), global, region, nodes: new Map(), edges: [], children: new Map(),
			level: global && this._lkMultiRegion() && !region ? 'regions' : 'hosts', counts: {}};

		if (graph.level === 'regions') {
			for (const item of regions) {
				graph.nodes.set(`r:${item.name}`, {id: `r:${item.name}`, kind: 'region', data: item, w: 224, h: 80});
			}
			const aggregated = new Map();
			for (const link of allLinks) {
				const a = hostRegion.get(String(link.a)), b = hostRegion.get(String(link.b));
				if (a === undefined || b === undefined || a === b) continue;
				const [first, second] = [a, b].sort();
				const key = `${first}\u0000${second}`;
				if (!aggregated.has(key)) aggregated.set(key, {kind: 'agg', a: `r:${first}`, b: `r:${second}`, links: []});
				aggregated.get(key).links.push(link);
			}
			graph.edges = [...aggregated.values()];
			graph.counts = {regions: regions.length, hosts: allHosts.length,
				links: graph.edges.reduce((sum, edge) => sum + edge.links.length, 0)};
			return graph;
		}

		let hosts = allHosts;
		let links = allLinks;
		if (!global) {
			links = mode === 'endpoints' ? [] : links.filter(link => String(link.a) === scope || String(link.b) === scope);
			const keep = new Set([scope, ...links.flatMap(link => [String(link.a), String(link.b)])]);
			hosts = hosts.filter(host => keep.has(String(host.id)));
		}
		else if (region) {
			hosts = hosts.filter(host => (host.region || '') === region);
		}
		const ids = new Set(hosts.map(host => String(host.id)));
		for (const host of hosts) graph.nodes.set(`h:${host.id}`, {id: `h:${host.id}`, kind: 'host', data: host, w: 176, h: 56});
		for (const link of links) {
			if (ids.has(String(link.a)) && ids.has(String(link.b))) graph.edges.push({kind: 'link', a: `h:${link.a}`, b: `h:${link.b}`, link});
		}

		// Dentro de una región: los enlaces que salen terminan en un nodo por cada región vecina.
		if (global && region) {
			const aggregated = new Map();
			for (const link of allLinks) {
				const inA = ids.has(String(link.a)), inB = ids.has(String(link.b));
				if (inA === inB) continue;
				const local = String(inA ? link.a : link.b), other = String(inA ? link.b : link.a);
				const otherRegion = hostRegion.get(other) ?? '?';
				const stubId = `x:${otherRegion}`;
				if (!graph.nodes.has(stubId)) graph.nodes.set(stubId, {id: stubId, kind: 'stub', region: otherRegion, hosts: new Set(), w: 184, h: 48});
				graph.nodes.get(stubId).hosts.add(other);
				const key = `${local}|${otherRegion}`;
				if (!aggregated.has(key)) {
					const edge = {kind: 'agg', a: `h:${local}`, b: stubId, links: []};
					aggregated.set(key, edge);
					graph.edges.push(edge);
				}
				aggregated.get(key).links.push(link);
			}
		}

		const showEndpoints = global ? this._lkEndpointsOn() : mode !== 'links';
		if (showEndpoints) this._lkAddEndpoints(graph, hosts.map(host => String(host.id)));
		let endpointCount = 0;
		for (const list of graph.children.values()) {
			for (const id of list) {
				const node = graph.nodes.get(id);
				endpointCount += node.kind === 'cluster' ? node.count : 1;
			}
		}
		graph.counts = {hosts: hosts.length, links: graph.edges.reduce((sum, edge) => sum + (edge.kind === 'agg' ? edge.links.length : 1), 0),
			endpoints: endpointCount};
		return graph;
	}

	_lkAddEndpoints(graph, hostIds) {
		const expanded = new Set(this._lkJson(this._lkGet(`expanded:${graph.key}`), []));
		const threshold = graph.global ? 3 : 7;
		const groups = this._lkTopo.endpoint_groups || [];
		for (const hostid of hostIds) {
			const parent = `h:${hostid}`;
			const children = [];
			const list = this._lkEndpointsOf(hostid);
			if (list) {
				const byType = new Map();
				for (const endpoint of list) {
					if (!byType.has(endpoint.type)) byType.set(endpoint.type, []);
					byType.get(endpoint.type).push(endpoint);
				}
				for (const [type, items] of byType) {
					const key = `${hostid}:${type}`;
					if (items.length >= threshold && !expanded.has(key)) {
						const id = `c:${key}`;
						graph.nodes.set(id, {id, kind: 'cluster', key, hostid, type, items, count: items.length,
							up: items.filter(item => item.status === 'up').length,
							down: items.filter(item => ['down', 'disabled'].includes(item.status)).length, parent, w: 176, h: 46});
						children.push(id);
					}
					else {
						for (const endpoint of items) {
							const id = `e:${endpoint.id}`;
							graph.nodes.set(id, {id, kind: 'endpoint', key: expanded.has(key) ? key : '', data: endpoint, parent, w: 150, h: 46});
							children.push(id);
						}
					}
				}
			}
			else {
				// Modo resumen: solo se conocen las cantidades hasta que se carga el detalle del equipo.
				for (const group of groups) {
					if (String(group.parent) !== hostid) continue;
					const key = `${hostid}:${group.type}`;
					const id = `c:${key}`;
					graph.nodes.set(id, {id, kind: 'cluster', key, hostid, type: group.type, items: null, count: group.count,
						up: group.up, down: group.down, parent, w: 176, h: 46});
					children.push(id);
				}
			}
			if (children.length) graph.children.set(parent, children);
		}
	}

	_lkLayout(graph) {
		const layoutNodes = [...graph.nodes.values()].filter(node => ['host', 'region', 'stub'].includes(node.kind));
		const adjacency = new Map(layoutNodes.map(node => [node.id, new Set()]));
		for (const edge of graph.edges) {
			adjacency.get(edge.a)?.add(edge.b);
			adjacency.get(edge.b)?.add(edge.a);
		}
		const roleLevel = {firewall: 0, router: 0, wan: 0, edge: 0, core: 1, distribution: 2, aggregation: 2, access: 3, server: 4};
		const level = new Map();
		const queue = [];
		for (const node of layoutNodes) {
			if (node.kind === 'stub') level.set(node.id, -1);
			else if (node.kind === 'host' && roleLevel[node.data.role] !== undefined) level.set(node.id, roleLevel[node.data.role]);
			else continue;
			queue.push(node.id);
		}
		if (queue.length === 0 && layoutNodes.length > 0) {
			const score = node => adjacency.get(node.id).size + (node.kind === 'host' && ['firewall', 'router'].includes(node.data.type) ? 100 : 0);
			const connected = layoutNodes.filter(node => adjacency.get(node.id).size > 0);
			const root = (connected.length ? connected : layoutNodes).reduce((best, node) => score(node) > score(best) ? node : best);
			level.set(root.id, 0);
			queue.push(root.id);
		}
		const bfs = list => {
			while (list.length) {
				const id = list.shift();
				for (const next of adjacency.get(id) || []) {
					if (!level.has(next)) {
						level.set(next, level.get(id) + 1);
						list.push(next);
					}
				}
			}
		};
		bfs(queue);
		let maxLevel = Math.max(0, ...level.values());
		for (const node of layoutNodes) {
			if (level.has(node.id) || adjacency.get(node.id).size === 0) continue;
			level.set(node.id, maxLevel + 1);
			bfs([node.id]);
			maxLevel = Math.max(...level.values());
		}
		for (const node of layoutNodes) if (!level.has(node.id)) level.set(node.id, maxLevel + 1);

		const used = [...new Set(level.values())].sort((a, b) => a - b);
		const label = node => node.kind === 'region' ? node.data.name : node.kind === 'stub' ? node.region : node.data.name;
		let rows = used.map(value => layoutNodes.filter(node => level.get(node.id) === value)
			.sort((a, b) => String(label(a)).localeCompare(String(label(b)), undefined, {numeric: true})));
		const position = new Map();
		const index = () => rows.forEach(row => row.forEach((node, i) => position.set(node.id, i)));
		index();
		for (let sweep = 0; sweep < 4; sweep++) {
			const order = sweep % 2 === 0 ? rows.map((row, i) => i).slice(1) : rows.map((row, i) => i).slice(0, -1).reverse();
			for (const r of order) {
				const refIds = new Set(rows[sweep % 2 === 0 ? r - 1 : r + 1].map(node => node.id));
				const bary = node => {
					const near = [...adjacency.get(node.id)].filter(id => refIds.has(id));
					return near.length ? near.reduce((sum, id) => sum + position.get(id), 0) / near.length : position.get(node.id);
				};
				rows[r] = [...rows[r]].sort((a, b) => bary(a) - bary(b));
				index();
			}
		}
		const maxPerRow = Math.max(6, Math.ceil(Math.sqrt(layoutNodes.length) * 1.4));
		rows = rows.flatMap(row => {
			const chunks = [];
			for (let i = 0; i < row.length; i += maxPerRow) chunks.push(row.slice(i, i + maxPerRow));
			return chunks;
		});
		const gapX = graph.level === 'regions' ? 110 : 90, gapY = graph.level === 'regions' ? 140 : 120;
		let y = 0;
		for (const row of rows) {
			const total = row.reduce((sum, node) => sum + node.w, 0) + gapX * Math.max(0, row.length - 1);
			let x = -total / 2;
			for (const node of row) {
				node.x = x;
				node.y = y;
				x += node.w + gapX;
			}
			y += Math.max(...row.map(node => node.h)) + gapY;
		}

		const blocks = [];
		for (const [parentId, ids] of graph.children) {
			const parent = graph.nodes.get(parentId);
			if (!parent || ids.length === 0) continue;
			const cols = Math.min(ids.length, ids.length > 8 ? 4 : 3);
			const width = cols * 176 + (cols - 1) * 14;
			blocks.push({parent, ids, cols, width, x: parent.x + parent.w / 2 - width / 2});
		}
		blocks.sort((a, b) => a.x - b.x);
		for (let i = 1; i < blocks.length; i++) blocks[i].x = Math.max(blocks[i].x, blocks[i - 1].x + blocks[i - 1].width + 40);
		if (blocks.length) {
			const left = blocks[0].x, right = blocks.at(-1).x + blocks.at(-1).width;
			const desired = blocks.reduce((sum, block) => sum + block.parent.x + block.parent.w / 2, 0) / blocks.length;
			const shift = desired - (left + right) / 2;
			for (const block of blocks) {
				block.x += shift;
				block.ids.forEach((id, i) => {
					const node = graph.nodes.get(id);
					node.x = block.x + (i % block.cols) * 190;
					node.y = y + Math.floor(i / block.cols) * 62;
				});
			}
		}

		const saved = this._lkJson(this._lkGet(`positions:${graph.key}`), {});
		for (const [id, point] of Object.entries(saved)) {
			const node = graph.nodes.get(id);
			if (node && Number.isFinite(point.x) && Number.isFinite(point.y)) {
				node.x = point.x;
				node.y = point.y;
			}
		}
	}

	_lkRenderTopology(panel, relayout = false) {
		if (!panel) return;
		const stage = panel.querySelector('.laka-topology-stage');
		if (!stage || stage.offsetParent === null) return;
		if (panel.dataset.scope === 'global') {
			const stored = this._lkGet('region');
			if (panel.dataset.region === undefined) panel.dataset.region = stored && this._lkMultiRegion()
				&& (this._lkSettings.regions || []).some(region => region.name === stored) ? stored : '';
		}
		const graph = this._lkTopologyGraph(panel);
		this._lkUpdateTopologyHeader(panel, graph);
		if (graph.nodes.size === 0) {
			stage.innerHTML = `<div class="laka-topology-empty">${this._lkEsc(this._lkT('No equipment available to build the topology.'))}</div>`;
			panel._graph = null;
			return;
		}
		this._lkLayout(graph);
		panel._graph = graph;
		if (relayout || !this._lkViews.has(graph.key)) {
			const saved = this._lkJson(this._lkGet(`view:${graph.key}`), null);
			if (!relayout && saved && Number.isFinite(saved.k)) this._lkViews.set(graph.key, saved);
			else this._lkViews.set(graph.key, this._lkFitView(graph, stage));
		}
		this._lkDrawTopology(panel);
		this._lkSetLinkButtons(panel);
		this._lkSearchTopology(panel, false);
	}

	_lkUpdateTopologyHeader(panel, graph) {
		const subtitle = panel.querySelector('.laka-topology-subtitle');
		if (subtitle) {
			subtitle.textContent = graph.level === 'regions'
				? this._lkT('%1$s regions · %2$s equipment · %3$s inter-region links', graph.counts.regions, graph.counts.hosts, graph.counts.links)
				: this._lkT('%1$s equipment · %2$s links · %3$s endpoints', graph.counts.hosts, graph.counts.links, graph.counts.endpoints);
		}
		const crumb = panel.querySelector('.laka-breadcrumb');
		if (crumb) {
			crumb.innerHTML = !this._lkMultiRegion() ? ''
				: graph.region
					? `<button type="button" data-action="region-up">${this._lkEsc(this._lkT('All regions'))}</button><span>›</span><b>${this._lkEsc(graph.region)}</b>`
					: `<b>${this._lkEsc(this._lkT('All regions'))}</b>`;
		}
		panel.closest('.laka-global-topology')?.classList.toggle('drilled', !!graph.region);
		const toggle = panel.querySelector('.laka-endpoint-toggle');
		if (toggle) {
			const on = this._lkEndpointsOn();
			toggle.classList.toggle('active', on);
			toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
			toggle.disabled = graph.level === 'regions';
		}
	}

	_lkOpenRegion(name) {
		this._lkShowTopology(true, name);
	}

	_lkBounds(graph) {
		let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
		for (const node of graph.nodes.values()) {
			minX = Math.min(minX, node.x);
			minY = Math.min(minY, node.y);
			maxX = Math.max(maxX, node.x + node.w);
			maxY = Math.max(maxY, node.y + node.h);
		}
		return {minX, minY, maxX, maxY};
	}

	_lkFitView(graph, stage) {
		const b = this._lkBounds(graph);
		const width = stage.clientWidth || 800, height = stage.clientHeight || 500, pad = 40;
		const k = Math.max(.2, Math.min(1, (width - pad * 2) / Math.max(1, b.maxX - b.minX), (height - pad * 2) / Math.max(1, b.maxY - b.minY)));
		return {k, x: (b.minX + b.maxX) / 2 - width / k / 2, y: (b.minY + b.maxY) / 2 - height / k / 2};
	}

	_lkApplyView(panel) {
		const stage = panel.querySelector('.laka-topology-stage');
		const svg = stage?.querySelector('svg');
		const view = this._lkViews.get(this._lkScopeKey(panel));
		if (!svg || !view) return;
		svg.setAttribute('viewBox', `${view.x} ${view.y} ${(stage.clientWidth || 800) / view.k} ${(stage.clientHeight || 500) / view.k}`);
		const frame = stage.querySelector('.lt-minimap .lt-mm-view');
		if (frame) {
			frame.setAttribute('x', view.x);
			frame.setAttribute('y', view.y);
			frame.setAttribute('width', (stage.clientWidth || 800) / view.k);
			frame.setAttribute('height', (stage.clientHeight || 500) / view.k);
		}
	}

	/** Línea animada sobre un enlace activo: la velocidad del flujo crece con la utilización. */
	_lkFlow(util, d, edge) {
		if (util === null || util === undefined || Number.isNaN(Number(util))) return '';
		const u = Math.max(0, Math.min(100, Number(util)));
		const duration = (3.2 - u / 100 * 2.6).toFixed(2);
		const level = u >= this._lkUtilCritical() ? ' high' : u >= this._lkUtilWarning() ? ' warn' : '';
		return `<path class="lt-flow${level}" data-a="${this._lkEsc(edge.a)}" data-b="${this._lkEsc(edge.b)}" d="${d}" style="animation-duration:${duration}s"/>`;
	}

	// ---------------------------------------------------------------- inventario de lo conectado (CDP/LLDP)

	/** Dispositivos detectados por CDP/LLDP bajo un equipo, por tipo: teléfonos, AP, cámaras, impresoras… */
	_lkConnected(hostid) {
		if (!this._lkConnectedMap) {
			this._lkConnectedMap = new Map();
			for (const group of this._lkTopo.endpoint_groups || []) {
				const key = String(group.parent);
				const entry = this._lkConnectedMap.get(key) || {total: 0, types: {}};
				entry.types[group.type] = (entry.types[group.type] || 0) + Number(group.count || 0);
				entry.total += Number(group.count || 0);
				this._lkConnectedMap.set(key, entry);
			}
		}
		return this._lkConnectedMap.get(String(hostid)) || {total: 0, types: {}};
	}

	_lkConnectedHtml(hostid) {
		const {total, types} = this._lkConnected(hostid);
		if (!total) return '<span class="laka-muted">—</span>';
		const short = {phone: '☎', access_point: 'AP', camera: 'Cam', printer: 'Imp', server: 'Srv', switch: 'Sw', router: 'Rt', firewall: 'FW'};
		const parts = Object.entries(types).sort((a, b) => b[1] - a[1]);
		const text = parts.slice(0, 3).map(([type, count]) => `${short[type] || '•'} ${count}`).join(' · ');
		const title = parts.map(([type, count]) => this._lkTypeLabel(type, count)).join(', ');
		return `<span class="laka-connected" title="${this._lkEsc(title)}">${this._lkEsc(text)}${parts.length > 3 ? ' …' : ''}</span>`;
	}

	// ---------------------------------------------------------------- auditoría CDP/LLDP

	_lkAuditIssues() {
		const hosts = new Map((this._lkTopo.hosts || []).map(host => [String(host.id), host]));
		const issues = [];
		for (const link of this._lkTopo.links || []) {
			for (const [code, a, b] of link.audit || []) issues.push({code, a, b, link, ha: hosts.get(String(link.a)), hb: hosts.get(String(link.b))});
		}
		return issues;
	}

	_lkOpenAudit() {
		const t = (...args) => this._lkT(...args);
		const esc = value => this._lkEsc(value);
		const issues = this._lkAuditIssues();
		const titles = {
			one_down: [t('One end down'), t('One side reports the port down and the other up: cable, SFP or port shutdown.')],
			speed: [t('Speed mismatch'), t('The two ends negotiated a different speed.')],
			duplex: [t('Duplex mismatch or half duplex'), t('Causes late collisions, CRC errors and slowness.')],
			errors: [t('Errors on the link'), t('Input/output errors on one of the ends.')],
			one_sided: [t('Seen from one side only'), t('Only one equipment reports the neighbour: CDP/LLDP is disabled or the topology template is missing on the other end.')]
		};
		const status = value => this._lkStatusLabel(value) || value;
		const detail = issue => {
			switch (issue.code) {
				case 'one_down': return `${status(issue.a)} ↔ ${status(issue.b)}`;
				case 'one_sided': return t('Reported by %1$s', (String(issue.link.a) === issue.a ? issue.ha : issue.hb)?.name || issue.a);
				default: return `${issue.a || '—'} ↔ ${issue.b || '—'}`;
			}
		};
		const sections = Object.keys(titles).map(code => {
			const list = issues.filter(issue => issue.code === code);
			if (!list.length) return '';
			const rows = list.map(issue => `<tr>
				<td><button type="button" class="laka-link" data-modal="open-host" data-hostid="${esc(issue.link.a)}">${esc(issue.ha?.name || issue.link.a)}</button></td>
				<td class="laka-locate-port">${esc(issue.link.an || issue.link.ai || '—')}</td>
				<td><button type="button" class="laka-link" data-modal="open-host" data-hostid="${esc(issue.link.b)}">${esc(issue.hb?.name || issue.link.b)}</button></td>
				<td class="laka-locate-port">${esc(issue.link.bn || issue.link.bi || '—')}</td>
				<td class="laka-audit-detail">${esc(detail(issue))}</td>
				<td><button type="button" class="laka-btn" data-modal="audit-map" data-a="${esc(issue.link.a)}" data-b="${esc(issue.link.b)}">${esc(t('Map'))}</button></td></tr>`).join('');
			return `<details class="laka-modal-section laka-audit-section" open><summary><b>${esc(titles[code][0])}</b> <span class="laka-count">${list.length}</span>
				<span class="laka-muted"> · ${esc(titles[code][1])}</span></summary>
				<div class="laka-table-scroll"><table class="laka-table"><thead><tr><th>${esc(t('End A'))}</th><th>${esc(t('Interface'))}</th><th>${esc(t('End B'))}</th><th>${esc(t('Interface'))}</th><th>${esc(t('Detail'))}</th><th></th></tr></thead>
				<tbody>${rows}</tbody></table></div></details>`;
		}).join('');
		const total = (this._lkTopo.links || []).length;
		this._lkOpenModal(`${this._lkModalHead(esc(t('CDP/LLDP link audit')), t('%1$s links checked · %2$s observations', total, issues.length))}
			<div class="laka-modal-body">${sections || `<div class="laka-locate-msg neutral">${esc(t('No observations: both ends of every link agree.'))}</div>`}</div>`, 'wide');
	}

	// ---------------------------------------------------------------- informe imprimible

	/** Informe por región en una ventana nueva, listo para imprimir o guardar como PDF desde el navegador. */
	_lkFleetReport() {
		const t = (...args) => this._lkT(...args);
		const esc = value => this._lkEsc(value);
		const rows = this._lkFleetRows();
		const issues = this._lkAuditIssues();
		const byRegion = new Map();
		for (const row of rows) {
			const key = row.region || t('Unassigned');
			if (!byRegion.has(key)) byRegion.set(key, []);
			byRegion.get(key).push(row);
		}
		const now = new Date().toLocaleString(this._lkLocale());
		const pct = (a, b) => b ? `${Math.round(a / b * 100)}%` : '—';
		const sum = (list, key) => list.reduce((total, row) => total + (Number(row[key]) || 0), 0);
		const kpi = (label, value, cls = '') => `<div class="k ${cls}"><div class="l">${esc(label)}</div><div class="v">${esc(value)}</div></div>`;
		const regionHtml = [...byRegion.entries()].sort((a, b) => a[0].localeCompare(b[0])).map(([region, list]) => {
			const unavailable = list.filter(row => row.health === 'unavailable').length;
			const problems = sum(list, 'problems');
			const body = list.sort((a, b) => this._lkFleetRank(a) - this._lkFleetRank(b) || String(a.name).localeCompare(String(b.name)))
				.map(row => `<tr class="${esc(row.health)}"><td>${esc(this._lkSettings.health_labels?.[row.health] || row.health)}</td><td><b>${esc(row.name)}</b><br><small>${esc(row.ip || '')}</small></td>
					<td>${esc(row.model || '—')}<br><small>${esc(row.os || '')}</small></td><td>${esc(row.location || '—')}</td><td>${esc(row.uptime || '—')}</td>
					<td class="n">${row.cpu ?? '—'}${row.cpu !== null && row.cpu !== undefined ? '%' : ''}</td><td class="n">${row.mem ?? '—'}${row.mem !== null && row.mem !== undefined ? '%' : ''}</td>
					<td class="n">${row.up}/${row.total}</td><td class="n">${row.ul_total ? `${row.ul_up}/${row.ul_total}` : '—'}</td><td class="n">${row.problems || 0}</td></tr>`).join('');
			return `<section><h2>${esc(region)}</h2><div class="kpis">${kpi(t('Equipment'), list.length)}${kpi(t('Unavailable'), unavailable, unavailable ? 'bad' : '')}
				${kpi(t('Active problems'), problems, problems ? 'warn' : '')}${kpi(t('Ports up'), pct(sum(list, 'up'), sum(list, 'total')))}
				${kpi(t('Free ports'), sum(list, 'total') - sum(list, 'up'))}</div>
				<table><thead><tr><th>${esc(t('Status'))}</th><th>${esc(t('Equipment'))}</th><th>${esc(t('Model'))}</th><th>${esc(t('Location'))}</th><th>${esc(t('Uptime'))}</th>
				<th>CPU</th><th>${esc(t('Memory'))}</th><th>${esc(t('Ports'))}</th><th>${esc(t('Uplinks'))}</th><th>${esc(t('Problems'))}</th></tr></thead><tbody>${body}</tbody></table></section>`;
		}).join('');
		const unavailable = rows.filter(row => row.health === 'unavailable').length;
		const html = `<!doctype html><html lang="${esc(this._lkSettings.language || 'es')}"><head><meta charset="utf-8"><title>${esc(t('Network report'))} · ${esc(now)}</title><style>
			body{font:12px/1.4 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2933;margin:28px}h1{font-size:20px;margin:0}h2{font-size:15px;margin:22px 0 8px;border-bottom:2px solid #1f2933;padding-bottom:4px}
			.sub{color:#5b6573;margin:4px 0 14px}.kpis{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}.k{border:1px solid #d5dbe1;border-radius:6px;padding:6px 10px;min-width:110px}.k .l{color:#5b6573;font-size:11px}.k .v{font-size:18px;font-weight:700}
			.k.bad .v{color:#c0392b}.k.warn .v{color:#b9770e}table{width:100%;border-collapse:collapse;margin-top:6px}th,td{border-bottom:1px solid #e3e7eb;padding:4px 6px;text-align:left;vertical-align:top}th{background:#f3f5f7;font-size:11px}
			td.n{text-align:right;font-variant-numeric:tabular-nums}small{color:#5b6573}tr.unavailable td:first-child{color:#c0392b;font-weight:700}tr.warning td:first-child{color:#b9770e;font-weight:700}
			footer{margin-top:28px;color:#5b6573;font-size:11px;border-top:1px solid #d5dbe1;padding-top:8px}section{break-inside:auto}h2{break-after:avoid}@media print{body{margin:12mm}button{display:none}}
			</style></head><body><button onclick="print()" style="float:right">${esc(t('Print / save PDF'))}</button>
			<h1>${esc(t('Network report'))}</h1><div class="sub">${esc(now)} · ${esc(t('%1$s equipment · %2$s regions', rows.length, byRegion.size))}</div>
			<div class="kpis">${kpi(t('Equipment'), rows.length)}${kpi(t('Unavailable'), unavailable, unavailable ? 'bad' : '')}${kpi(t('Active problems'), sum(rows, 'problems'), sum(rows, 'problems') ? 'warn' : '')}
			${kpi(t('Ports up'), pct(sum(rows, 'up'), sum(rows, 'total')))}${kpi(t('Free ports'), sum(rows, 'total') - sum(rows, 'up'))}${kpi(t('Link audit'), issues.length, issues.length ? 'warn' : '')}</div>
			${regionHtml}<footer>© LAKA Soluciones Tecnologicas</footer></body></html>`;
		const win = window.open('', '_blank');
		if (!win) {
			this._lkDownload(new Blob([html], {type: 'text/html'}), 'informe-red.html');
			return;
		}
		win.document.open();
		win.document.write(html);
		win.document.close();
	}

	// ---------------------------------------------------------------- modo NOC

	/** Pantalla completa con rotación automática: regiones (las con problemas primero) o lista y topología. */
	_lkStartNoc() {
		if (this._lkNoc) return;
		const consoleEl = this._lkConsole;
		consoleEl.classList.add('laka-noc');
		const bar = document.createElement('div');
		bar.className = 'laka-noc-bar';
		(consoleEl.querySelector('.laka-main') || consoleEl).prepend(bar);
		this._lkNoc = {bar, step: 0, timer: null, clock: null, interval: 20000};
		// Pantalla completa sobre el contenedor del widget (estable entre refrescos, a diferencia de la consola).
		this._lkNocStyle = this._target.getAttribute('style');
		this._target.style.background = getComputedStyle(consoleEl).getPropertyValue('--l-bg').trim() || getComputedStyle(consoleEl).backgroundColor;
		this._target.style.overflow = 'auto';
		this._target.requestFullscreen?.().catch(() => {});
		this._lkNocKeys = event => { if (event.key === 'Escape') this._lkStopNoc(); };
		this._lkNocFs = () => { if (!document.fullscreenElement && this._lkNoc?.wasFull) this._lkStopNoc(); else if (document.fullscreenElement) this._lkNoc.wasFull = true; };
		document.addEventListener('keydown', this._lkNocKeys);
		document.addEventListener('fullscreenchange', this._lkNocFs);
		this._lkNocRender();
		this._lkNoc.clock = setInterval(() => this._lkNocBar(), 1000);
		this._lkNoc.timer = setInterval(() => { this._lkNoc.step++; this._lkNocRender(); }, this._lkNoc.interval);
	}

	_lkStopNoc() {
		if (!this._lkNoc) return;
		clearInterval(this._lkNoc.timer);
		clearInterval(this._lkNoc.clock);
		this._lkNoc.bar.remove();
		this._lkConsole?.classList.remove('laka-noc');
		document.removeEventListener('keydown', this._lkNocKeys);
		document.removeEventListener('fullscreenchange', this._lkNocFs);
		if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
		if (this._lkNocStyle === null) this._target.removeAttribute('style');
		else this._target.setAttribute('style', this._lkNocStyle);
		this._lkNoc = null;
		this._lkShowFleet(false);
	}

	_lkNocSequence() {
		const rows = this._lkFleetRows();
		if (!this._lkMultiRegion()) return [{kind: 'fleet'}, {kind: 'topology', region: null}];
		const score = new Map();
		for (const row of rows) {
			const key = row.region || '';
			const current = score.get(key) || 0;
			score.set(key, current + (row.health === 'unavailable' ? 100 : 0) + (Number(row.problems) || 0));
		}
		const regions = [...score.entries()].sort((a, b) => b[1] - a[1]).map(([region]) => ({kind: 'topology', region}));
		return [{kind: 'fleet'}, {kind: 'topology', region: ''}, ...regions];
	}

	_lkNocRender() {
		if (!this._lkNoc) return;
		const sequence = this._lkNocSequence();
		const view = sequence[this._lkNoc.step % sequence.length];
		this._lkNoc.view = view;
		if (view.kind === 'fleet') this._lkShowFleet(false);
		else this._lkShowTopology(false, view.region);
		this._lkNocBar();
	}

	_lkNocBar() {
		const noc = this._lkNoc;
		if (!noc) return;
		const t = (...args) => this._lkT(...args);
		const rows = this._lkFleetRows();
		const unavailable = rows.filter(row => row.health === 'unavailable').length;
		const problems = rows.reduce((total, row) => total + (Number(row.problems) || 0), 0);
		const view = noc.view?.kind === 'fleet' ? t('Equipment list') : noc.view?.region ? noc.view.region
			: this._lkMultiRegion() ? t('All regions') : t('Network topology');
		noc.bar.innerHTML = `<span class="laka-noc-title">⛶ ${this._lkEsc(t('NOC mode'))} · ${this._lkEsc(view)}</span>
			<span class="laka-noc-kpi">${this._lkEsc(t('Equipment'))} <b>${rows.length}</b></span>
			<span class="laka-noc-kpi ${unavailable ? 'bad' : ''}">${this._lkEsc(t('Unavailable'))} <b>${unavailable}</b></span>
			<span class="laka-noc-kpi ${problems ? 'warn' : ''}">${this._lkEsc(t('Active problems'))} <b>${problems}</b></span>
			<span class="laka-noc-clock">${this._lkEsc(new Date().toLocaleString(this._lkLocale(), {weekday: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit'}))}</span>
			<button type="button" class="laka-btn" data-action="noc-stop">${this._lkEsc(t('Exit (Esc)'))}</button>`;
	}

	_lkUtilWarning() { return Number(this._lkSettings?.util_warning) || 60; }
	_lkUtilCritical() { return Number(this._lkSettings?.util_critical) || 80; }

	/** Minimapa: todos los nodos en miniatura y el rectángulo de la zona visible; clic para centrar. */
	_lkDrawMinimap(panel) {
		const graph = panel._graph;
		const mini = panel.querySelector('.lt-minimap');
		if (!graph || !mini) return;
		const nodes = [...graph.nodes.values()];
		if (nodes.length < 6) {
			mini.style.display = 'none';
			return;
		}
		mini.style.display = '';
		const b = this._lkBounds(graph);
		const pad = 30;
		mini.setAttribute('viewBox', `${b.minX - pad} ${b.minY - pad} ${b.maxX - b.minX + pad * 2} ${b.maxY - b.minY + pad * 2}`);
		mini.setAttribute('preserveAspectRatio', 'xMidYMid meet');
		let lines = '';
		for (const edge of graph.edges) {
			const a = graph.nodes.get(edge.a), c = graph.nodes.get(edge.b);
			if (a && c) lines += `<line x1="${a.x + a.w / 2}" y1="${a.y + a.h / 2}" x2="${c.x + c.w / 2}" y2="${c.y + c.h / 2}"/>`;
		}
		const boxes = nodes.map(node => {
			const health = node.kind === 'host' ? (node.data.health || 'unknown') : node.kind === 'region'
				? (node.data.unavailable > 0 ? 'unavailable' : node.data.problems > 0 ? 'warning' : 'available') : 'endpoint';
			return `<rect class="lt-mm-node ${this._lkEsc(health)}" x="${node.x}" y="${node.y}" width="${node.w}" height="${node.h}" rx="6"/>`;
		}).join('');
		mini.innerHTML = `<g class="lt-mm-edges">${lines}</g>${boxes}<rect class="lt-mm-view" rx="4"/>`;
		mini.dataset.action = 'minimap';
	}

	_lkLinkClass(link) {
		if (link.state === 'down') return 'down';
		if (link.state !== 'up') return 'unknown';
		return link.util_level === 'high' ? 'high' : link.util_level === 'warn' ? 'warn' : '';
	}

	_lkLinkWidth(speed) {
		const s = Number(speed) || 0;
		return s >= 40e9 ? 5.5 : s >= 25e9 ? 4.5 : s >= 10e9 ? 3.6 : s >= 1e9 ? 2.4 : s > 0 ? 1.6 : 2;
	}

	_lkAnchors(a, b, offset) {
		const ax = a.x + a.w / 2, ay = a.y + a.h / 2, bx = b.x + b.w / 2, by = b.y + b.h / 2;
		const vertical = Math.abs(by - ay) > Math.abs(bx - ax) * .35;
		if (vertical) {
			const down = by > ay;
			const p1 = {x: ax + offset, y: down ? a.y + a.h : a.y}, p2 = {x: bx + offset, y: down ? b.y : b.y + b.h};
			const mid = (p1.y + p2.y) / 2;
			return [p1, {x: p1.x, y: mid}, {x: p2.x, y: mid}, p2];
		}
		if (Math.abs(by - ay) < 12 && Math.abs(bx - ax) > (a.w + b.w) / 2 + 150) {
			// Misma fila pero no vecinos: arco por debajo de la fila en lugar de cruzar los nodos intermedios.
			const p1 = {x: ax + offset, y: a.y + a.h}, p2 = {x: bx + offset, y: b.y + b.h};
			const dip = Math.min(160, 50 + Math.abs(bx - ax) * .12);
			return [p1, {x: p1.x, y: p1.y + dip}, {x: p2.x, y: p2.y + dip}, p2];
		}
		const right = bx > ax;
		const p1 = {x: right ? a.x + a.w : a.x, y: ay + offset}, p2 = {x: right ? b.x : b.x + b.w, y: by + offset};
		const mid = (p1.x + p2.x) / 2;
		return [p1, {x: mid, y: p1.y}, {x: mid, y: p2.y}, p2];
	}

	_lkBezier([p0, p1, p2, p3], t) {
		const u = 1 - t;
		return {
			x: u * u * u * p0.x + 3 * u * u * t * p1.x + 3 * u * t * t * p2.x + t * t * t * p3.x,
			y: u * u * u * p0.y + 3 * u * u * t * p1.y + 3 * u * t * t * p2.y + t * t * t * p3.y
		};
	}

	_lkIcon(type) {
		const icons = {
			switch: '<rect x="1" y="7" width="24" height="12" rx="2"/><path d="M5 11h2m3 0h2m3 0h2m3 0h2M5 15h2m3 0h2m3 0h2m3 0h2"/>',
			router: '<circle cx="13" cy="13" r="11"/><path d="M7 13h12M13 7v12M9 9l4-3 4 3M9 17l4 3 4-3"/>',
			firewall: '<path d="M13 1l10 4v7c0 7-4 11-10 13C7 23 3 19 3 12V5l10-4z"/><path d="M7 10h12M7 15h12M11 6v4m5 0v5m-5 0v5"/>',
			phone: '<path d="M6 2h14l2 15H4L6 2zM7 21h12M9 6h8v6H9z"/>',
			access_point: '<circle cx="13" cy="19" r="2"/><path d="M7 14a8 8 0 0112 0M3 10a13 13 0 0120 0M13 21v4"/>',
			server: '<rect x="3" y="2" width="20" height="9" rx="1"/><rect x="3" y="14" width="20" height="9" rx="1"/><path d="M7 6.5h1m3 0h8M7 18.5h1m3 0h8"/>',
			camera: '<path d="M2 8h15v11H2zM17 11l7-4v13l-7-4z"/><circle cx="9.5" cy="13.5" r="3"/>',
			printer: '<path d="M6 2h14v7H6zM4 10h18a3 3 0 013 3v7h-5v4H6v-4H1v-7a3 3 0 013-3zM7 17h12v5H7z"/>',
			ups: '<rect x="5" y="1" width="16" height="24" rx="2"/><path d="M14 5l-4 7h6l-4 7"/>',
			endpoint: '<circle cx="13" cy="13" r="11"/><circle cx="13" cy="10" r="4"/><path d="M6 20c2-4 12-4 14 0"/>',
			region: '<path d="M13 25s-8-7.5-8-13a8 8 0 0116 0c0 5.5-8 13-8 13z"/><circle cx="13" cy="12" r="3"/>'
		};
		return `<g class="lt-icon">${icons[type] || icons.endpoint}</g>`;
	}

	_lkTypeLabel(type, count) {
		const labels = {
			phone: 'Phones', access_point: 'Access points', camera: 'Cameras', printer: 'Printers', server: 'Servers',
			switch: 'Switches', router: 'Routers', firewall: 'Firewalls', ups: 'UPS', endpoint: 'Endpoints'
		};
		return `${count} ${this._lkT(labels[type] || 'Endpoints')}`;
	}

	_lkAggState(links) {
		const up = links.filter(link => link.state === 'up').length;
		const down = links.filter(link => link.state === 'down').length;
		const high = links.some(link => link.util_level === 'high');
		const warn = links.some(link => link.util_level === 'warn');
		const cls = down > 0 && up === 0 ? 'down' : down > 0 ? 'warn' : up > 0 ? (high ? 'high' : warn ? 'warn' : '') : 'unknown';
		const utils = links.filter(link => link.state === 'up' && link.util !== null && link.util !== undefined).map(link => Number(link.util));
		return {up, down, cls, speed: Math.max(0, ...links.map(link => Number(link.speed_bps) || 0)), util: utils.length ? Math.max(...utils) : null};
	}

	_lkPath(points) {
		return `M${points[0].x},${points[0].y} C${points[1].x},${points[1].y} ${points[2].x},${points[2].y} ${points[3].x},${points[3].y}`;
	}

	_lkDrawTopology(panel) {
		const graph = panel._graph;
		const stage = panel.querySelector('.laka-topology-stage');
		if (!graph || !stage) return;
		const nodes = graph.nodes;
		const selectedLink = panel.dataset.selectedLink || '';
		const current = graph.global ? '' : graph.scope;
		let edges = '', labels = '', nodeSvg = '', flows = '';
		const ends = edge => `data-a="${this._lkEsc(edge.a)}" data-b="${this._lkEsc(edge.b)}"`;

		// Enlaces entre equipos (enlaces paralelos desplazados, con etiqueta en cada extremo).
		const pairs = new Map();
		graph.edges.forEach((edge, index) => {
			if (edge.kind !== 'link') return;
			const key = [edge.a, edge.b].sort().join('|');
			if (!pairs.has(key)) pairs.set(key, []);
			pairs.get(key).push(edge);
		});
		// Los nodos muy conectados (switches core) acumularían etiquetas de puerto superpuestas; ahí solo se etiqueta el extremo lejano.
		const degree = new Map();
		for (const edge of graph.edges) {
			degree.set(edge.a, (degree.get(edge.a) || 0) + 1);
			degree.set(edge.b, (degree.get(edge.b) || 0) + 1);
		}
		for (const list of pairs.values()) {
			list.forEach((edge, i) => {
				const link = edge.link;
				const a = nodes.get(edge.a), b = nodes.get(edge.b);
				if (!a || !b) return;
				const flip = edge.a > edge.b;
				const offset = (i - (list.length - 1) / 2) * 12 * (flip ? -1 : 1);
				const points = this._lkAnchors(a, b, offset);
				const d = this._lkPath(points);
				const cls = this._lkLinkClass(link);
				edges += `<path class="lt-edge ${cls}${String(link.id) === selectedLink ? ' selected' : ''}" ${ends(edge)} d="${d}" style="stroke-width:${this._lkLinkWidth(link.speed_bps)}px"/>`;
				flows += this._lkFlow(link.state === 'up' ? link.util : null, d, edge);
				if (list.length === 1 && link.state === 'up' && link.util !== null && link.util !== undefined) {
					const p = this._lkBezier(points, .5);
					const text = `${Math.round(link.util)}%`;
					labels += `<g class="lt-util-g ${cls}" ${ends(edge)}><rect class="lt-util" x="${p.x - 17}" y="${p.y - 9}" width="34" height="18" rx="9"/>
						<text class="lt-util-text" x="${p.x}" y="${p.y + 4}" text-anchor="middle">${text}</text></g>`;
				}
				edges += `<path class="lt-hit" data-action="edge" data-link="${this._lkEsc(link.id)}" d="${d}"><title>${this._lkEsc(`${link.an || link.ai} ↔ ${link.bn || link.bi} · ${link.protocol || this._lkT('Manual')}`)}</title></path>`;
				if (list.length <= 4) {
					const along = .16 + i * .13;
					for (const [t, text, end] of [[along, link.an || link.ai, edge.a], [1 - along, link.bn || link.bi, edge.b]]) {
						if ((degree.get(end) || 0) > 6) continue;
						const p = this._lkBezier(points, t);
						const label = this._lkEsc(this._lkClip(text, 14));
						const w = Math.max(28, label.length * 6 + 10);
						labels += `<g class="lt-port" ${ends(edge)}><rect class="lt-port-bg" x="${p.x - w / 2}" y="${p.y - 8}" width="${w}" height="16" rx="8"/>
							<text class="lt-port-label" x="${p.x}" y="${p.y + 3.5}" text-anchor="middle">${label}</text></g>`;
					}
				}
				if (list.length > 1 && i === 0) {
					const p = this._lkBezier(points, .5);
					labels += `<g class="lt-badge-g" ${ends(edge)}><rect class="lt-badge" x="${p.x - 15}" y="${p.y - 9}" width="30" height="18" rx="9"/>
						<text class="lt-badge-text" x="${p.x}" y="${p.y + 4}" text-anchor="middle">${list.length}×</text></g>`;
				}
			});
		}

		// Enlaces agregados: región ↔ región, o equipo ↔ nodo de la región vecina.
		graph.edges.forEach((edge, index) => {
			if (edge.kind !== 'agg') return;
			const a = nodes.get(edge.a), b = nodes.get(edge.b);
			if (!a || !b) return;
			const state = this._lkAggState(edge.links);
			const points = this._lkAnchors(a, b, 0);
			const d = this._lkPath(points);
			const width = Math.min(9, this._lkLinkWidth(state.speed) + Math.min(3, (edge.links.length - 1) * .7));
			edges += `<path class="lt-edge ${state.cls}" ${ends(edge)} d="${d}" style="stroke-width:${width}px"/>`;
			flows += this._lkFlow(state.up > 0 ? state.util : null, d, edge);
			edges += `<path class="lt-hit" data-action="edge" data-agg="${index}" d="${d}"><title>${this._lkEsc(this._lkT('%1$s links · %2$s up · %3$s down', edge.links.length, state.up, state.down))}</title></path>`;
			const p = this._lkBezier(points, .5);
			const text = `${edge.links.length}×${state.down ? ` · ${state.down}↓` : ''}${state.util !== null ? ` · ${Math.round(state.util)}%` : ''}`;
			const w = Math.max(30, text.length * 6.5 + 12);
			labels += `<g class="lt-badge-g${state.down ? ' bad' : ''}" ${ends(edge)}><rect class="lt-badge" x="${p.x - w / 2}" y="${p.y - 9}" width="${w}" height="18" rx="9"/>
				<text class="lt-badge-text" x="${p.x}" y="${p.y + 4}" text-anchor="middle">${this._lkEsc(text)}</text></g>`;
		});

		// Enlaces de equipos finales y de grupos.
		for (const node of nodes.values()) {
			if (!['endpoint', 'cluster'].includes(node.kind)) continue;
			const parent = nodes.get(node.parent);
			if (!parent) continue;
			const d = this._lkPath(this._lkAnchors(parent, node, 0));
			const state = node.kind === 'endpoint' ? node.data.status
				: node.up > 0 ? 'up' : node.down === node.count ? 'down' : 'unknown';
			const cls = state === 'up' ? '' : ['down', 'disabled'].includes(state) ? 'down' : 'unknown';
			edges += `<path class="lt-edge endpoint ${cls}" data-a="${this._lkEsc(node.parent)}" data-b="${this._lkEsc(node.id)}" d="${d}"/>`;
		}

		for (const node of nodes.values()) {
			const attrs = `data-action="node" data-node="${this._lkEsc(node.id)}" transform="translate(${node.x},${node.y})"`;
			if (node.kind === 'host') {
				const h = node.data;
				const health = ['available', 'warning', 'unavailable', 'maintenance'].includes(h.health) ? h.health : 'unknown';
				const sev = Number(h.severity) >= 0 ? ` sev-${Number(h.severity)}` : '';
				const sub = [h.model, h.ip].filter(Boolean).join(' · ') || '—';
				nodeSvg += `<g class="lt-node health-${health}${sev}${String(h.id) === current ? ' current' : ''}" ${attrs}>
					<title>${this._lkEsc(`${h.name}\n${sub}\n${[h.region, h.location].filter(Boolean).join(' · ')}`)}</title>
					<rect class="lt-node-box" width="${node.w}" height="${node.h}" rx="7"/>
					<rect class="lt-node-strip" x="0" y="8" width="4" height="${node.h - 16}" rx="2"/>
					<g transform="translate(12,15)">${this._lkIcon(h.type)}</g>
					<text class="lt-name" x="46" y="20">${this._lkEsc(this._lkClip(h.name, 18))}</text>
					<text class="lt-sub" x="46" y="34">${this._lkEsc(this._lkClip(sub, 24))}</text>
					<text class="lt-sub" x="46" y="47">${this._lkEsc(this._lkT('%1$s/%2$s up', h.up, h.total))}${h.role ? ' · ' + this._lkEsc(h.role) : ''}</text></g>`;
			}
			else if (node.kind === 'region') {
				const r = node.data;
				const health = r.unavailable > 0 ? 'unavailable' : r.problems > 0 ? 'warning' : r.hosts > 0 ? 'available' : 'unknown';
				const sev = r.unavailable > 0 ? ' sev-5' : r.max_severity >= 0 ? ` sev-${r.max_severity}` : '';
				const bar = [['available', r.available], ['warning', r.warning], ['unavailable', r.unavailable],
					['maintenance', r.maintenance], ['unknown', r.unknown]];
				let x = 14;
				const barWidth = node.w - 28;
				const segments = bar.map(([cls, count]) => {
					const w = r.hosts > 0 ? barWidth * (count || 0) / r.hosts : 0;
					const rect = w > 0 ? `<rect class="lt-bar ${cls}" x="${x}" y="${node.h - 14}" width="${w}" height="5"/>` : '';
					x += w;
					return rect;
				}).join('');
				const ports = r.ports_total > 0 ? Math.round(r.ports_up / r.ports_total * 100) + '%' : '—';
				nodeSvg += `<g class="lt-node lt-region health-${health}${sev}" ${attrs}>
					<title>${this._lkEsc(this._lkT('Click to open the region'))}</title>
					<rect class="lt-node-box" width="${node.w}" height="${node.h}" rx="10"/>
					<rect class="lt-node-strip" x="0" y="10" width="5" height="${node.h - 20}" rx="2"/>
					<g transform="translate(14,14)">${this._lkIcon('region')}</g>
					<text class="lt-name lt-region-name" x="48" y="24">${this._lkEsc(this._lkClip(r.name, 20))}</text>
					<text class="lt-sub" x="48" y="40">${this._lkEsc(this._lkT('%1$s equipment · %2$s problems', r.hosts, r.problems))}</text>
					<text class="lt-sub" x="48" y="54">${this._lkEsc(this._lkT('%1$s unavailable · ports up %2$s', r.unavailable, ports))}</text>
					<rect class="lt-bar-bg" x="14" y="${node.h - 14}" width="${barWidth}" height="5"/>${segments}</g>`;
			}
			else if (node.kind === 'stub') {
				nodeSvg += `<g class="lt-node lt-stub" ${attrs}>
					<title>${this._lkEsc(this._lkT('Click to open the region'))}</title>
					<rect class="lt-node-box" width="${node.w}" height="${node.h}" rx="24"/>
					<g transform="translate(12,11) scale(.9)">${this._lkIcon('region')}</g>
					<text class="lt-name" x="44" y="21" style="font-size:11px">${this._lkEsc(this._lkClip('→ ' + node.region, 20))}</text>
					<text class="lt-sub" x="44" y="35">${this._lkEsc(this._lkT('%1$s linked equipment', node.hosts.size))}</text></g>`;
			}
			else if (node.kind === 'endpoint') {
				const e = node.data;
				const ghost = ['switch', 'router', 'firewall'].includes(e.type);
				nodeSvg += `<g class="lt-node lt-endpoint st-${this._lkEsc(e.status)}${ghost ? ' lt-ghost' : ''}" ${attrs}>
					<title>${this._lkEsc(`${e.name}\n${e.ip || ''}\n${e.local_port} ↔ ${e.remote_port}\n${e.platform || ''}${ghost ? '\n' + this._lkT('CDP/LLDP neighbour not monitored in Zabbix') : ''}`)}</title>
					<rect class="lt-node-box" width="${node.w}" height="${node.h}" rx="6"/>
					<g transform="translate(9,11) scale(.86)">${this._lkIcon(e.type)}</g>
					<text class="lt-name" x="38" y="19" style="font-size:11px">${this._lkEsc(this._lkClip(e.name, 15))}</text>
					<text class="lt-sub" x="38" y="34">${this._lkEsc(this._lkClip(e.local_port || e.ip || '', 18))}</text></g>`;
			}
			else {
				nodeSvg += `<g class="lt-node lt-endpoint lt-cluster" ${attrs}>
					<title>${this._lkEsc(this._lkT('Click to expand'))}</title>
					<rect class="lt-node-box" width="${node.w}" height="${node.h}" rx="6"/>
					<g transform="translate(9,11) scale(.86)">${this._lkIcon(node.type)}</g>
					<text class="lt-cluster-count" x="38" y="20">${this._lkEsc(this._lkClip(this._lkTypeLabel(node.type, node.count), 19))}</text>
					<text class="lt-sub" x="38" y="35">${this._lkEsc(this._lkT('%1$s up · click to expand', node.up))}</text></g>`;
			}
		}

		stage.innerHTML = `<svg class="laka-topology-svg" role="img" aria-label="${this._lkEsc(this._lkT('Network topology'))}">
			<g class="lt-edges">${edges}</g><g class="lt-flows">${flows}</g><g class="lt-labels">${labels}</g><g class="lt-nodes">${nodeSvg}</g></svg>
			<svg class="lt-minimap" aria-hidden="true"></svg>`;
		panel.classList.toggle('lt-no-anim', this._lkGet('topology-anim') === '0');
		panel.classList.toggle('lt-no-minimap', this._lkGet('topology-minimap') === '0');
		this._lkDrawMinimap(panel);
		this._lkApplyView(panel);
	}

	_lkFocusNode(element, on) {
		const svg = element.closest('svg');
		if (!svg) return;
		svg.classList.toggle('focusing', on);
		for (const node of svg.querySelectorAll('.hl')) node.classList.remove('hl');
		if (!on) return;
		const id = element.dataset.node;
		const related = new Set([id]);
		for (const edge of svg.querySelectorAll('.lt-edge, .lt-port, .lt-badge-g')) {
			if (edge.dataset.a === id || edge.dataset.b === id) {
				edge.classList.add('hl');
				related.add(edge.dataset.a);
				related.add(edge.dataset.b);
			}
		}
		for (const node of svg.querySelectorAll('.lt-node')) {
			if (related.has(node.dataset.node)) node.classList.add('hl');
		}
	}

	_lkSearchTopology(panel, center) {
		const svg = panel?.querySelector('svg');
		const query = (panel?.querySelector('.laka-topology-search')?.value || '').trim().toLocaleLowerCase();
		if (!svg || !panel._graph) return;
		let first = null;
		for (const element of svg.querySelectorAll('.lt-node')) {
			const node = panel._graph.nodes.get(element.dataset.node);
			const text = node.kind === 'host' ? `${node.data.name} ${node.data.ip} ${node.data.model} ${node.data.location}`
				: node.kind === 'endpoint' ? `${node.data.name} ${node.data.ip} ${node.data.local_port}`
				: node.kind === 'region' ? `${node.data.name} ${(this._lkTopo.hosts || []).filter(host => host.region === node.data.name)
					.map(host => `${host.name} ${host.ip}`).join(' ')}`
				: node.kind === 'stub' ? node.region
				: (node.items || []).map(item => `${item.name} ${item.ip}`).join(' ');
			const match = query !== '' && text.toLocaleLowerCase().includes(query);
			element.classList.toggle('match', match);
			if (match && !first) first = node;
		}
		if (center && first) {
			const stage = panel.querySelector('.laka-topology-stage');
			const view = this._lkViews.get(this._lkScopeKey(panel));
			view.k = Math.max(view.k, 1);
			view.x = first.x + first.w / 2 - stage.clientWidth / view.k / 2;
			view.y = first.y + first.h / 2 - stage.clientHeight / view.k / 2;
			this._lkApplyView(panel);
		}
	}

	_lkZoom(panel, action, pivot = null) {
		const stage = panel?.querySelector('.laka-topology-stage');
		const view = this._lkViews.get(this._lkScopeKey(panel));
		if (!stage || !view || !panel._graph) return;
		if (action === 'fit') {
			this._lkViews.set(this._lkScopeKey(panel), this._lkFitView(panel._graph, stage));
		}
		else {
			const factor = action === 'in' ? 1.25 : action === 'out' ? 1 / 1.25 : Number(action) || 1;
			const k = Math.max(.15, Math.min(3, view.k * factor));
			const px = pivot?.x ?? stage.clientWidth / 2, py = pivot?.y ?? stage.clientHeight / 2;
			const wx = view.x + px / view.k, wy = view.y + py / view.k;
			view.k = k;
			view.x = wx - px / k;
			view.y = wy - py / k;
		}
		this._lkApplyView(panel);
		this._lkSet(`view:${this._lkScopeKey(panel)}`, JSON.stringify(this._lkViews.get(this._lkScopeKey(panel))));
	}

	_lkOnWheel(event) {
		const stage = event.target.closest('.laka-topology-stage');
		if (!stage || !this._target.contains(stage)) return;
		event.preventDefault();
		const box = stage.getBoundingClientRect();
		this._lkZoom(stage.closest('.laka-topology'), event.deltaY < 0 ? 1.12 : 1 / 1.12,
			{x: event.clientX - box.left, y: event.clientY - box.top});
	}

	_lkOnPointerDown(event) {
		if (event.target.closest('.lt-minimap')) return;
		const stage = event.target.closest('.laka-topology-stage');
		if (!stage || !this._target.contains(stage) || event.button !== 0) return;
		const box = stage.getBoundingClientRect();
		if (box.right - event.clientX < 18 && box.bottom - event.clientY < 18) return;
		const panel = stage.closest('.laka-topology');
		const view = this._lkViews.get(this._lkScopeKey(panel));
		if (!view || !panel._graph) return;
		if (event.target.closest('.lt-hit')) return;
		const nodeElement = event.target.closest('.lt-node');
		const node = nodeElement ? panel._graph.nodes.get(nodeElement.dataset.node) : null;
		this._lkDrag = {panel, stage, view, node, pointerId: event.pointerId, sx: event.clientX, sy: event.clientY,
			ox: node ? node.x : view.x, oy: node ? node.y : view.y, moved: false};
	}

	_lkOnPointerMove(event) {
		const drag = this._lkDrag;
		if (!drag || drag.pointerId !== event.pointerId) return;
		const dx = (event.clientX - drag.sx) / drag.view.k, dy = (event.clientY - drag.sy) / drag.view.k;
		if (!drag.moved && Math.abs(event.clientX - drag.sx) + Math.abs(event.clientY - drag.sy) < 5) return;
		if (!drag.moved) {
			// Captura solo cuando empieza un arrastre real, para que los clics simples sigan llegando a nodos y enlaces.
			drag.stage.setPointerCapture?.(event.pointerId);
			drag.stage.classList.add('panning');
			this._lkHideTooltip();
		}
		drag.moved = true;
		if (drag.node) {
			drag.node.x = Math.round(drag.ox + dx);
			drag.node.y = Math.round(drag.oy + dy);
			if (!drag.frame) {
				drag.frame = requestAnimationFrame(() => {
					drag.frame = null;
					this._lkDrawTopology(drag.panel);
				});
			}
		}
		else {
			drag.view.x = drag.ox - dx;
			drag.view.y = drag.oy - dy;
			this._lkApplyView(drag.panel);
		}
		event.preventDefault();
	}

	_lkOnPointerUp(event) {
		const drag = this._lkDrag;
		if (!drag || drag.pointerId !== event.pointerId) return;
		if (drag.moved) drag.stage.releasePointerCapture?.(event.pointerId);
		drag.stage.classList.remove('panning');
		this._lkDrag = null;
		if (!drag.moved) return;
		this._lkSuppressClick = true;
		setTimeout(() => { this._lkSuppressClick = false; }, 0);
		const scope = this._lkScopeKey(drag.panel);
		if (drag.node) {
			const saved = this._lkJson(this._lkGet(`positions:${scope}`), {});
			saved[drag.node.id] = {x: drag.node.x, y: drag.node.y};
			this._lkSet(`positions:${scope}`, JSON.stringify(saved));
			this._lkDrawTopology(drag.panel);
			this._lkSearchTopology(drag.panel, false);
		}
		else this._lkSet(`view:${scope}`, JSON.stringify(drag.view));
	}

	_lkObserveStages() {
		this._lkResizeObserver?.disconnect();
		if (typeof ResizeObserver === 'undefined') return;
		this._lkResizeObserver = new ResizeObserver(entries => {
			for (const entry of entries) {
				const panel = entry.target.closest('.laka-topology');
				if (!panel || entry.target.offsetParent === null) continue;
				if (entry.target.style.height) this._lkSet(`stage:${panel.dataset.scope}`, Math.round(entry.contentRect.height));
				this._lkApplyView(panel);
			}
		});
		for (const stage of this._lkConsole.querySelectorAll('.laka-topology-stage')) this._lkResizeObserver.observe(stage);
	}

	// ================================================================== topología: interacción con nodos / enlaces

	_lkOnNodeClick(element) {
		const panel = element.closest('.laka-topology');
		const node = panel?._graph?.nodes.get(element.dataset.node);
		if (!node) return;
		if (node.kind === 'region') return this._lkDrillRegion(panel, node.data.name);
		if (node.kind === 'stub') return this._lkDrillRegion(panel, node.region);
		if (node.kind === 'cluster') {
			const expand = () => {
				const key = this._lkScopeKey(panel);
				const expanded = new Set(this._lkJson(this._lkGet(`expanded:${key}`), []));
				expanded.add(node.key);
				this._lkSet(`expanded:${key}`, JSON.stringify([...expanded]));
				this._lkRenderTopology(panel, true);
			};
			if (node.items) expand();
			else {
				element.classList.add('loading');
				this._lkEnsureDevice(node.hostid).then(expand).catch(() => element.classList.remove('loading'));
			}
			return;
		}
		if (node.kind === 'endpoint') this._lkOpenEndpointModal(panel, node);
		else this._lkOpenHostModal(panel, node.data);
	}

	_lkDrillRegion(panel, region) {
		panel.dataset.region = region;
		panel.dataset.selectedLink = '';
		this._lkSet('region', region);
		const search = panel.querySelector('.laka-topology-search');
		if (search) search.value = '';
		this._lkRenderTopology(panel);
	}

	_lkOpenEndpointModal(panel, node) {
		const endpoint = node.data;
		const parent = (this._lkTopo.hosts || []).find(host => String(host.id) === String(endpoint.parent));
		const port = endpoint.local_index ? this._lkPort(endpoint.parent, endpoint.local_index) : null;
		const local = this._lkFacts([
			[this._lkT('Equipment'), parent?.name], [this._lkT('IP address'), parent?.ip], [this._lkT('Interface'), endpoint.local_port],
			[this._lkT('Port status'), this._lkStatusLabel(endpoint.status)],
			[this._lkT('In / Out'), port ? `${port.in_text} / ${port.out_text}` : ''], ['VLAN', endpoint.vlan || port?.vlan]
		]);
		const remote = this._lkFacts([
			[this._lkT('Hostname'), endpoint.name], [this._lkT('IP address'), endpoint.ip], [this._lkT('Remote port'), endpoint.remote_port],
			[this._lkT('Platform'), endpoint.platform], [this._lkT('Software'), endpoint.software, true], [this._lkT('Description'), endpoint.description, true],
			[this._lkT('Last discovery'), endpoint.lastclock ? new Date(endpoint.lastclock * 1000).toLocaleString(this._lkLocale()) : '']
		]);
		const collapse = node.key ? `<button type="button" class="laka-btn" data-modal="collapse" data-key="${this._lkEsc(node.key)}">${this._lkEsc(this._lkT('Group again'))}</button>` : '';
		const openPort = port ? `<button type="button" class="laka-btn" data-modal="open-port" data-hostid="${this._lkEsc(endpoint.parent)}" data-index="${this._lkEsc(port.index)}">${this._lkEsc(this._lkT('Open interface'))}</button>` : '';
		const box = this._lkOpenModal(`${this._lkModalHead(this._lkEsc(endpoint.name), `${endpoint.protocol} · ${this._lkT(this._lkTypeLabel(endpoint.type, 1).replace(/^1 /, ''))}`)}
			<div class="laka-modal-body"><div class="laka-compare">
				<section class="laka-compare-side"><div class="laka-compare-title">${this._lkEsc(this._lkT('Local equipment'))}</div>${local}</section>
				<div class="laka-compare-bridge"><span>↔</span><span class="laka-tag">${this._lkEsc(endpoint.protocol)}</span></div>
				<section class="laka-compare-side"><div class="laka-compare-title">${this._lkEsc(this._lkT('Discovered neighbor'))}</div>${remote}</section>
			</div><div class="laka-modal-actions">${collapse}${openPort}</div></div>`);
		box.querySelector('[data-modal="collapse"]')?.addEventListener('click', () => {
			const key = this._lkScopeKey(panel);
			const expanded = new Set(this._lkJson(this._lkGet(`expanded:${key}`), []));
			expanded.delete(node.key);
			this._lkSet(`expanded:${key}`, JSON.stringify([...expanded]));
			this._lkCloseModal();
			this._lkRenderTopology(panel);
		});
	}

	_lkOpenHostModal(panel, host) {
		const links = (this._lkTopo.links || []).filter(link => String(link.a) === String(host.id) || String(link.b) === String(host.id));
		const names = new Map((this._lkTopo.hosts || []).map(candidate => [String(candidate.id), candidate.name]));
		const rows = links.map(link => {
			const isA = String(link.a) === String(host.id);
			const other = isA ? link.b : link.a;
			return `<tr><td>${this._lkEsc(isA ? link.an || link.ai : link.bn || link.bi)}</td><td>${this._lkEsc(names.get(String(other)) || other)}</td>
				<td>${this._lkEsc(isA ? link.bn || link.bi : link.an || link.ai)}</td><td>${this._lkEsc(link.protocol || this._lkT('Manual'))}</td>
				<td>${this._lkEsc(link.speed_bps ? this._lkRate(link.speed_bps) : '—')}</td><td>${link.util === null || link.util === undefined ? '—' : this._lkEsc(link.util + '%')}</td>
				<td>${this._lkEsc(this._lkStatusLabel(link.state === 'up' ? 'up' : link.state === 'down' ? 'down' : 'unknown'))}</td></tr>`;
		}).join('');
		const health = this._lkSettings.health_labels?.[host.health] || host.health;
		this._lkOpenModal(`${this._lkModalHead(this._lkEsc(host.name), [host.model, host.ip, host.location].filter(Boolean).join(' · '),
			` <span class="laka-pill health-${this._lkEsc(host.health)}">${this._lkEsc(health)}</span>`)}
			<div class="laka-modal-body">
				<div class="laka-modal-section">${this._lkFacts([[this._lkT('Ports up'), `${host.up} / ${host.total}`], [this._lkT('Ports down'), host.down],
					[this._lkT('Interconnections'), links.length], [this._lkT('Role'), host.role || '—'], [this._lkT('Type'), host.type]])}</div>
				${rows ? `<div class="laka-modal-section"><div class="laka-modal-section-title">${this._lkEsc(this._lkT('Interconnections'))}</div>
					<table class="laka-detail-table"><thead><tr><th>${this._lkEsc(this._lkT('Local port'))}</th><th>${this._lkEsc(this._lkT('Neighbor'))}</th>
					<th>${this._lkEsc(this._lkT('Remote port'))}</th><th>${this._lkEsc(this._lkT('Source'))}</th><th>${this._lkEsc(this._lkT('Speed'))}</th>
					<th>${this._lkEsc(this._lkT('Use'))}</th><th>${this._lkEsc(this._lkT('Status'))}</th></tr></thead><tbody>${rows}</tbody></table></div>` : ''}
				<div class="laka-modal-actions"><button type="button" class="laka-btn primary" data-modal="open-host" data-hostid="${this._lkEsc(host.id)}">${this._lkEsc(this._lkT('Open equipment panel'))}</button></div>
			</div>`);
	}

	_lkOnEdgeClick(element) {
		const panel = element.closest('.laka-topology');
		if (element.dataset.agg !== undefined) return this._lkOpenAggregateModal(panel, panel._graph?.edges[Number(element.dataset.agg)]);
		const link = (this._lkTopo.links || []).find(candidate => String(candidate.id) === element.dataset.link);
		if (!link) return;
		panel.dataset.selectedLink = String(link.id);
		this._lkDrawTopology(panel);
		this._lkSetLinkButtons(panel);
		const names = new Map((this._lkTopo.hosts || []).map(host => [String(host.id), host.name]));
		const manageable = this._lkCanManage && link.source === 'manual';
		const actions = manageable ? `<button type="button" class="laka-btn" data-modal="link-edit" data-id="${this._lkEsc(link.id)}">${this._lkEsc(this._lkT('Edit'))}</button>
			<button type="button" class="laka-btn danger" data-modal="link-delete" data-id="${this._lkEsc(link.id)}">${this._lkEsc(this._lkT('Delete'))}</button>` : '';
		const side = (host, port) => this._lkFacts([[this._lkT('Equipment'), names.get(String(host)) || host], [this._lkT('Interface'), port]]);
		this._lkOpenModal(`${this._lkModalHead(`${this._lkEsc(link.an || link.ai)} ↔ ${this._lkEsc(link.bn || link.bi)}`,
			`${link.protocol || this._lkT('Manual')} · ${link.type || 'uplink'}${link.description ? ' · ' + link.description : ''}`)}
			<div class="laka-modal-body">
				<div class="laka-compare">
					<section class="laka-compare-side"><div class="laka-compare-title">A</div>${side(link.a, link.an || link.ai)}</section>
					<div class="laka-compare-bridge"><span>↔</span><span class="laka-tag">${this._lkEsc(link.protocol || this._lkT('Manual'))}</span></div>
					<section class="laka-compare-side"><div class="laka-compare-title">B</div>${side(link.b, link.bn || link.bi)}</section>
				</div>
				<div class="laka-modal-section" style="margin-top:14px">${this._lkFacts([
					[this._lkT('Status'), this._lkStatusLabel(link.state === 'up' ? 'up' : link.state === 'down' ? 'down' : 'unknown')],
					[this._lkT('Speed'), link.speed_bps ? this._lkRate(link.speed_bps) : '—'],
					[this._lkT('Use'), link.util === null || link.util === undefined ? '—' : `${link.util}%`],
					[this._lkT('Traffic A (in / out)'), link.traffic || '—']])}</div>
				${actions ? `<div class="laka-modal-actions">${actions}</div>` : ''}
			</div>`, true);
	}

	_lkOpenAggregateModal(panel, edge) {
		if (!edge) return;
		const hosts = new Map((this._lkTopo.hosts || []).map(host => [String(host.id), host]));
		const state = this._lkAggState(edge.links);
		const label = id => {
			const node = panel._graph.nodes.get(id);
			return node?.kind === 'region' ? node.data.name : node?.kind === 'stub' ? node.region : node?.data?.name || id;
		};
		const status = link => this._lkStatusLabel(link.state === 'up' ? 'up' : link.state === 'down' ? 'down' : 'unknown');
		const rows = edge.links.map(link => {
			const a = hosts.get(String(link.a)), b = hosts.get(String(link.b));
			return `<tr><td>${this._lkEsc(a?.name || link.a)} <span class="laka-muted">${this._lkEsc(a?.region || '')}</span></td>
				<td>${this._lkEsc(link.an || link.ai)}</td><td>${this._lkEsc(b?.name || link.b)} <span class="laka-muted">${this._lkEsc(b?.region || '')}</span></td>
				<td>${this._lkEsc(link.bn || link.bi)}</td><td>${this._lkEsc(link.protocol || this._lkT('Manual'))}</td>
				<td>${this._lkEsc(link.speed_bps ? this._lkRate(link.speed_bps) : '—')}</td>
				<td>${link.util === null || link.util === undefined ? '—' : this._lkEsc(link.util + '%')}</td>
				<td><span class="laka-dot st-${link.state === 'up' ? 'up' : link.state === 'down' ? 'down' : 'unknown'}"></span>${this._lkEsc(status(link))}</td></tr>`;
		}).join('');
		this._lkOpenModal(`${this._lkModalHead(`${this._lkEsc(label(edge.a))} ↔ ${this._lkEsc(label(edge.b))}`,
			this._lkT('%1$s links · %2$s up · %3$s down', edge.links.length, state.up, state.down))}
			<div class="laka-modal-body"><table class="laka-detail-table"><thead><tr>
				<th>${this._lkEsc(this._lkT('Equipment A'))}</th><th>${this._lkEsc(this._lkT('Interface A'))}</th>
				<th>${this._lkEsc(this._lkT('Equipment B'))}</th><th>${this._lkEsc(this._lkT('Interface B'))}</th>
				<th>${this._lkEsc(this._lkT('Source'))}</th><th>${this._lkEsc(this._lkT('Speed'))}</th><th>${this._lkEsc(this._lkT('Use'))}</th>
				<th>${this._lkEsc(this._lkT('Status'))}</th></tr></thead><tbody>${rows}</tbody></table></div>`);
	}

	_lkSelectedLink(panel) {
		return (this._lkTopo.links || []).find(link => String(link.id) === String(panel?.dataset.selectedLink || '')) || null;
	}

	_lkSetLinkButtons(panel) {
		const link = this._lkSelectedLink(panel);
		const enabled = this._lkCanManage && link?.source === 'manual';
		for (const action of ['link-edit', 'link-delete']) {
			const button = panel.querySelector(`[data-action="${action}"]`);
			if (button) button.disabled = !enabled;
		}
	}

	_lkOpenLinkForm(panel, link) {
		const hosts = this._lkTopo.hosts || [];
		if (hosts.length < 2) {
			window.alert(this._lkT('At least two equipment are needed to create a link.'));
			return;
		}
		const selectedA = String(link?.a || hosts[0].id);
		const selectedB = String(link?.b || hosts.find(host => String(host.id) !== selectedA)?.id || '');
		const options = selected => hosts.map(host => `<option value="${this._lkEsc(host.id)}"${String(host.id) === selected ? ' selected' : ''}>${this._lkEsc(host.name)}</option>`).join('');
		const types = {uplink: 'Uplink', trunk: 'Trunk', wan: 'WAN', ha: 'HA', fortilink: 'FortiLink', other: this._lkT('Other')};
		const box = this._lkOpenModal(`${this._lkModalHead(this._lkEsc(link ? this._lkT('Edit link') : this._lkT('Add manual link')), this._lkT('Equipment and interface at each end'))}
			<div class="laka-modal-body"><div class="laka-form-grid">
				<div class="laka-field"><label>${this._lkEsc(this._lkT('Equipment A'))}</label><select data-field="host-a">${options(selectedA)}</select></div>
				<div class="laka-field"><label>${this._lkEsc(this._lkT('Equipment B'))}</label><select data-field="host-b">${options(selectedB)}</select></div>
				<div class="laka-field"><label>${this._lkEsc(this._lkT('Interface A'))}</label><select data-field="port-a"></select></div>
				<div class="laka-field"><label>${this._lkEsc(this._lkT('Interface B'))}</label><select data-field="port-b"></select></div>
				<div class="laka-field"><label>${this._lkEsc(this._lkT('Link type'))}</label><select data-field="type">${Object.entries(types)
					.map(([value, label]) => `<option value="${value}"${(link?.type || 'uplink') === value ? ' selected' : ''}>${this._lkEsc(label)}</option>`).join('')}</select></div>
				<div class="laka-field"><label>${this._lkEsc(this._lkT('Description'))}</label><input data-field="description" maxlength="255" value="${this._lkEsc(link?.description || '')}"></div>
			</div>
			<div class="laka-form-note">${this._lkEsc(this._lkT('The link is stored as a macro on equipment A and is visible to all users.'))}</div>
			<div class="laka-modal-actions"><button type="button" class="laka-btn" data-modal="close">${this._lkEsc(this._lkT('Cancel'))}</button>
				<button type="button" class="laka-btn primary" data-modal="save-link">${this._lkEsc(this._lkT('Save link'))}</button></div></div>`, true);
		box._link = link;
		const fill = (hostSelect, portSelect, selectedIndex) => {
			const host = hosts.find(candidate => String(candidate.id) === hostSelect.value);
			portSelect.innerHTML = (host?.ports || []).map(([index, name, alias]) => `<option value="${this._lkEsc(index)}" data-name="${this._lkEsc(name)}"${String(index) === String(selectedIndex || '') ? ' selected' : ''}>${this._lkEsc(name)}${alias ? ' · ' + this._lkEsc(alias) : ''}</option>`).join('');
		};
		const field = name => box.querySelector(`[data-field="${name}"]`);
		fill(field('host-a'), field('port-a'), link?.ai);
		fill(field('host-b'), field('port-b'), link?.bi);
		field('host-a').addEventListener('change', () => fill(field('host-a'), field('port-a'), ''));
		field('host-b').addEventListener('change', () => fill(field('host-b'), field('port-b'), ''));
	}

	_lkSaveLinkForm() {
		const box = this._lkModal?.querySelector('.laka-modal-box');
		if (!box) return;
		box.querySelector('.laka-form-error')?.remove();
		const link = box._link;
		const field = name => box.querySelector(`[data-field="${name}"]`);
		const portA = field('port-a'), portB = field('port-b');
		const payload = {
			operation: 'save', macro_id: link?.macro_id || '', link_id: link?.id || '',
			hostid_a: field('host-a').value, hostid_b: field('host-b').value,
			port_index_a: portA.value, port_index_b: portB.value,
			port_name_a: portA.selectedOptions[0]?.dataset.name || '', port_name_b: portB.selectedOptions[0]?.dataset.name || '',
			link_type: field('type').value, description: field('description').value,
			_csrf_token: this._lkConsole.dataset.csrfToken || '', lang: this._lkSettings.language || 'auto'
		};
		this._lkRequest('widget.laka_network_switch.topology', payload, true).then(response => {
			const saved = response.data?.[0];
			if (!saved) return;
			const links = (this._lkTopo.links || []).filter(candidate => String(candidate.id) !== String(link?.id || saved.id));
			links.push({...saved, state: 'unknown', protocol: ''});
			this._lkUpdateLinks(links);
			this._lkCloseModal();
		}).catch(error => {
			const message = document.createElement('div');
			message.className = 'laka-form-error';
			message.textContent = error.message || this._lkT('Could not save the link.');
			box.querySelector('.laka-modal-actions').before(message);
		});
	}

	_lkConfirmDeleteLink(link) {
		if (!window.confirm(this._lkT('Delete the link %1$s ↔ %2$s?', link.an || link.ai, link.bn || link.bi))) return;
		this._lkRequest('widget.laka_network_switch.topology', {operation: 'delete', macro_id: link.macro_id || '',
			_csrf_token: this._lkConsole.dataset.csrfToken || '', lang: this._lkSettings.language || 'auto'}, true)
			.then(() => {
				this._lkCloseModal();
				this._lkUpdateLinks((this._lkTopo.links || []).filter(candidate => String(candidate.id) !== String(link.id)));
			})
			.catch(error => window.alert(error.message || this._lkT('Could not delete the link.')));
	}

	_lkUpdateLinks(links) {
		this._lkTopo.links = links;
		const count = this._lkConsole.querySelector('.laka-nav-topology .laka-count');
		if (count) count.textContent = String(links.length);
		for (const panel of this._lkConsole.querySelectorAll('.laka-topology')) {
			panel.dataset.selectedLink = '';
			this._lkRenderTopology(panel);
		}
	}
}
