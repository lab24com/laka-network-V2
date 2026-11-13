<?php declare(strict_types = 1);

/*
 * LAKA Network – módulo de red para Zabbix 7.0 – 8.0
 * © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3
 */

namespace Modules\LakaNetworkSwitch\Actions;

use API;
use CController;
use CControllerResponseData;

/**
 * Historial de tráfico de una interfaz. Los valores se agrupan en intervalos de tiempo (promedio + máximo) para que las
 * ráfagas cortas no se pierdan al reducir la serie para dibujarla. También devuelve el pico y el percentil 95.
 */
class WidgetHistory extends CController {
	private const PERIODS = ['1h' => 3600, '6h' => 21600, '12h' => 43200, '24h' => 86400, '7d' => 604800];
	private const BUCKETS = 240;

	protected function init(): void {
		// Endpoint de solo lectura llamado con fetch(); los permisos los aplican las llamadas a la API de abajo.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput([
			'hostid' => 'required|id',
			'in_itemid' => 'id',
			'out_itemid' => 'id',
			'period' => 'string',
			// Rango libre (zoom arrastrando sobre la gráfica) y comparación con la semana anterior.
			'from' => 'int32',
			'till' => 'int32',
			'compare' => 'in 0,1',
			// Consultas por lote para la tabla de interfaces: mini-gráficas, mapa de calor y puertos sin uso.
			'mode' => 'in chart,sparks,heat,unused',
			'itemids' => 'array_id'
		]);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		try {
			$hostid = (string) $this->getInput('hostid');
			$mode = (string) $this->getInput('mode', 'chart');
			if ($mode !== 'chart') {
				$this->respond(true, $this->batch($mode, $hostid, array_slice((array) $this->getInput('itemids', []), 0, 400)));
				return;
			}
			$period = (string) $this->getInput('period', '12h');
			if (!array_key_exists($period, self::PERIODS)) $period = '12h';
			$time_till = time();
			$time_from = $time_till - self::PERIODS[$period];
			$from = (int) $this->getInput('from', 0);
			$till = (int) $this->getInput('till', 0);
			if ($from > 0 && $till > $from + 60 && $till <= $time_till + 60 && $till - $from <= 31 * 86400) {
				$time_from = $from;
				$time_till = min($till, $time_till);
				$period = $time_till - $time_from > 2 * 86400 ? '7d' : 'custom';
			}
			$compare = (int) $this->getInput('compare', 0) === 1;
			$requested = [
				'in' => (string) $this->getInput('in_itemid', ''),
				'out' => (string) $this->getInput('out_itemid', '')
			];
			$itemids = array_values(array_unique(array_filter($requested)));

			$items_by_id = [];
			if ($itemids) {
				foreach (API::Item()->get([
					'output' => ['itemid', 'value_type', 'units'],
					'itemids' => $itemids,
					'hostids' => [$hostid],
					'filter' => ['status' => ITEM_STATUS_ACTIVE]
				]) as $item) {
					$items_by_id[(string) $item['itemid']] = $item;
				}
			}

			$payload = ['in' => [], 'out' => [], 'in_prev' => [], 'out_prev' => [], 'stats' => [], 'units' => 'bps',
				'period' => $period, 'time_from' => $time_from, 'time_till' => $time_till,
				'source' => $period === '7d' ? 'trends' : 'history', 'events' => $this->events($hostid, $time_from, $time_till)];
			foreach ($requested as $direction => $itemid) {
				$item = $items_by_id[$itemid] ?? null;
				if ($item === null || !in_array((int) $item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true)) {
					continue;
				}
				$units = (string) $item['units'];
				$multiplier = preg_match('~(^|[^b])B/s~', $units) === 1 || $units === 'B' ? 8 : 1;
				$payload['units'] = $units ?: $payload['units'];

				$points = $period === '7d'
					? $this->trendPoints($itemid, $time_from, $time_till)
					: $this->historyPoints($itemid, (int) $item['value_type'], $time_from, $time_till);
				if (!$points && $period !== '7d') {
					// Retención de historial corta: se usan los trends.
					$points = $this->trendPoints($itemid, $time_from, $time_till);
				}
				foreach ($points as &$point) {
					$point[1] *= $multiplier;
					$point[2] *= $multiplier;
				}
				unset($point);

				$payload[$direction] = $this->bucketize($points, $time_from, $time_till);
				$payload['stats'][$direction] = $this->statistics($points);

				if ($compare) {
					// Misma ventana una semana antes, desplazada para dibujarse sobre la actual.
					$week = 7 * 86400;
					$previous = $period === '7d'
						? $this->trendPoints($itemid, $time_from - $week, $time_till - $week)
						: $this->historyPoints($itemid, (int) $item['value_type'], $time_from - $week, $time_till - $week);
					if (!$previous) $previous = $this->trendPoints($itemid, $time_from - $week, $time_till - $week);
					foreach ($previous as &$point) {
						$point[0] += $week;
						$point[1] *= $multiplier;
						$point[2] *= $multiplier;
					}
					unset($point);
					$payload[$direction.'_prev'] = $this->bucketize($previous, $time_from, $time_till);
				}
			}

			$this->respond(true, $payload);
		}
		catch (\Throwable $exception) {
			$this->respond(false, [], $exception->getMessage());
		}
	}

	/** Problemas del equipo que empezaron dentro de la ventana: se marcan sobre la gráfica. */
	private function events(string $hostid, int $from, int $till): array {
		$events = API::Event()->get([
			'output' => ['eventid', 'clock', 'name', 'severity'],
			'hostids' => [$hostid],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
			'time_from' => $from,
			'time_till' => $till,
			'sortfield' => ['clock'],
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 40
		]);
		return array_map(static fn(array $event): array => [(int) $event['clock'], (int) $event['severity'],
			mb_substr((string) $event['name'], 0, 160)], $events);
	}

	/**
	 * Consultas por lote de los puertos de un equipo (una sola llamada a la API por tipo de dato):
	 *  - sparks: tráfico de las últimas 6 h en 24 puntos (mini-gráficas de la tabla de interfaces);
	 *  - heat:   máximo por hora de las últimas 24 h (mapa de calor puertos × horas);
	 *  - unused: máximo de los últimos 30 días y desde cuándo hay datos (puertos sin uso).
	 */
	private function batch(string $mode, string $hostid, array $itemids): array {
		$items = [];
		foreach (API::Item()->get([
			'output' => ['itemid', 'value_type', 'units'],
			'itemids' => $itemids,
			'hostids' => [$hostid],
			'filter' => ['status' => ITEM_STATUS_ACTIVE, 'value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]]
		]) as $item) {
			$units = (string) $item['units'];
			$item['multiplier'] = preg_match('~(^|[^b])B/s~', $units) === 1 || $units === 'B' ? 8 : 1;
			$items[(string) $item['itemid']] = $item;
		}
		$now = time();
		$result = ['mode' => $mode, 'series' => [], 'time_till' => $now];
		if (!$items) return $result;

		if ($mode === 'sparks') {
			$from = $now - 6 * 3600;
			$buckets = 24;
			$width = 6 * 3600 / $buckets;
			$sums = [];
			foreach ([ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64] as $type) {
				$ids = array_keys(array_filter($items, static fn(array $item): bool => (int) $item['value_type'] === $type));
				if (!$ids) continue;
				foreach (API::History()->get([
					'output' => ['itemid', 'clock', 'value'],
					'itemids' => array_map('strval', $ids),
					'history' => $type,
					'time_from' => $from,
					'time_till' => $now,
					'limit' => 200000
				]) as $row) {
					$index = min($buckets - 1, (int) floor(((int) $row['clock'] - $from) / $width));
					$sums[(string) $row['itemid']][$index][0] = ($sums[(string) $row['itemid']][$index][0] ?? 0) + (float) $row['value'];
					$sums[(string) $row['itemid']][$index][1] = ($sums[(string) $row['itemid']][$index][1] ?? 0) + 1;
				}
			}
			foreach ($sums as $itemid => $slots) {
				$values = array_fill(0, $buckets, null);
				foreach ($slots as $index => [$sum, $count]) {
					$values[$index] = round($sum / $count * $items[$itemid]['multiplier'], 1);
				}
				$result['series'][$itemid] = $values;
			}
			return $result;
		}

		$hours = $mode === 'heat' ? 24 : 30 * 24;
		$from = (int) (floor($now / 3600) - $hours) * 3600;
		$rows = API::Trend()->get([
			'output' => ['itemid', 'clock', 'value_max'],
			'itemids' => array_map('strval', array_keys($items)),
			'time_from' => $from,
			'time_till' => $now
		]);
		if ($mode === 'heat') {
			$result['time_from'] = $from;
			foreach ($rows as $row) {
				$itemid = (string) $row['itemid'];
				$index = (int) floor(((int) $row['clock'] - $from) / 3600);
				if ($index < 0 || $index >= $hours) continue;
				$result['series'][$itemid] ??= array_fill(0, $hours, null);
				$result['series'][$itemid][$index] = round((float) $row['value_max'] * $items[$itemid]['multiplier'], 1);
			}
			return $result;
		}
		foreach ($rows as $row) {
			$itemid = (string) $row['itemid'];
			$value = (float) $row['value_max'] * $items[$itemid]['multiplier'];
			$current = $result['series'][$itemid] ?? ['max' => 0.0, 'since' => (int) $row['clock']];
			$result['series'][$itemid] = ['max' => max($current['max'], $value), 'since' => min($current['since'], (int) $row['clock'])];
		}
		return $result;
	}

	/** @return list<array{0:int,1:float,2:float}> hora, promedio, máximo */
	private function historyPoints(string $itemid, int $value_type, int $from, int $till): array {
		$rows = API::History()->get([
			'output' => ['clock', 'value'],
			'itemids' => [$itemid],
			'history' => $value_type,
			'time_from' => $from,
			'time_till' => $till,
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_UP,
			'limit' => 20000
		]);
		return array_map(static fn(array $row): array => [(int) $row['clock'], (float) $row['value'], (float) $row['value']], $rows);
	}

	private function trendPoints(string $itemid, int $from, int $till): array {
		$rows = API::Trend()->get([
			'output' => ['clock', 'value_avg', 'value_max'],
			'itemids' => [$itemid],
			'time_from' => $from,
			'time_till' => $till
		]);
		$points = array_map(static fn(array $row): array => [(int) $row['clock'], (float) $row['value_avg'],
			(float) $row['value_max']], $rows);
		usort($points, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
		return $points;
	}

	/** Agrupa los puntos en intervalos de tiempo fijos, conservando el promedio y el máximo de cada intervalo. */
	private function bucketize(array $points, int $from, int $till): array {
		if (count($points) <= self::BUCKETS) {
			return array_map(static fn(array $point): array => [$point[0], round($point[1], 2), round($point[2], 2)], $points);
		}
		$width = max(1, ($till - $from) / self::BUCKETS);
		$buckets = [];
		foreach ($points as [$clock, $avg, $max]) {
			$index = (int) floor(($clock - $from) / $width);
			$buckets[$index] ??= ['clock' => 0, 'sum' => 0.0, 'count' => 0, 'max' => 0.0];
			$buckets[$index]['clock'] += $clock;
			$buckets[$index]['sum'] += $avg;
			$buckets[$index]['count']++;
			$buckets[$index]['max'] = max($buckets[$index]['max'], $max);
		}
		ksort($buckets);
		$result = [];
		foreach ($buckets as $bucket) {
			$result[] = [(int) round($bucket['clock'] / $bucket['count']), round($bucket['sum'] / $bucket['count'], 2),
				round($bucket['max'], 2)];
		}
		return $result;
	}

	private function statistics(array $points): array {
		if (!$points) return ['avg' => null, 'max' => null, 'p95' => null, 'last' => null];
		$averages = array_column($points, 1);
		$maximums = array_column($points, 2);
		sort($averages);
		$p95_index = (int) max(0, ceil(count($averages) * .95) - 1);
		return [
			'avg' => array_sum($averages) / count($averages),
			'max' => max($maximums),
			'p95' => $averages[$p95_index],
			'last' => end($points)[1]
		];
	}

	private function respond(bool $ok, array $payload = [], string $error = ''): void {
		$this->setResponse((new CControllerResponseData([
			'main_block' => json_encode(['ok' => $ok, 'data' => $payload, 'error' => $error])
		]))->disableView());
	}
}
