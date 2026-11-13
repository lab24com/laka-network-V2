<?php declare(strict_types = 1);

/*
 * LAKA Network – módulo de red para Zabbix 7.0 – 8.0
 * © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3
 */

namespace Modules\LakaNetworkSwitch\Includes;

/**
 * Perfiles de claves de ítem. Un perfil reemplaza los patrones del perfil "Personalizado", para que un widget nuevo funcione con
 * las plantillas oficiales de Zabbix sin editar 23 campos. Los patrones de interfaz deben tener exactamente un "*"
 * ({#SNMPINDEX}); los patrones de resumen (modelo, CPU, memoria, uptime, ubicación) aceptan "*" como comodín.
 */
final class Profiles {
	public const CUSTOM = 0;
	public const CISCO = 1;
	public const GENERIC = 2;
	public const FORTIGATE = 3;
	/** Solo las claves escritas en el formulario; sin detección automática. */
	public const STRICT = 4;

	/** Remapeo de valores IF-MIB para plantillas cuyos ítems de estado usan otra numeración. */
	private const SDWAN_OPER = ['0' => '1', '1' => '2', '2' => '2', '10' => '4'];
	private const SDWAN_ADMIN = ['0' => '1', '1' => '2', '10' => '3'];
	private const FTD_OPER = ['1' => '1', '0' => '2'];

	/**
	 * Claves candidatas de las plantillas oficiales de Zabbix 7.0 - 8.0, que se prueban después del patrón configurado.
	 * Interfaces: "*" = {#SNMPINDEX} numérico; "\"*\"" = clave de descubrimiento entre comillas (plantillas HTTP).
	 * Plantillas: Cisco IOS by SNMP, IOS 12.0_3_T-12.2_3.5, IOS prior to 12.0_3_T (+ Network interfaces by SNMP),
	 * Catalyst 3750V2-*, Nexus 9000 Series, ASAv by SNMP, SD-WAN device by HTTP, Secure Firewall Threat Defense by HTTP.
	 */
	public const PORT_CANDIDATES = [
		'oper_pattern' => ['net.if.status[ifOperStatus.*]', 'net.if.status[*]', 'cisco.asav.net.if.operstatus[*]',
			['sd_wan.device.if.status["*"]', self::SDWAN_OPER], ['cisco.ftd.interface.status["*"]', self::FTD_OPER]],
		'admin_pattern' => ['net.if.adminstatus[ifAdminStatus.*]', 'net.if.adminstatus[*]',
			'cisco.asav.net.if.adminstatus[*]', ['sd_wan.device.if.adm.status["*"]', self::SDWAN_ADMIN]],
		'name_pattern' => ['net.if.name[ifName.*]', 'net.if.name[*]', 'cisco.asav.net.if.name[*]'],
		'alias_pattern' => ['net.if.alias[ifAlias.*]', 'net.if.alias[*]'],
		'speed_pattern' => ['net.if.speed[ifHighSpeed.*]', 'net.if.speed[*]', 'net.if.speed[ifSpeed.*]',
			'cisco.asav.net.if.highspeed[*]', 'sd_wan.device.if.speed["*"]'],
		'traffic_in_pattern' => ['net.if.in[ifHCInOctets.*]', 'net.if.in[*]', 'net.if.in[ifInOctets.*]',
			'cisco.asav.net.if.in.traffic[*]', 'sd_wan.device.if.in["*"]', 'cisco.ftd.net.if.in.traffic["*"]'],
		'traffic_out_pattern' => ['net.if.out[ifHCOutOctets.*]', 'net.if.out[*]', 'net.if.out[ifOutOctets.*]',
			'cisco.asav.net.if.out.traffic[*]', 'sd_wan.device.if.out["*"]', 'cisco.ftd.interface.out.traffic["*"]'],
		'errors_in_pattern' => ['net.if.in.errors[ifInErrors.*]', 'net.if.in.errors[*]', 'sd_wan.device.if.in.errors["*"]',
			'cisco.ftd.interface.input.errors["*"]'],
		'errors_out_pattern' => ['net.if.out.errors[ifOutErrors.*]', 'net.if.out.errors[*]',
			'sd_wan.device.if.out.errors["*"]', 'cisco.ftd.interface.output.errors["*"]'],
		'discards_in_pattern' => ['net.if.in.discards[ifInDiscards.*]', 'net.if.in.discards[*]',
			'sd_wan.device.if.in.discards["*"]'],
		'discards_out_pattern' => ['net.if.out.discards[ifOutDiscards.*]', 'net.if.out.discards[*]',
			'sd_wan.device.if.out.discards["*"]'],
		'duplex_pattern' => ['net.if.duplex[dot3StatsDuplexStatus.*]', 'net.if.duplex[*]'],
		'type_pattern' => ['net.if.type[ifType.*]', 'net.if.type[*]'],
		'trunk_pattern' => ['vlan.Trunk.Port.Encapsulation[OperType.*]'],
		// Plantilla LAKA Network Optics and PoE by SNMP (extras/optics-poe).
		'poe_status_pattern' => ['laka.poe.status[*]'],
		'poe_power_pattern' => ['laka.poe.power[*]'],
		'optic_rx_pattern' => ['laka.optic.rx[*]'],
		'optic_tx_pattern' => ['laka.optic.tx[*]']
	];

