<?php

if (!defined('GLPI_ROOT')) {
    die("Acceso no permitido");
}

/**
 * Tareas programadas del plugin Nautilus.
 *
 * Tareas:
 * - urbackup_clean: elimina de UrBackup clientes que no deben mantenerse.
 * - urbackup_sync: sincroniza la fecha del último backup desde UrBackup a GLPI Fields.
 */
class PluginNautilusCron extends CommonGLPI
{
    private const TABLA_FIELDS = 'glpi_plugin_fields_computerglpinautilus';
    private const ITEMTYPE_EQUIPO = 'Computer';

    /* =========================
       INFORMACIÓN DE CRON
       ========================= */

    /**
     * Devuelve la información descriptiva de cada tarea cron.
     *
     * Este método lo utiliza GLPI para mostrar la descripción de las tareas
     * automáticas del plugin.
     *
     * @param string $name Nombre interno de la tarea.
     *
     * @return array
     */
    public static function cronInfo($name)
    {
        $info = [];

        switch ($name) {
            case 'urbackup_clean':
                $info = [
                    'description' => 'Nautilus: limpieza de clientes desactivados en UrBackup',
                    'parameter'   => 'Margen de seguridad en minutos'
                ];
                break;

            case 'urbackup_sync':
                $info = [
                    'description' => 'Nautilus: sincronización de fecha de último backup desde UrBackup'
                ];
                break;
        }

        return $info;
    }

    /* =========================
       UTILIDADES GENERALES
       ========================= */

    /**
     * Escribe una línea en el log propio del cron de Nautilus.
     *
     * GLPI ya añade la fecha en el log, por eso aquí solo se escribe el mensaje.
     *
     * @param string $mensaje
     *
     * @return void
     */
    private static function escribirLog($mensaje)
    {
        Toolbox::logInFile('nautilus_cron', $mensaje . "\n");
    }

    /**
     * Comprueba que existe la tabla de campos personalizados usada por Nautilus.
     *
     * @return bool
     */
    private static function existeTablaFields()
    {
        global $DB;

        $existe = false;

        if ($DB->tableExists(self::TABLA_FIELDS)) {
            $existe = true;
        } else {
            self::escribirLog("[ERROR] No existe la tabla " . self::TABLA_FIELDS);
        }

        return $existe;
    }

    /**
     * Comprueba si un valor representa verdadero.
     *
     * Se usa porque UrBackup puede devolver valores booleanos, enteros
     * o cadenas según el origen del JSON.
     *
     * @param mixed $valor
     *
     * @return bool
     */
    private static function valorVerdadero($valor)
    {
        return in_array(
            $valor,
            [true, 1, '1', 'true', 'True', 'TRUE', 'yes', 'YES', 'on', 'ON'],
            true
        );
    }

    /**
     * Comprueba si el ID recibido desde UrBackup es válido.
     *
     * @param mixed $id_cliente
     *
     * @return bool
     */
    private static function idClienteValido($id_cliente)
    {
        $valido = !in_array($id_cliente, [null, '', '-'], true);

        return $valido;
    }

