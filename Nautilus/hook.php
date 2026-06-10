<?php

/*
Proyecto: GLPI-Nautilus
Repositorio: https://github.com/javiermengu/GLPI-Nautilus
Autor: Francisco Javier Mengual Maldonado
Descripción:
    GLPI-Nautilus es una solución basada en software libre para centralizar 
    la gestión de copias de seguridad de equipos inventariados en GLPI.

    Gestiona la instalación y desinstalación del plugin.

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

/**
 * Plugin Nautilus - Hooks de ciclo de vida.
 *
 * Este fichero gestiona:
 * - Instalación del plugin;
 * - Creación de perfil Nemo;
 * - Creación de usuario Nemo;
 * - Activación de API e inventario;
 * - Creación de cliente API;
 * - Registro de tareas cron;
 * - Creación del bloque Fields GLPI-Nautilus;
 * - Creación de la tabla de configuración del plugin.
 * - Deshabilita AgentWakeup
 */

if (!defined('GLPI_ROOT')) {
    die("Acceso no permitido");
}

/* =========================
   CONSTANTES DEL PLUGIN
   ========================= */

define('PLUGIN_NAUTILUS_TABLA_CONFIG', 'glpi_plugin_nautilus_configs');
define('PLUGIN_NAUTILUS_NOMBRE_PERFIL', 'Nemo');
define('PLUGIN_NAUTILUS_NOMBRE_USUARIO', 'nemo');
define('PLUGIN_NAUTILUS_NOMBRE_CLIENTE_API', 'Nemo-Client');

define('PLUGIN_NAUTILUS_FIELDS_NAME', 'nautilus_block');
define('PLUGIN_NAUTILUS_FIELDS_LABEL', 'GLPI-Nautilus');

define('PLUGIN_NAUTILUS_GRUPO_INVENTORY', 'Nautilus - Todos los ordenadores');
define('PLUGIN_NAUTILUS_GRUPO_INVENTORY_DESCRIPCION', 'Grupo dinámico creado por GLPI-Nautilus para despliegue de Nemo');
define('PLUGIN_NAUTILUS_ENTIDAD_RAIZ_ID', 0);

/* =========================
   UTILIDADES GENERALES
   ========================= */

/**
 * Escribe una línea en el log de instalación del plugin.
 *
 * @param string $mensaje
 *
 * @return void
 */
function plugin_nautilus_log($mensaje)
{
    Toolbox::logInFile('nautilus_install', $mensaje . "\n");
}

/**
 * Limpia la caché de GLPI si está disponible.
 *
 * @return void
 */
function plugin_nautilus_limpiar_cache()
{
    global $GLPI_CACHE;

    if (isset($GLPI_CACHE)) {
        $GLPI_CACHE->clear();
    }
}

/**
 * Comprueba si existe un registro en una tabla.
 *
 * @param string $tabla
 * @param array  $criterios
 *
 * @return array|null
 */
function plugin_nautilus_obtener_registro($tabla, $criterios)
{
    global $DB;

    $registro = null;

    $resultado = $DB->request([
        'FROM'  => $tabla,
        'WHERE' => $criterios,
        'LIMIT' => 1
    ]);

    if ($resultado->count() > 0) {
        $registro = $resultado->current();
    }

    return $registro;
}

/* =========================
   PERFIL Y USUARIO
   ========================= */

/**
 * Crea el perfil Nemo si no existe.
 *
 * @return int|null
 */
function plugin_nautilus_crear_perfil_nemo()
{
    global $DB;

    $id_perfil = null;

    $perfil = plugin_nautilus_obtener_registro(
        'glpi_profiles',
        ['name' => PLUGIN_NAUTILUS_NOMBRE_PERFIL]
    );

    if ($perfil) {
        $id_perfil = (int)$perfil['id'];
    } else {
        $DB->insert('glpi_profiles', [
            'name'       => PLUGIN_NAUTILUS_NOMBRE_PERFIL,
            'interface'  => 'central',
            'is_default' => 0
        ]);

        $id_perfil = (int)$DB->insertId();

        $DB->insert('glpi_profilerights', [
            'profiles_id' => $id_perfil,
            'name'        => 'computer',
            'rights'      => 1
        ]);

        $DB->insert('glpi_profilerights', [
            'profiles_id' => $id_perfil,
            'name'        => 'plugin_nautilus_read',
            'rights'      => 1
        ]);
    }

    return $id_perfil;
}

