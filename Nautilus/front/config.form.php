<?php

/*
Proyecto: GLPI-Nautilus
Repositorio: https://github.com/javiermengu/GLPI-Nautilus
Autor: Francisco Javier Mengual Maldonado
Descripción:
    GLPI-Nautilus es una solución basada en software libre para centralizar 
    la gestión de copias de seguridad de equipos inventariados en GLPI.

    Muestra y guarda la configuración desde la interfaz de GLPI.

Copyright (C) 2026 Francisco Javier Mengual Maldonado

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

License: GPL-2.0
*/


include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkLoginUser();

if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
}

Plugin::load('nautilus', true);

$configuracion = new PluginNautilusConfig();

/*
 * Procesamiento del formulario.
 *
 * El formulario de configuración siempre trabaja sobre el registro ID 1,
 * ya que el plugin Nautilus solo necesita una configuración global.
 */
if (isset($_POST['update'])) {

    $_POST['id'] = 1;

    /*
     * El campo de contraseña puede dejarse vacío en el formulario.
     *
     * En ese caso no se interpreta como "borrar contraseña", sino como:
     * "mantener la contraseña actualmente guardada".
     *
     * Esto evita que una actualización de otros campos elimine accidentalmente
     * la contraseña de UrBackup.
     */
    if (
        isset($_POST['urbackup_password'])
        && $_POST['urbackup_password'] === ''
    ) {
        $configuracion_actual = new PluginNautilusConfig();

        if ($configuracion_actual->getFromDB(1)) {
            $_POST['urbackup_password'] = $configuracion_actual->fields['urbackup_password'];
        }
    }

    $configuracion->update($_POST);

    Session::addMessageAfterRedirect(
        __('Configuración guardada correctamente', 'nautilus')
    );

    Html::redirect(
        $CFG_GLPI['root_doc']
            . '/plugins/nautilus/front/config.form.php?itemtype=pluginnautilusconfig&glpi_tab=1'
    );
}

/*
 * Cabecera de la página de configuración del plugin.
 */
Html::header(
    __('Nautilus', 'nautilus'),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginNautilusConfig'
);

/*
 * Se usa showForm(1) para mostrar directamente el formulario del registro
 * principal de configuración.
 *
 * No se usa display(), porque en este caso puede provocar una doble inserción
 * del formulario.
 */
$configuracion->showForm(1);

Html::footer();
