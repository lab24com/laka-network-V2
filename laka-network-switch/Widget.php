<?php declare(strict_types = 1);

/*
 * LAKA Network – módulo de red para Zabbix 7.0 – 8.0
 * © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3
 */

namespace Modules\LakaNetworkSwitch;

use Zabbix\Core\CWidget;

class Widget extends CWidget {
	public function getDefaultName(): string {
		return _('Network');
	}
}