/**
 * Crea el usuario técnico nemo si no existe.
 *
 * @return int|null
 */
function plugin_nautilus_crear_usuario_nemo()
{
    global $DB;

    $id_usuario = null;

    $usuario = new User();
    $token_usuario = Toolbox::getRandomString(40);

    // Crear/obtener usuario
    if ($usuario->getFromDBByCrit(['name' => PLUGIN_NAUTILUS_NOMBRE_USUARIO])) {
        $id_usuario = (int)$usuario->fields['id'];
    } else {
        $id_usuario = $usuario->add([
            'name'      => PLUGIN_NAUTILUS_NOMBRE_USUARIO,
            'realname'  => 'Sistema',
            'firstname' => 'Nemo',
            'is_active' => 1,
            'api_token' => $token_usuario
        ]);
    }

    // Obtener perfil Nemo
    $id_perfil = plugin_nautilus_crear_perfil_nemo();

    // Asignar perfil al usuario
    $existe = plugin_nautilus_obtener_registro(
        'glpi_profiles_users',
        [
            'users_id'    => $id_usuario,
            'profiles_id' => $id_perfil
        ]
    );

    if (!$existe) {
        $DB->insert('glpi_profiles_users', [
            'users_id'    => $id_usuario,
            'profiles_id' => $id_perfil,
            'entities_id' => 0,
            'is_recursive'=> 1,
            'is_dynamic'  => 0
        ]);

        plugin_nautilus_log(
            "[USUARIO] Perfil Nemo asignado a usuario nemo"
        );
    }

    return $id_usuario;
}

/**
 * Habilita API REST clásica e inventario nativo de GLPI.
 *
 * Activa:
 * - API REST legacy de GLPI.
 * - Login por credenciales.
 * - Login por token externo de usuario.
 * - Inventario nativo.
 *
 * @return void
 */
function plugin_nautilus_habilitar_api_inventario()
{
    global $CFG_GLPI;

    if (class_exists('Config')) {
        /*
         * API REST clásica de GLPI.
         *
         * enable_api:
         *   Activa la API REST.
         *
         * enable_api_login_credentials:
         *   Permite autenticación con usuario/contraseña.
         *
         * enable_api_login_external_token:
         *   Permite autenticación con user_token.
         */
        Config::setConfigurationValues('core', [
            'use_api'                         => 1,
            'enable_api'                      => 1,
            'enable_api_login_credentials'    => 1,
            'enable_api_login_external_token' => 1
        ]);

        /*
         * Inventario nativo de GLPI.
         */
        Config::setConfigurationValues('inventory', [
            'enable_inventory' => 1
        ]);

        /*
         * Refresco básico de la configuración en memoria.
         * Esto ayuda durante la misma ejecución de instalación.
         */
        if (isset($CFG_GLPI) && is_array($CFG_GLPI)) {
            $CFG_GLPI['use_api'] = 1;
            $CFG_GLPI['enable_api'] = 1;
            $CFG_GLPI['enable_api_login_credentials'] = 1;
            $CFG_GLPI['enable_api_login_external_token'] = 1;
        }

        plugin_nautilus_log("[CONFIG] API REST e inventario habilitados.");
    }
}

/**
 * Crea el cliente API Nemo-Client si no existe.
 *
 * @return int|null
 */
function plugin_nautilus_crear_cliente_api()
{
    $id_cliente_api = null;

    $cliente_api = new APIClient();
    $token_aplicacion = Toolbox::getRandomString(40);

    if ($cliente_api->getFromDBByCrit(['name' => PLUGIN_NAUTILUS_NOMBRE_CLIENTE_API])) {
        $id_cliente_api = (int)$cliente_api->fields['id'];
    } else {
        $id_cliente_api = $cliente_api->add([
            'name'      => PLUGIN_NAUTILUS_NOMBRE_CLIENTE_API,
            'app_token' => $token_aplicacion,
            'is_active' => 1
        ]);
    }

    return $id_cliente_api;
}

/* =========================
   CRON
   ========================= */

/**
 * Registra las tareas cron de Nautilus si no existen.
 *
 * @return void
 */
