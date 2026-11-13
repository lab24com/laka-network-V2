<?php declare(strict_types = 1);

/*
 * LAKA Network – módulo de red para Zabbix 7.0 – 8.0
 * © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3
 */

namespace Modules\LakaNetworkSwitch\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;
use Modules\LakaNetworkSwitch\Includes\Lang;
use Modules\LakaNetworkSwitch\Includes\MacTable;
use Modules\LakaNetworkSwitch\Includes\Profiles;

class WidgetView extends CControllerDashboardWidgetView {
	private const STALE_SECONDS = 900;
	/** Desde esta cantidad de equipos la consola pasa a modo resumen + detalle bajo demanda (en Automático). */
	private const EMBED_THRESHOLD = 30;
	/** Límite fijo de "Siempre embebido": por encima, la consola siempre carga el detalle bajo demanda. */
	private const EMBED_MAX = 60;
	/** Métricas que se cargan de cada equipo en modo resumen (suficientes para estado, velocidad, uso y topología). */
	/** Dúplex incluido para la auditoría de enlaces (desajuste entre extremos) también en modo resumen. */
	private const OVERVIEW_METRICS = ['oper', 'admin', 'name', 'alias', 'speed', 'in', 'out', 'duplex'];

	/** Métrica de puerto => campo del widget con su patrón de clave. */
	private const PORT_METRICS = [
		'oper' => 'oper_pattern', 'admin' => 'admin_pattern', 'name' => 'name_pattern', 'alias' => 'alias_pattern',
		'speed' => 'speed_pattern', 'in' => 'traffic_in_pattern', 'out' => 'traffic_out_pattern',
		'errors_in' => 'errors_in_pattern', 'errors_out' => 'errors_out_pattern',
		'discards_in' => 'discards_in_pattern', 'discards_out' => 'discards_out_pattern',
		'duplex' => 'duplex_pattern', 'iftype' => 'type_pattern', 'trunk' => 'trunk_pattern',
		'vlan' => 'vlan_pattern', 'poe_status' => 'poe_status_pattern', 'poe_power' => 'poe_power_pattern',
		'lastchange' => 'lastchange_pattern', 'optic_rx' => 'optic_rx_pattern', 'optic_tx' => 'optic_tx_pattern'
	];

	/** Nombres largos de interfaz que informa CDP/LLDP => prefijo corto de ifName. Los más largos primero. */
	private const IFNAME_PREFIXES = [
		'hundredgigabitethernet' => 'hu', 'hundredgige' => 'hu', 'fortygigabitethernet' => 'fo', 'fortygige' => 'fo',
		'twentyfivegigabitethernet' => 'twe', 'twentyfivegige' => 'twe', 'tengigabitethernet' => 'te', 'tengige' => 'te',
		'fivegigabitethernet' => 'fi', 'twogigabitethernet' => 'tw', 'appgigabitethernet' => 'ap',
		'gigabitethernet' => 'gi', 'fastethernet' => 'fa', 'port-channel' => 'po', 'ethernet' => 'eth',
		'loopback' => 'lo', 'vlan' => 'vl'
	];

	private array $patterns = [];
	/** Las claves de las plantillas oficiales de Zabbix se prueban después de las configuradas (perfil distinto de "Solo claves personalizadas"). */
	private bool $auto_detect = true;
	/** Buscadores de interfaces indexados por base de clave (texto antes de "["): base => [[métrica, prioridad, regex, remapeo], ...]. */
	private array $matchers = [];
	private array $summary_candidates = [];
	private int $util_warning = 60;
	private int $util_critical = 80;

	protected function init(): void {
		parent::init();
		// Detalle de un solo equipo, que pide el propio widget cuando la consola funciona en modo resumen.
		if (method_exists($this, 'addValidationRules')) $this->addValidationRules(['laka_detail' => 'id', 'laka_mac' => 'string']);
	}

	// ------------------------------------------------------------------ localizador MAC / IP

	/** Las tablas recolectadas hace más de este tiempo están "posiblemente desactualizadas"; con más de LOCATE_EXPIRED, "vencidas". */
	private const LOCATE_STALE = 1800;
	private const LOCATE_EXPIRED = 7200;
	/** Ventana del historial que revisa el localizador cuando la MAC o la IP ya no está en las tablas (24 h). */
	private const LOCATE_HISTORY = 86400;
	/** Ventana para la hora del último valor de cada tabla: cubre LOCATE_EXPIRED y un intervalo de recolección largo. */
	private const LOCATE_WINDOW = 10800;
	/** Con más MAC que esta cantidad, un puerto de acceso probablemente tiene un switch, AP o hipervisor detrás. */
	private const LOCATE_MANY_MACS = 3;

	/**
	 * Localiza una dirección MAC o IP con las tablas que recolecta "LAKA Network MAC table by SNMP": el mismo recorrido que
	 * "show mac address-table address" desde el core, siguiendo los vecinos CDP/LLDP hasta el puerto de acceso.
	 */
	private function locate(string $query, array $hosts): array {
		$result = $this->locateTables($query, $hosts);
		// Vecinos CDP/LLDP: teléfonos, AP, cámaras y switches que se anuncian con su nombre, IP o MAC. No dependen de las
		// tablas MAC, así que encuentran el puerto aunque el recolector todavía no tenga datos o tenga errores.
		$hosts_by_id = [];
		foreach ($hosts as $host) $hosts_by_id[(string) $host['hostid']] = $host;
		$result['neighbors'] = $this->locateNeighbors($query, $result, $hosts_by_id);
		if ($result['neighbors'] && in_array($result['status'], ['invalid', 'not_found', 'no_data', 'pending', 'no_arp'], true)) {
			$result['status'] = 'neighbor';
			if ($result['kind'] === '') $result['kind'] = 'name';
		}
		arsort($result['error_samples']);
		$result['error_samples'] = array_slice($result['error_samples'], 0, 3, true);
		return $result;
	}

