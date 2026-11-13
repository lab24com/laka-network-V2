<?php declare(strict_types = 1);

/*
 * LAKA Network – módulo de red para Zabbix 7.0 – 8.0
 * © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3
 */

namespace Modules\LakaNetworkSwitch\Includes;

/**
 * Utilidades para leer los valores que recolecta extras/mac-table/laka_fdb.sh (formato LAKAFDB1).
 *
 *   fdb: "LAKAFDB1;fdb;vlan=20;src=bridge;n=3" + líneas "ifIndex:mac,mac,..."   (ifIndex "b12" = puerto bridge sin ifIndex)
 *   arp: "LAKAFDB1;arp;if=35;n=2"              + líneas "a.b.c:d=mac,d=mac,..."
 *   lag: "LAKAFDB1;lag;n=1"                    + líneas "ifIndexAgregado;nombre;miembro,miembro"
 *   errores: "LAKAFDB1;fdb;error=..."
 */
final class MacTable {

	public const KEY_PREFIX = 'laka_fdb.sh[';

	/** 12 dígitos hexadecimales en minúscula a partir de cualquier notación MAC habitual, o null. */
	public static function normalizeMac(string $value): ?string {
		$value = strtolower(trim($value));
		if (preg_match('/^[0-9a-f]{1,2}([:\-])[0-9a-f]{1,2}(\1[0-9a-f]{1,2}){4}$/', $value) === 1) {
			return implode('', array_map(static fn(string $octet): string => str_pad($octet, 2, '0', STR_PAD_LEFT),
				preg_split('/[:\-]/', $value)));
		}
		if (preg_match('/^[0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4}$/', $value) === 1) return str_replace('.', '', $value);
		if (preg_match('/^[0-9a-f]{12}$/', $value) === 1) return $value;
		if (preg_match('/^([0-9a-f]{2} ){5}[0-9a-f]{2}$/', $value) === 1) return str_replace(' ', '', $value);
		return null;
	}

