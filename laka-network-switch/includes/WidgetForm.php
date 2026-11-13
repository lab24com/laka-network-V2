<?php declare(strict_types = 1);

/*
 * LAKA Network – módulo de red para Zabbix 7.0 – 8.0
 * © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3
 */

namespace Modules\LakaNetworkSwitch\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\CWidgetFieldIntegerBox;
use Zabbix\Widgets\Fields\CWidgetFieldMultiSelectGroup;
use Zabbix\Widgets\Fields\CWidgetFieldMultiSelectHost;
use Zabbix\Widgets\Fields\CWidgetFieldSelect;
use Zabbix\Widgets\Fields\CWidgetFieldTextBox;

class WidgetForm extends CWidgetForm {

	/** Campos con expresiones regulares escritas por el usuario, que deben compilar. */
	public const REGEX_FIELDS = [
		'include_regex', 'exclude_regex', 'temperature_sensor_regex', 'fan_sensor_regex',
		'psu_sensor_regex', 'other_sensor_regex'
	];

	public function addFields(): self {
		// Las etiquetas del formulario de configuración siguen el idioma guardado en este widget (cuando el framework lo expone).
		if (isset($this->values['language']) && is_numeric($this->values['language'])) {
			Lang::setLanguage((int) $this->values['language']);
		}
		$t = [Lang::class, 't'];
		$yes_no = [0 => $t('No'), 1 => $t('Yes')];

		$this
			// Origen de datos.
			->addField(new CWidgetFieldMultiSelectGroup('groupids', $t('Host groups')))
			->addField((new CWidgetFieldMultiSelectHost('hostids', $t('Additional or specific hosts')))->setMultiple(true))
			->addField((new CWidgetFieldSelect('profile', $t('Template profile'), [
				Profiles::CUSTOM => $t('Automatic: official Zabbix templates + item keys below'),
				Profiles::CISCO => $t('Automatic, Cisco IOS / IOS-XE first'),
				Profiles::GENERIC => $t('Automatic, generic IF-MIB first'),
				Profiles::FORTIGATE => $t('Automatic, FortiGate first'),
				Profiles::STRICT => $t('Only the item keys below (no detection)')
			]))->setDefault(Profiles::CUSTOM))
			->addField((new CWidgetFieldSelect('language', $t('Language'), [
				Lang::AUTO => $t('Automatic (Zabbix user language)')
			] + Lang::NAMES))->setDefault(Lang::AUTO))
			->addField((new CWidgetFieldSelect('hide_empty_devices', $t('Hide equipment without matching interfaces'), $yes_no))
				->setDefault(0))

			// Regiones y escala.
			->addField((new CWidgetFieldSelect('region_source', $t('Region source'), [
				0 => $t('Automatic (tag, then host group prefix)'),
				1 => $t('Host tag'),
				2 => $t('Host group prefix'),
				3 => $t('Location item'),
				4 => $t('No regions')
			]))->setDefault(0))
			->addField((new CWidgetFieldTextBox('region_tag', $t('Region tag name')))->setDefault('region'))
			->addField((new CWidgetFieldTextBox('region_group_prefix', $t('Region host group prefix')))->setDefault(''))
			->addField((new CWidgetFieldIntegerBox('max_hosts', $t('Maximum equipment'), 10, 500))->setDefault(300))
			->addField((new CWidgetFieldSelect('detail_mode', $t('Equipment detail loading'), [
				0 => $t('Automatic (on demand above 30 equipment)'),
				1 => $t('Embedded (up to 60 equipment)'),
				2 => $t('Always on demand')
			]))->setDefault(0))

			// Presentación.
			->addField((new CWidgetFieldSelect('layout_mode', $t('Port layout'), [
				0 => $t('Automatic (recommended)'),
				1 => $t('Compact grid'),
				2 => $t('Physical odd/even')
			]))->setDefault(0))
			->addField((new CWidgetFieldIntegerBox('columns', $t('Ports per row (compact grid)'), 4, 48))->setDefault(24))
			->addField((new CWidgetFieldSelect('show_alias', $t('Show interface alias'), $yes_no))->setDefault(1))
			->addField((new CWidgetFieldSelect('show_utilization', $t('Show utilization percentage'), $yes_no))->setDefault(1))
			->addField((new CWidgetFieldIntegerBox('interface_list_rows', $t('Visible interface rows'), 6, 60))->setDefault(24))
			->addField((new CWidgetFieldIntegerBox('util_warning', $t('Utilization warning (%)'), 1, 100))->setDefault(60))
			->addField((new CWidgetFieldIntegerBox('util_critical', $t('Utilization critical (%)'), 1, 100))->setDefault(80))

			// Gráficas destacadas.
			->addField((new CWidgetFieldSelect('show_featured_charts', $t('Show featured interface charts'), $yes_no))->setDefault(0))
			->addField((new CWidgetFieldIntegerBox('featured_chart_limit', $t('Maximum featured interface charts'), 1, 12))
				->setDefault(4))
			->addField((new CWidgetFieldSelect('featured_chart_period', $t('Featured chart default period'), [
				0 => '1h', 1 => '6h', 2 => '12h', 3 => '24h', 4 => '7d'
			]))->setDefault(2))
			->addField((new CWidgetFieldIntegerBox('featured_chart_height', $t('Featured chart height (px)'), 180, 320))
				->setDefault(200))

			// Hardware.
			->addField((new CWidgetFieldSelect('show_hardware_health', $t('Show hardware health'), $yes_no))->setDefault(1))
			->addField((new CWidgetFieldTextBox('temperature_sensor_regex', $t('Temperature sensor name/key (regex)')))
				->setDefault('(temperature|temp sensor|hotspot)'))
			->addField((new CWidgetFieldTextBox('fan_sensor_regex', $t('Fan sensor name/key (regex)')))
				->setDefault('(^|[^a-z])fan([ :_]|$)'))
			->addField((new CWidgetFieldTextBox('psu_sensor_regex', $t('PSU sensor name/key (regex)')))
				->setDefault('(power supply|psu)'))
			->addField((new CWidgetFieldTextBox('other_sensor_regex', $t('Other hardware sensor name/key (regex)')))
				->setDefault('(voltage|humidity|power consumption)'))
			->addField((new CWidgetFieldTextBox('temperature_warning', $t('Temperature warning threshold')))->setDefault('50'))
			->addField((new CWidgetFieldTextBox('temperature_critical', $t('Temperature critical threshold')))->setDefault('60'))

			// Filtros de interfaces.
			->addField((new CWidgetFieldTextBox('include_regex', $t('Include interface names (regex)')))
				->setDefault('^(Gi|Te|Tw|Twe|Fo|Hu|Eth|Fa|port|wan|lan|internal|x)'))
			->addField((new CWidgetFieldTextBox('exclude_regex', $t('Exclude interface names (regex)')))
				->setDefault('^(Vl|Vlan|Lo|Loopback|Po|Port-channel|Nu|Null|StackPort|StackSub|AppGigabitEthernet|Ap[0-9]|Bl|Tu)'));

		// Patrones de clave de ítem (perfil "Personalizado"). Los valores por defecto siguen las plantillas SNMP oficiales de Zabbix.
		foreach (Profiles::fieldLabels() as $field => $label) {
			$this->addField((new CWidgetFieldTextBox($field, $t($label)))->setDefault(Profiles::DEFAULTS[$field] ?? ''));
		}

		return $this;
	}

	public function validate(bool $strict = false): array {
		$errors = parent::validate($strict);

		foreach (self::REGEX_FIELDS as $name) {
			$field = $this->getField($name);
			if ($field === null) {
				continue;
			}
			$pattern = trim((string) $field->getValue());
			if ($pattern !== '' && @preg_match('~'.str_replace('~', '\~', $pattern).'~i', '') === false) {
				$errors[] = sprintf(Lang::t('Invalid regular expression in "%1$s".'), $field->getLabel());
			}
		}

		$warning = $this->getField('util_warning');
		$critical = $this->getField('util_critical');
		if ($warning !== null && $critical !== null && (int) $warning->getValue() >= (int) $critical->getValue()) {
			$errors[] = Lang::t('Utilization warning must be lower than utilization critical.');
		}

		return $errors;
	}
}
