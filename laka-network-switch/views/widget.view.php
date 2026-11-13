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

// La consola la dibuja widget.view.body.php. Cualquier error al dibujar se muestra dentro del widget, porque un
// error no controlado invalida la respuesta y Zabbix deja el widget con el indicador de carga para siempre.
try {
	include __DIR__.'/widget.view.body.php';
}
catch (\Throwable $e) {
	error_log('[LAKA Network] '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
	$message = sprintf(Lang::t('The widget could not be generated: %1$s'),
		$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')');
	if (!mb_check_encoding($message, 'UTF-8')) $message = mb_scrub($message, 'UTF-8');
	(new CWidgetView(['name' => $data['name'] ?? 'Network', 'user' => $data['user'] ?? ['debug_mode' => 0]]))
		->addItem((new CDiv($message))->addClass('laka-scope')->addClass('laka-console')->addClass('empty')
			->addItem((new CTag('footer', true, '© LAKA Soluciones Tecnologicas'))->addClass('laka-footer')))
		->show();
}