	/** Candidatas del resumen del equipo ("*" = cualquier texto), en orden de prioridad después del patrón configurado. */
	public const SUMMARY_CANDIDATES = [
		'model' => ['system.hw.model', 'system.hw.model*', 'sd_wan.device.model', 'cisco.ftd.model', 'cisco.asav.model'],
		'serial' => ['system.hw.serialnumber', 'sd_wan.device.serialnumber', 'cisco.ftd.serialnumber',
			'system.hw.serialnumber[entPhysicalSerialNum.*]', 'system.hw.serialnumber[*]', 'cisco.asav.phys.sn[*]'],
		'os' => ['system.sw.os[sysDescr.0]', 'system.sw.os', 'system.sw.os*', 'sd_wan.device.version',
			'cisco.ftd.software.version', 'cisco.asav.model', 'system.descr[sysDescr.0]', 'system.descr'],
		'cpu' => ['system.cpu.util*', 'cisco.asav.cpu.util[*]', 'sd_wan.device.cpu.util', 'cisco.ftd.cpu.utilization'],
		'memory' => ['vm.memory.util*', 'cisco.asav.memory.util[*]', 'sd_wan.device.memory.util',
			'cisco.ftd.memory.utilization'],
		'location' => ['system.location[sysLocation.0]', 'system.location', 'system.location*', 'cisco.asav.location'],
		'uptime' => ['system.net.uptime[sysUpTime.0]', 'system.net.uptime', 'system.hw.uptime[hrSystemUptime.0]',
			'system.hw.uptime', 'system.uptime[sysUpTime.0]', 'system.uptime', 'system.uptime[fgSysUpTime.0]',
			'cisco.asav.uptime', 'sd_wan.device.uptime', 'cisco.ftd.uptime'],
		'icmp_ping' => ['icmpping'],
		'icmp_loss' => ['icmppingloss'],
		'icmp_rtt' => ['icmppingsec'],
		'poe_budget' => ['laka.poe.budget.total'],
		'poe_used' => ['laka.poe.used.total']
	];

	public const DEFAULTS = [
		'model_pattern' => 'system.hw.model',
		'uptime_pattern' => 'system.net.uptime[sysUpTime.0]',
		'cpu_pattern' => 'system.cpu.util[cpmCPUTotal5minRev.*]',
		'memory_pattern' => 'vm.memory.util[vm.memory.util.*]',
		'location_pattern' => 'system.location[sysLocation.0]',
		'oper_pattern' => 'net.if.status[ifOperStatus.*]',
		'admin_pattern' => 'net.if.adminstatus[ifAdminStatus.*]',
		'name_pattern' => 'net.if.name[ifName.*]',
		'alias_pattern' => 'net.if.alias[ifAlias.*]',
		'speed_pattern' => 'net.if.speed[ifHighSpeed.*]',
		'traffic_in_pattern' => 'net.if.in[ifHCInOctets.*]',
		'traffic_out_pattern' => 'net.if.out[ifHCOutOctets.*]',
		'errors_in_pattern' => 'net.if.in.errors[ifInErrors.*]',
		'errors_out_pattern' => 'net.if.out.errors[ifOutErrors.*]',
		'discards_in_pattern' => 'net.if.in.discards[ifInDiscards.*]',
		'discards_out_pattern' => 'net.if.out.discards[ifOutDiscards.*]',
		'duplex_pattern' => 'net.if.duplex[dot3StatsDuplexStatus.*]',
		'type_pattern' => 'net.if.type[ifType.*]',
		'trunk_pattern' => 'vlan.Trunk.Port.Encapsulation[OperType.*]',
		'vlan_pattern' => '',
		'poe_status_pattern' => '',
		'poe_power_pattern' => '',
		'lastchange_pattern' => ''
	];

	private const OVERRIDES = [
		self::CISCO => [],
		self::GENERIC => [
			'model_pattern' => 'system.hw.model*',
			'cpu_pattern' => 'system.cpu.util*',
			'memory_pattern' => 'vm.memory.util*',
			'trunk_pattern' => ''
		],
		self::FORTIGATE => [
			'model_pattern' => 'system.hw.model*',
			'uptime_pattern' => 'system.uptime[fgSysUpTime.0]',
			'cpu_pattern' => 'system.cpu.util*',
			'memory_pattern' => 'vm.memory.util*',
			'trunk_pattern' => ''
		]
	];