function plugin_nautilus_registrar_crons()
{
    global $DB;

    $tareas_cron = [
        'urbackup_clean' => [
            'frequency' => 86400,
            'param'     => 0,
            'mode'      => 2,
            'hourmin'   => 1,
            'hourmax'   => 3,
        ],
        'urbackup_sync' => [
            'frequency' => 86400,
            'param'     => 0,
            'mode'      => 2,
            'hourmin'   => 10,
            'hourmax'   => 14,

        ]
    ];

    foreach ($tareas_cron as $nombre_tarea => $configuracion_tarea) {
        $cron = plugin_nautilus_obtener_registro(
            'glpi_crontasks',
            [
                'itemtype' => 'PluginNautilusCron',
                'name'     => $nombre_tarea
            ]
        );

        if (!$cron) {
            $DB->insert('glpi_crontasks', [
                'itemtype'  => 'PluginNautilusCron',
                'name'      => $nombre_tarea,
                'frequency' => $configuracion_tarea['frequency'],
                'param'     => $configuracion_tarea['param'],
                'state'     => 1,
                'mode'      => $configuracion_tarea['mode'],
                'allowmode' => 3,
                'hourmin'   => $configuracion_tarea['hourmin'],
                'hourmax'   => $configuracion_tarea['hourmax'],
                'comment'   => 'Módulo automatizado Nautilus'
            ]);
        }
    }
}

/* =========================
   FIELDS
   ========================= */

/**
 * Obtiene el ID del contenedor Fields de Nautilus si ya existe.
 *
 * Se busca por name y también por label para evitar errores si el bloque
 * ya fue creado anteriormente con el mismo nombre visible.
 *
 * @return int|null
 */
function plugin_nautilus_obtener_contenedor_fields()
{
    global $DB;

    $id_contenedor = null;

    if (
        class_exists('PluginFieldsContainer')
        && $DB->tableExists('glpi_plugin_fields_containers')
    ) {
        $resultado = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_fields_containers',
            'WHERE'  => [
                'OR' => [
                    ['name'  => PLUGIN_NAUTILUS_FIELDS_NAME],
                    ['label' => PLUGIN_NAUTILUS_FIELDS_LABEL]
                ]
            ],
            'LIMIT' => 1
        ]);

        if ($resultado->count() > 0) {
            $fila = $resultado->current();
            $id_contenedor = (int)$fila['id'];
        }
    }

    return $id_contenedor;
}

/**
 * Crea el contenedor Fields GLPI-Nautilus si no existe.
 *
 * @return int|null
 */
function plugin_nautilus_crear_contenedor_fields()
{
    $id_contenedor = plugin_nautilus_obtener_contenedor_fields();

    if (
        $id_contenedor === null
        && class_exists('PluginFieldsContainer')
    ) {
        $contenedor = new PluginFieldsContainer();

        $id_contenedor = $contenedor->add([
            'name'      => PLUGIN_NAUTILUS_FIELDS_NAME,
            'label'     => PLUGIN_NAUTILUS_FIELDS_LABEL,
            'itemtypes' => ['Computer'],
            'is_active' => 1,
            'type'      => 'dom'
        ]);
    }

    return $id_contenedor;
}

/**
 * Comprueba si existe un campo dentro del contenedor Fields.
 *
 * @param int    $id_contenedor
 * @param string $nombre_campo
 *
 * @return bool
 */
function plugin_nautilus_existe_campo_fields($id_contenedor, $nombre_campo)
{
    $existe = false;

    if (class_exists('PluginFieldsField')) {
        $campo = new PluginFieldsField();

        if ($campo->getFromDBByCrit([
            'plugin_fields_containers_id' => (int)$id_contenedor,
            'name'                        => $nombre_campo
        ])) {
            $existe = true;
        }
    }

    return $existe;
}

/**
 * Crea un campo Fields si no existe.
 *
 * @param int    $id_contenedor
 * @param string $nombre
 * @param string $etiqueta
 * @param string $tipo
 *
 * @return void
 */
function plugin_nautilus_crear_campo_fields($id_contenedor, $nombre, $etiqueta, $tipo)
{
    if (
        class_exists('PluginFieldsField')
        && !plugin_nautilus_existe_campo_fields($id_contenedor, $nombre)
    ) {
        $campo = new PluginFieldsField();

        $campo->add([
            'plugin_fields_containers_id' => (int)$id_contenedor,
            'name'                        => $nombre,
            'label'                       => $etiqueta,
            'type'                        => $tipo,
            'is_active'                   => 1,
            'is_readonly'                 => 0
        ]);
    }
}