	/**
	 * Busca en los vecinos CDP/LLDP de todos los equipos: IP anunciada, nombre (parcial), MAC del nombre de los teléfonos
	 * Cisco ("SEP00AA6E218EB3") o chassis ID LLDP con formato MAC.
	 */
	private function locateNeighbors(string $query, array $result, array $hosts_by_id): array {
		$needle = mb_strtolower(trim($query));
		$ip = MacTable::isIpv4($query) ? trim($query) : null;
		$ips = $ip !== null ? [$ip] : $result['ips'];
		$mac = $result['mac'] !== '' ? $result['mac'] : MacTable::normalizeMac($query);
		if ($ip === null && $mac === null && mb_strlen($needle) < 3) return [];
		$now = time();
		$found = [];
		foreach (array_chunk(array_keys($hosts_by_id), self::ITEM_BATCH) as $batch) {
			$items = API::Item()->get([
				'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'], 'hostids' => $batch, 'webitems' => true,
				'search' => ['key_' => 'laka.topology.'], 'startSearch' => true,
				'filter' => ['status' => ITEM_STATUS_ACTIVE]
			]);
			$by_host = [];
			foreach ($items as $item) $by_host[(string) $item['hostid']][] = $item;
			unset($items);
			foreach ($by_host as $hostid => $host_items) {
				foreach ($this->topologyNeighbors($host_items) as $neighbor) {
					$name = mb_strtolower($neighbor['name']);
					$neighbor_mac = preg_match('/^sep([0-9a-f]{12})$/i', $neighbor['name'], $m) === 1 ? strtolower($m[1])
						: MacTable::normalizeMac($neighbor['chassis']) ?? MacTable::normalizeMac($neighbor['name']);
					$match = ($ips && $neighbor['ip'] !== '' && in_array($neighbor['ip'], $ips, true))
						|| ($mac !== null && $neighbor_mac === $mac)
						|| ($ip === null && $mac === null && $name !== '' && str_contains($name, $needle));
					if (!$match) continue;
					$found[] = ['hostid' => (string) $hostid, 'host' => (string) ($hosts_by_id[$hostid]['name'] ?? $hostid),
						'port' => ucfirst($this->normalizeIfName($neighbor['local_port'])), 'protocol' => strtoupper($neighbor['protocol']),
						'name' => $neighbor['name'], 'ip' => $neighbor['ip'], 'mac' => $neighbor_mac ?? '',
						'platform' => $neighbor['platform'], 'remote_port' => $neighbor['remote_port'],
						'type' => $this->deviceType($neighbor['name'].' '.$neighbor['platform'].' '.$neighbor['description']),
						'age' => $neighbor['lastclock'] > 0 ? $now - $neighbor['lastclock'] : null];
					if (count($found) >= 20) break 3;
				}
			}
		}
		// El mismo vecino visto por CDP y LLDP en el mismo puerto se muestra una vez.
		$unique = [];
		foreach ($found as $row) $unique[$row['hostid'].'|'.$row['port'].'|'.mb_strtolower($row['name'])] ??= $row;
		return array_values($unique);
	}

	private function locateTables(string $query, array $hosts): array {
		$result = ['query' => $query, 'kind' => '', 'mac' => '', 'formats' => null, 'special' => '', 'local' => false,
			'ips' => [], 'status' => 'invalid', 'confidence' => '', 'final' => null, 'path' => [], 'sightings' => [],
			'arp' => [], 'hosts' => [], 'notes' => [], 'vendor' => '', 'history' => [],
			'neighbors' => [], 'error_samples' => [],
			'coverage' => ['hosts' => 0, 'tables' => 0, 'errors' => 0, 'pending' => 0, 'arp_tables' => 0, 'arp_pending' => 0]];
		$mac = MacTable::normalizeMac($query);
		$ip = $mac === null && MacTable::isIpv4($query) ? trim($query) : null;

		$hosts_by_id = [];
		foreach ($hosts as $host) $hosts_by_id[(string) $host['hostid']] = $host;
		$ids = array_keys($hosts_by_id);
		$now = time();

		// 0. Equipos monitoreados con esa IP o nombre (la IP de gestión de un switch suele ser un loopback: nunca está en ARP).
		if ($mac === null) {
			$result['hosts'] = $this->locateHosts($query, $ip, $hosts_by_id);
			if ($ip === null) {
				$result['kind'] = 'name';
				$result['status'] = $result['hosts'] ? 'host' : 'invalid';
				return $result;
			}
		}
		$result['kind'] = $mac !== null ? 'mac' : 'ip';

		// 1. IP -> MAC con las tablas ARP; MAC -> IP para la respuesta.
		$arp_hits = [];
		$arp_itemids = [];
		$arp_needle = $mac ?? implode('.', array_slice(explode('.', (string) $ip), 0, 3)).':';
		foreach ($this->locateTable($ids, 'arp', [$arp_needle]) as $item) {
			$value = (string) $item['lastvalue'];
			$arp_itemids[(string) $item['itemid']] = (string) $item['hostid'];
			if ($item['lastclock'] === 0) {
				$result['coverage']['arp_pending']++;
				continue;
			}
			$result['coverage']['arp_tables']++;
			if ($value === '' || isset(MacTable::header($value)['error'])) continue;
			foreach (MacTable::findInArp($value, $ip, $mac) as $entry_ip => $entry_mac) {
				$arp_hits[] = ['hostid' => (string) $item['hostid'], 'ip' => $entry_ip, 'mac' => $entry_mac,
					'ifindex' => (string) (MacTable::header($value)['if'] ?? ''), 'clock' => (int) $item['lastclock']];
			}
		}
		usort($arp_hits, static fn(array $a, array $b): int => $b['clock'] <=> $a['clock']);
		if ($mac === null) {
			if (!$arp_hits && $result['coverage']['arp_tables'] > 0) {
				// La IP no está en el ARP actual: última MAC conocida en el historial de 24 h.
				$arp_hits = $this->locateArpHistory($arp_itemids, $ip);
				if ($arp_hits) $result['notes'][] = 'ip_from_history';
			}
			if (!$arp_hits) {
				$this->locateCoverage($ids, $result);
				$result['status'] = $result['hosts'] ? 'host'
					: ($result['coverage']['arp_tables'] > 0 ? 'not_found'
						: ($result['coverage']['arp_pending'] > 0 ? 'pending' : 'no_arp'));
				return $result;
			}
			$mac = $arp_hits[0]['mac'];
			$macs = array_values(array_unique(array_column($arp_hits, 'mac')));
			if (count($macs) > 1) $result['notes'][] = 'ip_multiple_macs';
		}
		$result['mac'] = $mac;
		$result['formats'] = MacTable::formats($mac);
		[$result['special'], $result['local']] = MacTable::special($mac);
		// Fabricante por OUI (las MAC aleatorias o administradas localmente no tienen fabricante).
		if (!$result['local'] && $result['special'] === '') $result['vendor'] = MacTable::vendor($mac) ?? '';
		foreach ($arp_hits as $hit) {
			if ($hit['mac'] === $mac) $result['ips'][$hit['ip']] = $hit['ip'];
		}
		$result['ips'] = array_values($result['ips']);

		// 2. Tablas MAC de todos los equipos.
		$hits = [];
		$tabled = [];
		$fdb_items = [];
		foreach ($this->locateTable($ids, 'fdb', [$mac]) as $item) {
			$hostid = (string) $item['hostid'];
			$fdb_items[(string) $item['itemid']] = $hostid;
			$value = (string) $item['lastvalue'];
			if ($item['lastclock'] === 0) {
				$result['coverage']['pending']++;
				continue;
			}
			$result['coverage']['tables']++;
			$tabled[$hostid] = true;
			if ($value === '') continue;
			$header = MacTable::header($value);
			if (isset($header['error'])) {
				$result['coverage']['errors']++;
				$this->locateErrorSample($result, $header, $value);
				continue;
			}
			foreach (MacTable::findInFdb($value, $mac) as $entry) {
				$hits[] = ['hostid' => $hostid, 'vlan' => (string) ($header['vlan'] ?? ''), 'ifindex' => $entry['ifindex'],
					'count' => $entry['count'], 'clock' => (int) $item['lastclock'], 'itemid' => (string) $item['itemid']];
			}
		}
		$result['coverage']['hosts'] = count($tabled);
		if (!$tabled) {
			$result['status'] = $result['coverage']['pending'] > 0 ? 'pending' : 'no_data';
			return $result;
		}
		if (!$hits) {
			$result['status'] = 'not_found';
			$result['history'] = $this->locateHistory($fdb_items, $mac, $hosts_by_id, $now);
			$result['arp'] = $this->locateArpRows($arp_hits, $mac, $hosts_by_id, $now);
			return $result;
		}

		// 3. Puertos, port-channels y vecinos CDP/LLDP de los equipos donde se vio la MAC.
		$hit_ids = array_values(array_unique(array_column($hits, 'hostid')));
		$lags = [];
		foreach ($this->locateItems($hit_ids, 'lag') as $item) {
			$lags[(string) $item['hostid']] = MacTable::parseLag((string) $item['lastvalue']);
		}
		$aliases = [];
		foreach ($hosts_by_id as $hostid => $host) {
			foreach ([$host['host'] ?? '', $host['name'] ?? ''] as $name) {
				foreach ($this->topologyNameAliases((string) $name) as $alias) $aliases[$alias] = $hostid;
			}
			foreach ($host['interfaces'] ?? [] as $interface) {
				foreach (['ip', 'dns'] as $field) {
					$value = strtolower(trim((string) ($interface[$field] ?? '')));
					if ($value !== '') $aliases[$value] = $hostid;
				}
			}
		}
		$devices = [];
		$saved = $this->fields_values;
		// Todas las interfaces (también port-channels y SVI que los filtros de interfaz excluyen de la consola).
		$this->fields_values['include_regex'] = '';
		$this->fields_values['exclude_regex'] = '';
		$items_by_host = $this->loadItems($hit_ids, $this->overviewPrefixes());
		foreach ($hit_ids as $hostid) {
			$devices[$hostid] = $this->buildDevice($hosts_by_id[$hostid], $items_by_host[$hostid] ?? [], [], false);
		}
		$this->fields_values = $saved;
		unset($items_by_host);

		// Enlaces vistos solo desde el otro extremo (CDP/LLDP deshabilitado o no recolectado en un lado).
		$reverse = [];
		foreach ($devices as $hostid => $device) {
			foreach ($device['topology_neighbors'] as $candidate) {
				foreach (array_merge($this->topologyNameAliases($candidate['name']), $this->topologyNameAliases($candidate['ip'])) as $alias) {
					$target = $aliases[$alias] ?? '';
					if ($target === '' || $target === (string) $hostid) continue;
					$reverse[$target][$this->normalizeIfName($candidate['remote_port'])] = [
						'name' => $device['name'], 'hostid' => (string) $hostid, 'type' => 'switch',
						'port' => $candidate['local_port'], 'protocol' => strtoupper($candidate['protocol'])
					];
					break;
				}
			}
		}

		$sightings = [];
		foreach ($hits as $hit) {
			$device = $devices[$hit['hostid']];
			$ports = [];
			foreach ($device['ports'] as $port) $ports[$port['index']] = $port;
			$port = $ports[$hit['ifindex']] ?? null;
			$lag = $lags[$hit['hostid']][$hit['ifindex']] ?? null;
			$name = $port['name'] ?? ($lag['name'] ?? '');
			if ($name === '') $name = str_starts_with($hit['ifindex'], 'b') ? 'bridge port '.substr($hit['ifindex'], 1) : 'ifIndex '.$hit['ifindex'];
			// Interfaces físicas cuyo vecino CDP/LLDP indica de dónde viene la MAC.
			$local_keys = [$this->normalizeIfName($name)];
			$members = [];
			foreach ($lag['members'] ?? [] as $member) {
				if (isset($ports[$member])) {
					$members[] = $ports[$member]['name'];
					$local_keys[] = $ports[$member]['key'];
				}
			}
			$neighbor = null;
			foreach ($device['topology_neighbors'] as $candidate) {
				if (!in_array($this->normalizeIfName($candidate['local_port']), $local_keys, true)) continue;
				$target = '';
				foreach (array_merge($this->topologyNameAliases($candidate['name']), $this->topologyNameAliases($candidate['ip'])) as $alias) {
					if (isset($aliases[$alias]) && $aliases[$alias] !== $hit['hostid']) {
						$target = $aliases[$alias];
						break;
					}
				}
				$type = $target !== '' ? 'switch' : $this->deviceType($candidate['name'].' '.$candidate['platform'].' '.$candidate['description']);
				$neighbor = ['name' => $target !== '' ? (string) $hosts_by_id[$target]['name'] : ($candidate['name'] ?: $candidate['ip']),
					'hostid' => $target, 'type' => $type,
					'port' => $candidate['remote_port'], 'protocol' => strtoupper($candidate['protocol'])];
				if ($target !== '') break;
			}
			if ($neighbor === null || $neighbor['hostid'] === '') {
				foreach ($local_keys as $local_key) {
					if (isset($reverse[$hit['hostid']][$local_key])) {
						$neighbor = $reverse[$hit['hostid']][$local_key];
						break;
					}
				}
			}
			// Fabric SD-Access / VXLAN: las MAC remotas se aprenden en la interfaz de túnel (L2LISP, Tunnel, NVE).
			$fabric = preg_match('/^(l2li|lisp|tu|tunnel|nve|vxlan|vx)/i', $name) === 1;
			$infra = $fabric || $neighbor !== null && ($neighbor['hostid'] !== '' || in_array($neighbor['type'], ['switch', 'router', 'firewall'], true));
			$age = $now - $hit['clock'];
			$sightings[] = [
				'hostid' => $hit['hostid'], 'host' => $device['name'], 'ip' => $device['ip'], 'region' => $device['region'],
				'role' => $device['role'], 'vlan' => $hit['vlan'], 'ifindex' => $hit['ifindex'], 'port' => $name,
				'port_index' => $port['index'] ?? '', 'alias' => $port['alias'] ?? '', 'status' => $port['status'] ?? 'unknown',
				'speed' => $port['speed_text'] ?? '—', 'members' => $members, 'lag' => $lag !== null || preg_match('/^(po|port-channel|ae|bond)/i', $name) === 1,
				'macs_on_port' => $hit['count'], 'neighbor' => $neighbor, 'kind' => $infra || $lag !== null ? 'uplink' : 'access',
				'age' => $age, 'freshness' => $age <= self::LOCATE_STALE ? 'current' : ($age <= self::LOCATE_EXPIRED ? 'stale' : 'expired')
			];
		}

		// 4. Puerto final y nivel de confianza.
		$access = array_values(array_filter($sightings, static fn(array $row): bool => $row['kind'] === 'access'));
		usort($access, static fn(array $a, array $b): int => [$a['macs_on_port'], $a['age']] <=> [$b['macs_on_port'], $b['age']]);
		$distinct = array_unique(array_map(static fn(array $row): string => $row['hostid'].'|'.$row['ifindex'], $access));
		if ($access) {
			$final = $access[0];
			$behind = in_array($final['neighbor']['type'] ?? '', ['access_point', 'server'], true);
			$phone = ($final['neighbor']['type'] ?? '') === 'phone';
			$result['confidence'] = count($distinct) > 1 ? 'multiple'
				: (!$behind && $final['macs_on_port'] <= self::LOCATE_MANY_MACS + ($phone ? 1 : 0) ? 'high' : 'medium');
		}
		else {
			// Vista solo en uplinks: el siguiente equipo no tiene tabla MAC (falta la plantilla) o la entrada venció.
			usort($sightings, static fn(array $a, array $b): int => $a['age'] <=> $b['age']);
			$final = $sightings[0];
			foreach ($sightings as $row) {
				$next = $row['neighbor']['hostid'] ?? '';
				if ($next === '' || !isset($tabled[$next])) {
					$final = $row;
					break;
				}
			}
			$result['confidence'] = 'low';
			$next = $final['neighbor']['hostid'] ?? '';
			if ($next !== '' && !isset($tabled[$next])) $result['notes'][] = 'next_without_table';
		}
		if ($final['freshness'] !== 'current') $result['notes'][] = 'outdated';

		// 5. Recorrido desde el core: sigue el uplink de cada equipo hasta el siguiente donde se vio la MAC.
		$by_host = [];
		foreach ($sightings as $row) {
			if (!isset($by_host[$row['hostid']]) || $by_host[$row['hostid']]['kind'] === 'access') $by_host[$row['hostid']] = $row;
		}
		$pointed = [];
		foreach ($by_host as $row) {
			if ($row['kind'] === 'uplink' && ($row['neighbor']['hostid'] ?? '') !== '') $pointed[$row['neighbor']['hostid']] = true;
		}
		$walk = function(string $start) use ($by_host, $final): array {
			$path = [];
			$visited = [];
			$current = $start;
			while ($current !== null && isset($by_host[$current]) && !isset($visited[$current]) && count($path) < 16) {
				$visited[$current] = true;
				$row = $current === $final['hostid'] ? $final : $by_host[$current];
				$path[] = $row;
				if ($current === $final['hostid']) break;
				$next = $row['neighbor']['hostid'] ?? '';
				$current = $row['kind'] === 'uplink' && $next !== '' ? $next : null;
			}
			return $path;
		};
		// Mejor inicio: la cadena más larga que llega al equipo final, prefiriendo los equipos core / WAN.
		$path = [$final];
		$best = -1;
		foreach ($by_host as $hostid => $row) {
			if (isset($pointed[$hostid]) && !in_array($row['role'], ['core', 'wan', 'router', 'firewall'], true)) continue;
			$candidate = $walk((string) $hostid);
			$reaches = end($candidate)['hostid'] === $final['hostid'];
			$score = ($reaches ? 100 : 0) + count($candidate) + (in_array($row['role'], ['core', 'wan'], true) ? 50 : 0);
			if ($score > $best) {
				$best = $score;
				$path = $candidate;
			}
		}
		$visited = array_flip(array_column($path, 'hostid'));
		if (!isset($visited[$final['hostid']])) $path[] = $final + ['gap' => true];

		$result['status'] = 'found';
		$result['final'] = $final;
		$result['path'] = array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['hostid', 'host', 'ip',
			'region', 'vlan', 'port', 'port_index', 'alias', 'kind', 'neighbor', 'members', 'age', 'freshness', 'gap'])), $path);
		usort($sightings, static fn(array $a, array $b): int => [$a['kind'] === 'access' ? 0 : 1, $a['host']] <=> [$b['kind'] === 'access' ? 0 : 1, $b['host']]);
		$result['sightings'] = $sightings;
		$result['arp'] = $this->locateArpRows($arp_hits, $mac, $hosts_by_id, $now);
		return $result;
	}

	/**
	 * Dónde se vio la MAC en las últimas 24 h (historial de las tablas MAC). La búsqueda la hace la base de datos
	 * (LIKE sobre el valor, acotada por ítem y fecha), así que solo viajan los pocos valores que contienen la MAC.
	 */
	private function locateHistory(array $fdb_items, string $mac, array $hosts_by_id, int $now): array {
		$found = [];
		foreach (array_chunk(array_keys($fdb_items), 500) as $batch) {
			$rows = API::History()->get([
				'output' => ['itemid', 'clock', 'value'], 'history' => ITEM_VALUE_TYPE_TEXT, 'itemids' => $batch,
				'time_from' => $now - self::LOCATE_HISTORY, 'search' => ['value' => $mac],
				'sortfield' => 'clock', 'sortorder' => ZBX_SORT_DOWN, 'limit' => 20
			]);
			foreach ($rows as $row) {
				$hostid = $fdb_items[(string) $row['itemid']] ?? '';
				$header = MacTable::header((string) $row['value']);
				foreach (MacTable::findInFdb((string) $row['value'], $mac) as $entry) {
					$key = $hostid.'|'.$entry['ifindex'];
					if (isset($found[$key]) && $found[$key]['clock'] >= (int) $row['clock']) continue;
					$found[$key] = ['hostid' => $hostid, 'host' => (string) ($hosts_by_id[$hostid]['name'] ?? $hostid),
						'vlan' => (string) ($header['vlan'] ?? ''), 'ifindex' => $entry['ifindex'], 'port' => '',
						'macs_on_port' => $entry['count'], 'clock' => (int) $row['clock'], 'age' => $now - (int) $row['clock']];
				}
			}
		}
		if (!$found) return [];
		// Primero el puerto de acceso (menos MAC), luego el más reciente.
		usort($found, static fn(array $a, array $b): int => [$a['macs_on_port'], -$a['clock']] <=> [$b['macs_on_port'], -$b['clock']]);
		$found = array_slice($found, 0, 6);
		$hostids = array_values(array_unique(array_column($found, 'hostid')));
		$names = [];
		foreach (API::Item()->get(['output' => ['hostid', 'key_', 'lastvalue'], 'hostids' => $hostids, 'webitems' => true,
				'search' => ['key_' => 'net.if.name['], 'startSearch' => true]) as $item) {
			if (str_starts_with((string) $item['key_'], 'net.if.name[')
					&& preg_match('/\[(?:ifName\.)?(\d+)\]$/', (string) $item['key_'], $m) === 1) {
				$names[(string) $item['hostid'].'|'.$m[1]] = (string) $item['lastvalue'];
			}
		}
		foreach ($found as &$row) {
			$row['port'] = $names[$row['hostid'].'|'.$row['ifindex']]
				?? (str_starts_with($row['ifindex'], 'b') ? 'bridge port '.substr($row['ifindex'], 1) : 'ifIndex '.$row['ifindex']);
		}
		unset($row);
		return $found;
	}

	/** Entradas ARP de una IP en el historial de 24 h (la más reciente primero). $itemids: itemid => hostid. */
	private function locateArpHistory(array $itemids, string $ip): array {
		$octets = explode('.', $ip);
		$hits = [];
		foreach (array_chunk(array_keys($itemids), 500) as $batch) {
			$rows = API::History()->get([
				'output' => ['itemid', 'clock', 'value'], 'history' => ITEM_VALUE_TYPE_TEXT, 'itemids' => $batch,
				'time_from' => time() - self::LOCATE_HISTORY, 'search' => ['value' => $octets[0].'.'.$octets[1].'.'.$octets[2].':'],
				'sortfield' => 'clock', 'sortorder' => ZBX_SORT_DOWN, 'limit' => 50
			]);
			foreach ($rows as $row) {
				foreach (MacTable::findInArp((string) $row['value'], $ip, null) as $entry_ip => $entry_mac) {
					$hits[] = ['hostid' => $itemids[(string) $row['itemid']] ?? '', 'ip' => $entry_ip, 'mac' => $entry_mac,
						'ifindex' => (string) (MacTable::header((string) $row['value'])['if'] ?? ''), 'clock' => (int) $row['clock']];
				}
			}
		}
		usort($hits, static fn(array $a, array $b): int => $b['clock'] <=> $a['clock']);
		return array_slice($hits, 0, 1);
	}

	/** Ítems del recolector MAC de un tipo (fdb, arp, lag), cargados por lotes de hosts. */
	/**
	 * Tablas del recolector de un tipo SIN pedir el último valor de cada una (con cientos de tablas de texto eso tarda
	 * decenas de segundos). La hora del último valor sale de una consulta liviana al historial (solo itemid y hora) y los
	 * valores se traen solo de las tablas que contienen lo buscado o un error: la base de datos hace el filtro.
	 * @return array itemid => ['itemid', 'hostid', 'key_', 'lastclock' (0 = sin datos recientes), 'lastvalue' ('' = sin coincidencia)]
	 */
	private function locateTable(array $hostids, string $mode, array $needles): array {
		$items = [];
		foreach (array_chunk($hostids, self::ITEM_BATCH * 4) as $batch) {
			foreach (API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_'], 'hostids' => $batch, 'webitems' => true,
				'filter' => ['status' => ITEM_STATUS_ACTIVE, 'value_type' => ITEM_VALUE_TYPE_TEXT],
				'search' => ['key_' => MacTable::KEY_PREFIX.'"'.$mode.'"'], 'startSearch' => true
			]) as $item) {
				if (MacTable::keyMode((string) $item['key_']) !== $mode) continue;
				$items[(string) $item['itemid']] = ['itemid' => (string) $item['itemid'], 'hostid' => (string) $item['hostid'],
					'key_' => (string) $item['key_'], 'lastclock' => 0, 'lastvalue' => ''];
			}
		}
		if (!$items) return [];
		$from = time() - self::LOCATE_WINDOW;
		$chunks = array_chunk(array_keys($items), 1000);
		foreach ($chunks as $chunk) {
			foreach (API::History()->get(['output' => ['itemid', 'clock'], 'history' => ITEM_VALUE_TYPE_TEXT,
					'itemids' => $chunk, 'time_from' => $from]) as $row) {
				$id = (string) $row['itemid'];
				if ((int) $row['clock'] > $items[$id]['lastclock']) $items[$id]['lastclock'] = (int) $row['clock'];
			}
		}
		// Coincidencias, errores del recolector y valores con formato desconocido; solo cuenta el valor más reciente.
		$queries = [];
		foreach (array_unique($needles) as $needle) $queries[] = ['search' => ['value' => $needle]];
		$queries[] = ['search' => ['value' => ';error=']];
		$queries[] = ['search' => ['value' => 'LAKAFDB'], 'excludeSearch' => true, 'limit' => 500];
		foreach ($queries as $query) {
			foreach ($chunks as $chunk) {
				foreach (API::History()->get($query + ['output' => ['itemid', 'clock', 'value'], 'history' => ITEM_VALUE_TYPE_TEXT,
						'itemids' => $chunk, 'time_from' => $from]) as $row) {
					$id = (string) $row['itemid'];
					if ((int) $row['clock'] === $items[$id]['lastclock'] && $items[$id]['lastvalue'] === '') {
						$items[$id]['lastvalue'] = (string) $row['value'];
					}
				}
			}
		}
		return $items;
	}

	private function locateItems(array $hostids, string $mode): \Generator {
		foreach (array_chunk($hostids, self::ITEM_BATCH) as $batch) {
			$items = API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock'],
				'hostids' => $batch,
				'webitems' => true,
				'filter' => ['status' => ITEM_STATUS_ACTIVE],
				'search' => ['key_' => MacTable::KEY_PREFIX.'"'.$mode.'"'],
				'startSearch' => true
			]);
			foreach ($items as $item) {
				// También se devuelven los ítems todavía sin valor (primer sondeo pendiente), para los contadores de cobertura.
				if (MacTable::keyMode((string) $item['key_']) === $mode) yield $item;
			}
			unset($items);
		}
	}

	/** Equipos monitoreados que coinciden con una IP (interfaces) o un nombre (host o nombre visible, parcial). */
	private function locateHosts(string $query, ?string $ip, array $hosts_by_id): array {
		$needle = mb_strtolower(trim($query));
		if ($ip === null && mb_strlen($needle) < 2) return [];
		$matches = [];
		foreach ($hosts_by_id as $hostid => $host) {
			$ips = [];
			foreach ($host['interfaces'] ?? [] as $interface) {
				foreach (['ip', 'dns'] as $field) {
					$value = trim((string) ($interface[$field] ?? ''));
					if ($value !== '') $ips[] = $value;
				}
			}
			$match = $ip !== null ? in_array($ip, $ips, true)
				: (str_contains(mb_strtolower((string) $host['name']), $needle) || str_contains(mb_strtolower((string) $host['host']), $needle));
			if (!$match) continue;
			$matches[] = ['hostid' => (string) $hostid, 'name' => (string) $host['name'], 'ip' => $this->primaryInterface($host),
				'region' => $this->regionOf($host, $this->inventoryLocation($host)), 'role' => $this->hostRole($host)];
			if (count($matches) >= 10) break;
		}
		return $matches;
	}

	/** Agrupa los errores de recolección por texto (los más frecuentes se muestran en el localizador). */
	private function locateErrorSample(array &$result, array $header, string $value): void {
		$text = $header['error'] === 'format'
			? 'formato no reconocido: '.mb_substr(trim(preg_replace('/\s+/', ' ', $value)), 0, 80)
			: (string) preg_replace('/\b(vlan|for vlan) \d+/', '$1 N', mb_substr($header['error'], 0, 120));
		$result['error_samples'][$text] = ($result['error_samples'][$text] ?? 0) + 1;
	}

	/** Cuenta las tablas MAC recolectadas (cuando la búsqueda terminó antes de leerlas). */
	private function locateCoverage(array $ids, array &$result): void {
		$tabled = [];
		foreach ($this->locateTable($ids, 'fdb', []) as $item) {
			if ($item['lastclock'] === 0) {
				$result['coverage']['pending']++;
				continue;
			}
			$result['coverage']['tables']++;
			$tabled[(string) $item['hostid']] = true;
			if ((string) $item['lastvalue'] === '') continue;
			$header = MacTable::header((string) $item['lastvalue']);
			if (isset($header['error'])) {
				$result['coverage']['errors']++;
				$this->locateErrorSample($result, $header, (string) $item['lastvalue']);
			}
		}
		$result['coverage']['hosts'] = count($tabled);
	}

	private function locateArpRows(array $arp_hits, string $mac, array $hosts_by_id, int $now): array {
		$rows = [];
		foreach ($arp_hits as $hit) {
			if ($hit['mac'] !== $mac) continue;
			$rows[] = ['hostid' => $hit['hostid'], 'host' => (string) ($hosts_by_id[$hit['hostid']]['name'] ?? $hit['hostid']),
				'ip' => $hit['ip'], 'ifindex' => $hit['ifindex'], 'age' => $now - $hit['clock']];
		}
		return $rows;
	}

	/** Hosts cuyos ítems se cargan y procesan por llamada a la API (memoria acotada con cientos de hosts). */
	private const ITEM_BATCH = 25;
	/** Memoria PHP mínima para esta petición; el valor por defecto del frontend de Zabbix (128M) no alcanza en redes grandes. */
	private const MEMORY_MIN = 512 * 1048576;

	protected function doAction(): void {
		$this->raiseLimits();
		try {
			$this->buildResponse();
		}
		catch (\Throwable $e) {
			// Nunca dejar el dashboard con el indicador de carga girando: un error de PHP se convierte en un mensaje visible.
			error_log('[LAKA Network] '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
			$this->setResponse(new CControllerResponseData([
				'name' => trim((string) $this->getInput('name', '')) ?: $this->widget->getDefaultName(),
				'mode' => method_exists($this, 'hasInput') && $this->hasInput('laka_detail') ? 'detail' : 'console',
				'lazy' => false, 'devices' => [], 'detail' => null, 'regions' => [], 'truncated' => false, 'total_hosts' => 0,
				'message' => sprintf(Lang::t('The widget could not be generated: %1$s'),
					$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'),
				'can_manage_topology' => false, 'settings' => $this->settings(),
				'topology' => ['hosts' => [], 'links' => [], 'endpoints' => [], 'endpoint_groups' => []],
				'user' => ['debug_mode' => $this->getDebugMode()]
			]));
		}
	}

	private function raiseLimits(): void {
		$limit = trim((string) ini_get('memory_limit'));
		if ($limit !== '' && $limit !== '-1') {
			$bytes = (int) $limit;
			$unit = strtolower(substr($limit, -1));
			if ($unit === 'g') $bytes *= 1073741824;
			elseif ($unit === 'm') $bytes *= 1048576;
			elseif ($unit === 'k') $bytes *= 1024;
			if ($bytes > 0 && $bytes < self::MEMORY_MIN) @ini_set('memory_limit', (string) self::MEMORY_MIN);
		}
	}

	private function buildResponse(): void {
		Lang::setLanguage((int) ($this->fields_values['language'] ?? Lang::AUTO));
		$name = trim((string) $this->getInput('name', '')) ?: $this->widget->getDefaultName();
		$this->patterns = Profiles::resolve($this->fields_values);
		$this->auto_detect = Profiles::autoDetect($this->fields_values);
		$this->summary_candidates = Profiles::summaryCandidates($this->patterns, $this->auto_detect);
		$this->util_warning = max(1, min(99, (int) ($this->fields_values['util_warning'] ?? 60)));
		$this->util_critical = max($this->util_warning + 1, min(100, (int) ($this->fields_values['util_critical'] ?? 80)));

		$data = [
			'name' => $name,
			'mode' => 'console',
			'lazy' => false,
			'devices' => [],
			'detail' => null,
			'regions' => [],
			'truncated' => false,
			'total_hosts' => 0,
			'message' => '',
			'can_manage_topology' => $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN,
			'settings' => $this->settings(),
			'topology' => ['hosts' => [], 'links' => [], 'endpoints' => [], 'endpoint_groups' => []],
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		$hostids = $this->selectedIds('hostids');
		$groupids = $this->selectedIds('groupids');
		if (!$hostids && !$groupids) {
			$data['message'] = Lang::t('Select at least one host group or host in the widget configuration.');
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		$hosts = $this->loadHosts($groupids, $hostids);
		$max_hosts = max(10, min(500, (int) ($this->fields_values['max_hosts'] ?? 300)));
		$data['total_hosts'] = count($hosts);
		if (count($hosts) > $max_hosts) {
			$hosts = array_slice($hosts, 0, $max_hosts);
			$data['truncated'] = true;
		}
		if (!$hosts) {
			$data['message'] = Lang::t('No monitored or accessible hosts were found.');
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		$ids = array_map(static fn(array $host): string => (string) $host['hostid'], $hosts);
		$detail_mode = (int) ($this->fields_values['detail_mode'] ?? 0);
		// "Siempre embebido" se respeta hasta EMBED_MAX equipos; por encima la página pesaría decenas de MB.
		$lazy = $detail_mode === 2 || count($hosts) > ($detail_mode === 1 ? self::EMBED_MAX : self::EMBED_THRESHOLD);
		$data['lazy'] = $lazy;
		$data['settings']['lazy'] = $lazy;

		// ---- Localizador MAC / IP (lo pide el widget desde su ventana de búsqueda).
		$mac_query = method_exists($this, 'hasInput') && $this->hasInput('laka_mac') ? trim((string) $this->getInput('laka_mac')) : '';
		if ($mac_query !== '') {
			$data['mode'] = 'mac';
			$data['locate'] = $this->locate($mac_query, $hosts);
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		// ---- Detalle de un equipo (modo resumen, bajo demanda).
		$detail_hostid = method_exists($this, 'hasInput') && $this->hasInput('laka_detail')
			? (string) $this->getInput('laka_detail') : '';
		if ($detail_hostid !== '') {
			$data['mode'] = 'detail';
			$target = null;
			foreach ($hosts as $host) {
				if ((string) $host['hostid'] === $detail_hostid) $target = $host;
			}
			if ($target === null) {
				$data['message'] = Lang::t('No monitored or accessible hosts were found.');
				$this->setResponse(new CControllerResponseData($data));
				return;
			}
			$items = $this->loadItems([$detail_hostid]);
			$problems = $this->loadProblems([$detail_hostid]);
			$device = $this->buildDevice($target, $items[$detail_hostid] ?? [], $problems, true);
			$others = [];
			foreach ($hosts as $host) {
				if ((string) $host['hostid'] !== $detail_hostid) $others[] = $this->stubDevice($host);
			}
			$topology = $this->buildTopology(array_merge([$device], $others), true);
			$this->attachNeighbors($device, $topology);
			$device['endpoints'] = array_values(array_filter($topology['endpoints'],
				static fn(array $endpoint): bool => $endpoint['parent'] === $detail_hostid));
			$data['detail'] = $device;
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		// ---- Consola (todos los equipos).
		$problems = $this->loadProblems($ids);
		$prefixes = $lazy ? $this->overviewPrefixes() : null;

		// Los ítems se piden por lotes y se liberan en cuanto se arma cada equipo.
		$hide_empty = (int) ($this->fields_values['hide_empty_devices'] ?? 0) === 1;
		foreach (array_chunk($hosts, self::ITEM_BATCH) as $batch) {
			$items_by_host = $this->loadItems(array_map(static fn(array $host): string => (string) $host['hostid'], $batch),
				$prefixes);
			foreach ($batch as $host) {
				$hostid = (string) $host['hostid'];
				$device = $this->buildDevice($host, $items_by_host[$hostid] ?? [], $problems, !$lazy);
				unset($items_by_host[$hostid]);
				if (!$hide_empty || $device['ports']) $data['devices'][] = $device;
			}
			unset($items_by_host);
		}
		if (!$data['devices']) {
			$data['message'] = Lang::t('No equipment with matching physical interfaces was found.');
		}

		$data['topology'] = $this->buildTopology($data['devices'], !$lazy);
		foreach ($data['devices'] as &$device) {
			if (!$lazy) {
				$this->attachNeighbors($device, $data['topology']);
				$device['endpoints'] = array_values(array_filter($data['topology']['endpoints'],
					static fn(array $endpoint): bool => $endpoint['parent'] === $device['hostid']));
			}
			else {
				$device['neighbor_count'] = count(array_filter($data['topology']['links'], static fn(array $link): bool =>
					(string) $link['a'] === $device['hostid'] || (string) $link['b'] === $device['hostid']))
					+ array_sum(array_map(static fn(array $group): int => $group['parent'] === $device['hostid'] ? $group['count'] : 0,
						$data['topology']['endpoint_groups']));
			}
		}
		unset($device);
		if ($lazy) $data['topology']['endpoints'] = [];
		$data['regions'] = $this->regionSummary($data['devices'], $data['topology']['links']);

		$this->setResponse(new CControllerResponseData($data));
	}

	private function inventoryLocation(array $host): string {
		$inventory = is_array($host['inventory'] ?? null) ? $host['inventory'] : [];
		$location = trim((string) ($inventory['location'] ?? ''));
		if ($location !== '') return $location;
		$parts = array_filter(array_map(static fn(string $field): string => trim((string) ($inventory[$field] ?? '')),
			['site_address_a', 'site_city', 'site_state', 'site_country']));
		return implode(', ', array_unique($parts));
	}

	/** Prefijos de clave necesarios en modo resumen ("search" de la API con startSearch). */
	private function overviewPrefixes(): array {
		$patterns = [];
		$fields = array_map(static fn(string $metric): string => self::PORT_METRICS[$metric], self::OVERVIEW_METRICS);
		$candidates = Profiles::portCandidates(array_intersect_key($this->patterns, array_flip($fields)), $this->auto_detect);
		foreach ($candidates as $list) {
			foreach ($list as [$pattern]) $patterns[] = $pattern;
		}
		foreach ($this->summary_candidates as $list) $patterns = array_merge($patterns, $list);
		$prefixes = ['laka.topology.'];
		foreach ($patterns as $pattern) {
			$prefix = strstr(trim((string) $pattern), '*', true);
			$prefixes[] = rtrim($prefix === false ? trim((string) $pattern) : $prefix, '"');
		}
		$prefixes = array_values(array_unique(array_filter($prefixes, static fn(string $prefix): bool => strlen($prefix) >= 3)));
		// Descarta los prefijos ya cubiertos por uno más corto (menos cláusulas LIKE en la consulta a la API).
		sort($prefixes);
		$result = [];
		foreach ($prefixes as $prefix) {
			$last = end($result);
			if ($last !== false && str_starts_with($prefix, $last)) continue;
			$result[] = $prefix;
		}
		return $result;
	}

	/** Equipo mínimo para resolver nombres de vecinos al armar el detalle de otro equipo. */
	private function stubDevice(array $host): array {
		return [
			'hostid' => (string) $host['hostid'], 'name' => (string) $host['name'], 'host' => $host,
			'ip' => $this->primaryInterface($host), 'role' => $this->hostRole($host), 'region' => $this->regionOf($host),
			'summary' => $this->emptySummary(),
			'ports' => [], 'topology_neighbors' => [], 'counts' => ['up' => 0, 'down' => 0, 'disabled' => 0, 'unknown' => 0,
				'problems' => 0, 'total' => 0], 'health' => 'unknown', 'health_severity' => -1
		];
	}

	/** Región de un host: etiqueta del host, grupo de hosts bajo un prefijo o ítem de ubicación; si no, "Sin asignar". */
	private function regionOf(array $host, string $location = ''): string {
		$source = (int) ($this->fields_values['region_source'] ?? 0);
		$tag_name = strtolower(trim((string) ($this->fields_values['region_tag'] ?? 'region'))) ?: 'region';
		$prefix = trim((string) ($this->fields_values['region_group_prefix'] ?? ''));
		if ($source === 4) return '';
		if ($source === 0 || $source === 1) {
			$names = $source === 0 ? array_unique([$tag_name, 'region', 'site', 'region.name']) : [$tag_name];
			foreach ($host['tags'] ?? [] as $tag) {
				if (in_array(strtolower((string) $tag['tag']), $names, true) && trim((string) $tag['value']) !== '') {
					return trim((string) $tag['value']);
				}
			}
		}
		if (($source === 0 || $source === 2) && $prefix !== '') {
			foreach ($host['hostgroups'] ?? $host['groups'] ?? [] as $group) {
				$group_name = (string) $group['name'];
				if (stripos($group_name, $prefix) === 0) {
					$rest = trim(substr($group_name, strlen($prefix)), '/ ');
					if ($rest !== '') return explode('/', $rest)[0];
				}
			}
		}
		if ($source === 3 && $location !== '') return $location;
		return Lang::t('Unassigned');
	}

	private function regionSummary(array $devices, array $links): array {
		$regions = [];
		$region_of = [];
		foreach ($devices as $device) {
			$name = $device['region'];
			$region_of[$device['hostid']] = $name;
			$regions[$name] ??= ['name' => $name, 'hosts' => 0, 'available' => 0, 'warning' => 0, 'unavailable' => 0,
				'maintenance' => 0, 'unknown' => 0, 'problems' => 0, 'max_severity' => -1, 'ports_up' => 0, 'ports_total' => 0,
				'links_internal' => 0, 'links_external' => 0, 'links_down' => 0];
			$region = &$regions[$name];
			$region['hosts']++;
			$region[$device['health']] = ($region[$device['health']] ?? 0) + 1;
			$region['problems'] += $device['kpi']['problem_count'];
			$region['max_severity'] = max($region['max_severity'], $device['health_severity']);
			$region['ports_up'] += $device['counts']['up'];
			$region['ports_total'] += $device['counts']['total'];
			unset($region);
		}
		foreach ($links as $link) {
			$a = $region_of[(string) $link['a']] ?? null;
			$b = $region_of[(string) $link['b']] ?? null;
			if ($a === null || $b === null) continue;
			if ($a === $b) {
				$regions[$a]['links_internal']++;
			}
			else {
				$regions[$a]['links_external']++;
				$regions[$b]['links_external']++;
			}
			if ($a !== $b && ($link['state'] ?? '') === 'down') {
				// En el resumen de regiones solo cuentan como "caídos" los enlaces entre regiones (WAN).
				$regions[$a]['links_down']++;
				$regions[$b]['links_down']++;
			}
		}
		uasort($regions, static fn(array $x, array $y): int => strnatcasecmp($x['name'], $y['name']));
		return array_values($regions);
	}

	private function settings(): array {
		$period = [0 => '1h', 1 => '6h', 2 => '12h', 3 => '24h', 4 => '7d'];
		return [
			'layout_mode' => (int) ($this->fields_values['layout_mode'] ?? 0),
			'columns' => max(4, min(48, (int) ($this->fields_values['columns'] ?? 24))),
			'show_alias' => (int) ($this->fields_values['show_alias'] ?? 1) === 1,
			'show_utilization' => (int) ($this->fields_values['show_utilization'] ?? 1) === 1,
			'interface_list_rows' => max(6, min(60, (int) ($this->fields_values['interface_list_rows'] ?? 24))),
			'show_featured_charts' => (int) ($this->fields_values['show_featured_charts'] ?? 0) === 1,
			'featured_chart_limit' => max(1, min(12, (int) ($this->fields_values['featured_chart_limit'] ?? 4))),
			'featured_chart_period' => $period[(int) ($this->fields_values['featured_chart_period'] ?? 2)] ?? '12h',
			'featured_chart_height' => max(180, min(320, (int) ($this->fields_values['featured_chart_height'] ?? 200))),
			'show_hardware_health' => (int) ($this->fields_values['show_hardware_health'] ?? 1) === 1,
			'util_warning' => $this->util_warning,
			'util_critical' => $this->util_critical,
			'detail_labels' => self::detailLabels(),
			'locale' => Lang::locale(),
			'language' => Lang::language()
		];
	}

	// ------------------------------------------------------------------ carga de datos (una llamada por tipo de entidad)

	private function loadHosts(array $groupids, array $hostids): array {
		$options = [
			'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
			'selectInterfaces' => ['available', 'error', 'ip', 'dns', 'main', 'type'],
			'selectTags' => ['tag', 'value'],
			'selectHostGroups' => ['groupid', 'name'],
			'selectParentTemplates' => ['templateid', 'name'],
			'selectInventory' => ['location', 'site_address_a', 'site_city', 'site_state', 'site_country'],
			'sortfield' => 'name'
		];
		$hosts_by_id = [];
		if ($groupids) {
			foreach (API::Host()->get($options + ['groupids' => $groupids, 'monitored_hosts' => true]) as $host) {
				$hosts_by_id[(string) $host['hostid']] = $host;
			}
		}
		if ($hostids) {
			foreach (API::Host()->get($options + ['hostids' => $hostids]) as $host) {
				$hosts_by_id[(string) $host['hostid']] = $host;
			}
		}
		$hosts = array_values($hosts_by_id);
		usort($hosts, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
		return $hosts;
	}

	private function loadItems(array $hostids, ?array $prefixes = null): array {
		$by_host = [];
		$options = [
			'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'prevvalue', 'lastclock', 'units', 'value_type'],
			'selectValueMap' => ['mappings'],
			'hostids' => $hostids,
			'webitems' => true,
			'filter' => ['status' => ITEM_STATUS_ACTIVE]
		];
		if ($prefixes !== null) {
			// Modo resumen: solo las claves necesarias para estado, velocidad, uso, resumen y topología.
			$options['output'] = ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type'];
			unset($options['selectValueMap']);
			$options += ['search' => ['key_' => $prefixes], 'searchByAny' => true, 'startSearch' => true];
		}
		else {
			// Las tablas MAC/ARP (cientos de ítems de texto) se excluyen en la consulta: pedir su último valor obliga a
			// Zabbix a leer el historial de cada una y era lo que demoraba la consola decenas de segundos.
			$options += ['search' => ['key_' => MacTable::KEY_PREFIX], 'startSearch' => true, 'excludeSearch' => true];
		}
		foreach (API::Item()->get($options) as $item) {
			// Las tablas MAC/ARP solo las lee el localizador, nunca la consola.
			if (str_starts_with((string) $item['key_'], MacTable::KEY_PREFIX)) continue;
			$by_host[(string) $item['hostid']][] = $item;
		}
		return $by_host;
	}

	/**
	 * Problemas activos con la severidad real del evento, el reconocimiento y la supresión.
	 * @return array ['items' => itemid => problemas, 'hosts' => hostid => [cantidad, máxima, sin reconocer]]
	 */
	private function loadProblems(array $hostids): array {
		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'priority'],
			'hostids' => $hostids,
			'monitored' => true,
			'filter' => ['value' => TRIGGER_VALUE_TRUE],
			'selectFunctions' => ['itemid'],
			'selectHosts' => ['hostid'],
			'expandDescription' => true
		]);
		if (!$triggers) return ['items' => [], 'hosts' => []];

		$events = [];
		foreach (API::Problem()->get([
			'output' => ['eventid', 'objectid', 'name', 'severity', 'acknowledged', 'suppressed', 'clock'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => array_column($triggers, 'triggerid')
		]) as $problem) {
			$events[(string) $problem['objectid']][] = $problem;
		}

		$by_item = [];
		$by_host = [];
		foreach ($triggers as $trigger) {
			$triggerid = (string) $trigger['triggerid'];
			$list = $events[$triggerid] ?? [[
				'eventid' => '', 'name' => $trigger['description'], 'severity' => $trigger['priority'],
				'acknowledged' => 0, 'suppressed' => 0, 'clock' => 0
			]];
			foreach ($list as $problem) {
				$entry = [
					'triggerid' => $triggerid,
					'eventid' => (string) $problem['eventid'],
					'name' => (string) $problem['name'],
					'severity' => (int) $problem['severity'],
					'acknowledged' => (int) $problem['acknowledged'] === 1,
					'suppressed' => (int) ($problem['suppressed'] ?? 0) === 1,
					'clock' => (int) ($problem['clock'] ?? 0)
				];
				foreach ($trigger['functions'] ?? [] as $function) {
					$by_item[(string) $function['itemid']][$triggerid.':'.$entry['eventid']] = $entry;
				}
				if ($entry['suppressed']) continue;
				foreach ($trigger['hosts'] ?? [] as $trigger_host) {
					$hostid = (string) $trigger_host['hostid'];
					$by_host[$hostid] ??= ['count' => 0, 'max' => -1, 'unacked' => 0];
					$by_host[$hostid]['count']++;
					$by_host[$hostid]['max'] = max($by_host[$hostid]['max'], $entry['severity']);
					if (!$entry['acknowledged']) $by_host[$hostid]['unacked']++;
				}
			}
		}
		return ['items' => $by_item, 'hosts' => $by_host];
	}

	// ------------------------------------------------------------------ equipo

	private function buildDevice(array $host, array $items, array $problems, bool $full = true): array {
		$hostid = (string) $host['hostid'];
		$problems_by_item = $problems['items'] ?? [];
		$host_problems = $problems['hosts'][$hostid] ?? ['count' => 0, 'max' => -1, 'unacked' => 0];
		$primary = $this->primaryInterface($host);
		$summary = $this->deviceSummary($items);
		// Ubicación: primero sysLocation de SNMP; si el equipo no la informa, el inventario del host en Zabbix.
		$summary['location_source'] = $summary['location'] !== '' ? 'item' : '';
		if ($summary['location'] === '') {
			$summary['location'] = $this->inventoryLocation($host);
			if ($summary['location'] !== '') $summary['location_source'] = 'inventory';
		}

		$device = [
			'hostid' => $hostid,
			'name' => (string) $host['name'],
			'host' => $host,
			'ip' => $primary,
			'role' => $this->hostRole($host),
			'region' => $this->regionOf($host, $summary['location']),
			'full' => $full,
			'summary' => $summary,
			'ports' => [],
			'hardware_sensors' => [],
			'topology_neighbors' => $this->topologyNeighbors($items),
			'templates' => array_values(array_map(static fn(array $template): string => (string) $template['name'],
				$host['parentTemplates'] ?? [])),
			'template' => $this->mainTemplate($host),
			'message' => ''
		];

		if ($full && (int) ($this->fields_values['show_hardware_health'] ?? 1) === 1) {
			$device['hardware_sensors'] = $this->hardwareSensors($items, $problems_by_item);
		}

		$matchers = $this->portMatchers($full);
		$ports = [];
		$priority = [];
		foreach ($items as $item) {
			$key = (string) $item['key_'];
			$base = strstr($key, '[', true);
			$list = $base === false ? [] : ($matchers[$base] ?? []);
			if (isset($matchers['*'])) $list = array_merge($list, $matchers['*']);
			foreach ($list as [$metric, $rank, $regex, $remap]) {
				if (preg_match($regex, $key, $match) !== 1) continue;
				$index = (string) $match[1];
				// Se conserva la métrica ya cargada por una clave de mayor prioridad (configurada o de una plantilla más nueva).
				if (isset($priority[$index][$metric]) && $priority[$index][$metric] <= $rank) break;
				$priority[$index][$metric] = $rank;
				$ports[$index] ??= $this->emptyPort($index);
				$port = &$ports[$index];
				$value = $item['lastvalue'];
				if ($remap !== null && array_key_exists((string) $value, $remap)) $value = $remap[(string) $value];
				$port[$metric] = $value;
				$port['raw'][$metric] = [
					'units' => (string) $item['units'],
					'clock' => (int) ($item['lastclock'] ?? 0),
					'prev' => $item['prevvalue'] ?? null,
					'item_name' => (string) $item['name'],
					'key' => (string) $item['key_'],
					'itemid' => (string) $item['itemid'],
					'mapped' => $this->mapValue($item)
				];
				$this->inferIdentity($port, (string) $item['name']);
				foreach ($problems_by_item[(string) $item['itemid']] ?? [] as $key => $problem) {
					$port['problems'][$key] = $problem;
				}
				unset($port);
				break;
			}
		}

		$include = Profiles::regex((string) ($this->fields_values['include_regex'] ?? ''));
		$exclude = Profiles::regex((string) ($this->fields_values['exclude_regex'] ?? ''));
		$uptime_seconds = $summary['uptime_seconds'];
		$result = [];
		foreach ($ports as $index => $port) {
			$port['name'] = trim((string) ($port['name'] ?? '')) ?: '#'.$index;
			if ($include !== null && preg_match($include, $port['name']) !== 1) continue;
			if ($exclude !== null && preg_match($exclude, $port['name']) === 1) continue;
			$result[] = $this->finalizePort($port, $hostid, $uptime_seconds, $full);
		}
		usort($result, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
		$device['ports'] = $result;

		if (!$result) {
			$device['message'] = Lang::t('No physical interfaces matched. Check the item key patterns and interface filters.');
			if ($device['template'] !== '') $device['message'] .= ' '.sprintf(Lang::t('Linked template: %1$s.'), $device['template']);
		}

		$device['counts'] = $this->portCounts($result);
		$device['kpi'] = $this->deviceKpi($result, $device['hardware_sensors']);
		$device['attention'] = $this->attentionList($result);
		$device['layout'] = $this->resolveLayout($result);
		$device['freshness'] = $this->freshness($items, $ports);

		$availability = array_map(static fn(array $interface): int => (int) ($interface['available'] ?? 0),
			$host['interfaces'] ?? []);
		// Problemas a nivel de host (todos los triggers, no solo los asociados a los ítems cargados).
		$device['kpi']['problem_count'] = max($device['kpi']['problem_count'], $host_problems['count']);
		$device['kpi']['unacked'] = $host_problems['unacked'];
		$device['kpi']['max_severity'] = max($device['kpi']['max_severity'], $host_problems['max']);
		$max_severity = $device['kpi']['max_severity'];
		$device['health'] = (int) $host['maintenance_status'] === 1 ? 'maintenance'
			: (in_array(2, $availability, true) ? 'unavailable'
				: ($max_severity >= 0 ? 'warning'
					: (in_array(1, $availability, true) || $result ? 'available' : 'unknown')));
		$device['health_severity'] = $device['health'] === 'unavailable' ? 5 : $max_severity;
		return $device;
	}

	/** Plantilla de red vinculada al host (primero las plantillas oficiales de Cisco), para mostrar y diagnosticar. */
	private function mainTemplate(array $host): string {
		$names = array_map(static fn(array $template): string => (string) $template['name'], $host['parentTemplates'] ?? []);
		foreach (['/^Cisco /i', '/(SNMP|HTTP)$/i', '/./'] as $regex) {
			foreach ($names as $name) {
				if (preg_match($regex, $name) === 1 && stripos($name, 'LAKA Network Topology') === false) return $name;
			}
		}
		return '';
	}

	private function primaryInterface(array $host): string {
		$primary = $host['interfaces'][0] ?? [];
		foreach ($host['interfaces'] ?? [] as $interface) {
			if ((int) ($interface['main'] ?? 0) === 1) {
				$primary = $interface;
				break;
			}
		}
		$ip = trim((string) ($primary['ip'] ?? ''));
		return $ip !== '' ? $ip : trim((string) ($primary['dns'] ?? ''));
	}

	/** Rol según la etiqueta del host "role" o "laka.role" (core, distribution, access, firewall, router, ...). */
	private function hostRole(array $host): string {
		foreach ($host['tags'] ?? [] as $tag) {
			if (in_array(strtolower((string) $tag['tag']), ['role', 'laka.role', 'network.role'], true)) {
				$value = strtolower(trim((string) $tag['value']));
				if ($value !== '') return $value;
			}
		}
		return '';
	}

	private function emptyPort(string $index): array {
		$port = array_fill_keys(array_keys(self::PORT_METRICS), null);
		return ['index' => $index, 'name' => '', 'alias' => '', 'problems' => [], 'raw' => []] + $port;
	}

	private function finalizePort(array $port, string $hostid, ?float $uptime_seconds, bool $full = true): array {
		$name = $port['name'];
		$raw = $port['raw'];
		$speed_bps = $this->speedBps($port['speed'], (string) ($raw['speed']['units'] ?? ''));
		$in_bps = $this->rateBps($port['in'], (string) ($raw['in']['units'] ?? ''));
		$out_bps = $this->rateBps($port['out'], (string) ($raw['out']['units'] ?? ''));
		$status = $this->status($port);
		$util = ($speed_bps !== null && $speed_bps > 0)
			? min(100.0, max(0.0, max((float) $in_bps, (float) $out_bps) / $speed_bps * 100.0)) : null;
		$problems = array_values($port['problems']);
		usort($problems, static fn(array $a, array $b): int => $b['severity'] <=> $a['severity']);
		$max_severity = $problems ? max(array_column($problems, 'severity')) : -1;
		$type = $this->portType($name);
		$num = static fn($value): ?float => is_numeric($value) ? (float) $value : null;

		$lastchange_text = '';
		if (is_numeric($port['lastchange'])) {
			$value = (float) $port['lastchange'];
			$units = strtolower((string) ($raw['lastchange']['units'] ?? ''));
			$seconds = in_array($units, ['uptime', 's', 'unixtime'], true) ? $value : $value / 100;
			if ($units === 'unixtime') $lastchange_text = $this->formatDuration(time() - $seconds);
			elseif ($uptime_seconds !== null && $uptime_seconds >= $seconds) {
				$lastchange_text = $this->formatDuration($uptime_seconds - $seconds);
			}
		}

		$result = [
			'index' => (string) $port['index'],
			'hostid' => $hostid,
			'name' => $name,
			'key' => $this->normalizeIfName($name),
			'alias' => trim((string) $port['alias']),
			'status' => $status,
			'status_inferred' => $status === 'up' && (!is_numeric($port['oper']) || (int) $port['oper'] !== 1),
			'admin' => is_numeric($port['admin']) ? (int) $port['admin'] : null,
			'oper' => is_numeric($port['oper']) ? (int) $port['oper'] : null,
			'type' => $type,
			'kind' => $type === 'uplink' ? 'sfp' : 'rj45',
			'member' => $this->stackMember($name),
			'module' => $this->moduleNumber($name),
			'number' => $this->physicalNumber($name),
			'speed_bps' => $speed_bps,
			'speed_text' => $speed_bps === null ? '—' : $this->humanRate($speed_bps),
			'slow' => $status === 'up' && $speed_bps !== null && $speed_bps < 1e9
				&& preg_match('/^(gi|te|tw|twe|fi)/i', $name) === 1,
			'in_bps' => $in_bps,
			'out_bps' => $out_bps,
			'in_text' => $in_bps === null ? '—' : $this->humanRate($in_bps),
			'out_text' => $out_bps === null ? '—' : $this->humanRate($out_bps),
			'util' => $util === null ? null : round($util, 1),
			'util_level' => $util === null ? '' : ($util >= $this->util_critical ? 'high' : ($util >= $this->util_warning ? 'warn' : '')),
			'errors_in' => $num($port['errors_in']),
			'errors_out' => $num($port['errors_out']),
			'discards_in' => $num($port['discards_in']),
			'discards_out' => $num($port['discards_out']),
			'errors_units' => (string) ($raw['errors_in']['units'] ?? $raw['errors_out']['units'] ?? ''),
			'duplex' => $this->enumText('duplex', $port['duplex']),
			'half_duplex' => is_numeric($port['duplex']) && (int) $port['duplex'] === 2,
			'vlan' => $this->displayValue($port['vlan'], $raw['vlan'] ?? []),
			'trunk' => $this->enumText('trunk', $port['trunk']),
			'poe_status' => $this->displayValue($port['poe_status'], $raw['poe_status'] ?? []),
			'poe_power' => $num($port['poe_power']),
			'poe_units' => (string) ($raw['poe_power']['units'] ?? 'W'),
			'lastchange' => $lastchange_text,
			'optic_rx' => $num($port['optic_rx']),
			'optic_tx' => $num($port['optic_tx']),
			'optic_level' => $this->opticLevel($num($port['optic_rx'])),
			'problems' => $problems,
			'max_severity' => $max_severity,
			'in_itemid' => (string) ($raw['in']['itemid'] ?? ''),
			'out_itemid' => (string) ($raw['out']['itemid'] ?? ''),
			'neighbor' => null,
			'detail_rows' => $full ? $this->detailRows($port) : []
		];
		// Uplinks que vale la pena vigilar: en uso o documentados. Las ranuras de módulo sin uso no son alarmas.
		$result['watched_uplink'] = $type === 'uplink' && ($status === 'up' || $result['alias'] !== '');
		$result['errors_total'] = (float) ($result['errors_in'] ?? 0) + (float) ($result['errors_out'] ?? 0);
		$result['discards_total'] = (float) ($result['discards_in'] ?? 0) + (float) ($result['discards_out'] ?? 0);
		return $result;
	}

	/** Nivel de la potencia óptica recibida: '' normal, 'warn', 'high' (crítico) o 'dark' (sin luz). */
	private function opticLevel(?float $rx): string {
		if ($rx === null) return '';
		if ($rx <= -40) return 'dark';
		return $rx < -18 ? 'high' : ($rx < -14 ? 'warn' : '');
	}

	private function portCounts(array $ports): array {
		$counts = ['up' => 0, 'down' => 0, 'disabled' => 0, 'unknown' => 0, 'problems' => 0, 'total' => count($ports)];
		foreach ($ports as $port) {
			$counts[$port['status']]++;
			if ($port['problems']) $counts['problems']++;
		}
		return $counts;
	}

	private function deviceKpi(array $ports, array $sensors): array {
		$uplinks_used = array_values(array_filter($ports, static fn(array $port): bool => $port['watched_uplink']
			&& $port['status'] !== 'disabled'));
		$top = null;
		$errors = $high_util = $poe_ports = $optics = $optics_bad = 0;
		$poe_power = 0.0;
		$poe_units = 'W';
		$max_severity = -1;
		foreach ($ports as $port) {
			if ($port['util'] !== null && ($top === null || $port['util'] > $top['util'])) $top = $port;
			if ($port['errors_total'] > 0 || $port['discards_total'] > 0) $errors++;
			if ($port['util_level'] === 'high') $high_util++;
			if ($port['poe_power'] !== null && $port['poe_power'] > 0) {
				$poe_ports++;
				$poe_power += $port['poe_power'];
				$poe_units = $port['poe_units'] ?: 'W';
			}
			if ($port['optic_rx'] !== null && $port['optic_level'] !== 'dark') {
				$optics++;
				if ($port['optic_level'] !== '') $optics_bad++;
			}
			$max_severity = max($max_severity, $port['max_severity']);
		}
		foreach ($sensors as $sensor) {
			foreach ($sensor['problems'] as $problem) $max_severity = max($max_severity, $problem['severity']);
		}
		$hardware = ['ok' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0];
		foreach ($sensors as $sensor) $hardware[$sensor['health']]++;
		return [
			'uplinks_total' => count($uplinks_used),
			'uplinks_up' => count(array_filter($uplinks_used, static fn(array $port): bool => $port['status'] === 'up')),
			'top_util' => $top === null ? null : ['name' => $top['name'], 'util' => $top['util'], 'index' => $top['index'],
				'level' => $top['util_level']],
			'error_ports' => $errors,
			'high_util_ports' => $high_util,
			'poe_ports' => $poe_ports,
			'poe_power' => $poe_ports ? round($poe_power, 1) : null,
			'poe_units' => $poe_units,
			'optics' => $optics,
			'optics_bad' => $optics_bad,
			'max_severity' => $max_severity,
			'problem_count' => array_sum(array_map(static fn(array $port): int => count($port['problems']), $ports))
				+ array_sum(array_map(static fn(array $sensor): int => count($sensor['problems']), $sensors)),
			'hardware' => $hardware
		];
	}

	/** Puertos que merecen atención, los más importantes primero. */
	private function attentionList(array $ports): array {
		$list = [];
		foreach ($ports as $port) {
			$score = 0;
			$reasons = [];
			if ($port['max_severity'] >= 0) {
				$score += 100 + $port['max_severity'] * 10;
				$reasons[] = $port['problems'][0]['name'];
			}
			if ($port['watched_uplink'] && $port['status'] === 'down') {
				$score += 90;
				$reasons[] = Lang::t('Uplink down');
			}
			if ($port['util_level'] === 'high') {
				$score += 60;
				$reasons[] = sprintf(Lang::t('Utilization %1$s%%'), number_format((float) $port['util'], 0));
			}
			if ($port['errors_total'] > 0) {
				$score += 40;
				$reasons[] = Lang::t('Interface errors');
			}
			if ($port['half_duplex']) {
				$score += 35;
				$reasons[] = Lang::t('Half duplex');
			}
			if ($port['slow']) {
				$score += 20;
				$reasons[] = sprintf(Lang::t('Negotiated at %1$s'), $port['speed_text']);
			}
			if ($port['discards_total'] > 0 && $port['errors_total'] <= 0) {
				$score += 10;
				$reasons[] = Lang::t('Discards');
			}
			if ($score > 0) {
				$list[] = ['index' => $port['index'], 'name' => $port['name'], 'alias' => $port['alias'],
					'status' => $port['status'], 'severity' => $port['max_severity'], 'score' => $score,
					'reason' => implode(' · ', array_unique($reasons))];
			}
		}
		usort($list, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: strnatcasecmp($a['name'], $b['name']));
		return array_slice($list, 0, 8);
	}

	private function resolveLayout(array $ports): string {
		$mode = (int) ($this->fields_values['layout_mode'] ?? 0);
		if ($mode === 1) return 'grid';
		if ($mode === 2) return 'physical';
		$chassis = array_filter($ports, static fn(array $port): bool => preg_match('~^[A-Za-z]+\d+/\d+/\d+$~', $port['name']) === 1);
		return count($ports) > 0 && count($chassis) / count($ports) >= .6 ? 'physical' : 'grid';
	}

	/** Antigüedad de la muestra de interfaz más reciente (cualquier plantilla detectada); ítems de resumen si no hay interfaces. */
	private function freshness(array $items, array $ports = []): array {
		$latest = 0;
		foreach ($ports as $port) {
			foreach ($port['raw'] ?? [] as $raw) $latest = max($latest, (int) ($raw['clock'] ?? 0));
		}
		if ($latest === 0) {
			foreach ($items as $item) {
				if (!str_starts_with((string) $item['key_'], 'laka.topology.')) $latest = max($latest, (int) ($item['lastclock'] ?? 0));
			}
		}
		return ['clock' => $latest, 'age' => $latest > 0 ? time() - $latest : null,
			'stale' => $latest > 0 && time() - $latest > self::STALE_SECONDS];
	}

	private function selectedIds(string $field): array {
		$value = $this->fields_values[$field] ?? [];
		$ids = [];
		$stack = [$value];
		while ($stack) {
			$current = array_pop($stack);
			if (is_array($current)) {
				foreach ($current as $nested) $stack[] = $nested;
			}
			elseif (is_scalar($current) && ctype_digit(trim((string) $current)) && (int) $current > 0) {
				$ids[] = trim((string) $current);
			}
		}
		return array_values(array_unique($ids));
	}

	private function wildcardRegex(string $pattern): string {
		$pattern = trim($pattern);
		if ($pattern === '' || substr_count($pattern, '*') !== 1) return '';
		$quoted = preg_quote($pattern, '~');
		// "*" entre comillas = clave de descubrimiento de texto de las plantillas HTTP (nombre de interfaz); si no, {#SNMPINDEX}.
		$quoted = str_replace('"\\*"', '"([^"]+)"', $quoted, $count);
		if ($count === 0) $quoted = str_replace('\\*', '([0-9]+)', $quoted);
		return '~^'.$quoted.'$~';
	}

	/** Buscadores de interfaz para cada clave configurada e incorporada, indexados por base de clave para mayor velocidad. */
	private function portMatchers(bool $full): array {
		$cache_key = $full ? 'full' : 'overview';
		if (isset($this->matchers[$cache_key])) return $this->matchers[$cache_key];
		$fields = [];
		foreach (self::PORT_METRICS as $metric => $field) {
			if (!$full && !in_array($metric, self::OVERVIEW_METRICS, true)) continue;
			$fields[$field] = $this->patterns[$field] ?? '';
		}
		$metric_of = array_flip(self::PORT_METRICS);
		$index = [];
		foreach (Profiles::portCandidates($fields, $this->auto_detect) as $field => $list) {
			foreach ($list as $rank => [$pattern, $remap]) {
				$regex = $this->wildcardRegex($pattern);
				if ($regex === '') continue;
				$base = strstr($pattern, '[', true);
				$base = $base === false || str_contains($base, '*') ? '*' : $base;
				$index[$base][] = [$metric_of[$field], $rank, $regex, $remap];
			}
		}
		return $this->matchers[$cache_key] = $index;
	}

	private function keyMatches(string $key, string $pattern): bool {
		$pattern = trim($pattern);
		if ($pattern === '') return false;
		return preg_match('~^'.str_replace('\\*', '.*', preg_quote($pattern, '~')).'$~', $key) === 1;
	}

	private function emptySummary(): array {
		return ['model' => '', 'location' => '', 'location_source' => '', 'uptime' => '', 'uptime_seconds' => null,
			'cpu' => null, 'memory' => null, 'serial' => '', 'os' => '', 'os_full' => '', 'icmp_ping' => null,
			'icmp_loss' => null, 'icmp_rtt' => null, 'poe_budget' => null, 'poe_used' => null];
	}

	/**
	 * Modelo, SO, serie, CPU, memoria, ubicación, uptime e ICMP de un equipo. Para cada valor gana la primera clave
	 * candidata que exista en el host (primero la configurada, luego las de las plantillas oficiales de Zabbix).
	 */
	private function deviceSummary(array $items): array {
		$fresh = time() - 86400;
		// Índice por base de clave (texto antes de "["), así cada candidata solo prueba los ítems que pueden coincidir.
		$by_base = [];
		foreach ($items as $item) {
			$key = (string) $item['key_'];
			if (str_ends_with($key, '.walk') || str_ends_with($key, '.get')) continue;
			$bracket = strpos($key, '[');
			$by_base[$bracket === false ? $key : substr($key, 0, $bracket)][] = $item;
		}
		$found = [];
		foreach ($this->summary_candidates as $metric => $patterns) {
			$values = [];
			foreach ($patterns as $pattern) {
				$pattern = trim((string) $pattern);
				if ($pattern === '') continue;
				$star = strpos($pattern, '*');
				$literal = $star === false ? $pattern : substr($pattern, 0, $star);
				$bracket = strpos($literal, '[');
				// Ítems candidatos: la misma base cuando la base es literal; si no, las bases que empiezan con el prefijo literal.
				$candidates = [];
				if ($bracket !== false || $star === false) {
					$base = $bracket !== false ? substr($literal, 0, $bracket) : $literal;
					$candidates = $by_base[$base] ?? [];
				}
				else {
					foreach ($by_base as $base => $list) {
						if (str_starts_with((string) $base, $literal)) array_push($candidates, ...$list);
					}
				}
				if (!$candidates) continue;
				$regex = '~^'.str_replace('\\*', '.*', preg_quote($pattern, '~')).'$~';
				foreach ($candidates as $item) {
					$key = (string) $item['key_'];
					if (($star === false ? $key !== $pattern : preg_match($regex, $key) !== 1)) continue;
					$value = $item['lastvalue'] ?? null;
					if ($value === null || $value === '') continue;
					if ($metric === 'uptime' && (!is_numeric($value) || (float) $value <= 0
							|| (int) ($item['lastclock'] ?? 0) < $fresh)) continue;
					$values[] = ['value' => $value, 'key' => $key, 'units' => (string) ($item['units'] ?? '')];
				}
				// Gana la primera candidata con valor (clave configurada, luego las plantillas oficiales en orden).
				if ($values) break;
			}
			$found[$metric] = $values;
		}

		$first = static fn(string $metric): string => trim((string) ($found[$metric][0]['value'] ?? ''));
		$max = static function(array $list): ?float {
			$numbers = array_map('floatval', array_filter(array_column($list, 'value'), 'is_numeric'));
			return $numbers ? max($numbers) : null;
		};
		$summary = $this->emptySummary();

		$model = $first('model');
		$model_key = (string) ($found['model'][0]['key'] ?? '');
		if ($model_key === 'cisco.asav.model') {
			// ASAv informa sysDescr: "Cisco Adaptive Security Appliance Version 9.x" -> "Cisco ASAv".
			$model = preg_match('/Adaptive Security Appliance/i', $model) === 1 ? 'Cisco ASA' : strtok($model, "\n");
		}
		$summary['model'] = mb_substr(trim((string) $model), 0, 80);

		$summary['serial'] = mb_substr($first('serial'), 0, 40);
		$descr = '';
		foreach ($items as $item) {
			if (in_array((string) $item['key_'], ['system.descr[sysDescr.0]', 'system.descr', 'cisco.asav.model'], true)) {
				$descr = (string) $item['lastvalue'];
				break;
			}
		}
		$os_key = (string) ($found['os'][0]['key'] ?? '');
		$hint = str_starts_with($os_key, 'sd_wan.') ? 'SD-WAN' : (str_starts_with($os_key, 'cisco.ftd.') ? 'FTD' : '');
		[$summary['os'], $summary['os_full']] = $this->osVersion($first('os'), $descr, $hint);

		$summary['cpu'] = $max($found['cpu']);
		$summary['memory'] = $max($found['memory']);
		$summary['location'] = $first('location');
		$uptime = $max($found['uptime']);
		$summary['uptime_seconds'] = $uptime;
		$summary['uptime'] = $uptime === null ? '' : $this->formatDuration($uptime);

		if (is_numeric($found['icmp_ping'][0]['value'] ?? null)) $summary['icmp_ping'] = (int) $found['icmp_ping'][0]['value'];
		if (is_numeric($found['icmp_loss'][0]['value'] ?? null)) $summary['icmp_loss'] = round((float) $found['icmp_loss'][0]['value'], 1);
		if (is_numeric($found['poe_budget'][0]['value'] ?? null) && (float) $found['poe_budget'][0]['value'] > 0) {
			$summary['poe_budget'] = round((float) $found['poe_budget'][0]['value'], 1);
			$summary['poe_used'] = is_numeric($found['poe_used'][0]['value'] ?? null)
				? round((float) $found['poe_used'][0]['value'], 1) : null;
		}
		if (is_numeric($found['icmp_rtt'][0]['value'] ?? null)) {
			$rtt = (float) $found['icmp_rtt'][0]['value'];
			// icmppingsec se guarda en segundos; se muestra en milisegundos.
			$summary['icmp_rtt'] = round(($found['icmp_rtt'][0]['units'] ?? 's') === 'ms' ? $rtt : $rtt * 1000, 1);
		}
		return $summary;
	}

	/**
	 * Etiqueta corta del SO ("IOS-XE 17.9.4", "NX-OS 10.3(4a)", "ASA 9.18(3)") y el texto completo para el tooltip.
	 * La plantilla oficial de IOS ya reduce sysDescr a la versión; sysDescr aporta la familia.
	 * @return array{0: string, 1: string}
	 */
	private function osVersion(string $os, string $descr, string $hint = ''): array {
		$os = trim($os);
		$descr = trim($descr);
		$text = $descr !== '' ? $descr : $os;
		if ($os === '' && $descr === '') return ['', ''];
		$family = match (true) {
			$hint !== '' => $hint,
			preg_match('/IOS[ -]?XE/i', $text) === 1 => 'IOS-XE',
			preg_match('/IOS[ -]?XR/i', $text) === 1 => 'IOS-XR',
			preg_match('/NX-?OS/i', $text) === 1 => 'NX-OS',
			preg_match('/Adaptive Security Appliance|\bASA\b/i', $text) === 1 => 'ASA',
			preg_match('/\bIOS\b/i', $text) === 1 => 'IOS',
			preg_match('/FortiOS|FortiGate/i', $text) === 1 => 'FortiOS',
			default => ''
		};
		$version = '';
		foreach ([$os, $descr] as $source) {
			if (preg_match('/Version\s+([0-9][^\s,;]*)/i', $source, $m) === 1) {
				$version = $m[1];
				break;
			}
		}
		if ($version === '' && $os !== '' && mb_strlen($os) <= 40 && preg_match('/^[0-9vV][^\s]*$/', $os) === 1) {
			$version = $os;
		}
		if ($version === '' && $os !== '' && mb_strlen($os) <= 40) $version = $os;
		$label = trim($family.' '.$version);
		if ($label === '') $label = mb_substr(strtok($text, "\n") ?: '', 0, 40);
		$full = $descr !== '' ? $descr : $os;
		return [mb_substr($label, 0, 48), mb_substr($full, 0, 500)];
	}

	private function formatDuration(float $seconds): string {
		$seconds = max(0, (int) $seconds);
		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$minutes = intdiv($seconds % 3600, 60);
		return $days > 0 ? $days.'d '.$hours.'h' : ($hours > 0 ? $hours.'h '.$minutes.'m' : $minutes.'m');
	}

	private function inferIdentity(array &$port, string $item_name): void {
		// El nombre y el alias salen del primer ítem "Interface X(alias): ..."; los demás ítems del puerto lo repiten.
		if (isset($port['identity_done'])) return;
		// SD-WAN 'Interface ["ge0/0"]: ...' y FTD "Interface [outside][GigabitEthernet0/0]: ...".
		if (preg_match('/^Interface\s+\["?([^"\]]+)"?\](?:\[([^\]]*)\])?:\s*/i', $item_name, $m) === 1) {
			if ((string) $port['name'] === '') $port['name'] = trim($m[1]);
			if ((string) $port['alias'] === '' && trim((string) ($m[2] ?? '')) !== '') $port['alias'] = trim($m[2]);
			$port['identity_done'] = true;
			return;
		}
		if (preg_match('/^Interface\s+(.+?)\((.*?)\):\s*/i', $item_name, $m) !== 1
				&& preg_match('/^Interface\s+(.+?):\s*/i', $item_name, $m) !== 1) return;
		$port['identity_done'] = true;
		$candidate = trim((string) $m[1]);
		if ((string) $port['name'] === '' && $candidate !== '') $port['name'] = $candidate;
		if ((string) $port['alias'] === '' && isset($m[2]) && trim((string) $m[2]) !== '') $port['alias'] = trim((string) $m[2]);
	}

	/** Texto mapeado del valor de un ítem según su mapa de valores, o null. */
	private function mapValue(array $item): ?string {
		$value = (string) ($item['lastvalue'] ?? '');
		foreach ($item['valuemap']['mappings'] ?? [] as $mapping) {
			$type = (int) ($mapping['type'] ?? 0);
			if (($type === 0 && (string) $mapping['value'] === $value) || $type === 5) {
				return (string) $mapping['newvalue'];
			}
		}
		return null;
	}

	private function displayValue(mixed $value, array $raw): string {
		if ($value === null || $value === '') return '';
		return ($raw['mapped'] ?? null) !== null ? (string) $raw['mapped'] : (string) $value;
	}

	private function hardwareSensors(array $items, array $problems_by_item): array {
		$patterns = [
			'temperature' => Profiles::regex((string) ($this->fields_values['temperature_sensor_regex'] ?? '(temperature|temp sensor|hotspot)')),
			'fan' => Profiles::regex((string) ($this->fields_values['fan_sensor_regex'] ?? '(^|[^a-z])fan([ :_]|$)')),
			'psu' => Profiles::regex((string) ($this->fields_values['psu_sensor_regex'] ?? '(power supply|psu)')),
			'other' => Profiles::regex((string) ($this->fields_values['other_sensor_regex'] ?? '(voltage|humidity|power consumption)'))
		];
		$warning = (float) ($this->fields_values['temperature_warning'] ?? 50);
		$critical = (float) ($this->fields_values['temperature_critical'] ?? 60);
		$sensors = [];
		$status_items = [];
		foreach ($items as $item) {
			$name = (string) $item['name'];
			$key = (string) $item['key_'];
			if (preg_match('/^Interface\s+/i', $name) === 1 || str_starts_with($key, 'net.if.')
					|| str_contains($key, '.net.if.') || str_ends_with($key, '.walk')
					|| preg_match('/SNMP\s+walk/i', $name) === 1) continue;
			// Primero las claves de las plantillas oficiales (IOS, Catalyst, Nexus, FTD), luego las expresiones de nombre/clave.
			$type = null;
			if (preg_match('/^(?:cisco\.ftd\.)?sensor\.(temp|fan|psu)\.(value|status|rpm|pwr)\[(.*)\]$/', $key, $km) === 1) {
				$type = ['temp' => 'temperature', 'fan' => 'fan', 'psu' => 'psu'][$km[1]];
				// Los ítems de estado de temperatura se unen al valor de temperatura con el mismo índice.
				if ($km[1] === 'temp' && $km[2] === 'status') {
					$status_items[$this->sensorIndex($km[3])] = $item;
					continue;
				}
			}
			else {
				foreach ($patterns as $candidate => $regex) {
					if ($regex !== null && preg_match($regex, $name.' '.$key) === 1) {
						$type = $candidate;
						break;
					}
				}
			}
			if ($type === null) continue;
			$itemid = (string) $item['itemid'];
			$problems = array_values($problems_by_item[$itemid] ?? []);
			$mapped = $this->mapValue($item);
			$health = (int) ($item['lastclock'] ?? 0) < time() - 86400 ? 'unknown' : 'ok';
			if ($problems) {
				$health = max(array_column($problems, 'severity')) >= 4 ? 'critical' : 'warning';
			}
			elseif ($mapped !== null) {
				$health = $this->stateHealth($mapped, $health);
			}
			elseif ($type === 'temperature' && is_numeric($item['lastvalue'])) {
				$value = (float) $item['lastvalue'];
				$health = $value >= $critical ? 'critical' : ($value >= $warning ? 'warning' : 'ok');
			}
			$value = (string) $item['lastvalue'];
			// El prefijo "!" en las unidades solo desactiva la conversión de unidades en Zabbix.
			$units = ltrim((string) $item['units'], '!');
			$display = $mapped !== null ? $mapped : (is_numeric($value) && (int) $item['value_type'] === 0
				? rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.') : $value);
			$sensors[] = [
				'itemid' => $itemid, 'name' => $name, 'key' => $key,
				'index' => isset($km[3]) && $type === 'temperature' && str_starts_with($key, 'sensor.')
					? $this->sensorIndex($km[3]) : null,
				'value' => trim($display.($mapped === null ? ' '.$units : '')),
				'raw' => $value, 'units' => $units,
				'clock' => (int) $item['lastclock'],
				'numeric' => in_array((int) $item['value_type'], [0, 3], true) && $mapped === null,
				'type' => $type, 'health' => $health, 'problems' => $problems,
				'percent' => $type === 'temperature' && is_numeric($value) && $critical > 0
					? max(0, min(100, (float) $value / ($critical * 1.25) * 100)) : null
			];
			unset($km);
			if (count($sensors) >= 48) break;
		}

		// El estado de temperatura (ciscoEnvMonTemperatureState / entSensorStatus) eleva la salud de su valor.
		$rank = ['unknown' => 0, 'ok' => 1, 'warning' => 2, 'critical' => 3];
		$merged = [];
		foreach ($sensors as &$sensor) {
			$index = $sensor['index'] ?? null;
			if ($index === null || !isset($status_items[$index])) continue;
			$status = $status_items[$index];
			$text = $this->mapValue($status) ?? (string) $status['lastvalue'];
			$health = $this->stateHealth($text, 'unknown');
			$sensor['state'] = $text;
			if ($rank[$health] > $rank[$sensor['health']] && $health !== 'unknown') $sensor['health'] = $health;
			foreach ($problems_by_item[(string) $status['itemid']] ?? [] as $problem) {
				$sensor['problems'][] = $problem;
				$sensor['health'] = (int) $problem['severity'] >= 4 ? 'critical' : 'warning';
			}
			$merged[$index] = true;
		}
		unset($sensor);
		// Un estado sin valor (raro) igual se muestra.
		foreach (array_diff_key($status_items, $merged) as $status) {
			$mapped = $this->mapValue($status);
			$sensors[] = [
				'itemid' => (string) $status['itemid'], 'name' => (string) $status['name'], 'key' => (string) $status['key_'],
				'index' => null, 'value' => $mapped ?? (string) $status['lastvalue'], 'raw' => (string) $status['lastvalue'],
				'units' => '', 'clock' => (int) $status['lastclock'], 'numeric' => false, 'type' => 'temperature',
				'health' => $this->stateHealth($mapped ?? '', 'unknown'),
				'problems' => array_values($problems_by_item[(string) $status['itemid']] ?? []), 'percent' => null
			];
		}
		return $sensors;
	}

	/** Índice del parámetro de la clave de un sensor: "ciscoEnvMonTemperatureValue.1006" y "1006" dan "1006". */
	private function sensorIndex(string $parameter): string {
		return preg_match('/([0-9]+)$/', trim($parameter, '"'), $m) === 1 ? $m[1] : trim($parameter, '"');
	}

	private function stateHealth(string $text, string $fallback): string {
		$text = strtolower($text);
		if (preg_match('/not ?present|notpresent|absent|unavailable|offadmin/', $text) === 1) return 'unknown';
		// ciscoEnvMon (normal, warning, critical, shutdown, notFunctioning), CISCO-ENTITY-FRU-CONTROL
		// (on, off*, failed, onButFanFail, onButInlinePowerFail) y CISCO-ENTITY-SENSOR (ok, nonoperational).
		if (preg_match('/onbut|warn|minor|degrad/', $text) === 1) return 'warning';
		if (preg_match('/crit|fail|shutdown|notfunction|not functioning|nonoper|major|fault|down|bad|off/', $text) === 1) return 'critical';
		if (preg_match('/normal|ok|good|on|up|present|running/', $text) === 1) return 'ok';
		return $fallback;
	}

	// ------------------------------------------------------------------ utilidades de puertos

	private function portType(string $name): string {
		if (preg_match('/^(Fo|Hu)/i', $name) === 1) return 'uplink';
		if (preg_match('~^[A-Za-z]+\d+/(\d+)/~', $name, $m) === 1 && (int) $m[1] > 0) return 'uplink';
		return 'ethernet';
	}

	private function stackMember(string $name): int {
		return preg_match('~^[A-Za-z]+(\d+)/~', $name, $m) === 1 ? max(1, (int) $m[1]) : 1;
	}

	private function moduleNumber(string $name): int {
		return preg_match('~^[A-Za-z]+\d+/(\d+)/~', $name, $m) === 1 ? (int) $m[1] : 0;
	}

	private function physicalNumber(string $name): ?int {
		return preg_match('~(\d+)$~', $name, $match) === 1 ? (int) $match[1] : null;
	}

	private function status(array $port): string {
		$admin = is_numeric($port['admin']) ? (int) $port['admin'] : null;
		$oper = is_numeric($port['oper']) ? (int) $port['oper'] : null;
		if ($admin === 2) return 'disabled';
		if ($oper === 1) return 'up';
		if ($oper !== null) return 'down';
		if ($this->hasRecentTraffic($port)) return 'up';
		return 'unknown';
	}

	private function hasRecentTraffic(array $port): bool {
		foreach (['in', 'out'] as $metric) {
			$value = $port[$metric] ?? null;
			$clock = (int) ($port['raw'][$metric]['clock'] ?? 0);
			if (is_numeric($value) && (float) $value > 0 && $clock >= time() - 600) return true;
		}
		return false;
	}

	private function speedBps(mixed $value, string $units): ?float {
		if (!is_numeric($value) || (float) $value <= 0) return null;
		return str_contains(strtolower($units), 'bps') ? (float) $value : (float) $value * 1000000;
	}

	private function rateBps(mixed $value, string $units): ?float {
		if (!is_numeric($value)) return null;
		$bps = (float) $value;
		if (preg_match('~(^|[^b])B/s~', $units) === 1 || $units === 'B') $bps *= 8;
		return $bps;
	}

	private function humanRate(float $bps): string {
		foreach ([1e12 => 'Tbps', 1e9 => 'Gbps', 1e6 => 'Mbps', 1e3 => 'Kbps'] as $n => $label) {
			if ($bps >= $n) return number_format($bps / $n, $bps / $n >= 10 ? 0 : 1).' '.$label;
		}
		return number_format($bps, 0).' bps';
	}

	private function enumText(string $metric, mixed $value): string {
		if ($value === null || $value === '') return '';
		$maps = [
			'oper' => [1 => 'up', 2 => 'down', 3 => 'testing', 4 => 'unknown', 5 => 'dormant', 6 => 'notPresent', 7 => 'lowerLayerDown'],
			'admin' => [1 => 'up', 2 => 'down', 3 => 'testing'],
			'duplex' => [1 => 'unknown', 2 => 'half', 3 => 'full'],
			'iftype' => [6 => 'ethernetCsmacd', 24 => 'softwareLoopback', 53 => 'propVirtual', 117 => 'gigabitEthernet', 161 => 'ieee8023adLag'],
			'trunk' => [1 => 'other', 2 => 'isl', 3 => 'dot10', 4 => 'dot1Q', 5 => 'lane', 6 => 'negotiate']
		];
		if (is_numeric($value) && isset($maps[$metric][(int) $value])) return $maps[$metric][(int) $value].' ('.(int) $value.')';
		return (string) $value;
	}

	public static function detailLabels(): array {
		return array_map([Lang::class, 't'], self::DETAIL_LABELS);
	}

	private const DETAIL_LABELS = [
		'oper' => 'Operational status', 'admin' => 'Administrative status', 'speed' => 'Speed',
		'in' => 'Bits received', 'out' => 'Bits sent', 'duplex' => 'Duplex status',
		'errors_in' => 'Inbound errors', 'errors_out' => 'Outbound errors',
		'discards_in' => 'Inbound packets discarded', 'discards_out' => 'Outbound packets discarded',
		'iftype' => 'Interface type', 'trunk' => 'Trunk/VLAN type', 'vlan' => 'Access VLAN',
		'poe_status' => 'PoE status', 'poe_power' => 'PoE power', 'lastchange' => 'Last change'
	];

	private function detailRows(array $port): array {
		$definitions = self::DETAIL_LABELS;
		$rows = [];
		foreach ($definitions as $metric => $label) {
			$value = $port[$metric] ?? null;
			if ($value === null || $value === '') continue;
			$raw = $port['raw'][$metric] ?? [];
			$units = (string) ($raw['units'] ?? '');
			$text = match ($metric) {
				'in', 'out' => $this->humanRate((float) $this->rateBps($value, $units)),
				'speed' => ($bps = $this->speedBps($value, $units)) === null ? '—' : $this->humanRate($bps),
				'oper', 'admin', 'duplex', 'iftype', 'trunk' => ($raw['mapped'] ?? null) ?? $this->enumText($metric, $value),
				default => ($raw['mapped'] ?? null) ?? (is_numeric($value)
					? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').($units !== '' ? ' '.$units : '')
					: (string) $value)
			};
			$previous = $raw['prev'] ?? null;
			$change = '';
			if (is_numeric($value) && is_numeric($previous) && !in_array($metric, ['oper', 'admin', 'duplex', 'iftype', 'trunk'], true)) {
				$delta = (float) $value - (float) $previous;
				$change = in_array($metric, ['in', 'out'], true)
					? ($delta >= 0 ? '+' : '−').$this->humanRate(abs((float) $this->rateBps($delta, $units)))
					: ($delta >= 0 ? '+' : '').rtrim(rtrim(number_format($delta, 2, '.', ''), '0'), '.');
			}
			// Fila compacta: [métrica, hora, valor, cambio]; las etiquetas se envían una sola vez en la configuración del widget.
			$rows[] = [$metric, (int) ($raw['clock'] ?? 0), $text, $change];
		}
		return $rows;
	}

	// ------------------------------------------------------------------ topología

	public function normalizeIfName(string $value): string {
		$value = strtolower(trim((string) preg_replace('/\s+/', '', $value)));
		foreach (self::IFNAME_PREFIXES as $long => $short) {
			if (str_starts_with($value, $long) && preg_match('~^\d~', substr($value, strlen($long))) === 1) {
				return $short.substr($value, strlen($long));
			}
		}
		return $value;
	}

	private function topologyNeighbors(array $items): array {
		$neighbors = [];
		$map = [
			'local.ifname' => 'local_port', 'local.portid' => 'local_port', 'local.portdesc' => 'local_port',
			'remote.name' => 'name', 'remote.address' => 'ip', 'remote.port' => 'remote_port',
			'remote.portid' => 'remote_port', 'remote.portdesc' => 'remote_port_desc',
			'remote.platform' => 'platform', 'remote.description' => 'description',
			'remote.software' => 'software', 'remote.chassis' => 'chassis', 'native.vlan' => 'vlan'
		];
		foreach ($items as $item) {
			$key = (string) $item['key_'];
			if (preg_match('/^laka\.topology\.(cdp|lldp)\.([a-z.]+)\[([^]]+)\]$/', $key, $match) !== 1) continue;
			[, $protocol, $metric, $entry] = $match;
			if (!isset($map[$metric])) continue;
			$id = $protocol.':'.$entry;
			$neighbors[$id] ??= ['key' => $entry, 'protocol' => $protocol, 'name' => '', 'ip' => '', 'local_port' => '',
				'remote_port' => '', 'remote_port_desc' => '', 'platform' => '', 'description' => '', 'software' => '',
				'chassis' => '', 'vlan' => '', 'lastclock' => 0];
			$value = trim((string) ($item['lastvalue'] ?? ''));
			if ($value === '' || preg_match('/^(?:no such|zbx_notsupported|not supported)/i', $value) === 1) continue;
			$field = $map[$metric];
			if ($neighbors[$id][$field] === '') $neighbors[$id][$field] = $value;
			$neighbors[$id]['lastclock'] = max($neighbors[$id]['lastclock'], (int) ($item['lastclock'] ?? 0));
		}
		foreach ($neighbors as &$neighbor) {
			if ($neighbor['name'] === '') $neighbor['name'] = $neighbor['chassis'];
			if ($neighbor['remote_port'] === '' || preg_match('/^([0-9a-f]{2}[:\- ]){5}[0-9a-f]{2}$/i', $neighbor['remote_port']) === 1) {
				// Los ID de puerto LLDP muchas veces son direcciones MAC; se prefiere la descripción del puerto cuando existe.
				if ($neighbor['remote_port_desc'] !== '') $neighbor['remote_port'] = $neighbor['remote_port_desc'];
			}
			$neighbor['ip'] = $this->topologyAddress($neighbor['ip']);
		}
		unset($neighbor);
		return array_values(array_filter($neighbors, static fn(array $neighbor): bool =>
			$neighbor['name'] !== '' || $neighbor['ip'] !== '' || $neighbor['remote_port'] !== ''));
	}

	private function topologyNameAliases(string $value): array {
		$value = strtolower(trim((string) preg_replace('/\s+/', '', $value)));
		if ($value === '') return [];
		$aliases = [$value];
		// Los ID de equipo CDP pueden traer el número de serie entre paréntesis: "SW-CORE(FOC1234)".
		$clean = (string) preg_replace('/\(.*\)$/', '', $value);
		if ($clean !== '' && $clean !== $value) $aliases[] = $clean;
		foreach ([$value, $clean] as $candidate) {
			if (strpos($candidate, '.') !== false && preg_match('/^\d+\.\d+\.\d+\.\d+$/', $candidate) !== 1) {
				$aliases[] = explode('.', $candidate, 2)[0];
			}
		}
		return array_values(array_unique(array_filter($aliases)));
	}

	private function topologyAddress(string $value): string {
		$value = trim($value);
		if (preg_match('/^(?:[0-9A-Fa-f]{2}[ :.-]){3}[0-9A-Fa-f]{2}$/', $value) === 1) {
			return implode('.', array_map(static fn(string $byte): int => hexdec($byte), preg_split('/[ :.-]+/', $value)));
		}
		return $value;
	}

	private function deviceType(string $value): string {
		$value = strtolower($value);
		foreach ([
			'phone' => '/phone|telefono|sip[0-9]|sep[0-9a-f]{6,}/',
			'access_point' => '/access[ -]?point|wireless|air-?ap|\bap[0-9_-]|\bc91[0-9]{2}ax/',
			'firewall' => '/fortigate|firewall|\basa\b|palo alto|checkpoint/',
			'router' => '/\brouter\b|\bisr[0-9]|\basr[0-9]|mikrotik|routeros/',
			'camera' => '/camera|camara|dahua|hikvision|axis/',
			'printer' => '/printer|impresora|lexmark|epson|xerox|ricoh|brother|laserjet/',
			'ups' => '/\bups\b|apc smart/',
			'server' => '/server|servidor|windows|linux|vmware|esxi|proxmox/',
			'switch' => '/\bswitch\b|catalyst|nexus|c9[0-9]{3}|ws-c|sg[0-9]{3}|cbs[0-9]{3}/'
		] as $type => $regex) if (preg_match($regex, $value) === 1) return $type;
		return 'endpoint';
	}

	private function buildTopology(array $devices, bool $with_endpoints = true): array {
		$aliases = [];
		$hosts = [];
		$ports_by_host = [];
		foreach ($devices as $device) {
			$hostid = $device['hostid'];
			foreach ([$device['host']['host'] ?? '', $device['host']['name'] ?? ''] as $name) {
				foreach ($this->topologyNameAliases((string) $name) as $alias) $aliases[$alias] = $hostid;
			}
			foreach ($device['host']['interfaces'] ?? [] as $interface) {
				foreach (['ip', 'dns'] as $field) {
					$value = strtolower(trim((string) ($interface[$field] ?? '')));
					if ($value !== '') $aliases[$value] = $hostid;
				}
			}
			$ports_by_host[$hostid] = [];
			foreach ($device['ports'] as $port) $ports_by_host[$hostid][$port['key']] = $port;
			$role = $device['role'];
			$type = in_array($role, ['firewall', 'router', 'server'], true) ? $role
				: $this->deviceType($device['name'].' '.$device['summary']['model']);
			if ($type === 'endpoint') $type = 'switch';
			$hosts[$hostid] = [
				'id' => $hostid, 'name' => $device['name'], 'ip' => $device['ip'],
				'model' => $device['summary']['model'], 'location' => $device['summary']['location'],
				'health' => $device['health'], 'severity' => $device['health_severity'],
				'up' => $device['counts']['up'], 'down' => $device['counts']['down'], 'total' => $device['counts']['total'],
				'role' => $role, 'type' => $type, 'region' => $device['region'] ?? '',
				'problems' => $device['kpi']['problem_count'] ?? 0,
				// Lista compacta [índice, nombre, alias], usada por el formulario de enlace manual.
				'ports' => array_map(static fn(array $port): array => [$port['index'], $port['name'], mb_substr($port['alias'], 0, 32)],
					$device['ports'])
			];
		}

		$links = [];
		$endpoints = [];
		foreach ($devices as $device) {
			$source = $device['hostid'];
			foreach ($device['topology_neighbors'] as $neighbor) {
				$target = '';
				foreach (array_merge($this->topologyNameAliases($neighbor['name']), $this->topologyNameAliases($neighbor['ip'])) as $alias) {
					if (isset($aliases[$alias]) && $aliases[$alias] !== $source) {
						$target = $aliases[$alias];
						break;
					}
				}
				$local_key = $this->normalizeIfName($neighbor['local_port']);
				if ($target !== '') {
					$remote_key = $this->normalizeIfName($neighbor['remote_port']);
					$key = $this->linkKey($source, $local_key, $target, $remote_key);
					$protocol = strtoupper($neighbor['protocol']);
					if (isset($links[$key])) {
						if (!str_contains($links[$key]['protocol'], $protocol)) $links[$key]['protocol'] .= '+'.$protocol;
						$links[$key]['seen'][$source] = true;
						continue;
					}
					$local = $ports_by_host[$source][$local_key] ?? null;
					$remote = $ports_by_host[$target][$remote_key] ?? null;
					$links[$key] = [
						'id' => substr(sha1('auto|'.$key), 0, 16), 'macro_id' => '',
						'a' => $source, 'b' => $target,
						'ai' => $local['index'] ?? $neighbor['local_port'], 'an' => $local['name'] ?? $neighbor['local_port'],
						'bi' => $remote['index'] ?? $neighbor['remote_port'], 'bn' => $remote['name'] ?? $neighbor['remote_port'],
						'type' => 'uplink', 'description' => $neighbor['platform'] ?: $neighbor['description'],
						'protocol' => $protocol, 'source' => strtolower($neighbor['protocol']),
						// Equipos que reportan el enlace por CDP/LLDP (uno solo = protocolo apagado o sin plantilla en el otro).
						'seen' => [$source => true]
					];
					continue;
				}
				$id = substr(sha1('endpoint|'.$source.'|'.$local_key.'|'.strtolower($neighbor['name'] ?: $neighbor['ip'])), 0, 16);
				if (isset($endpoints[$id])) {
					$protocol = strtoupper($neighbor['protocol']);
					if (!str_contains($endpoints[$id]['protocol'], $protocol)) $endpoints[$id]['protocol'] .= '+'.$protocol;
					continue;
				}
				$local = $ports_by_host[$source][$local_key] ?? null;
				$endpoints[$id] = [
					'id' => $id, 'parent' => $source,
					'name' => $neighbor['name'] ?: Lang::t('Unknown neighbor'),
					'ip' => $neighbor['ip'],
					'local_port' => $local['name'] ?? $neighbor['local_port'],
					'local_index' => $local['index'] ?? '',
					'remote_port' => $neighbor['remote_port'],
					'platform' => $neighbor['platform'], 'description' => $neighbor['description'],
					'software' => $neighbor['software'], 'vlan' => $neighbor['vlan'],
					'protocol' => strtoupper($neighbor['protocol']),
					'type' => $this->deviceType(implode(' ', [$neighbor['name'], $neighbor['platform'],
						$neighbor['description'], $neighbor['software']])),
					'status' => $local['status'] ?? 'unknown',
					'speed_bps' => $local['speed_bps'] ?? null,
					'lastclock' => $neighbor['lastclock']
				];
			}
		}

		// Los enlaces manuales reemplazan a los descubiertos para el mismo par de puertos.
		$manual = $this->manualLinks(array_keys($hosts), $ports_by_host);
		foreach ($manual as $link) {
			$key = $this->linkKey($link['a'], $this->normalizeIfName($link['an'] ?: $link['ai']),
				$link['b'], $this->normalizeIfName($link['bn'] ?: $link['bi']));
			if (isset($links[$key])) $link['protocol'] = $links[$key]['protocol'];
			$links[$key] = $link;
		}

		foreach ($links as &$link) $this->decorateLink($link, $ports_by_host, $hosts);
		unset($link);

		$links = array_values($links);
		usort($links, static fn(array $a, array $b): int => strnatcasecmp($a['an'], $b['an']));
		// Cantidad de equipos finales por equipo y tipo: suficiente para dibujar grupos sin la lista completa.
		$groups = [];
		foreach ($endpoints as $endpoint) {
			$key = $endpoint['parent'].':'.$endpoint['type'];
			$groups[$key] ??= ['parent' => $endpoint['parent'], 'type' => $endpoint['type'], 'count' => 0, 'up' => 0, 'down' => 0];
			$groups[$key]['count']++;
			if ($endpoint['status'] === 'up') $groups[$key]['up']++;
			elseif (in_array($endpoint['status'], ['down', 'disabled'], true)) $groups[$key]['down']++;
		}
		if ($with_endpoints) {
			foreach ($endpoints as &$endpoint) {
				$endpoint['description'] = mb_substr($endpoint['description'], 0, 120);
				$endpoint['software'] = mb_substr($endpoint['software'], 0, 120);
			}
			unset($endpoint);
		}

		return ['hosts' => array_values($hosts), 'links' => $links, 'endpoints' => array_values($endpoints),
			'endpoint_groups' => array_values($groups)];
	}

	private function linkKey(string $a, string $ai, string $b, string $bi): string {
		$ends = [$a.'|'.$ai, $b.'|'.$bi];
		sort($ends, SORT_STRING);
		return implode('<>', $ends);
	}

	/** Estado, velocidad y uso de un enlace según sus puertos. */
	private function decorateLink(array &$link, array $ports_by_host, array $hosts): void {
		$find = function(string $hostid, string $index, string $name) use ($ports_by_host): ?array {
			foreach ($ports_by_host[$hostid] ?? [] as $port) {
				if ($port['index'] === $index) return $port;
			}
			return $ports_by_host[$hostid][$this->normalizeIfName($name ?: $index)] ?? null;
		};
		$a = $find((string) $link['a'], (string) $link['ai'], (string) $link['an']);
		$b = $find((string) $link['b'], (string) $link['bi'], (string) $link['bn']);
		$statuses = array_filter([$a['status'] ?? null, $b['status'] ?? null]);
		$unavailable = in_array($hosts[$link['a']]['health'] ?? '', ['unavailable'], true)
			|| in_array($hosts[$link['b']]['health'] ?? '', ['unavailable'], true);
		$state = 'unknown';
		if (array_intersect($statuses, ['down', 'disabled'])) $state = 'down';
		elseif ($statuses && !array_diff($statuses, ['up'])) $state = 'up';
		elseif ($unavailable) $state = 'down';
		$link['state'] = $state;
		$link['speed_bps'] = $a['speed_bps'] ?? $b['speed_bps'] ?? null;
		$utils = array_filter([$a['util'] ?? null, $b['util'] ?? null], static fn($value): bool => $value !== null);
		$link['util'] = $utils ? max($utils) : null;
		$link['util_level'] = $link['util'] === null ? ''
			: ($link['util'] >= $this->util_critical ? 'high' : ($link['util'] >= $this->util_warning ? 'warn' : ''));
		$link['traffic'] = trim(($a['in_text'] ?? '').' / '.($a['out_text'] ?? ''), ' /');
		if ($a !== null) $link['an'] = $a['name'];
		if ($b !== null) $link['bn'] = $b['name'];

		// Auditoría del enlace: diferencias entre los dos extremos que suelen causar fallas intermitentes.
		$audit = [];
		$speed_a = $a['speed_bps'] ?? null;
		$speed_b = $b['speed_bps'] ?? null;
		if ($speed_a && $speed_b && $state === 'up' && abs($speed_a - $speed_b) > max($speed_a, $speed_b) * .01) {
			$audit[] = ['speed', $a['speed_text'] ?? '', $b['speed_text'] ?? ''];
		}
		$duplex = static fn(?array $port): string => strtolower(trim((string) ($port['duplex'] ?? '')));
		$duplex_a = $duplex($a);
		$duplex_b = $duplex($b);
		$known = static fn(string $value): bool => str_contains($value, 'full') || str_contains($value, 'half');
		if ($state === 'up' && (($known($duplex_a) && $known($duplex_b) && str_contains($duplex_a, 'full') !== str_contains($duplex_b, 'full'))
				|| str_contains($duplex_a, 'half') || str_contains($duplex_b, 'half'))) {
			$audit[] = ['duplex', $a['duplex'] ?? '', $b['duplex'] ?? ''];
		}
		$status_a = $a['status'] ?? '';
		$status_b = $b['status'] ?? '';
		if ($status_a !== '' && $status_b !== '' && ($status_a === 'up') !== ($status_b === 'up')) {
			$audit[] = ['one_down', $status_a, $status_b];
		}
		$errors = (float) ($a['errors_total'] ?? 0) + (float) ($b['errors_total'] ?? 0);
		if ($errors > 0) $audit[] = ['errors', (string) ($a['errors_total'] ?? 0), (string) ($b['errors_total'] ?? 0)];
		if (isset($link['seen']) && count($link['seen']) === 1 && $link['macro_id'] === '') {
			$audit[] = ['one_sided', (string) array_key_first($link['seen']), ''];
		}
		$link['audit'] = $audit;
		unset($link['seen']);
	}

	private function manualLinks(array $hostids, array $ports_by_host): array {
		if (!$hostids) return [];
		$links = [];
		foreach (API::UserMacro()->get([
			'output' => ['hostmacroid', 'hostid', 'macro', 'value'],
			'hostids' => $hostids,
			'search' => ['macro' => '{$LAKA.TOPOLOGY.LINK:'],
			'startSearch' => true
		]) as $macro) {
			if (preg_match('/^\{\$LAKA\.TOPOLOGY\.LINK:"([a-f0-9]{8,32})"\}$/i', (string) $macro['macro'], $match) !== 1) continue;
			$value = json_decode((string) $macro['value'], true);
			if (!is_array($value) || !isset($value['b'], $value['ai'], $value['bi'])) continue;
			if (!isset($ports_by_host[(string) $value['b']])) continue;
			$links[] = [
				'id' => strtolower($match[1]), 'macro_id' => (string) $macro['hostmacroid'],
				'a' => (string) $macro['hostid'], 'b' => (string) $value['b'],
				'ai' => (string) $value['ai'], 'bi' => (string) $value['bi'],
				'an' => trim((string) ($value['an'] ?? '')), 'bn' => trim((string) ($value['bn'] ?? '')),
				'type' => trim((string) ($value['type'] ?? 'uplink')) ?: 'uplink',
				'description' => trim((string) ($value['description'] ?? '')),
				'protocol' => '', 'source' => 'manual'
			];
		}
		return $links;
	}

	/** Resumen de vecinos por puerto local, usado por la tabla de interfaces y los tooltips. */
	private function attachNeighbors(array &$device, array $topology): void {
		$by_port = [];
		$names = array_column($topology['hosts'], 'name', 'id');
		foreach ($topology['endpoints'] as $endpoint) {
			if ($endpoint['parent'] !== $device['hostid']) continue;
			$by_port[$this->normalizeIfName($endpoint['local_port'])] = [
				'name' => $endpoint['name'], 'port' => $endpoint['remote_port'], 'ip' => $endpoint['ip'],
				'protocol' => $endpoint['protocol'], 'type' => $endpoint['type'], 'hostid' => ''
			];
		}
		foreach ($topology['links'] as $link) {
			foreach ([['a', 'b'], ['b', 'a']] as [$local, $remote]) {
				if ((string) $link[$local] !== $device['hostid']) continue;
				$by_port[$this->normalizeIfName($link[$local.'n'] ?: $link[$local.'i'])] = [
					'name' => $names[$link[$remote]] ?? '', 'port' => $link[$remote.'n'] ?: $link[$remote.'i'],
					'ip' => '', 'protocol' => $link['protocol'] ?: Lang::t('Manual'), 'type' => 'switch',
					'hostid' => (string) $link[$remote]
				];
			}
		}
		foreach ($device['ports'] as &$port) {
			$port['neighbor'] = $by_port[$port['key']] ?? null;
		}
		unset($port);
		$device['neighbor_count'] = count($by_port);
	}
}