    private static function clienteUrbackupValido($cliente)
    {
        $id_cliente = $cliente['id'] ?? null;
        $rechazado  = $cliente['rejected'] ?? false;
        $nombre     = self::obtenerNombreClienteUrbackup($cliente);

        $campos_eliminacion = [
            'deleted',
            'deleting',
            'delete_pending',
            'pending_delete',
            'remove_pending',
            'pending_remove',
            'marked_for_deletion',
            'client_delete',
            'client_delete_pending',
        ];

        if (!self::idClienteValido($id_cliente)) {
            self::escribirLog(
                "[urbackup] Cliente no válido: ID no válido. Cliente={$nombre}"
            );
            return false;
        }

        if (self::valorVerdadero($rechazado)) {
            self::escribirLog(
                "[urbackup] Cliente no válido: rechazado. Cliente={$nombre}"
            );
            return false;
        }

        foreach ($campos_eliminacion as $campo) {
            if (
                array_key_exists($campo, $cliente)
                && self::valorVerdadero($cliente[$campo])
            ) {
                self::escribirLog(
                    "[urbackup] Cliente no válido: eliminado o pendiente de eliminación. " .
                        "Cliente={$nombre} | Campo={$campo}"
                );
                return false;
            }
        }

        if (isset($cliente['status'])) {
            $estado = strtolower(trim((string)$cliente['status']));

            if (in_array($estado, ['deleted', 'deleting', 'remove', 'removing'], true)) {
                self::escribirLog(
                    "[urbackup] Cliente no válido: estado de eliminación. " .
                        "Cliente={$nombre} | Estado={$estado}"
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Devuelve el nombre del cliente de UrBackup.
     *
     * @param array $cliente
     *
     * @return string
     */
    private static function obtenerNombreClienteUrbackup($cliente)
    {
        $nombre_cliente = trim((string)($cliente['name'] ?? 'SIN_NOMBRE'));

        return $nombre_cliente;
    }

    /* =========================
       CONSULTAS GLPI
       ========================= */

    /**
     * Obtiene el ID de un ordenador en GLPI a partir de su nombre.
     *
     * @param string $nombre_equipo
     *
     * @return int|null
     */
    public static function obtenerIdOrdenador($nombre_equipo)
    {
        global $DB;

        $id_equipo = null;
        $nombre_equipo = trim((string)$nombre_equipo);

        if ($nombre_equipo !== '') {
            $resultado = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => [
                    'name'       => $nombre_equipo,
                    'is_deleted' => 0
                ],
                'LIMIT' => 1
            ]);

            $equipo = $resultado->current();

            if ($equipo) {
                $id_equipo = (int)$equipo['id'];
            }
        }

        return $id_equipo;
    }

    /**
     * Comprueba si existe un ordenador activo en GLPI.
     *
     *
     * @param int|string $items_id
     *
     * @return bool
     */
    public static function existId($items_id)
    {
        global $DB;

        $existe = false;
        $items_id = (int)$items_id;

        if ($items_id > 0) {
            $resultado = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => [
                    'id'         => $items_id,
                    'is_deleted' => 0
                ],
                'LIMIT' => 1
            ]);

            if ($resultado->current()) {
                $existe = true;
            }
        }

        return $existe;
    }

    /**
     * Obtiene el valor del campo estado_backup desde GLPI Fields.
     *
     * @param int $items_id
     *
     * @return int|null
     */
    public static function obtenerEstadoBackup($items_id)
    {
        global $DB;

        $estado_backup = null;

        if (self::existeTablaFields()) {
            $iterator = $DB->request([
                'SELECT' => ['estado_backup'],
                'FROM'   => self::TABLA_FIELDS,
                'WHERE'  => [
                    'items_id' => (int)$items_id,
                    'itemtype' => self::ITEMTYPE_EQUIPO
                ],
                'LIMIT' => 1
            ]);

            if ($iterator->count() > 0) {
                $fila = $iterator->current();
                $estado_backup = (int)$fila['estado_backup'];
            }
        }

        return $estado_backup;
    }

    /**
     * Obtiene la fecha del último backup almacenada en GLPI Fields.
     *
     * @param int $items_id
     *
     * @return string|null
     */
    public static function obtenerFechaUltimoBackup($items_id)
    {
        global $DB;

        $fecha_ultimo_backup = null;

        if (self::existeTablaFields()) {
            $iterator = $DB->request([
                'SELECT' => ['fecha_ultimo_backup'],
                'FROM'   => self::TABLA_FIELDS,
                'WHERE'  => [
                    'items_id' => (int)$items_id,
                    'itemtype' => self::ITEMTYPE_EQUIPO
                ],
                'LIMIT' => 1
            ]);

            if ($iterator->count() > 0) {
                $fila = $iterator->current();
                $fecha_ultimo_backup = $fila['fecha_ultimo_backup'];
            }
        }

        return $fecha_ultimo_backup;
    }

    /**
     * Obtiene el registro de GLPI Fields asociado a un equipo.
     *
     * @param int $items_id
     *
     * @return array|null
     */
    private static function obtenerRegistroCampos($items_id)
    {
        global $DB;

        $registro = null;

        if (self::existeTablaFields()) {
            $iterator = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::TABLA_FIELDS,
                'WHERE'  => [
                    'items_id' => (int)$items_id,
                    'itemtype' => self::ITEMTYPE_EQUIPO
                ],
                'LIMIT' => 1
            ]);