	public static function isIpv4(string $value): bool {
		return filter_var(trim($value), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
	}

	/** "001a.2b3c.4d5e" (Cisco) y "00:1A:2B:3C:4D:5E". */
	public static function formats(string $mac): array {
		return [
			'cisco' => substr($mac, 0, 4).'.'.substr($mac, 4, 4).'.'.substr($mac, 8, 4),
			'colon' => strtoupper(implode(':', str_split($mac, 2)))
		];
	}

	/**
	 * Direcciones que no son un equipo final normal: broadcast, multicast, gateways virtuales (HSRP, VRRP, GLBP).
	 * @return array{0: string, 1: bool} [tipo o '', administrada localmente]
	 */
	public static function special(string $mac): array {
		$first = hexdec(substr($mac, 0, 2));
		$kind = match (true) {
			$mac === 'ffffffffffff' => 'broadcast',
			($first & 1) === 1 => 'multicast',
			str_starts_with($mac, '00000c07ac') => 'hsrp',
			str_starts_with($mac, '00000c9ff') => 'hsrp',
			str_starts_with($mac, '00005e0001'), str_starts_with($mac, '00005e0002') => 'vrrp',
			str_starts_with($mac, '0007b4') => 'glbp',
			default => ''
		};
		return [$kind, ($first & 2) === 2];
	}

	/** Fabricante de la MAC según el prefijo OUI de la IEEE (includes/oui.txt), o null. */
	public static function vendor(string $mac): ?string {
		static $data = null;
		if ($data === null) $data = (string) @file_get_contents(__DIR__.'/oui.txt');
		$at = strpos($data, "\n".substr($mac, 0, 6)."\t");
		if ($at === false) return null;
		$end = strpos($data, "\n", $at + 8);
		$name = substr($data, $at + 8, $end === false ? null : $end - $at - 8);
		return $name === 'IEEE Registration Authority' ? null : $name;
	}

	/** Campos del encabezado de un valor recolectado ("vlan" => "20", "n" => "3", "error" => ...). */
	public static function header(string $value): array {
		$line = strtok(ltrim($value), "\n");
		if ($line === false || !str_starts_with($line, 'LAKAFDB')) return ['error' => 'format'];
		$fields = [];
		foreach (array_slice(explode(';', trim($line)), 2) as $part) {
			[$name, $field] = array_pad(explode('=', $part, 2), 2, '');
			$fields[$name] = $field;
		}
		return $fields;
	}

	/** Modo de una clave de ítem: fdb, arp, lag, vlans, arpifs (o ''). */
	public static function keyMode(string $key): string {
		return preg_match('/^laka_fdb\.sh\["?([a-z]+)"?[,\]]/', $key, $m) === 1 ? $m[1] : '';
	}

	/**
	 * Entradas de una MAC en un valor FDB.
	 * @return array [['ifindex' => '10102', 'count' => 3]] (normalmente cero o una)
	 */
	public static function findInFdb(string $value, string $mac): array {
		if (!str_contains($value, $mac)) return [];
		$hits = [];
		foreach (explode("\n", $value) as $line) {
			if (!str_contains($line, $mac)) continue;
			$colon = strpos($line, ':');
			if ($colon === false) continue;
			$macs = explode(',', trim(substr($line, $colon + 1)));
			if (in_array($mac, $macs, true)) $hits[] = ['ifindex' => substr($line, 0, $colon), 'count' => count($macs)];
		}
		return $hits;
	}

	/** Cantidad de MAC por ifIndex de un valor FDB. */
	public static function fdbCounts(string $value): array {
		$counts = [];
		foreach (explode("\n", $value) as $line) {
			$colon = strpos($line, ':');
			if ($colon === false || str_starts_with($line, 'LAKAFDB')) continue;
			$counts[substr($line, 0, $colon)] = substr_count($line, ',') + 1;
		}
		return $counts;
	}

	/** Entradas ARP [ip => mac] de un valor que coinciden con una IP o una MAC. */
	public static function findInArp(string $value, ?string $ip, ?string $mac): array {
		$result = [];
		if ($ip !== null) {
			$octets = explode('.', $ip);
			$prefix = $octets[0].'.'.$octets[1].'.'.$octets[2].':';
			if (!str_contains($value, $prefix)) return [];
		}
		elseif ($mac !== null && !str_contains($value, $mac)) {
			return [];
		}
		foreach (explode("\n", $value) as $line) {
			$colon = strpos($line, ':');
			if ($colon === false || str_starts_with($line, 'LAKAFDB')) continue;
			$prefix = substr($line, 0, $colon);
			if ($ip !== null && $prefix.'.' !== substr($ip, 0, strlen($prefix) + 1)) continue;
			if ($mac !== null && !str_contains($line, $mac)) continue;
			foreach (explode(',', trim(substr($line, $colon + 1))) as $entry) {
				[$last, $entry_mac] = array_pad(explode('=', $entry, 2), 2, '');
				$entry_ip = $prefix.'.'.$last;
				if (($ip === null || $entry_ip === $ip) && ($mac === null || $entry_mac === $mac) && strlen($entry_mac) === 12) {
					$result[$entry_ip] = $entry_mac;
				}
			}
		}
		return $result;
	}

	/** Port-channels de un valor lag: ifIndexAgregado => ['name' => 'Po1', 'members' => ['10197', ...]]. */
	public static function parseLag(string $value): array {
		$result = [];
		foreach (explode("\n", $value) as $line) {
			if (str_starts_with($line, 'LAKAFDB') || substr_count($line, ';') < 2) continue;
			[$agg, $name, $members] = explode(';', trim($line), 3);
			$result[$agg] = ['name' => $name, 'members' => array_values(array_filter(explode(',', $members), 'strlen'))];
		}
		return $result;
	}
}