/**
 * Crea los campos necesarios de Nautilus dentro del bloque Fields.
 *
 * @param int $id_contenedor
 *
 * @return void
 */
function plugin_nautilus_crear_campos_fields($id_contenedor)
{
    plugin_nautilus_crear_campo_fields(
        $id_contenedor,
        'estado_backup',
        'Backup',
        'yesno'
    );

    plugin_nautilus_crear_campo_fields(
        $id_contenedor,
        'fecha_ultimo_backup',
        'Fecha último backup',
        'datetime'
    );
}

/**
 * Da permisos de acceso al bloque Fields para todos los perfiles.
 *
 * Criterio aplicado:
 * - Todos los perfiles reciben permiso de lectura.
 * - El perfil Nemo recibe permiso de lectura y escritura.
 *
 * Esto permite que el usuario técnico asociado a Nemo pueda consultar y
 * actualizar los campos del bloque GLPI-Nautilus, especialmente:
 *
 * - estado_backup
 * - fecha_ultimo_backup
 *
 * Si el permiso ya existe, se actualiza.
 * Si no existe, se crea.
 *
 * @param int $id_contenedor
 *
 * @return void
 */
/**
 * Da permisos de acceso al bloque Fields para los perfiles de GLPI.
 *
 * Criterio aplicado:
 * - Super-Admin: escritura.
 * - Admin: escritura.
 * - Technician: escritura.
 * - Nemo: escritura.
 * - Resto de perfiles: lectura.
 *
 * En el plugin Fields, el permiso "Write" se corresponde con CREATE.
 * No basta con UPDATE ni con READ | UPDATE.
 *
 * Si el permiso ya existe, se actualiza.
 * Si no existe, se crea.
 *
 * @param int $id_contenedor
 *
 * @return void
 */
function plugin_nautilus_asignar_perfiles_fields($id_contenedor)
{
    global $DB;

    if (
        $id_contenedor
        && class_exists('PluginFieldsProfile')
        && $DB->tableExists('glpi_plugin_fields_profiles')
    ) {
        /*
         * LECTURA   = 1
         * ESCRITURA = 4
         */
        $permiso_lectura = 1;
        $permiso_escritura = 4;

        /*
         * Perfiles que deben tener escritura.
         *
         * Se comprueba por constantes de GLPI cuando existen.
         * También se comprueba por nombre como respaldo, por si la instalación
         * no define alguna constante o mantiene los nombres originales.
         */
        $perfiles = $DB->request('glpi_profiles');

        foreach ($perfiles as $perfil) {
            $perfil_fields = new PluginFieldsProfile();

            $id_perfil = (int)$perfil['id'];
            $nombre_perfil = (string)($perfil['name'] ?? '');

            $es_perfil_nemo = $nombre_perfil === PLUGIN_NAUTILUS_NOMBRE_PERFIL;

            $es_super_admin = (
                defined('PROFILE_SUPER_ADMIN')
                && $id_perfil === (int)PROFILE_SUPER_ADMIN
            );

            $es_admin = (
                defined('PROFILE_ADMIN')
                && $id_perfil === (int)PROFILE_ADMIN
            );

            $es_technician = (
                defined('PROFILE_TECHNICIAN')
                && $id_perfil === (int)PROFILE_TECHNICIAN
            );

            /*
             * Respaldo por nombre.
             *
             * Esto ayuda si las constantes no están disponibles, aunque si el
             * perfil fue renombrado solo será fiable la comprobación por ID.
             */
            if (!$es_super_admin) {
                $es_super_admin = $nombre_perfil === 'Super-Admin';
            }

            if (!$es_admin) {
                $es_admin = $nombre_perfil === 'Admin';
            }

            if (!$es_technician) {
                $es_technician = $nombre_perfil === 'Technician';
            }

            $permiso = $permiso_lectura;

            if (
                $es_perfil_nemo
                || $es_super_admin
                || $es_admin
                || $es_technician
            ) {
                $permiso = $permiso_escritura;
            }

            if ($perfil_fields->getFromDBByCrit([
                'plugin_fields_containers_id' => (int)$id_contenedor,
                'profiles_id'                 => $id_perfil
            ])) {
                $perfil_fields->update([
                    'id'    => (int)$perfil_fields->fields['id'],
                    'right' => $permiso
                ]);
            } else {
                $perfil_fields->add([
                    'plugin_fields_containers_id' => (int)$id_contenedor,
                    'profiles_id'                 => $id_perfil,
                    'right'                       => $permiso
                ]);
            }

            plugin_nautilus_log(
                "[FIELDS] Perfil={$nombre_perfil} | ID={$id_perfil} | Permiso={$permiso}"
            );
        }
    }
}