	/** Nombre del campo => etiqueta, en el orden en que se muestra. */
	public static function fieldLabels(): array {
		return [
			'model_pattern' => 'Hardware model key pattern',
			'uptime_pattern' => 'Hardware uptime key pattern',
			'cpu_pattern' => 'CPU utilization key pattern',
			'memory_pattern' => 'Memory utilization key pattern',
			'location_pattern' => 'Location key pattern',
			'oper_pattern' => 'Operational status key pattern',
			'admin_pattern' => 'Administrative status key pattern',
			'name_pattern' => 'Interface name key pattern',
			'alias_pattern' => 'Interface alias key pattern',
			'speed_pattern' => 'Speed key pattern',
			'traffic_in_pattern' => 'Traffic IN key pattern',
			'traffic_out_pattern' => 'Traffic OUT key pattern',
			'errors_in_pattern' => 'Errors IN key pattern',
			'errors_out_pattern' => 'Errors OUT key pattern',
			'discards_in_pattern' => 'Discards IN key pattern',
			'discards_out_pattern' => 'Discards OUT key pattern',
			'duplex_pattern' => 'Duplex key pattern',
			'type_pattern' => 'Interface type key pattern',
			'trunk_pattern' => 'Trunk/VLAN type key pattern',
			'vlan_pattern' => 'Access VLAN key pattern (optional)',
			'poe_status_pattern' => 'PoE status key pattern (optional)',
			'poe_power_pattern' => 'PoE power key pattern (optional)',
			'lastchange_pattern' => 'Last change key pattern (optional, ifLastChange)'
		];
	}

	/** Verdadero cuando hay que probar las candidatas incorporadas (todos los perfiles salvo "Solo claves personalizadas"). */
	public static function autoDetect(array $fields_values): bool {
		return (int) ($fields_values['profile'] ?? self::CUSTOM) !== self::STRICT;
	}

	/**
	 * Claves candidatas de interfaz por campo del widget: [[patrón, remapeo|null], ...], primero el patrón configurado.
	 * Se quitan los duplicados, así una clave configurada igual a una incorporada se prueba una sola vez.
	 */
	public static function portCandidates(array $patterns, bool $auto): array {
		$result = [];
		foreach ($patterns as $field => $pattern) {
			$list = [];
			if (trim((string) $pattern) !== '') $list[trim((string) $pattern)] = [trim((string) $pattern), null];
			if ($auto) {
				foreach (self::PORT_CANDIDATES[$field] ?? [] as $candidate) {
					[$key, $remap] = is_array($candidate) ? $candidate : [$candidate, null];
					if (!isset($list[$key])) $list[$key] = [$key, $remap];
				}
			}
			$result[$field] = array_values($list);
		}
		return $result;
	}

	/** Candidatas de resumen: primero el patrón configurado, luego la lista incorporada. */
	public static function summaryCandidates(array $patterns, bool $auto): array {
		$configured = [
			'model' => $patterns['model_pattern'] ?? '', 'cpu' => $patterns['cpu_pattern'] ?? '',
			'memory' => $patterns['memory_pattern'] ?? '', 'location' => $patterns['location_pattern'] ?? '',
			'uptime' => $patterns['uptime_pattern'] ?? ''
		];
		$result = [];
		foreach (self::SUMMARY_CANDIDATES as $metric => $list) {
			$first = trim((string) ($configured[$metric] ?? ''));
			$all = $first !== '' ? [$first] : [];
			if ($auto || !array_key_exists($metric, $configured)) $all = array_merge($all, $list);
			$result[$metric] = array_values(array_unique($all));
		}
		return $result;
	}

	/** Patrones de clave efectivos para los valores de campo del widget dados. */
	public static function resolve(array $fields_values): array {
		$profile = (int) ($fields_values['profile'] ?? self::CUSTOM);
		$result = [];
		foreach (self::DEFAULTS as $field => $default) {
			if ($profile !== self::CUSTOM && $profile !== self::STRICT && array_key_exists($profile, self::OVERRIDES)) {
				$result[$field] = self::OVERRIDES[$profile][$field] ?? $default;
			}
			else {
				$value = $fields_values[$field] ?? null;
				$result[$field] = $value === null ? $default : trim((string) $value);
			}
		}
		return $result;
	}

	/** Arma una PCRE segura sin distinción de mayúsculas a partir de lo que escribe el usuario; devuelve null si la expresión no es válida. */
	public static function regex(string $pattern): ?string {
		$pattern = trim($pattern);
		if ($pattern === '') return null;
		$regex = '~'.str_replace('~', '\~', $pattern).'~i';
		return @preg_match($regex, '') === false ? null : $regex;
	}
}
