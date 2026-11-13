<?php declare(strict_types = 1);

/*
 * LAKA Network – módulo de red para Zabbix 7.0 – 8.0
 * © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3
 */

use Modules\LakaNetworkSwitch\Includes\Lang;

/**
 * @var CView $this
 * @var array $data
 */

$t = static fn(string $text): string => Lang::t($text);
$json = static fn($value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	| JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
$button = static function(string $label, string $action, array $attributes = [], array $classes = []) {
	$tag = (new CTag('button', true, $label))->setAttribute('type', 'button')->setAttribute('data-action', $action);
	foreach ($attributes as $name => $value) $tag->setAttribute($name, (string) $value);
	foreach ($classes as $class) $tag->addClass($class);
	return $tag;
};
// Los datos JSON viajan en un atributo data: CTag codifica los valores del atributo y el navegador los decodifica.
$jsonScript = static fn(string $class, $value) => (new CDiv())->addClass($class)->addClass('laka-json')
	->setAttribute('data-json', $json($value))->setAttribute('hidden', 'hidden');
$percent = static fn(?float $value, int $decimals = 0): string => $value === null ? '—' : number_format($value, $decimals).'%';
$level = static fn(?float $value, int $warn, int $crit): string => $value === null ? '' : ($value >= $crit ? 'high' : ($value >= $warn ? 'warn' : 'ok'));

$settings = $data['settings'];
$devices = $data['devices'] ?? [];
$topology = $data['topology'];
$severity_names = [$t('Not classified'), $t('Information'), $t('Warning'), $t('Average'), $t('High'), $t('Disaster')];
$status_labels = ['up' => $t('Up'), 'down' => $t('Down'), 'disabled' => $t('Admin down'), 'unknown' => $t('No data')];
$health_labels = ['available' => $t('Available'), 'warning' => $t('Problems'), 'unavailable' => $t('Unavailable'),
	'maintenance' => $t('Maintenance'), 'unknown' => $t('No data')];

$console_ids = array_map(static fn(array $device): string => $device['hostid'], $devices);
$console = (new CDiv())->addClass('laka-console')
	->setAttribute('data-console-key', 'laka-'.substr(sha1(implode(',', $console_ids)), 0, 12))
	->setAttribute('data-csrf-token', CCsrfTokenHelper::get('widget'))
	->setAttribute('data-can-manage', !empty($data['can_manage_topology']) ? '1' : '0')
	->setAttribute('data-i18n', $json(Lang::js()))
	->setAttribute('data-settings', $json($settings + ['severity_names' => $severity_names, 'status_labels' => $status_labels,
		'health_labels' => $health_labels, 'regions' => $data['regions'] ?? []]));

/** Componente de topología compartido por la vista global y la pestaña de cada equipo. */
$topologyPanel = static function(string $scope) use ($t, $button, $topology, $data): CDiv {
	$is_global = $scope === 'global';
	$panel = (new CDiv())->addClass('laka-topology')->setAttribute('data-scope', $scope);
	$head = (new CDiv())->addClass('laka-topology-head');
	$heading = (new CDiv())->addClass('laka-topology-heading');
	$heading->addItem((new CDiv($is_global ? $t('Network topology') : $t('Connectivity map')))->addClass('laka-topology-title'));
	if ($is_global) $heading->addItem((new CDiv())->addClass('laka-breadcrumb'));
	$heading->addItem((new CDiv(''))->addClass('laka-topology-subtitle'));
	$head->addItem($heading);
	$tools = (new CDiv())->addClass('laka-toolbar-group');
	$tools->addItem((new CTag('input', false))->addClass('laka-input')->addClass('laka-topology-search')
		->setAttribute('type', 'search')->setAttribute('data-input', 'topology-search')
		->setAttribute('placeholder', $t('Find node…'))->setAttribute('aria-label', $t('Find node…')));
	if ($is_global) {
		$tools->addItem($button($t('Endpoints'), 'topology-endpoints', ['aria-pressed' => 'false',
			'title' => $t('Show discovered endpoints (grouped by type)')], ['laka-btn', 'laka-endpoint-toggle']));
		if (!empty($data['can_manage_topology'])) {
			$tools->addItem($button($t('Add link'), 'link-add', [], ['laka-btn', 'primary']));
			$tools->addItem($button($t('Edit'), 'link-edit', ['disabled' => 'disabled'], ['laka-btn']));
			$tools->addItem($button($t('Delete'), 'link-delete', ['disabled' => 'disabled'], ['laka-btn', 'danger']));
		}
	}
	else {
		$modes = (new CDiv())->addClass('laka-segmented');
		foreach (['both' => $t('All'), 'links' => $t('Interconnections'), 'endpoints' => $t('Endpoints')] as $mode => $label) {
			$modes->addItem($button($label, 'map-mode', ['data-value' => $mode], $mode === 'both' ? ['active'] : []));
		}
		$tools->addItem($modes);
	}
	$zoom = (new CDiv())->addClass('laka-segmented');
	foreach ([['out', '−', $t('Zoom out')], ['in', '+', $t('Zoom in')], ['fit', $t('Fit'), $t('Fit map')]] as [$value, $label, $title]) {
		$zoom->addItem($button($label, 'zoom', ['data-value' => $value, 'title' => $title, 'aria-label' => $title]));
	}
	$zoom->addItem($button('↺', 'layout-reset', ['title' => $t('Reset layout'), 'aria-label' => $t('Reset layout')]));
	$tools->addItem($zoom);
	// Vista: animación de tráfico, minimapa, exportar imagen y (global) ruta entre dos equipos.
	$view_tools = (new CDiv())->addClass('laka-segmented');
	$view_tools->addItem($button('≋', 'topology-anim', ['title' => $t('Traffic animation'), 'aria-label' => $t('Traffic animation'),
		'aria-pressed' => 'true']));
	$view_tools->addItem($button('▣', 'topology-minimap', ['title' => $t('Minimap'), 'aria-label' => $t('Minimap'),
		'aria-pressed' => 'true']));
	$view_tools->addItem($button('⤓', 'topology-export', ['title' => $t('Export image (PNG / SVG)'),
		'aria-label' => $t('Export image (PNG / SVG)')]));
	if ($is_global) {
		$view_tools->addItem($button('⇄ '.$t('Path'), 'topology-route', ['title' => $t('Path between two equipment')]));
		$view_tools->addItem($button('✓ '.$t('Audit'), 'topology-audit', ['title' => $t('CDP/LLDP links with observations')]));
	}
	$tools->addItem($view_tools);
	$head->addItem($tools);
	$panel->addItem($head);

	$panel->addItem((new CDiv())->addClass('laka-topology-stage')->setAttribute('tabindex', '0'));
	$legend = (new CDiv())->addClass('laka-topology-legend');
	foreach (['up' => $t('Up'), 'warn' => $t('High use'), 'high' => $t('Saturated'), 'down' => $t('Down'),
		'unknown' => $t('No data'), 'endpoint' => $t('Endpoint')] as $class => $label) {
		$legend->addItem((new CSpan($label))->addClass('laka-legend-line')->addClass($class));
	}
	$legend->addItem((new CSpan($t('Line width = link speed')))->addClass('laka-muted'));
	$legend->addItem((new CSpan($t('Drag nodes to arrange · wheel to zoom · drag background to pan')))
		->addClass('laka-muted')->addClass('laka-legend-hint'));
	$panel->addItem($legend);
	return $panel;
};

$kpi = static function(string $label, $value, string $sub = '', string $state = '', string $action = '', array $attributes = []) {
	$card = (new CDiv())->addClass('laka-kpi');
	if ($state !== '') $card->addClass($state);
	if ($action !== '') {
		$card = (new CTag('button', true))->addClass('laka-kpi')->setAttribute('type', 'button')
			->setAttribute('data-action', $action);
		if ($state !== '') $card->addClass($state);
		foreach ($attributes as $name => $attribute) $card->setAttribute($name, (string) $attribute);
	}
	$card->addItem((new CDiv($label))->addClass('laka-kpi-label'));
	$card->addItem((new CDiv($value))->addClass('laka-kpi-value'));
	if ($sub !== '') $card->addItem((new CDiv($sub))->addClass('laka-kpi-sub'));
	return $card;
};

$meter = static function(?float $value, string $level_class) {
	$bar = (new CSpan())->addClass('laka-meter');
	if ($level_class !== '') $bar->addClass($level_class);
	$bar->addItem((new CSpan())->setAttribute('style', '--value:'.max(0, min(100, (float) $value)).'%'));
	return $bar;
};

/**
 * HTML del widget como texto UTF-8 válido. Zabbix codifica la respuesta con JSON_THROW_ON_ERROR, así que un byte inválido en
 * un valor SNMP (sysDescr, nombre CDP...) rompería la respuesta y dejaría el dashboard cargando para siempre.
 */
$lakaUtf8 = static function($tag): string {
	$html = is_string($tag) ? $tag : $tag->toString();
	if (!mb_check_encoding($html, 'UTF-8')) $html = mb_scrub($html, 'UTF-8');
	return $html;
};

/** Panel completo de un equipo (embebido en la consola, o devuelto solo en modo detalle). */
$renderPane = static function(array $device, bool $active) use ($t, $button, $json, $jsonScript, $percent, $level, $settings,
		$severity_names, $status_labels, $health_labels, $kpi, $meter, $topologyPanel): CTag {
	$hostid = $device['hostid'];
	$summary = $device['summary'];
	$counts = $device['counts'];
	$kpis = $device['kpi'];
	$ports = $device['ports'];
	$pane = (new CTag('section', true))->addClass('laka-pane')->setAttribute('data-hostid', $hostid);
	if ($active) $pane->addClass('active');
	$pane->addItem($jsonScript('laka-device-data', [
		'hostid' => $hostid, 'name' => $device['name'], 'ports' => $ports, 'sensors' => $device['hardware_sensors'],
		'endpoints' => $device['endpoints'] ?? []
	]));
	$root = (new CDiv())->addClass('laka-sw')->setAttribute('data-hostid', $hostid)->setAttribute('data-active-tab', 'summary');

	// Encabezado.
	$head = (new CTag('header', true))->addClass('laka-head');
	$identity = (new CDiv())->addClass('laka-head-id');
	$title = (new CDiv())->addClass('laka-head-title');
	$title->addItem((new CSpan($device['name']))->addClass('laka-head-name'));
	$title->addItem((new CSpan($health_labels[$device['health']] ?? $t('No data')))->addClass('laka-pill')
		->addClass('health-'.$device['health']));
	if ($device['role'] !== '') $title->addItem((new CSpan($device['role']))->addClass('laka-pill')->addClass('neutral'));
	if (($device['region'] ?? '') !== '' && $device['region'] !== $t('Unassigned')) {
		$title->addItem($button('◎ '.$device['region'], 'open-region', ['data-region' => $device['region'],
			'title' => $t('Open region map')], ['laka-pill', 'neutral', 'laka-pill-btn']));
	}
	$identity->addItem($title);
	$meta = (new CDiv())->addClass('laka-head-meta');
	foreach ([
		[$t('IP'), $device['ip'], ''], [$t('Location'), $summary['location'], 'location'], [$t('Model'), $summary['model'], ''],
		[$t('Software'), $summary['os'] ?? '', 'os'], [$t('S/N'), $summary['serial'] ?? '', ''],
		[$t('Uptime'), $summary['uptime'], ''], [$t('Latency'), $summary['icmp_rtt'] ?? null, 'icmp']
	] as [$label, $value, $kind]) {
		if ($kind === 'icmp') {
			if (($summary['icmp_ping'] ?? null) === 0) $value = $t('No response');
			elseif ($value !== null) $value = $value.' ms'.(($summary['icmp_loss'] ?? 0) > 0
				? ' · '.$summary['icmp_loss'].'% '.$t('loss') : '');
		}
		// La ubicación siempre se muestra (con un guion si no se conoce) para que quede en la fila de la IP.
		if (($value === '' || $value === null) && $kind !== 'location') continue;
		$item = (new CSpan([(new CSpan($label))->addClass('laka-muted'), ' ',
			(new CSpan($value !== '' && $value !== null ? $value : '—'))->addClass('laka-strong')]))->addClass('laka-meta-item');
		if ($kind === 'location') {
			$item->addClass('laka-meta-location')->setAttribute('title', match ($summary['location_source'] ?? '') {
				'item' => $t('Location reported by the equipment (sysLocation)'),
				'inventory' => $t('Location from the Zabbix host inventory'),
				default => $t('No location: configure snmp-server location on the equipment or the host inventory in Zabbix')
			});
		}
		if ($kind === 'os' && ($summary['os_full'] ?? '') !== '') $item->setAttribute('title', $summary['os_full']);
		if ($kind === 'icmp' && (($summary['icmp_ping'] ?? null) === 0 || ($summary['icmp_loss'] ?? 0) >= 20)) $item->addClass('bad');
		if ($kind === '' && $label === $t('Model') && ($device['template'] ?? '') !== '') {
			$item->setAttribute('title', $t('Template').': '.implode(', ', $device['templates'] ?? [$device['template']]));
		}
		$meta->addItem($item);
	}
	$freshness = $device['freshness'];
	if ($freshness['age'] !== null) {
		$age = $freshness['age'] < 60 ? $t('just now') : sprintf($t('%1$s ago'),
			$freshness['age'] < 3600 ? intdiv($freshness['age'], 60).'m' : intdiv($freshness['age'], 3600).'h');
		$meta->addItem((new CSpan([(new CSpan($t('Data')))->addClass('laka-muted'), ' ', (new CSpan($age))->addClass('laka-strong')]))
			->addClass('laka-meta-item')->addClass($freshness['stale'] ? 'stale' : '')
			->setAttribute('title', $freshness['stale'] ? $t('Interface data is older than 15 minutes.') : ''));
	}
	$identity->addItem($meta);
	$head->addItem($identity);
	$root->addItem($head);

	// Pestañas.
	$tabs = (new CTag('nav', true))->addClass('laka-tabs')->setAttribute('role', 'tablist');
	$tab_defs = [
		['summary', $t('Summary'), null],
		['interfaces', $t('Interfaces'), $counts['total']],
		['topology', $t('Topology'), $device['neighbor_count'] ?? 0]
	];
	$tab_defs[] = ['consumption', $t('Consumption'), null];
	if ($settings['show_hardware_health']) $tab_defs[] = ['hardware', $t('Hardware'), count($device['hardware_sensors'])];
	foreach ($tab_defs as [$key, $label, $count]) {
		$tab = $button($label, 'tab', ['data-value' => $key, 'role' => 'tab',
			'aria-selected' => $key === 'summary' ? 'true' : 'false'], ['laka-tab']);
		if ($key === 'summary') $tab->addClass('active');
		if ($count !== null) $tab->addItem((new CSpan((string) $count))->addClass('laka-count'));
		$tabs->addItem($tab);
	}
	$root->addItem($tabs);

	if (!$ports) {
		$root->addItem((new CDiv($device['message']))->addClass('laka-empty'));
	}

	// ------------------------------------------------------------ pestaña resumen
	$summary_panel = (new CDiv())->addClass('laka-tab-panel')->setAttribute('data-tab', 'summary');
	if ($ports) {
		$kpi_row = (new CDiv())->addClass('laka-kpis');
		$kpi_row->addItem($kpi($t('Ports up'), [(new CSpan((string) $counts['up']))->addClass('laka-big'), ' / '.$counts['total']],
			sprintf($t('%1$s down · %2$s admin down'), $counts['down'], $counts['disabled']), '', 'filter-ports',
			['data-value' => 'up']));
		if ($kpis['uplinks_total'] > 0) {
			$all_up = $kpis['uplinks_up'] === $kpis['uplinks_total'];
			$kpi_row->addItem($kpi($t('Uplinks'), [(new CSpan((string) $kpis['uplinks_up']))->addClass('laka-big'),
				' / '.$kpis['uplinks_total']], $all_up ? $t('All operational') : $t('Uplink down'),
				$all_up ? 'ok' : 'critical', 'filter-ports', ['data-value' => 'uplink']));
		}
		$sev = $kpis['max_severity'];
		$kpi_row->addItem($kpi($t('Problems'), (new CSpan((string) $kpis['problem_count']))->addClass('laka-big'),
			$sev >= 0 ? $severity_names[$sev] : $t('No active problems'), $sev >= 0 ? 'sev-'.$sev : 'ok',
			'filter-ports', ['data-value' => 'problem']));
		$kpi_row->addItem($kpi($t('Ports with errors'), (new CSpan((string) $kpis['error_ports']))->addClass('laka-big'),
			$t('Errors or discards'), $kpis['error_ports'] > 0 ? 'warn' : 'ok', 'filter-ports', ['data-value' => 'error']));
		$top = $kpis['top_util'];
		$kpi_row->addItem($kpi($t('Peak utilization'), (new CSpan($top === null ? '—' : $percent($top['util'])))->addClass('laka-big'),
			$top === null ? $t('No speed data') : $top['name'], $top === null ? '' : ($top['level'] ?: 'ok'),
			$top === null ? '' : 'open-port', $top === null ? [] : ['data-index' => $top['index']]));
		foreach (['CPU' => $summary['cpu'], $t('Memory') => $summary['memory']] as $label => $value) {
			if ($value === null) continue;
			$state = $level($value, 70, 85);
			$kpi_row->addItem($kpi((string) $label, [(new CSpan($percent($value)))->addClass('laka-big'), $meter($value, $state)],
				'', $state === 'ok' ? '' : ($state === 'high' ? 'critical' : 'warn')));
		}
		$watts = static fn(float $value): string => rtrim(rtrim(number_format($value, 1), '0'), '.');
		if ($summary['poe_budget'] !== null) {
			// Presupuesto PoE de la plantilla LAKA Network Optics and PoE: consumo / disponible.
			$used = (float) ($summary['poe_used'] ?? $kpis['poe_power'] ?? 0);
			$poe_pct = round($used / $summary['poe_budget'] * 100, 1);
			$state = $level($poe_pct, 75, 90);
			$kpi_row->addItem($kpi($t('PoE budget'), [(new CSpan($watts($used).' W'))->addClass('laka-big'),
				(new CSpan(' / '.$watts($summary['poe_budget']).' W'))->addClass('laka-muted'), $meter($poe_pct, $state)],
				$percent($poe_pct).($kpis['poe_ports'] ? ' · '.sprintf($t('%1$s powered ports'), $kpis['poe_ports']) : ''),
				$state === 'ok' ? '' : ($state === 'high' ? 'critical' : 'warn')));
		}
		elseif ($kpis['poe_power'] !== null) {
			$kpi_row->addItem($kpi($t('PoE'), (new CSpan($watts($kpis['poe_power']).' '.$kpis['poe_units']))
				->addClass('laka-big'), sprintf($t('%1$s powered ports'), $kpis['poe_ports'])));
		}
		if ($kpis['optics'] > 0) {
			$kpi_row->addItem($kpi($t('Optics'), (new CSpan((string) $kpis['optics']))->addClass('laka-big'),
				$kpis['optics_bad'] ? sprintf($t('%1$s with low Rx power'), $kpis['optics_bad']) : $t('Rx power normal'),
				$kpis['optics_bad'] ? 'warn' : 'ok', 'filter-ports', ['data-value' => 'optic']));
		}
		$summary_panel->addItem($kpi_row);

		// Frente del equipo.
		$faceplate = (new CDiv())->addClass('laka-card')->addClass('laka-faceplate-card');
		$face_head = (new CDiv())->addClass('laka-card-head');
		$face_head->addItem((new CSpan($t('Front panel')))->addClass('laka-card-title'));
		$legend = (new CDiv())->addClass('laka-port-legend');
		foreach (['up' => $t('Up'), 'slow' => $t('< 1 Gbps'), 'down' => $t('Down'), 'disabled' => $t('Admin down'),
			'unknown' => $t('No data'), 'problem' => $t('Problem')] as $state => $label) {
			$legend->addItem((new CSpan($label))->addClass('laka-legend-port')->addClass($state));
		}
		$face_head->addItem($legend);
		$faceplate->addItem($face_head);
		$face_body = (new CDiv())->addClass('laka-faceplate')->addClass('layout-'.$device['layout']);

		$portTile = static function(array $port, string $layout) use ($settings) {
			$tile = (new CTag('button', true))->addClass('laka-port')->setAttribute('type', 'button')
				->setAttribute('data-action', 'open-port')->setAttribute('data-index', $port['index'])
				->setAttribute('aria-label', $port['name'].' '.$port['status']);
			$tile->addClass('st-'.$port['status'])->addClass('kind-'.$port['kind']);
			if ($port['type'] === 'uplink') $tile->addClass('uplink');
			if ($port['watched_uplink']) $tile->addClass('watched');
			if ($port['max_severity'] >= 0) $tile->addClass('has-problem')->addClass('sev-'.$port['max_severity']);
			if ($port['slow']) $tile->addClass('slow');
			if ($port['poe_power'] !== null && $port['poe_power'] > 0) $tile->addClass('poe');
			if ($port['optic_level'] === 'warn' || $port['optic_level'] === 'high') $tile->addClass('optic-'.$port['optic_level']);
			$tile->addItem((new CSpan())->addClass('laka-port-led'));
			$label = $layout === 'physical' && $port['number'] !== null ? (string) $port['number'] : $port['name'];
			$tile->addItem((new CSpan($label))->addClass('laka-port-num'));
			if ($layout === 'grid' && $settings['show_alias'] && $port['alias'] !== '') {
				$tile->addItem((new CSpan($port['alias']))->addClass('laka-port-alias'));
			}
			if ($settings['show_utilization'] && $port['util'] !== null && $port['util'] >= 1) {
				$bar = (new CSpan())->addClass('laka-port-util')->setAttribute('style', '--util:'.$port['util'].'%');
				if ($port['util_level'] !== '') $bar->addClass($port['util_level']);
				$tile->addItem($bar);
			}
			return $tile;
		};

		$members = [];
		foreach ($ports as $port) $members[$port['member']][] = $port;
		ksort($members);
		foreach ($members as $member => $member_ports) {
			$chassis = (new CDiv())->addClass('laka-chassis');
			if (count($members) > 1) {
				$chassis->addItem((new CDiv(sprintf($t('Member %1$s'), $member)))->addClass('laka-chassis-label'));
			}
			$body = (new CDiv())->addClass('laka-chassis-body');
			if ($device['layout'] === 'physical') {
				$chassis_name = static fn(array $port): bool => preg_match('~^[A-Za-z]+\d+/\d+/\d+$~', $port['name']) === 1;
				$access = array_values(array_filter($member_ports, static fn(array $port): bool =>
					$chassis_name($port) && $port['module'] === 0));
				$modules = array_values(array_filter($member_ports, static fn(array $port): bool =>
					$chassis_name($port) && $port['module'] !== 0));
				$others = array_values(array_filter($member_ports, static fn(array $port): bool => !$chassis_name($port)));
				usort($access, static fn(array $a, array $b): int => ($a['number'] ?? PHP_INT_MAX) <=> ($b['number'] ?? PHP_INT_MAX));
				usort($modules, static fn(array $a, array $b): int => [$a['module'], $a['number']] <=> [$b['module'], $b['number']]);
				foreach (array_chunk($access, 12) as $chunk) {
					$block = (new CDiv())->addClass('laka-port-block');
					foreach ($chunk as $port) $block->addItem($portTile($port, 'physical'));
					$body->addItem($block);
				}
				if ($modules) {
					$block = (new CDiv())->addClass('laka-port-block')->addClass('modules')
						->setAttribute('title', $t('Uplink module'));
					foreach ($modules as $port) $block->addItem($portTile($port, 'physical'));
					$body->addItem($block);
				}
				if ($others) {
					$block = (new CDiv())->addClass('laka-port-block')->addClass('modules')->addClass('others');
					foreach ($others as $port) $block->addItem($portTile($port, 'grid'));
					$body->addItem($block);
				}
			}
			else {
				$block = (new CDiv())->addClass('laka-port-grid')->setAttribute('style', '--cols:'.$settings['columns']);
				foreach ($member_ports as $port) $block->addItem($portTile($port, 'grid'));
				$body->addItem($block);
			}
			$chassis->addItem($body);
			$face_body->addItem($chassis);
		}
		$faceplate->addItem($face_body);
		$summary_panel->addItem($faceplate);

		// Lista de atención.
		$attention = (new CDiv())->addClass('laka-card');
		$attention_head = (new CDiv())->addClass('laka-card-head');
		$attention_head->addItem((new CSpan($t('Needs attention')))->addClass('laka-card-title'));
		$attention_head->addItem((new CSpan((string) count($device['attention'])))->addClass('laka-count'));
		$attention->addItem($attention_head);
		if (!$device['attention']) {
			$attention->addItem((new CDiv($t('Nothing to review: no problems, errors, saturation or degraded links.')))
				->addClass('laka-empty-inline'));
		}
		else {
			$list = (new CDiv())->addClass('laka-attention');
			foreach ($device['attention'] as $entry) {
				$row = $button('', 'open-port', ['data-index' => $entry['index']], ['laka-attention-row']);
				$row->addItem((new CSpan())->addClass('laka-dot')
					->addClass($entry['severity'] >= 0 ? 'sev-'.$entry['severity']
						: ($entry['status'] === 'up' ? 'hw-warning' : 'st-'.$entry['status'])));
				$row->addItem((new CSpan($entry['name']))->addClass('laka-attention-port'));
				$row->addItem((new CSpan($entry['alias'] ?: '—'))->addClass('laka-attention-alias'));
				$row->addItem((new CSpan($entry['reason']))->addClass('laka-attention-reason'));
				$list->addItem($row);
			}
			$attention->addItem($list);
		}
		$summary_panel->addItem($attention);
	}
	$root->addItem($summary_panel);

	// ------------------------------------------------------------ pestaña interfaces
	$interfaces = (new CDiv())->addClass('laka-tab-panel')->setAttribute('data-tab', 'interfaces');
	if ($ports) {
		$toolbar = (new CDiv())->addClass('laka-toolbar');
		$filter_group = (new CDiv())->addClass('laka-segmented')->addClass('laka-port-filters');
		$problem_ports = count(array_filter($ports, static fn(array $port): bool => $port['max_severity'] >= 0));
		foreach ([
			['all', $t('All'), $counts['total']], ['up', $t('Up'), $counts['up']], ['down', $t('Down'), $counts['down']],
			['disabled', $t('Admin down'), $counts['disabled']], ['unknown', $t('No data'), $counts['unknown']],
			['uplink', $t('Uplinks'), count(array_filter($ports, static fn(array $port): bool => $port['type'] === 'uplink'))],
			['problem', $t('Problems'), $problem_ports], ['error', $t('Errors'), $kpis['error_ports']],
			['util', $t('High use'), $kpis['high_util_ports']], ['optic', $t('Optics'), count(array_filter($ports, static fn(array $port): bool => $port['optic_rx'] !== null))]
		] as [$value, $label, $count]) {
			if ($count === 0 && !in_array($value, ['all', 'up', 'down'], true)) continue;
			$filter = $button($label, 'filter-ports', ['data-value' => $value], $value === 'all' ? ['active'] : []);
			$filter->addItem((new CSpan((string) $count))->addClass('laka-count'));
			$filter_group->addItem($filter);
		}
		$toolbar->addItem($filter_group);
		$right = (new CDiv())->addClass('laka-toolbar-group');
		$right->addItem((new CTag('input', false))->addClass('laka-input')->addClass('laka-interface-search')
			->setAttribute('type', 'search')->setAttribute('data-input', 'interface-search')
			->setAttribute('placeholder', $t('Search port, description or neighbor…'))
			->setAttribute('aria-label', $t('Search port, description or neighbor…')));
		$right->addItem((new CSpan(''))->addClass('laka-muted')->addClass('laka-interface-result'));
		$right->addItem($button($t('Unused 30 days'), 'unused-ports', ['title' => $t('Interfaces without traffic in the last 30 days (Zabbix trends)')],
			['laka-btn', 'laka-unused-btn']));
		$right->addItem($button($t('Export CSV'), 'export-csv', [], ['laka-btn']));
		$toolbar->addItem($right);
		$interfaces->addItem($toolbar);

		$has = static fn(string $field): bool => (bool) array_filter($ports,
			static fn(array $port): bool => $port[$field] !== null && $port[$field] !== '');
		$columns = [
			['name', $t('Port'), true], ['alias', $t('Description'), true], ['status', $t('Status'), true],
			['speed', $t('Speed'), true], ['duplex', $t('Duplex'), $has('duplex')], ['vlan', $t('VLAN'), $has('vlan')],
			['in', $t('In'), true], ['out', $t('Out'), true], ['util', $t('Use'), true],
			['trend', $t('Traffic 6 h'), (bool) array_filter($ports, static fn(array $port): bool =>
				ctype_digit((string) $port['in_itemid']) || ctype_digit((string) $port['out_itemid']))],
			['errors', $t('Errors'), true], ['discards', $t('Discards'), true],
			['neighbor', $t('Neighbor'), $has('neighbor')], ['lastchange', $t('Last change'), $has('lastchange')],
			['poe', $t('PoE'), $has('poe_power')], ['optic', $t('Rx / Tx dBm'), $has('optic_rx') || $has('optic_tx')]
		];
		$columns = array_values(array_filter($columns, static fn(array $column): bool => $column[2]));

		$scroll = (new CDiv())->addClass('laka-table-scroll')
			->setAttribute('style', '--rows:'.$settings['interface_list_rows']);
		$table = (new CTag('table', true))->addClass('laka-table');
		$thead = new CTag('thead', true);
		$head_row = new CTag('tr', true);
		foreach ($columns as [$key, $label]) {
			$th = (new CTag('th', true))->addClass('col-'.$key);
			if ($key === 'trend') {
				$head_row->addItem($th->addItem((new CSpan($label))->addClass('laka-th-label')));
				continue;
			}
			$sort = $button($label, 'sort', ['data-value' => $key], ['laka-sort']);
			if ($key === 'name') $sort->addClass('active')->setAttribute('data-direction', 'asc');
			$th->addItem($sort);
			$head_row->addItem($th);
		}
		$thead->addItem($head_row);
		$table->addItem($thead);
		$tbody = new CTag('tbody', true);
		$number = static fn(?float $value): string => $value === null ? '—'
			: ($value == 0 ? '0' : ($value < 10 ? rtrim(rtrim(number_format($value, 2), '0'), '.') : number_format($value, 0)));
		foreach ($ports as $port) {
			$row = (new CTag('tr', true))->addClass('laka-row')->addClass('st-'.$port['status'])
				->setAttribute('data-action', 'open-port')
				->setAttribute('data-index', $port['index'])
				->setAttribute('data-status', $port['status'])
				->setAttribute('data-type', $port['type'])
				->setAttribute('data-problem', $port['max_severity'] >= 0 ? '1' : '0')
				->setAttribute('data-error', $port['errors_total'] > 0 || $port['discards_total'] > 0 ? '1' : '0')
				->setAttribute('data-high', $port['util_level'] === 'high' ? '1' : '0')
				->setAttribute('data-search', mb_strtolower($port['name'].' '.$port['alias'].' '.($port['neighbor']['name'] ?? '')
					.' '.$port['vlan']))
				->setAttribute('data-s-name', $port['name'])
				->setAttribute('data-s-alias', mb_strtolower($port['alias']))
				->setAttribute('data-s-status', (string) array_search($port['status'], ['up', 'down', 'disabled', 'unknown'], true))
				->setAttribute('data-s-speed', (string) ($port['speed_bps'] ?? 0))
				->setAttribute('data-s-duplex', $port['duplex'])
				->setAttribute('data-s-vlan', $port['vlan'])
				->setAttribute('data-s-in', (string) ($port['in_bps'] ?? 0))
				->setAttribute('data-s-out', (string) ($port['out_bps'] ?? 0))
				->setAttribute('data-s-util', (string) ($port['util'] ?? -1))
				->setAttribute('data-s-errors', (string) $port['errors_total'])
				->setAttribute('data-s-discards', (string) $port['discards_total'])
				->setAttribute('data-s-neighbor', mb_strtolower((string) ($port['neighbor']['name'] ?? '')))
				->setAttribute('data-s-lastchange', $port['lastchange'])
				->setAttribute('data-s-poe', (string) ($port['poe_power'] ?? 0))
				->setAttribute('data-s-optic', (string) ($port['optic_rx'] ?? 99))
				->setAttribute('data-optic', $port['optic_rx'] !== null ? '1' : '0')
				->setAttribute('data-in-item', (string) $port['in_itemid'])
				->setAttribute('data-out-item', (string) $port['out_itemid']);
			foreach ($columns as [$key]) {
				$cell = (new CTag('td', true))->addClass('col-'.$key);
				switch ($key) {
					case 'trend':
						// Mini-gráfica de las últimas 6 h, la dibuja el script al abrir la pestaña.
						$cell->addItem((new CSpan())->addClass('laka-spark'));
						break;
					case 'name':
						$cell->addItem($port['name']);
						if ($port['type'] === 'uplink') $cell->addItem((new CSpan('UL'))->addClass('laka-tag')->setAttribute('title', $t('Uplink')));
						break;
					case 'alias':
						$cell->addItem($port['alias'] !== '' ? $port['alias'] : '—');
						$cell->setAttribute('title', $port['alias']);
						break;
					case 'status':
						$cell->addItem((new CSpan())->addClass('laka-dot')->addClass('st-'.$port['status']));
						$cell->addItem($status_labels[$port['status']]);
						if ($port['max_severity'] >= 0) {
							$cell->addItem((new CSpan('!'))->addClass('laka-badge')->addClass('sev-'.$port['max_severity'])
								->setAttribute('title', implode(' | ', array_column($port['problems'], 'name'))));
						}
						break;
					case 'speed':
						$cell->addItem($port['speed_text']);
						if ($port['slow']) $cell->addClass('warn');
						break;
					case 'duplex':
						$cell->addItem($port['duplex'] !== '' ? preg_replace('/\s*\(\d+\)$/', '', $port['duplex']) : '—');
						if ($port['half_duplex']) $cell->addClass('warn');
						break;
					case 'vlan':
						$cell->addItem($port['vlan'] !== '' ? $port['vlan'] : ($port['trunk'] !== '' ? 'trunk' : '—'));
						break;
					case 'in':
						$cell->addItem($port['in_text']);
						break;
					case 'out':
						$cell->addItem($port['out_text']);
						break;
					case 'util':
						$cell->addItem($meter($port['util'], $port['util_level']));
						$cell->addItem((new CSpan($percent($port['util'])))->addClass('laka-num'));
						break;
					case 'errors':
						$cell->addItem($number($port['errors_total'] > 0 ? $port['errors_total'] : ($port['errors_in'] === null ? null : 0.0)));
						if ($port['errors_total'] > 0) $cell->addClass('bad');
						break;
					case 'discards':
						$cell->addItem($number($port['discards_total'] > 0 ? $port['discards_total'] : ($port['discards_in'] === null ? null : 0.0)));
						if ($port['discards_total'] > 0) $cell->addClass('warn');
						break;
					case 'neighbor':
						$neighbor = $port['neighbor'];
						if ($neighbor === null) {
							$cell->addItem('—');
						}
						else {
							$cell->addItem((new CSpan($neighbor['protocol']))->addClass('laka-tag'));
							$cell->addItem(' '.$neighbor['name'].($neighbor['port'] !== '' ? ' · '.$neighbor['port'] : ''));
							$cell->setAttribute('title', trim($neighbor['name'].' '.$neighbor['ip'].' '.$neighbor['port']));
						}
						break;
					case 'lastchange':
						$cell->addItem($port['lastchange'] !== '' ? $port['lastchange'] : '—');
						break;
					case 'poe':
						$cell->addItem($port['poe_power'] !== null && $port['poe_power'] > 0
							? rtrim(rtrim(number_format($port['poe_power'], 1), '0'), '.').' '.$port['poe_units'] : '—');
						break;
					case 'optic':
						$dbm = static fn(?float $value): string => $value === null ? '—' : number_format($value, 1);
						$cell->addItem($port['optic_rx'] === null && $port['optic_tx'] === null ? '—'
							: $dbm($port['optic_rx']).' / '.$dbm($port['optic_tx']));
						if ($port['optic_level'] === 'warn') $cell->addClass('warn');
						if ($port['optic_level'] === 'high') $cell->addClass('bad');
						if ($port['optic_level'] === 'dark') $cell->setAttribute('title', $t('No light'));
						break;
				}
				$row->addItem($cell);
			}
			$tbody->addItem($row);
		}
		$table->addItem($tbody);
		$scroll->addItem($table);
		$interfaces->addItem($scroll);
		$interfaces->addItem((new CDiv($t('No interfaces match this filter.')))->addClass('laka-empty-inline')->addClass('laka-no-match'));
	}
	$root->addItem($interfaces);

	// ------------------------------------------------------------ pestaña topología
	$topology_tab = (new CDiv())->addClass('laka-tab-panel')->setAttribute('data-tab', 'topology');
	$topology_tab->addItem($topologyPanel($hostid));
	$root->addItem($topology_tab);

	// ------------------------------------------------------------ pestaña consumo
	// Mapa de calor (siempre) y gráficas destacadas (si están habilitadas en la configuración).
	$consumption = (new CDiv())->addClass('laka-tab-panel')->setAttribute('data-tab', 'consumption');
	$heat = (new CDiv())->addClass('laka-card')->addClass('laka-heatmap-card');
	$heat_head = (new CDiv())->addClass('laka-toolbar');
	$heat_title = (new CDiv())->addClass('laka-toolbar-title');
	$heat_title->addItem((new CDiv($t('Use per interface, last 24 hours')))->addClass('laka-card-title'));
	$heat_title->addItem((new CDiv($t('Hourly peak of the highest direction (in or out) against the interface speed. Click a row to open the interface.')))
		->addClass('laka-muted'));
	$heat_head->addItem($heat_title);
	$heat_legend = (new CDiv())->addClass('laka-heat-legend');
	foreach (['0' => '0–10 %', '1' => '10–30 %', '2' => '30 % – '.$settings['util_warning'].' %',
			'3' => $settings['util_warning'].' – '.$settings['util_critical'].' %', '4' => '≥ '.$settings['util_critical'].' %'] as $level => $label) {
		$heat_legend->addItem((new CSpan($label))->addClass('laka-heat-key')->addClass('lv-'.$level));
	}
	$heat_head->addItem($heat_legend);
	$heat->addItem($heat_head);
	$heat->addItem((new CDiv())->addClass('laka-heatmap'));
	$consumption->addItem($heat);
	if ($settings['show_featured_charts']) {
		$featured = (new CDiv())->addClass('laka-featured')
			->setAttribute('data-period', $settings['featured_chart_period'])
			->setAttribute('data-max-charts', (string) $settings['featured_chart_limit']);
		$candidates = array_values(array_filter($ports, static fn(array $port): bool =>
			ctype_digit($port['in_itemid']) || ctype_digit($port['out_itemid'])));
		$defaults = array_values(array_filter($candidates, static fn(array $port): bool => $port['type'] === 'uplink'
			&& $port['status'] === 'up')) ?: array_values(array_filter($candidates,
			static fn(array $port): bool => $port['status'] === 'up')) ?: $candidates;
		usort($defaults, static fn(array $a, array $b): int => ($b['util'] ?? 0) <=> ($a['util'] ?? 0));
		$featured->setAttribute('data-default-selection', $json(array_map(static fn(array $port): string => $port['index'],
			array_slice($defaults, 0, min(2, $settings['featured_chart_limit'])))));
		$featured_head = (new CDiv())->addClass('laka-toolbar');
		$featured_title = (new CDiv())->addClass('laka-toolbar-title');
		$featured_title->addItem((new CDiv($t('Featured interface consumption')))->addClass('laka-card-title'));
		$featured_title->addItem((new CDiv(sprintf($t('Average line, peak band, 95th percentile · up to %1$s interfaces'),
			$settings['featured_chart_limit'])))->addClass('laka-muted'));
		$featured_head->addItem($featured_title);
		$featured_tools = (new CDiv())->addClass('laka-toolbar-group');
		$periods = (new CDiv())->addClass('laka-segmented');
		foreach (['1h', '6h', '12h', '24h', '7d'] as $period) {
			$periods->addItem($button($period, 'featured-period', ['data-value' => $period],
				$period === $settings['featured_chart_period'] ? ['active'] : []));
		}
		$featured_tools->addItem($periods);
		$featured_tools->addItem($button('⇆ '.$t('Previous week'), 'featured-compare', ['aria-pressed' => 'false',
			'title' => $t('Compare with the same period of the previous week')], ['laka-btn']));
		$featured_tools->addItem($button($t('Choose interfaces'), 'featured-config', [], ['laka-btn']));
		$featured_head->addItem($featured_tools);
		$featured->addItem($featured_head);
		$picker = (new CDiv())->addClass('laka-featured-picker');
		$picker_head = (new CDiv())->addClass('laka-featured-picker-head');
		$picker_head->addItem((new CSpan($t('Select the interfaces to graph for this equipment.')))->addClass('laka-muted'));
		$picker_head->addItem($button($t('Clear selection'), 'featured-clear', [], ['laka-btn']));
		$picker->addItem($picker_head);
		$options = (new CDiv())->addClass('laka-chips');
		foreach ($candidates as $port) {
			$options->addItem($button($port['name'].($port['alias'] !== '' ? ' · '.$port['alias'] : ''), 'featured-toggle',
				['data-index' => $port['index']], ['laka-chip']));
		}
		$picker->addItem($options);
		$featured->addItem($picker);
		$list = (new CDiv())->addClass('laka-featured-list')
			->setAttribute('style', '--chart-height:'.$settings['featured_chart_height'].'px');
		$featured->addItem($list);
		$featured->addItem((new CDiv($candidates ? $t('No interfaces selected. Use “Choose interfaces”.')
			: $t('No interfaces with traffic items are available.')))->addClass('laka-empty-inline')->addClass('laka-featured-empty'));
		$consumption->addItem($featured);
	}
	$root->addItem($consumption);

	// ------------------------------------------------------------ pestaña hardware
	if ($settings['show_hardware_health']) {
		$hardware = (new CDiv())->addClass('laka-tab-panel')->setAttribute('data-tab', 'hardware');
		$sensors = $device['hardware_sensors'];
		$health_counts = $kpis['hardware'];
		$hardware_head = (new CDiv())->addClass('laka-toolbar');
		$hardware_head->addItem((new CDiv($t('Hardware health')))->addClass('laka-card-title'));
		$chips = (new CDiv())->addClass('laka-toolbar-group');
		foreach (['ok' => $t('OK'), 'warning' => $t('Warning'), 'critical' => $t('Critical'), 'unknown' => $t('No data')] as $state => $label) {
			if ($health_counts[$state] === 0 && $state !== 'ok') continue;
			$chips->addItem((new CSpan([(new CSpan())->addClass('laka-dot')->addClass('hw-'.$state), ' '.$label.' ',
				(new CTag('b', true, (string) $health_counts[$state]))]))->addClass('laka-stat'));
		}
		$hardware_head->addItem($chips);
		$hardware->addItem($hardware_head);
		if (!$sensors) {
			$hardware->addItem((new CDiv($t('No temperature, fan, PSU or additional sensors matched the configured expressions.')))
				->addClass('laka-empty-inline'));
		}
		else {
			$labels = ['temperature' => $t('Temperature'), 'fan' => $t('Fans'), 'psu' => $t('Power supplies'), 'other' => $t('Other sensors')];
			$grouped = ['temperature' => [], 'fan' => [], 'psu' => [], 'other' => []];
			foreach ($sensors as $sensor) $grouped[$sensor['type']][] = $sensor;
			foreach ($grouped as $type => $list) {
				if (!$list) continue;
				usort($list, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
				$group = (new CDiv())->addClass('laka-card');
				$group_head = (new CDiv())->addClass('laka-card-head');
				$group_head->addItem((new CSpan($labels[$type]))->addClass('laka-card-title'));
				$group_head->addItem((new CSpan((string) count($list)))->addClass('laka-count'));
				$group->addItem($group_head);
				$grid = (new CDiv())->addClass('laka-sensor-grid');
				foreach ($list as $sensor) {
					$card = $button('', 'open-sensor', ['data-itemid' => $sensor['itemid'], 'title' => $sensor['name']],
						['laka-sensor', 'hw-'.$sensor['health']]);
					$card->addItem((new CSpan($sensor['name']))->addClass('laka-sensor-name'));
					$card->addItem((new CSpan($sensor['value'] !== '' ? $sensor['value'] : '—'))->addClass('laka-sensor-value'));
					if ($sensor['percent'] !== null) {
						$card->addItem($meter($sensor['percent'], $sensor['health'] === 'critical' ? 'high'
							: ($sensor['health'] === 'warning' ? 'warn' : 'ok')));
					}
					$grid->addItem($card);
				}
				$group->addItem($grid);
				$hardware->addItem($group);
			}
		}
		$root->addItem($hardware);
	}

	$pane->addItem($root);
	return $pane;
};

if (($data['mode'] ?? '') === 'mac') {
	// Respuesta del localizador MAC / IP: datos JSON que el script del widget muestra en su ventana de búsqueda.
	$output = (new CDiv())->addClass('laka-mac-response')->addItem($jsonScript('laka-mac-data', $data['locate'] ?? []));
	(new CWidgetView($data))->addItem($lakaUtf8($output))->show();
	return;
}

if (($data['mode'] ?? '') === 'detail') {
	// Petición de detalle: solo el panel del equipo pedido.
	$output = (new CDiv())->addClass('laka-detail-response');
	$output->addItem($data['detail'] !== null ? $renderPane($data['detail'], true) : (new CDiv($data['message']))->addClass('laka-empty'));
	(new CWidgetView($data))->addItem($lakaUtf8($output))->show();
	return;
}

if (!$devices) {
	$console->addClass('empty');
	$console->addItem((new CDiv($data['message']))->addClass('laka-empty'));
}
else {
	$console->addItem($jsonScript('laka-topology-data', $topology));

	// ---------------------------------------------------------------- navegación
	$nav = (new CTag('aside', true))->addClass('laka-nav');
	$nav_head = (new CDiv())->addClass('laka-nav-head');
	$title_row = (new CDiv())->addClass('laka-nav-title-row');
	$title_row->addItem((new CSpan([$t('Network equipment').' ', (new CTag('b', true, (string) count($devices)))]))
		->addClass('laka-nav-title'));
	$title_row->addItem($button('«', 'nav-toggle', ['title' => $t('Collapse equipment menu'),
		'aria-label' => $t('Collapse equipment menu')], ['laka-icon-btn', 'laka-nav-toggle']));
	$nav_head->addItem($title_row);
	$fleet_button = $button('', 'show-fleet', ['title' => $t('Network equipment list')], ['laka-nav-topology', 'laka-nav-fleet']);
	$fleet_button->addItem((new CSpan('☰'))->addClass('laka-nav-topology-icon'));
	$fleet_button->addItem((new CSpan($t('Equipment list')))->addClass('laka-nav-topology-label'));
	$fleet_button->addItem((new CSpan((string) count($devices)))->addClass('laka-count'));
	$nav_head->addItem($fleet_button);
	$topology_button = $button('', 'show-topology', ['title' => $t('Network topology')], ['laka-nav-topology']);
	$topology_button->addItem((new CSpan('⬡'))->addClass('laka-nav-topology-icon'));
	$topology_button->addItem((new CSpan($t('Topology')))->addClass('laka-nav-topology-label'));
	$topology_button->addItem((new CSpan((string) count($topology['links'])))->addClass('laka-count')
		->setAttribute('title', $t('Interconnections')));
	$nav_head->addItem($topology_button);
	$locate_button = $button('', 'locate-open', ['title' => $t('Locate equipment by MAC or IP')], ['laka-nav-topology', 'laka-nav-locate']);
	$locate_button->addItem((new CSpan('⌖'))->addClass('laka-nav-topology-icon'));
	$locate_button->addItem((new CSpan($t('Locate MAC / IP')))->addClass('laka-nav-topology-label'));
	$nav_head->addItem($locate_button);
	$noc_button = $button('', 'noc-start', ['title' => $t('NOC mode: full screen with automatic rotation')], ['laka-nav-topology', 'laka-nav-noc']);
	$noc_button->addItem((new CSpan('⛶'))->addClass('laka-nav-topology-icon'));
	$noc_button->addItem((new CSpan($t('NOC mode')))->addClass('laka-nav-topology-label'));
	$nav_head->addItem($noc_button);
	$nav_head->addItem((new CTag('input', false))->addClass('laka-input')->addClass('laka-nav-search')
		->setAttribute('type', 'search')->setAttribute('data-input', 'device-search')
		->setAttribute('placeholder', $t('Search equipment…'))->setAttribute('aria-label', $t('Search equipment…')));
	$filters = (new CDiv())->addClass('laka-segmented')->addClass('laka-nav-filters');
	foreach (['all' => $t('All'), 'issues' => $t('Issues'), 'unknown' => $t('No data'), 'maintenance' => $t('Maint.')] as $value => $label) {
		$filters->addItem($button($label, 'device-filter', ['data-value' => $value], $value === 'all' ? ['active'] : []));
	}
	$nav_head->addItem($filters);
	$nav->addItem($nav_head);

	$navItem = static function(array $device, bool $active) use ($t, $button, $percent, $level, $health_labels) {
		$summary = $device['summary'];
		$severity = (int) $device['health_severity'];
		$item = $button('', 'select-device', [
			'data-hostid' => $device['hostid'],
			'data-health' => $device['health'],
			'data-search' => mb_strtolower($device['name'].' '.$summary['model'].' '.$device['ip'].' '.$summary['location'].' '.$device['region']),
			'data-region' => $device['region'],
			'title' => $device['name'].' · '.($health_labels[$device['health']] ?? '')
		], ['laka-device']);
		if ($active) $item->addClass('active');
		$item->addItem((new CSpan())->addClass('laka-device-dot')->addClass('health-'.$device['health'])
			->addClass($severity >= 0 ? 'sev-'.$severity : 'sev-none'));
		$text = (new CSpan())->addClass('laka-device-main');
		$text->addItem((new CSpan($device['name']))->addClass('laka-device-name'));
		$text->addItem((new CSpan(implode(' · ', array_filter([$summary['model'], $device['ip']])) ?: '—'))
			->addClass('laka-device-sub'));
		$stats = (new CSpan())->addClass('laka-device-stats');
		$stats->addItem((new CSpan($device['counts']['up'].'/'.$device['counts']['total'].' '.$t('up')))->addClass('laka-device-ports'));
		if ($summary['cpu'] !== null) {
			$stats->addItem((new CSpan('CPU '.$percent($summary['cpu'])))
				->addClass($level($summary['cpu'], 70, 85)));
		}
		if ($summary['memory'] !== null) {
			$stats->addItem((new CSpan('RAM '.$percent($summary['memory'])))
				->addClass($level($summary['memory'], 70, 85)));
		}
		$text->addItem($stats);
		$item->addItem($text);
		if ($device['kpi']['problem_count'] > 0) {
			$item->addItem((new CSpan((string) $device['kpi']['problem_count']))->addClass('laka-badge')->addClass('sev-'.$severity)
				->setAttribute('title', $t('Active problems')));
		}
		return $item;
	};
	$nav_list = (new CDiv())->addClass('laka-nav-list');
	$regions = $data['regions'] ?? [];
	$first = $devices[0]['hostid'];
	if (count($regions) > 1) {
		foreach ($devices as $device) {
			if ($device['region'] === $regions[0]['name']) {
				$first = $device['hostid'];
				break;
			}
		}
	}
	if (count($regions) > 1) {
		$by_region = [];
		foreach ($devices as $device) $by_region[$device['region']][] = $device;
		foreach ($regions as $region) {
			$group = (new CDiv())->addClass('laka-region-group')->setAttribute('data-region', $region['name']);
			$head = $button('', 'region-toggle', ['data-region' => $region['name'], 'aria-expanded' => 'true'], ['laka-region-head']);
			$head->addItem((new CSpan('▾'))->addClass('laka-region-chevron'));
			$head->addItem((new CSpan($region['name']))->addClass('laka-region-name'));
			$head->addItem((new CSpan((string) $region['hosts']))->addClass('laka-count'));
			if ($region['unavailable'] > 0) {
				$head->addItem((new CSpan((string) $region['unavailable']))->addClass('laka-badge')->addClass('sev-5')
					->setAttribute('title', $t('Unavailable')));
			}
			elseif ($region['problems'] > 0) {
				$head->addItem((new CSpan((string) $region['problems']))->addClass('laka-badge')
					->addClass('sev-'.max(0, $region['max_severity']))->setAttribute('title', $t('Active problems')));
			}
			$group->addItem($head);
			$body = (new CDiv())->addClass('laka-region-body');
			foreach ($by_region[$region['name']] ?? [] as $device) $body->addItem($navItem($device, $device['hostid'] === $first));
			$group->addItem($body);
			$nav_list->addItem($group);
		}
	}
	else {
		foreach ($devices as $device) $nav_list->addItem($navItem($device, $device['hostid'] === $first));
	}
	$nav->addItem($nav_list);
	$console->addItem($nav);

	// ---------------------------------------------------------------- área principal
	$main = (new CTag('main', true))->addClass('laka-main');
	// Visible solo hasta que se inicia el script del widget; queda visible si el navegador usa un script viejo en caché.
	$main->addItem((new CDiv($t('The widget script did not start. Reload the page with Ctrl+F5 to load the current module version.')))
		->addClass('laka-banner')->addClass('warn')->addClass('laka-js-warning'));
	if (!empty($data['truncated'])) {
		$main->addItem((new CDiv(sprintf($t('Showing the first %1$s of %2$s equipment. Narrow the host groups to see the rest.'),
			count($devices), $data['total_hosts'])))->addClass('laka-banner')->addClass('warn'));
	}
	// ---------------------------------------------------------------- lista de equipos (vista de flota)
	$fleet_rows = [];
	$fleet_totals = ['hosts' => count($devices), 'available' => 0, 'unavailable' => 0, 'warning' => 0, 'maintenance' => 0,
		'unknown' => 0, 'problems' => 0, 'unacked' => 0, 'uplinks_down' => 0, 'cpu_high' => 0, 'mem_high' => 0, 'stale' => 0,
		'ports_up' => 0, 'ports_total' => 0];
	foreach ($devices as $device) {
		$kpis = $device['kpi'];
		$summary = $device['summary'];
		$uplinks_down = max(0, $kpis['uplinks_total'] - $kpis['uplinks_up']);
		$fleet_totals[$device['health']] = ($fleet_totals[$device['health']] ?? 0) + 1;
		$fleet_totals['problems'] += $kpis['problem_count'];
		$fleet_totals['unacked'] += $kpis['unacked'] ?? 0;
		$fleet_totals['uplinks_down'] += $uplinks_down > 0 ? 1 : 0;
		$fleet_totals['cpu_high'] += $summary['cpu'] !== null && $summary['cpu'] >= 85 ? 1 : 0;
		$fleet_totals['mem_high'] += $summary['memory'] !== null && $summary['memory'] >= 85 ? 1 : 0;
		$fleet_totals['stale'] += !empty($device['freshness']['stale']) || $device['freshness']['age'] === null ? 1 : 0;
		$fleet_totals['ports_up'] += $device['counts']['up'];
		$fleet_totals['ports_total'] += $device['counts']['total'];
		$fleet_rows[] = [
			'id' => $device['hostid'], 'name' => $device['name'], 'ip' => $device['ip'], 'region' => $device['region'],
			'role' => $device['role'], 'model' => $summary['model'], 'location' => $summary['location'],
			'os' => $summary['os'] ?? '', 'os_full' => $summary['os_full'] ?? '', 'serial' => $summary['serial'] ?? '',
			'ping' => $summary['icmp_ping'] ?? null, 'rtt' => $summary['icmp_rtt'] ?? null, 'loss' => $summary['icmp_loss'] ?? null,
			'template' => $device['template'] ?? '',
			'health' => $device['health'], 'severity' => $device['health_severity'],
			'uptime' => $summary['uptime'], 'uptime_s' => $summary['uptime_seconds'],
			'cpu' => $summary['cpu'] === null ? null : round($summary['cpu'], 1),
			'mem' => $summary['memory'] === null ? null : round($summary['memory'], 1),
			'up' => $device['counts']['up'], 'total' => $device['counts']['total'], 'down' => $device['counts']['down'],
			'ul_up' => $kpis['uplinks_up'], 'ul_total' => $kpis['uplinks_total'],
			'problems' => $kpis['problem_count'], 'unacked' => $kpis['unacked'] ?? 0,
			'age' => $device['freshness']['age'], 'stale' => !empty($device['freshness']['stale'])
		];
	}
	$fleet = (new CTag('section', true))->addClass('laka-fleet');
	$fleet->addItem($jsonScript('laka-fleet-data', $fleet_rows));
	$fleet_head = (new CDiv())->addClass('laka-fleet-head');
	$fleet_title = (new CDiv())->addClass('laka-toolbar-title');
	$fleet_title->addItem((new CDiv($t('Network equipment list')))->addClass('laka-fleet-title'));
	$fleet_title->addItem((new CDiv(sprintf($t('%1$s equipment · %2$s available · %3$s with problems · %4$s unavailable'),
		$fleet_totals['hosts'], $fleet_totals['available'], $fleet_totals['warning'], $fleet_totals['unavailable'])))
		->addClass('laka-muted'));
	$fleet_head->addItem($fleet_title);
	$fleet->addItem($fleet_head);
	$fleet_kpis = (new CDiv())->addClass('laka-kpis')->addClass('laka-fleet-kpis');
	$ports_pct = $fleet_totals['ports_total'] > 0 ? round($fleet_totals['ports_up'] / $fleet_totals['ports_total'] * 100) : null;
	foreach ([
		[$t('All equipment'), $fleet_totals['hosts'], sprintf($t('%1$s available'), $fleet_totals['available']), 'ok', 'all'],
		[$t('Unavailable'), $fleet_totals['unavailable'], $t('Agent or SNMP not responding'),
			$fleet_totals['unavailable'] > 0 ? 'critical' : 'ok', 'unavailable'],
		[$t('With problems'), $fleet_totals['warning'], $t('Equipment with active problems'),
			$fleet_totals['warning'] > 0 ? 'warn' : 'ok', 'problems'],
		[$t('Active problems'), $fleet_totals['problems'], sprintf($t('%1$s unacknowledged'), $fleet_totals['unacked']),
			$fleet_totals['problems'] > 0 ? 'warn' : 'ok', 'problems'],
		[$t('Uplink down'), $fleet_totals['uplinks_down'], $t('Equipment with an uplink down'),
			$fleet_totals['uplinks_down'] > 0 ? 'critical' : 'ok', 'uplink'],
		[$t('High CPU'), $fleet_totals['cpu_high'], '≥ 85%', $fleet_totals['cpu_high'] > 0 ? 'warn' : 'ok', 'cpu'],
		[$t('High memory'), $fleet_totals['mem_high'], '≥ 85%', $fleet_totals['mem_high'] > 0 ? 'warn' : 'ok', 'mem'],
		[$t('Outdated data'), $fleet_totals['stale'], $t('No data in the last 15 minutes'),
			$fleet_totals['stale'] > 0 ? 'warn' : 'ok', 'stale'],
		[$t('Ports up'), $ports_pct === null ? '—' : $ports_pct.'%', sprintf('%s / %s', $fleet_totals['ports_up'],
			$fleet_totals['ports_total']), '', 'all']
	] as [$label, $value, $sub, $state, $filter]) {
		$fleet_kpis->addItem($kpi($label, (new CSpan((string) $value))->addClass('laka-big'), $sub, $state, 'fleet-filter',
			['data-value' => $filter]));
	}
	// Auditoría CDP/LLDP: enlaces con diferencias entre extremos, un extremo caído, errores o vistos de un solo lado.
	$audited = count(array_filter($data['topology']['links'] ?? [], static fn(array $link): bool => !empty($link['audit'])));
	$fleet_kpis->addItem($kpi($t('Link audit'), (new CSpan((string) $audited))->addClass('laka-big'),
		$t('CDP/LLDP links with observations'), $audited > 0 ? 'warn' : 'ok', 'topology-audit'));
	$fleet->addItem($fleet_kpis);
	$fleet_card = (new CDiv())->addClass('laka-card')->addClass('laka-fleet-card');
	$fleet_card_head = (new CDiv())->addClass('laka-card-head');
	$fleet_card_title = new CDiv();
	$fleet_card_title->addItem((new CDiv($t('Equipment status')))->addClass('laka-card-title'));
	$fleet_card_title->addItem((new CDiv($t('Click a row to open the equipment · click a header to sort')))->addClass('laka-muted'));
	$fleet_card_head->addItem($fleet_card_title);
	$fleet_tools = (new CDiv())->addClass('laka-toolbar-group');
	if (count($data['regions'] ?? []) > 1) {
		$fleet_tools->addItem($button($t('Group by region'), 'fleet-group', ['aria-pressed' => 'true'], ['laka-btn', 'laka-fleet-group-toggle']));
	}
	$fleet_tools->addItem((new CTag('input', false))->addClass('laka-input')->addClass('laka-fleet-search')
		->setAttribute('type', 'search')->setAttribute('data-input', 'fleet-search')
		->setAttribute('placeholder', $t('Filter…'))->setAttribute('aria-label', $t('Filter…')));
	$fleet_tools->addItem($button('⌖ '.$t('Locate MAC / IP'), 'locate-open', [], ['laka-btn']));
	$fleet_tools->addItem($button('▤ '.$t('Report'), 'fleet-report', ['title' => $t('Printable report by region (PDF)')], ['laka-btn']));
	$fleet_tools->addItem($button('⛶ '.$t('NOC mode'), 'noc-start', ['title' => $t('Full screen with automatic rotation')], ['laka-btn']));
	$fleet_tools->addItem($button($t('Export CSV'), 'fleet-csv', [], ['laka-btn']));
	$fleet_card_head->addItem($fleet_tools);
	$fleet_card->addItem($fleet_card_head);
	$fleet_card->addItem((new CDiv())->addClass('laka-fleet-filters'));
	$fleet_card->addItem((new CDiv())->addClass('laka-fleet-table'));
	$fleet->addItem($fleet_card);
	$main->addItem($fleet);

	$global = (new CTag('section', true, $topologyPanel('global')))->addClass('laka-global-topology');
	if (count($data['regions'] ?? []) > 1) {
		$cards = (new CDiv())->addClass('laka-region-cards');
		foreach ($data['regions'] as $region) {
			$severity = $region['unavailable'] > 0 ? 5 : $region['max_severity'];
			$card = $button('', 'open-region', ['data-region' => $region['name']], ['laka-region-card']);
			if ($severity >= 0) $card->addClass('sev-'.$severity)->addClass('has-issue');
			$card->addItem((new CDiv($region['name']))->addClass('laka-region-card-title'));
			$card->addItem((new CDiv(sprintf($t('%1$s equipment'), $region['hosts'])))->addClass('laka-muted'));
			$stats = (new CDiv())->addClass('laka-region-card-stats');
			$up = $region['ports_total'] > 0 ? round($region['ports_up'] / $region['ports_total'] * 100) : null;
			foreach ([
				[$t('Unavailable'), $region['unavailable'], $region['unavailable'] > 0 ? 'bad' : ''],
				[$t('Problems'), $region['problems'], $region['problems'] > 0 ? 'warn' : ''],
				[$t('WAN links'), $region['links_external'].($region['links_down'] > 0 ? ' · '.$region['links_down'].' ↓' : ''),
					$region['links_down'] > 0 ? 'bad' : ''],
				[$t('Ports up'), $up === null ? '—' : $up.'%', '']
			] as [$label, $value, $class]) {
				$stats->addItem((new CDiv([(new CSpan($label))->addClass('laka-muted'), (new CTag('b', true, (string) $value))->addClass($class)])));
			}
			$card->addItem($stats);
			$cards->addItem($card);
		}
		$global->addItem($cards);
	}
	$main->addItem($global);

	foreach ($devices as $index => $device) {
		if (!empty($data['lazy'])) {
			// Modo resumen: el panel se pide bajo demanda cuando se abre el equipo.
			$pane = (new CTag('section', true))->addClass('laka-pane')->addClass('laka-pane-lazy')
				->setAttribute('data-hostid', $device['hostid']);
			if ($device['hostid'] === $first) $pane->addClass('active');
			$pane->addItem((new CDiv([(new CSpan())->addClass('laka-spinner'), ' '.$t('Loading equipment…')]))
				->addClass('laka-empty'));
			$main->addItem($pane);
		}
		else {
			$main->addItem($renderPane($device, $device['hostid'] === $first));
		}
	}
	$console->addItem($main);
}
$console->addItem((new CTag('footer', true, '© LAKA Soluciones Tecnologicas'))->addClass('laka-footer'));

(new CWidgetView($data))
	->addItem($lakaUtf8($console))
	->show();