/**
 * Crea o reutiliza el bloque Fields de Nautilus.
 *
 * Esta función evita crear de nuevo el bloque si ya existe. Esto impide errores
 * al activar el plugin varias veces o tras una reinstalación.
 *
 * @return void
 */
function plugin_nautilus_preparar_fields()
{
    if (
        class_exists('PluginFieldsContainer')
        && class_exists('PluginFieldsField')
    ) {
        $id_contenedor = plugin_nautilus_crear_contenedor_fields();

        if ($id_contenedor) {
            plugin_nautilus_crear_campos_fields($id_contenedor);
            plugin_nautilus_asignar_perfiles_fields($id_contenedor);
        }
    } else {
        plugin_nautilus_log(
            "[FIELDS] Plugin Fields no disponible. No se crean campos Nautilus."
        );
    }
}

/* =========================
   TABLA DE CONFIGURACIÓN
   ========================= */

/**
 * Crea la tabla de configuración del plugin si no existe.
 *
 * @return void
 */
function plugin_nautilus_crear_tabla_configuracion()
{
    global $DB;

    $migration = new Migration(100);
    $tabla = PLUGIN_NAUTILUS_TABLA_CONFIG;

    if (!$DB->tableExists($tabla)) {
        $consulta = "CREATE TABLE `$tabla` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `urbackup_url` varchar(255) DEFAULT NULL,
            `urbackup_username` varchar(255) DEFAULT NULL,
            `urbackup_password` text DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";

        $DB->doQuery($consulta);
    }

    $migration->executeMigration();
}

/**
 * Crea el registro inicial de configuración si no existe.
 *
 * @return void
 */
function plugin_nautilus_crear_registro_configuracion()
{
    global $DB;

    $tabla = PLUGIN_NAUTILUS_TABLA_CONFIG;

    if ($DB->tableExists($tabla)) {
        $registro = plugin_nautilus_obtener_registro(
            $tabla,
            ['id' => 1]
        );

        if (!$registro) {
            $DB->insert($tabla, [
                'id'                => 1,
                'urbackup_url'      => '',
                'urbackup_username' => '',
                'urbackup_password' => ''
            ]);
        }
    }
}

/* =========================
   GLPI INVENTORY - GRUPO DINÁMICO
   ========================= */

/**
 * Comprueba si existe un campo en una tabla.
 *
 * Se usa para evitar errores si cambia la estructura interna del plugin
 * GLPI Inventory entre versiones.
 *
 * @param string $tabla
 * @param string $campo
 *
 * @return bool
 */
function plugin_nautilus_existe_campo($tabla, $campo)
{
    global $DB;

    $existe = false;

    if (
        $DB->tableExists($tabla)
        && $DB->fieldExists($tabla, $campo)
    ) {
        $existe = true;
    }

    return $existe;
}

/**
 * Obtiene el ID del grupo dinámico de GLPI Inventory creado para Nautilus.
 *
 * @return int|null
 */
function plugin_nautilus_obtener_grupo_inventory()
{
    global $DB;

    $id_grupo = null;
    $tabla_grupos = 'glpi_plugin_glpiinventory_deploygroups';

    if ($DB->tableExists($tabla_grupos)) {
        $resultado = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => $tabla_grupos,
            'WHERE'  => [
                'name' => PLUGIN_NAUTILUS_GRUPO_INVENTORY
            ],
            'LIMIT' => 1
        ]);

        if ($resultado->count() > 0) {
            $fila = $resultado->current();
            $id_grupo = (int)$fila['id'];
        }
    }

    return $id_grupo;
}

/**
 * Construye el criterio de búsqueda del grupo dinámico.
 *
 * Criterio usado:
 * - itemtype: Computer
 * - entidad raíz: ID 0
 *
 * En GLPI, el campo de búsqueda 80 suele corresponder a la entidad.
 * Si en una instalación concreta este identificador cambia, el grupo puede
 * ajustarse manualmente desde la interfaz de GLPI Inventory.
 *
 * @return array
 */
