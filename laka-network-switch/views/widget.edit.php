<?php declare(strict_types = 1);

/*
 * LAKA Network – módulo de red para Zabbix 7.0 – 8.0
 * © LAKA Soluciones Tecnológicas · Autor: Willian Tola · Licencia GPLv3
 */

use Modules\LakaNetworkSwitch\Includes\Lang;
use Modules\LakaNetworkSwitch\Includes\Profiles;

/**
 * @var CView $this
 * @var array $data
 */

$fields = $data['fields'];
$form = new CWidgetFormView($data);

$view = static function(string $name) use ($fields) {
	$field = $fields[$name];
	if ($field instanceof Zabbix\Widgets\Fields\CWidgetFieldMultiSelectGroup) return new CWidgetFieldMultiSelectGroupView($field);
	if ($field instanceof Zabbix\Widgets\Fields\CWidgetFieldMultiSelectHost) return new CWidgetFieldMultiSelectHostView($field);
	if ($field instanceof Zabbix\Widgets\Fields\CWidgetFieldSelect) return new CWidgetFieldSelectView($field);
	if ($field instanceof Zabbix\Widgets\Fields\CWidgetFieldIntegerBox) return new CWidgetFieldIntegerBoxView($field);
	return new CWidgetFieldTextBoxView($field);
};

foreach (['groupids', 'hostids', 'language', 'profile', 'hide_empty_devices'] as $name) {
	$form->addField($view($name));
}

$sections = [
	[Lang::t('Regions and scale'), ['region_source', 'region_tag', 'region_group_prefix', 'max_hosts', 'detail_mode']],
	[Lang::t('Presentation'), ['layout_mode', 'columns', 'show_alias', 'show_utilization', 'interface_list_rows',
		'util_warning', 'util_critical']],
	[Lang::t('Featured interface charts'), ['show_featured_charts', 'featured_chart_limit', 'featured_chart_period',
		'featured_chart_height']],
	[Lang::t('Hardware health'), ['show_hardware_health', 'temperature_sensor_regex', 'fan_sensor_regex',
		'psu_sensor_regex', 'other_sensor_regex', 'temperature_warning', 'temperature_critical']],
	[Lang::t('Interface filters'), ['include_regex', 'exclude_regex']],
	[Lang::t('Item keys'), array_keys(Profiles::fieldLabels())]
];

foreach ($sections as [$title, $names]) {
	$fieldset = new CWidgetFormFieldsetCollapsibleView($title);
	foreach ($names as $name) {
		if (array_key_exists($name, $fields)) $fieldset->addField($view($name));
	}
	$form->addFieldset($fieldset);
}

$form->show();
