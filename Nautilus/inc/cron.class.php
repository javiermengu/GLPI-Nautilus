<?php
/*
Proyecto: GLPI-Nautilus
Repositorio: https://github.com/javiermengu/GLPI-Nautilus
Autor: Francisco Javier Mengual Maldonado
Descripción:
    GLPI-Nautilus es una solución basada en software libre para centralizar 
    la gestión de copias de seguridad de equipos inventariados en GLPI.

    Contiene las tareas automáticas del plugin, urbackup_clean y urbackup_sync.

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

    /* =========================================================
       INFORMACIÓN CRON
       ========================================================= */

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

    /* =========================================================
       LOG
       ========================================================= */

    private static function escribirLog($mensaje)
    {
        Toolbox::logInFile(
            'nautilus_cron',
            $mensaje . "\n"
        );
    }

    /* =========================================================
       UTILIDADES
       ========================================================= */

    private static function existeTablaFields()
    {
        global $DB;

        $existe = false;

        if ($DB->tableExists(self::TABLA_FIELDS)) {

            $existe = true;
        } else {

            self::escribirLog(
                "[ERROR] No existe la tabla " .
                    self::TABLA_FIELDS
            );
        }

        return $existe;
    }

    private static function valorVerdadero($valor)
    {
        return in_array(
            $valor,
            [
                true,
                1,
                '1',
                'true',
                'True',
                'TRUE',
                'yes',
                'YES',
                'on',
                'ON'
            ],
            true
        );
    }

    private static function idClienteValido($id_cliente)
    {
        return !in_array(
            $id_cliente,
            [null, '', '-'],
            true
        );
    }

    private static function obtenerNombreClienteUrbackup($cliente)
    {
        return trim(
            (string)($cliente['name'] ?? 'SIN_NOMBRE')
        );
    }

    private static function clienteUrbackupValido($cliente)
    {
        $id_cliente = $cliente['id'] ?? null;

        $rechazado = $cliente['rejected'] ?? false;

        $nombre = self::obtenerNombreClienteUrbackup(
            $cliente
        );

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
                "[urbackup] Cliente no válido: " .
                    "ID inválido. Cliente={$nombre}"
            );

            return false;
        }

        if (self::valorVerdadero($rechazado)) {

            self::escribirLog(
                "[urbackup] Cliente rechazado. " .
                    "Cliente={$nombre}"
            );

            return false;
        }

        foreach ($campos_eliminacion as $campo) {

            if (
                array_key_exists($campo, $cliente)
                && self::valorVerdadero($cliente[$campo])
            ) {

                self::escribirLog(
                    "[urbackup] Cliente pendiente " .
                        "de eliminación. Cliente={$nombre}"
                );

                return false;
            }
        }

        if (isset($cliente['status'])) {

            $estado = strtolower(
                trim((string)$cliente['status'])
            );

            if (
                in_array(
                    $estado,
                    [
                        'deleted',
                        'deleting',
                        'remove',
                        'removing'
                    ],
                    true
                )
            ) {

                self::escribirLog(
                    "[urbackup] Cliente en estado " .
                        "de eliminación. Cliente={$nombre}"
                );

                return false;
            }
        }

        return true;
    }

    /* =========================================================
       GLPI
       ========================================================= */

    public static function obtenerIdOrdenador($nombre_equipo)
    {
        global $DB;

        $id_equipo = null;

        $nombre_equipo = trim(
            (string)$nombre_equipo
        );

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

    public static function existId($items_id)
    {
        global $DB;

        $items_id = (int)$items_id;

        if ($items_id <= 0) {
            return false;
        }

        $resultado = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => [
                'id'         => $items_id,
                'is_deleted' => 0
            ],
            'LIMIT' => 1
        ]);

        return (bool)$resultado->current();
    }

    public static function obtenerEstadoBackup($items_id)
    {
        global $DB;

        if (!self::existeTablaFields()) {
            return null;
        }

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

            return (int)$fila['estado_backup'];
        }

        return null;
    }

    public static function obtenerFechaUltimoBackup($items_id)
    {
        global $DB;

        if (!self::existeTablaFields()) {
            return null;
        }

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

            return $fila['fecha_ultimo_backup'];
        }

        return null;
    }

    private static function obtenerRegistroCampos($items_id)
    {
        global $DB;

        if (!self::existeTablaFields()) {
            return null;
        }

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
            return $iterator->current();
        }

        return null;
    }

    private static function guardarFechaUltimoBackup(
        $items_id,
        $fecha_ultimo_backup
    ) {
        global $DB;

        $guardado = false;

        $registro = self::obtenerRegistroCampos(
            $items_id
        );

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

            if ($fecha_ultimo_backup !== null) {

                self::escribirLog(
                    "[urbackup_sync] Fecha guardada. " .
                        "Cliente={$items_id} | " .
                        "Fecha={$fecha_ultimo_backup}"
                );
            } else {

                self::escribirLog(
                    "[urbackup_sync] Fecha eliminada. " .
                        "Cliente={$items_id}"
                );
            }
        }

        return $guardado;
    }

    /* =========================================================
       LIMPIEZA URBACKUP
       ========================================================= */

    private static function clienteDebeBorrarse(
        $cliente,
        $nombre_cliente
    ) {
        if (!self::existId($nombre_cliente)) {

            self::escribirLog(
                "[urbackup_clean] Cliente inexistente " .
                    "en GLPI. Cliente={$nombre_cliente}"
            );

            return true;
        }

        $estado_backup = self::obtenerEstadoBackup(
            $nombre_cliente
        );

        if (
            $estado_backup === null
            || $estado_backup === 0
        ) {

            self::escribirLog(
                "[urbackup_clean] Backup desactivado. " .
                    "Cliente={$nombre_cliente}"
            );

            return true;
        }

        return false;
    }

    private static function procesarClienteLimpieza(
        $api,
        CronTask $task,
        $cliente
    ) {
        $nombre_cliente = self::obtenerNombreClienteUrbackup(
            $cliente
        );

        if (!self::clienteUrbackupValido($cliente)) {

            self::escribirLog(
                "[urbackup_clean] Cliente inválido. " .
                    "Cliente={$nombre_cliente}"
            );

            return false;
        }

        if (
            self::clienteDebeBorrarse(
                $cliente,
                $nombre_cliente
            )
        ) {

            $borrado = $api->borrarCliente(
                $nombre_cliente
            );

            if ($borrado) {

                $task->addVolume(1);

                self::escribirLog(
                    "[urbackup_clean] Cliente eliminado. " .
                        "Cliente={$nombre_cliente}"
                );

                return true;
            }

            self::escribirLog(
                "[urbackup_clean] Error eliminando cliente. " .
                    "Cliente={$nombre_cliente}"
            );
        }

        return false;
    }

    /* =========================================================
       CRON urbackup_clean
       ========================================================= */

    public static function cronurbackup_clean(CronTask $task)
    {
        $resultado = 0;

        $contador_borrados = 0;

        self::escribirLog(
            "[urbackup_clean] Inicio"
        );

        if (self::existeTablaFields()) {

            $api = new PluginNautilusApi();

            $clientes = $api->getUrbackupClients();

            if (!empty($clientes)) {

                foreach ($clientes as $cliente) {

                    $borrado = self::procesarClienteLimpieza(
                        $api,
                        $task,
                        $cliente
                    );

                    if ($borrado) {
                        $contador_borrados++;
                    }
                }

                if ($contador_borrados > 0) {
                    $resultado = 1;
                }
            } else {

                self::escribirLog(
                    "[urbackup_clean] No se han obtenido clientes"
                );
            }
        }

        self::escribirLog(
            "[urbackup_clean] Fin. " .
                "Clientes borrados={$contador_borrados}"
        );

        return $resultado;
    }

    /* =========================================================
       SINCRONIZACIÓN
       ========================================================= */

    private static function procesarClienteSincronizacion(
        CronTask $task,
        $cliente
    ) {
        $actualizado = false;

        $nombre_cliente = self::obtenerNombreClienteUrbackup(
            $cliente
        );

        $fecha_timestamp = $cliente['lastbackup'] ?? 0;

        if (!self::clienteUrbackupValido($cliente)) {

            self::guardarFechaUltimoBackup(
                $nombre_cliente,
                null
            );

            self::escribirLog(
                "[urbackup_sync] Cliente inválido. " .
                    "Cliente={$nombre_cliente}"
            );

            return false;
        }

        if (!self::existId($nombre_cliente)) {

            self::escribirLog(
                "[urbackup_sync] Equipo inexistente en GLPI. " .
                    "Cliente={$nombre_cliente}"
            );

            return false;
        }

        if ((int)$fecha_timestamp > 0) {

            $fecha_ultimo_backup = date(
                "Y-m-d H:i:s",
                (int)$fecha_timestamp
            );

            $guardado = self::guardarFechaUltimoBackup(
                $nombre_cliente,
                $fecha_ultimo_backup
            );

            if ($guardado) {

                $actualizado = true;

                $task->addVolume(1);

                self::escribirLog(
                    "[urbackup_sync] Fecha actualizada. " .
                        "Cliente={$nombre_cliente} | " .
                        "Fecha={$fecha_ultimo_backup}"
                );
            }
        } else {

            self::guardarFechaUltimoBackup(
                $nombre_cliente,
                null
            );

            self::escribirLog(
                "[urbackup_sync] Fecha eliminada por timestamp inválido. " .
                    "Cliente={$nombre_cliente}"
            );
        }

        return $actualizado;
    }

    private static function limpiarFechasClientesInexistentes($clientes_urbackup) {
        global $DB;

        $ids_urbackup = [];

        foreach ($clientes_urbackup as $cliente) {

            $nombre_cliente = self::obtenerNombreClienteUrbackup(
                $cliente
            );

            if (self::existId($nombre_cliente)) {

                $ids_urbackup[] = (int)$nombre_cliente;
            }
        }

        $iterator = $DB->request([
            'SELECT' => [
                'items_id',
                'fecha_ultimo_backup'
            ],
            'FROM' => self::TABLA_FIELDS,
            'WHERE' => [
                'itemtype' => self::ITEMTYPE_EQUIPO
            ]
        ]);

        foreach ($iterator as $fila) {

            $items_id = (int)$fila['items_id'];

            $fecha_actual = $fila['fecha_ultimo_backup'];

            if (
                !in_array(
                    $items_id,
                    $ids_urbackup,
                    true
                )
                && $fecha_actual !== null
                && $fecha_actual !== ''
            ) {

                self::guardarFechaUltimoBackup(
                    $items_id,
                    null
                );

                self::escribirLog(
                    "[urbackup_sync] Fecha eliminada. " .
                        "Cliente={$items_id} no existe en UrBackup"
                );
            }
        }
    }

    /* =========================================================
       CRON urbackup_sync
       ========================================================= */

    public static function cronurbackup_sync(CronTask $task)
    {
        $resultado = 0;

        $contador_actualizados = 0;

        self::escribirLog(
            "[urbackup_sync] Inicio"
        );

        if (self::existeTablaFields()) {

            $api = new PluginNautilusApi();

            $clientes = $api->getUrbackupClients();

            if (!empty($clientes)) {

                foreach ($clientes as $cliente) {

                    $actualizado = self::procesarClienteSincronizacion(
                        $task,
                        $cliente
                    );

                    if ($actualizado) {
                        $contador_actualizados++;
                    }
                }

                self::limpiarFechasClientesInexistentes(
                    $clientes
                );

                if ($contador_actualizados > 0) {
                    $resultado = 1;
                }
            } else {

                self::escribirLog(
                    "[urbackup_sync] No se han obtenido clientes desde UrBackup"
                );

                self::limpiarFechasClientesInexistentes([]);
            }
        }

        self::escribirLog(
            "[urbackup_sync] Fin. " .
                "Registros actualizados={$contador_actualizados}"
        );

        return $resultado;
    }
}