function plugin_nautilus_obtener_criterio_grupo_inventory()
{
    $criterio = [
        'itemtype' => 'Computer',
        'criteria' => [
            [
                'field'      => 80,
                'searchtype' => 'equals',
                'value'      => PLUGIN_NAUTILUS_ENTIDAD_RAIZ_ID
            ]
        ],
        'metacriteria' => [],
        'sort'  => 1,
        'order' => 'ASC',
        'start' => 0
    ];

    return $criterio;
}

/**
 * Crea el registro principal del grupo de ordenadores en GLPI Inventory.
 *
 * El campo "type" es obligatorio en la tabla de grupos de GLPI Inventory.
 * Para un grupo dinámico debe usarse el valor "DYNAMIC".
 *
 * @return int|null
 */
function plugin_nautilus_crear_registro_grupo_inventory()
{
    global $DB;

    $id_grupo = plugin_nautilus_obtener_grupo_inventory();
    $tabla_grupos = 'glpi_plugin_glpiinventory_deploygroups';

    if (
        $id_grupo === null
        && $DB->tableExists($tabla_grupos)
    ) {
        $datos_grupo = [
            'name' => PLUGIN_NAUTILUS_GRUPO_INVENTORY,
            'type' => 'DYNAMIC'
        ];

        if (plugin_nautilus_existe_campo($tabla_grupos, 'comment')) {
            $datos_grupo['comment'] = PLUGIN_NAUTILUS_GRUPO_INVENTORY_DESCRIPCION;
        }

        if (plugin_nautilus_existe_campo($tabla_grupos, 'is_active')) {
            $datos_grupo['is_active'] = 1;
        }

        if (plugin_nautilus_existe_campo($tabla_grupos, 'entities_id')) {
            $datos_grupo['entities_id'] = PLUGIN_NAUTILUS_ENTIDAD_RAIZ_ID;
        }

        if (plugin_nautilus_existe_campo($tabla_grupos, 'is_recursive')) {
            $datos_grupo['is_recursive'] = 1;
        }

        $DB->insert($tabla_grupos, $datos_grupo);
        $id_grupo = (int)$DB->insertId();

        plugin_nautilus_log(
            "[GLPI Inventory] Grupo dinámico creado: " . PLUGIN_NAUTILUS_GRUPO_INVENTORY
        );
    }

    return $id_grupo;
}

/**
 * Obtiene el nombre del campo donde GLPI Inventory guarda el criterio dinámico.
 *
 * Según la versión del plugin, el campo puede variar. Por eso se comprueban
 * varios nombres posibles.
 *
 * @return string|null
 */
function plugin_nautilus_obtener_campo_criterio_inventory()
{
    $campo_criterio = null;
    $tabla_dinamica = 'glpi_plugin_glpiinventory_deploygroups_dynamicdatas';

    $campos_posibles = [
        'fields_array',
        'search',
        'criteria',
        'serialized_search'
    ];

    foreach ($campos_posibles as $campo) {
        if (plugin_nautilus_existe_campo($tabla_dinamica, $campo)) {
            $campo_criterio = $campo;
            break;
        }
    }

    return $campo_criterio;
}

/**
 * Crea o actualiza los datos dinámicos del grupo de GLPI Inventory.
 *
 * Esta parte almacena el criterio del grupo dinámico. El criterio seleccionado
 * incluye los ordenadores de la entidad raíz.
 *
 * @param int $id_grupo
 *
 * @return void
 */
function plugin_nautilus_guardar_criterio_grupo_inventory($id_grupo)
{
    global $DB;

    $tabla_dinamica = 'glpi_plugin_glpiinventory_deploygroups_dynamicdatas';

    if (
        $id_grupo
        && $DB->tableExists($tabla_dinamica)
    ) {
        $campo_criterio = plugin_nautilus_obtener_campo_criterio_inventory();

        if ($campo_criterio === null) {
            plugin_nautilus_log(
                "[GLPI Inventory] No se ha localizado el campo de criterio del grupo dinámico"
            );
        } else {
            $criterio = plugin_nautilus_obtener_criterio_grupo_inventory();
            $criterio_json = json_encode($criterio);

            $registro = plugin_nautilus_obtener_registro(
                $tabla_dinamica,
                [
                    'plugin_glpiinventory_deploygroups_id' => (int)$id_grupo
                ]
            );

            $datos_dinamicos = [
                'plugin_glpiinventory_deploygroups_id' => (int)$id_grupo,
                $campo_criterio => $criterio_json
            ];

            if (plugin_nautilus_existe_campo($tabla_dinamica, 'computers_id_cache')) {
                $datos_dinamicos['computers_id_cache'] = '[]';
            }

            if ($registro) {
                $DB->update(
                    $tabla_dinamica,
                    $datos_dinamicos,
                    [
                        'id' => (int)$registro['id']
                    ]
                );

                plugin_nautilus_log(
                    "[GLPI Inventory] Criterio actualizado para el grupo dinámico"
                );
            } else {
                $DB->insert($tabla_dinamica, $datos_dinamicos);

                plugin_nautilus_log(
                    "[GLPI Inventory] Criterio creado para el grupo dinámico"
                );
            }
        }
    }
}

