<?php

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
