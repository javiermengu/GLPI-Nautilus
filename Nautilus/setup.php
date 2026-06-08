<?php

/**
 * Plugin Nautilus.
 *
 * Fichero principal de registro del plugin en GLPI.
 *
 * Este fichero define:
 * - los hooks utilizados por el plugin;
 * - la información de versión;
 * - los requisitos previos;
 * - la comprobación básica de configuración.
 */

/**
 * Inicializa el plugin Nautilus en GLPI.
 *
 * Esta función es llamada automáticamente por GLPI.
 * Registra los hooks, las tareas cron y la página de configuración.
 *
 * @return void
 */
function plugin_init_nautilus()
{
    global $PLUGIN_HOOKS;

    /*
     * Indica que el plugin cumple con la protección CSRF de GLPI.
     */
    $PLUGIN_HOOKS['csrf_compliant']['nautilus'] = true;

    /*
     * Tareas automáticas registradas por el plugin.
     *
     * urbackup_clean:
     * Limpia en UrBackup clientes que ya no deben mantenerse activos.
     *
     * urbackup_sync:
     * Sincroniza en GLPI la fecha del último backup conocida por UrBackup.
     */
    $PLUGIN_HOOKS['cron']['nautilus'] = [
        'urbackup_clean',
        'urbackup_sync'
    ];

    /*
     * Hook ejecutado antes de mostrar el formulario de un equipo.
     *
     * Se utiliza para integrar la lógica del plugin en la ficha de Computer.
     */
    $PLUGIN_HOOKS['pre_item_form']['nautilus'] = [
        'Computer' => 'PluginNautilus'
    ];

    /*
     * Derecho propio del plugin.
     *
     * Permite declarar permisos específicos si se amplía la gestión de derechos.
     */
    $PLUGIN_HOOKS['have_right']['nautilus'] = [
        'plugin_nautilus_read'
    ];

    /*
     * Página de configuración visible desde:
     *
     * Configuración > Plugins > Nautilus
     */
    $PLUGIN_HOOKS['config_page']['nautilus'] = 'front/config.form.php';

    /*
     * Clase principal de configuración del plugin.
     */
    Plugin::registerClass('PluginNautilusConfig');
}

/**
 * Devuelve la información de versión del plugin.
 *
 * Esta función es llamada automáticamente por GLPI para mostrar información
 * del plugin en la pantalla de plugins.
 *
 * @return array
 * 
 * 0.0.1  -> prototipo mínimo
 * 0.1.0  -> primera versión funcional
 * 1.0.0  -> versión estable y madura
 */
function plugin_version_nautilus()
{
    $version = [
        'name'         => 'GLPI-Nautilus',
        'version'      => '0.1.0',
        'author'       => 'Francisco Javier Mengual Maldonado',
        'license'      => 'GPLv2',
        'homepage'     => 'https://github.com/javiermengu/GLPI-Nautilus',
        'requirements' => [
            'glpi' => [
                'min' => '11.0',
            ]
        ]
    ];

    return $version;
}

/**
 * Comprueba los requisitos previos del plugin.
 *
 * Requisitos:
 * - plugin Fields activo;
 * - plugin GLPI Inventory activo.
 *
 * Fields se utiliza para almacenar los campos personalizados del equipo.
 * GLPI Inventory se utiliza como base para el despliegue y gestión de agentes.
 *
 * @return bool
 */
function plugin_nautilus_check_prerequisites()
{
    $requisitos_correctos = true;

    /*
     * El plugin Fields es necesario para trabajar con los campos personalizados
     * usados por Nautilus, como estado_backup y fecha_ultimo_backup.
     */
    if (!Plugin::isPluginActive('fields')) {
        echo "[Nautilus] Error: El plugin 'Fields' es obligatorio para gestionar los campos personalizados.<br>";
        $requisitos_correctos = false;
    }

    /*
     * El plugin GLPI Inventory es necesario para el escenario de despliegue
     * y gestión de agentes previsto por Nautilus.
     */
    if (!Plugin::isPluginActive('glpiinventory')) {
        echo "[Nautilus] Error: El plugin 'GLPI Inventory' es obligatorio para la gestión de agentes.<br>";
        $requisitos_correctos = false;
    }

    return $requisitos_correctos;
}

/**
 * Comprueba la configuración general del plugin.
 *
 * Actualmente no se requiere ninguna comprobación adicional.
 *
 * @return bool
 */
function plugin_nautilus_check_config()
{
    $configuracion_correcta = true;

    return $configuracion_correcta;
}