/**
 * Crea el grupo dinámico de GLPI Inventory para el despliegue de Nemo.
 *
 * El grupo se crea si existe el plugin GLPI Inventory y sus tablas están
 * disponibles. Si no están disponibles, la instalación de Nautilus continúa
 * sin interrumpirse.
 *
 * @return void
 */
function plugin_nautilus_crear_grupo_dinamico_inventory()
{
    global $DB;

    $tabla_grupos = 'glpi_plugin_glpiinventory_deploygroups';
    $tabla_dinamica = 'glpi_plugin_glpiinventory_deploygroups_dynamicdatas';

    if (
        Plugin::isPluginActive('glpiinventory')
        && $DB->tableExists($tabla_grupos)
        && $DB->tableExists($tabla_dinamica)
    ) {
        $id_grupo = plugin_nautilus_crear_registro_grupo_inventory();

        if ($id_grupo) {
            plugin_nautilus_guardar_criterio_grupo_inventory($id_grupo);
        }
    } else {
        plugin_nautilus_log(
            "[GLPI Inventory] No se crea grupo dinámico. Plugin o tablas no disponibles."
        );
    }
}

/**
 * Debido a las limitaciones presentadas en el mecanismo de wakeup del GLPI Agent,
 * se opta por deshabilitar la tarea wakeupAgents, evitando así ejecuciones fallidas,
 * delegando la ejecución en el modo servicio del agente, y que sea éste quien entre
 * en ejecución. Dependerá del periodo en GLPI inventario, que se recomienda cada 4-5 horas
 *
 * @return void
 */
function plugin_nautilus_disable_wakeup()
{
    global $DB;

    $DB->update(
        'glpi_crontasks',
        ['state' => 0],
        [
            'name'     => 'wakeupAgents',
            'itemtype' => 'PluginGlpiinventoryAgentWakeup'
        ]
    );
}


/* =========================
   INSTALACIÓN
   ========================= */

/**
 * Instala el plugin Nautilus.
 *
 * @return bool
 */
function plugin_nautilus_install()
{
    $instalado = true;

    plugin_nautilus_crear_perfil_nemo();
    plugin_nautilus_crear_usuario_nemo();
    plugin_nautilus_habilitar_api_inventario();
    plugin_nautilus_crear_cliente_api();
    plugin_nautilus_registrar_crons();
    plugin_nautilus_crear_grupo_dinamico_inventory();
    plugin_nautilus_preparar_fields();
    plugin_nautilus_crear_tabla_configuracion();
    plugin_nautilus_crear_registro_configuracion();
    plugin_nautilus_limpiar_cache();
    plugin_nautilus_disable_wakeup();

    return $instalado;
}

/* =========================
   DESINSTALACIÓN
   ========================= */

/**
 * Desinstala el plugin Nautilus.
 *
 * Se eliminan:
 * - tareas cron propias del plugin;
 * - tabla de configuración propia del plugin.
 *
 * No se elimina el bloque Fields GLPI-Nautilus.
 *
 * Motivo:
 * El bloque Fields puede contener datos funcionales o históricos
 * como estado_backup y fecha_ultimo_backup. Eliminarlo automáticamente
 * podría provocar pérdida de información.
 *
 * @return bool
 */
function plugin_nautilus_uninstall()
{
    global $DB;

    $desinstalado = true;
    $tabla = PLUGIN_NAUTILUS_TABLA_CONFIG;

    $DB->delete('glpi_crontasks', [
        'itemtype' => 'PluginNautilusCron'
    ]);

    if ($DB->tableExists($tabla)) {
        $DB->doQuery("DROP TABLE `$tabla`");
    }

    plugin_nautilus_limpiar_cache();

    return $desinstalado;
}