            if ($iterator->count() > 0) {
                $registro = $iterator->current();
            }
        }

        return $registro;
    }

    /**
     * Guarda la fecha del último backup en GLPI Fields.
     *
     * Si ya existe registro, lo actualiza.
     * Si no existe, lo crea.
     *
     * Nota:
     * Se inicializa estado_backup a 1 para sincronizar equipos introducidos
     * manualmente en UrBackup. Si se desea control total desde GLPI, puede
     * eliminarse esa asignación.
     *
     * @param int    $items_id
     * @param string $fecha_ultimo_backup
     *
     * @return bool
     */
    private static function guardarFechaUltimoBackup($items_id, $fecha_ultimo_backup)
    {
        global $DB;

        $guardado = false;
        $registro = self::obtenerRegistroCampos($items_id);

        if ($registro !== null) {
            $guardado = $DB->update(
                self::TABLA_FIELDS,
                [
                    'fecha_ultimo_backup' => $fecha_ultimo_backup
                ],
                [
                    'id' => (int)$registro['id']
                ]
            );
        } else {
            $guardado = $DB->insert(
                self::TABLA_FIELDS,
                [
                    'items_id'            => (int)$items_id,
                    'itemtype'            => self::ITEMTYPE_EQUIPO,
                    'estado_backup'       => 1,
                    'fecha_ultimo_backup' => $fecha_ultimo_backup
                ]
            );
        }

        if ($guardado) {
            if ($fecha_ultimo_backup != null) {
                self::escribirLog(
                    "[urbackup_sync] Se almacena la fecha {$fecha_ultimo_backup} en el Cliente={$items_id}"
                );
            } else {
                self::escribirLog(
                    "[urbackup_sync] Se borra la fecha en el Cliente={$items_id}"
                );
            }
        }

        return $guardado;
    }

    /* =========================
       PROCESO: LIMPIEZA
       ========================= */

    /**
     * Determina si un cliente de UrBackup debe eliminarse.
     *
     * Un cliente se elimina si:
     * - no existe el equipo en GLPI;
     * - existe, pero estado_backup es null o 0.
     *
     * @param array  $cliente
     * @param string $nombre_cliente
     *
     * @return bool
     */
    private static function clienteDebeBorrarse($cliente, $nombre_cliente)
    {
        $debe_borrarse = false;

        if (!self::existId($nombre_cliente)) {
            $debe_borrarse = true;
            self::escribirLog(
                "[urbackup_clean] No existe el cliente en GLPI. Cliente={$nombre_cliente}"
            );
        } else {
            $estado_backup = self::obtenerEstadoBackup($nombre_cliente);

            if ($estado_backup === null || $estado_backup === 0) {
                $debe_borrarse = true;
                self::escribirLog(
                    "[urbackup_clean] Backup desactivado. Cliente={$nombre_cliente}"
                );
            }
        }

        return $debe_borrarse;
    }

    /**
     * Procesa un cliente dentro de la tarea urbackup_clean.
     *
     * @param PluginNautilusApi $api
     * @param CronTask          $task
     * @param array             $cliente
     *
     * @return bool
     */
    private static function procesarClienteLimpieza($api, CronTask $task, $cliente)
    {
        $borrado_correcto = false;
        $nombre_cliente = self::obtenerNombreClienteUrbackup($cliente);

        if (!self::clienteUrbackupValido($cliente)) {
            self::escribirLog(
                "[urbackup_clean] Cliente sin ID válido o rechazado. Cliente={$nombre_cliente}"
            );
        } else {
            $debe_borrarse = self::clienteDebeBorrarse($cliente, $nombre_cliente);

            if ($debe_borrarse) {
                $borrado_correcto = $api->borrarCliente($nombre_cliente);

                if ($borrado_correcto) {
                    $task->addVolume(1);
                    self::escribirLog(
                        "[urbackup_clean] Cliente eliminado de UrBackup. Cliente={$nombre_cliente}"
                    );
                } else {
                    self::escribirLog(
                        "[urbackup_clean] Error al eliminar cliente de UrBackup. Cliente={$nombre_cliente}"
                    );
                }
            }
        }

        return $borrado_correcto;
    }

    /* =========================
       CRON: urbackup_clean
       ========================= */

    /**
     * Limpia de UrBackup los clientes que ya no deben estar activos.
     *
     * @param CronTask $task
     *
     * @return int
     */
    public static function cronurbackup_clean(CronTask $task)
    {
        $resultado = 0;
        $contador_borrados = 0;

        self::escribirLog("[urbackup_clean] Inicio");

        if (self::existeTablaFields()) {
            $api = new PluginNautilusApi();
            $clientes = $api->getUrbackupClients();

            if (!empty($clientes)) {
                foreach ($clientes as $cliente) {
                    $borrado = self::procesarClienteLimpieza($api, $task, $cliente);

                    if ($borrado) {
                        $contador_borrados++;
                    }
                }

                if ($contador_borrados > 0) {
                    $resultado = 1;
                }
            } else {
                self::escribirLog(
                    "[urbackup_clean] No se han obtenido clientes desde UrBackup"
                );
            }
        }

        self::escribirLog(
            "[urbackup_clean] Fin. Clientes borrados={$contador_borrados}"
        );

        return $resultado;
    }

    /* =========================
       PROCESO: SINCRONIZACIÓN
       ========================= */

    /**
     * Procesa un cliente dentro de la tarea urbackup_sync.
     *
     * @param CronTask $task
     * @param array    $cliente
     *
     * @return bool
     */
    private static function procesarClienteSincronizacion(CronTask $task, $cliente)
    {
        $actualizado = false;

        $nombre_cliente = self::obtenerNombreClienteUrbackup($cliente);
        $fecha_timestamp = $cliente['lastbackup'] ?? 0;

        if (!self::clienteUrbackupValido($cliente)) {
            // Guarda null en fecha en GLPI para clientes rechazados, pendientes de borrado, ...
            $guardado = self::guardarFechaUltimoBackup(
                $nombre_cliente,
                null
            );

            self::escribirLog(
                "[urbackup_sync] Cliente sin ID válido, rechazado, pendiende de borrado, ... Cliente={$nombre_cliente}"
            );
        } else {
            if (
                self::existId($nombre_cliente)
                && (int)$fecha_timestamp > 0
            ) {
                $fecha_ultimo_backup = date("Y-m-d H:i:s", (int)$fecha_timestamp);

                $guardado = self::guardarFechaUltimoBackup(
                    $nombre_cliente,
                    $fecha_ultimo_backup
                );

                if ($guardado) {
                    $actualizado = true;
                    $task->addVolume(1);

                    self::escribirLog(
                        "[urbackup_sync] Fecha actualizada. Cliente={$nombre_cliente} | Fecha={$fecha_ultimo_backup}"
                    );
                } else {
                    self::escribirLog(
                        "[urbackup_sync] Error al guardar fecha. Cliente={$nombre_cliente}"
                    );
                }
            } else {
                self::escribirLog(
                    "[urbackup_sync] Cliente ignorado. Cliente={$nombre_cliente} | ID equipo no encontrado o fecha no válida"
                );
            }
        }

        return $actualizado;
    }

    /* =========================
       CRON: urbackup_sync
       ========================= */

    /**
     * Sincroniza la fecha del último backup desde UrBackup hacia GLPI Fields.
     *
     * @param CronTask $task
     *
     * @return int
     */
    public static function cronurbackup_sync(CronTask $task)
    {
        $resultado = 0;
        $contador_actualizados = 0;

        self::escribirLog("[urbackup_sync] Inicio");

        if (self::existeTablaFields()) {
            $api = new PluginNautilusApi();
            $clientes = $api->getUrbackupClients();

            if (!empty($clientes)) {
                foreach ($clientes as $cliente) {
                    $actualizado = self::procesarClienteSincronizacion($task, $cliente);

                    if ($actualizado) {
                        $contador_actualizados++;
                    }
                }

                if ($contador_actualizados > 0) {
                    $resultado = 1;
                }
            } else {
                self::escribirLog(
                    "[urbackup_sync] No se han obtenido clientes desde UrBackup"
                );
            }
        }

        self::escribirLog(
            "[urbackup_sync] Fin. Registros actualizados={$contador_actualizados}"
        );

        return $resultado;
    }
}
