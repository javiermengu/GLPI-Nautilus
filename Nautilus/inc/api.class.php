<?php

/*
Proyecto: GLPI-Nautilus
Repositorio: https://github.com/javiermengu/GLPI-Nautilus
Autor: Francisco Javier Mengual Maldonado
Descripción:
    GLPI-Nautilus es una solución basada en software libre para centralizar 
    la gestión de copias de seguridad de equipos inventariados en GLPI.

    Contiene la comunicación con la API de UrBackup.

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
    die("Sorry. You can't access directly to this file");
}

/**
 * Clase de acceso a la API de UrBackup desde el plugin Nautilus.
 */
class PluginNautilusApi
{
    private $url_urbackup;
    private $usuario;
    private $password;
    private $token_sesion;

    /**
     * Carga la configuración de UrBackup almacenada en GLPI.
     */
    public function __construct()
    {
        $this->url_urbackup = '';
        $this->usuario      = '';
        $this->password     = '';
        $this->token_sesion = '';

        $configuracion = new PluginNautilusConfig();

        if ($configuracion->getFromDB(1)) {
            $this->url_urbackup = trim((string)($configuracion->fields['urbackup_url'] ?? ''));
            $this->usuario      = trim((string)($configuracion->fields['urbackup_username'] ?? ''));
            $this->password     = (string)($configuracion->fields['urbackup_password'] ?? '');

            /*
             * UrBackup utiliza normalmente la ruta /x para la API.
             * Si en la configuración se indica solo:
             *
             *     http://servidor:55414
             *
             * se transforma automáticamente en:
             *
             *     http://servidor:55414/x
             */
            if (
                $this->url_urbackup !== ''
                && !$this->terminaEn($this->url_urbackup, '/x')
            ) {
                $this->url_urbackup = rtrim($this->url_urbackup, '/') . '/x';
            }
        }
    }

    /* =========================
       FUNCIONES AUXILIARES
       ========================= */

    /**
     * Comprueba si un texto termina con un sufijo concreto.
     *
     * Se usa en lugar de str_ends_with para mantener el código más compatible.
     */
    private function terminaEn($texto, $sufijo)
    {
        $termina = false;

        if ($sufijo === '') {
            $termina = true;
        } else {
            $termina = substr($texto, -strlen($sufijo)) === $sufijo;
        }

        return $termina;
    }

    /**
     * Escribe una línea en el log del plugin.
     */
    private function escribirLog($mensaje)
    {
        Toolbox::logInFile('nautilus_api', $mensaje . "\n");
    }

    /**
     * Comprueba si un cliente está marcado como rechazado por UrBackup.
     */
    private function clienteRechazado($valor)
    {
        $rechazado = in_array(
            $valor,
            [true, 1, '1', 'true', 'True', 'yes', 'YES'],
            true
        );

        return $rechazado;
    }

    /**
     * Comprueba si el identificador de cliente recibido desde UrBackup es válido.
     */
    private function idClienteValido($id_cliente)
    {
        $valido = !in_array($id_cliente, [null, '', '-'], true);

        return $valido;
    }

    /**
     * Valida si la respuesta salt de UrBackup contiene los datos mínimos.
     */
    private function respuestaSaltValida($respuesta_salt)
    {
        $valida = false;

        if (
            !empty($respuesta_salt)
            && isset($respuesta_salt['ses'])
            && isset($respuesta_salt['salt'])
            && isset($respuesta_salt['rnd'])
        ) {
            $valida = true;
        }

        return $valida;
    }

    /* =========================
       PETICIONES A URBACKUP
       ========================= */

    /**
     * Ejecuta una petición POST contra la API de UrBackup.
     *
     * La API de UrBackup recibe la acción en el parámetro "a".
     * Si ya existe sesión, se añade automáticamente el parámetro "ses".
     *
     * @param string $accion
     * @param array  $parametros
     *
     * @return array|null
     */
    private function ejecutarPeticion($accion, $parametros = [])
    {
        $resultado = null;
        $url       = $this->url_urbackup . '?a=' . urlencode($accion);

        if (!empty($this->token_sesion)) {
            $parametros['ses'] = $this->token_sesion;
        }

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($parametros));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);

        $respuesta = curl_exec($curl);
        $error     = curl_error($curl);

        curl_close($curl);

        if ($respuesta === false) {
            $this->escribirLog("[CURL ERROR] {$error}");
        } else {
            $resultado = json_decode($respuesta, true);
        }

        return $resultado;
    }

    /* =========================
       LOGIN URBACKUP
       ========================= */

    /**
     * Inicia sesión en UrBackup usando el flujo real de autenticación:
     *
     * 1. Solicita salt, rnd y sesión temporal.
     * 2. Calcula el hash base de la contraseña.
     * 3. Aplica PBKDF2 si el servidor lo requiere.
     * 4. Calcula el hash final con rnd.
     * 5. Ejecuta login.
     *
     * @return bool
     */
    private function login()
    {
        $login_correcto = false;

        if (!empty($this->token_sesion)) {
            $login_correcto = true;
        } else {
            $this->escribirLog('[LOGIN] Paso 1: solicitud de salt');

            $respuesta_salt = $this->ejecutarPeticion('salt', [
                'username' => $this->usuario
            ]);

            if (!$this->respuestaSaltValida($respuesta_salt)) {
                $this->escribirLog('[LOGIN ERROR] Respuesta salt inválida');
            } else {
                $this->token_sesion = $respuesta_salt['ses'];

                $salt = $respuesta_salt['salt'];
                $rnd  = $respuesta_salt['rnd'];

                $this->escribirLog('[LOGIN] Paso 2: cálculo de hash');

                /*
                 * Hash inicial:
                 * UrBackup calcula primero MD5(salt + password).
                 */
                $hash_password = md5($salt . $this->password);

                /*
                 * Algunos servidores UrBackup requieren PBKDF2.
                 * Si pbkdf2_rounds existe y es mayor que cero, se aplica.
                 */
                if (!empty($respuesta_salt['pbkdf2_rounds'])) {
                    $hash_password = hash_pbkdf2(
                        'sha256',
                        hex2bin($hash_password),
                        $salt,
                        (int)$respuesta_salt['pbkdf2_rounds'],
                        0,
                        false
                    );
                }

                /*
                 * Hash final:
                 * UrBackup espera MD5(rnd + hash_calculado).
                 */
                $hash_final = md5($rnd . $hash_password);

                $this->escribirLog('[LOGIN] Paso 3: login');

                $respuesta_login = $this->ejecutarPeticion('login', [
                    'username' => $this->usuario,
                    'password' => $hash_final
                ]);

                if (
                    isset($respuesta_login['success'])
                    && $respuesta_login['success'] === true
                ) {
                    $login_correcto = true;
                    $this->escribirLog('[LOGIN OK]');
                } else {
                    $this->escribirLog(
                        '[LOGIN ERROR] ' . print_r($respuesta_login, true)
                    );
                }
            }
        }

        return $login_correcto;
    }

    /* =========================
       CLIENTES URBACKUP
       ========================= */

    /**
     * Obtiene la lista de clientes de UrBackup.
     *
     * Método público mantenido con el nombre original para no romper llamadas
     * existentes en otros ficheros del plugin.
     *
     * @return array
     */
    public function getUrbackupClients()
    {
        $clientes = [];

        if ($this->login()) {
            $datos = $this->ejecutarPeticion('status');
            $clientes = $datos['status'] ?? [];
        }

        return $clientes;
    }

    /**
     * Busca el ID de un cliente válido en la lista de clientes de UrBackup.
     *
     * Un cliente no se considera válido si:
     * - está rechazado;
     * - no tiene ID;
     * - tiene ID vacío o "-".
     *
     * Método público mantenido con el nombre original para compatibilidad.
     *
     * @param array  $clientes
     * @param string $nombre_cliente
     *
     * @return int|null
     */
    public function buscarIdCliente($clientes, $nombre_cliente)
    {
        $id_cliente_encontrado = null;
        $nombre_cliente        = trim((string)$nombre_cliente);

        if ($nombre_cliente !== '') {
            foreach ($clientes as $cliente) {
                $nombre_urbackup = trim((string)($cliente['name'] ?? ''));

                if ($nombre_urbackup === $nombre_cliente) {
                    $rechazado  = $cliente['rejected'] ?? false;
                    $id_cliente = $cliente['id'] ?? null;

                    if ($this->clienteRechazado($rechazado)) {
                        $this->escribirLog(
                            "[buscarIdCliente] Cliente rechazado: {$nombre_cliente}"
                        );
                    } elseif (!$this->idClienteValido($id_cliente)) {
                        $this->escribirLog(
                            "[buscarIdCliente] Cliente sin ID válido: {$nombre_cliente}"
                        );
                    } else {
                        $id_cliente_encontrado = (int)$id_cliente;

                        $this->escribirLog(
                            "[buscarIdCliente] Cliente válido. id: {$id_cliente_encontrado}"
                        );
                    }

                    break;
                }
            }
        }

        return $id_cliente_encontrado;
    }

    /**
     * Borra un cliente de UrBackup a partir de su nombre.
     *
     * Método público mantenido con el nombre original para compatibilidad.
     *
     * @param string $client_name
     *
     * @return bool
     */
    public function borrarCliente($client_name)
    {
        $borrado = false;

        if ($this->login()) {
            $clientes   = $this->getUrbackupClients();
            $id_cliente = $this->buscarIdCliente($clientes, $client_name);

            if ($id_cliente === null) {
                $this->escribirLog(
                    "[BORRAR CLIENTE] Cliente no encontrado en UrBackup: {$client_name}"
                );
            } else {
                $this->ejecutarPeticion('status', [
                    'remove_client' => (int)$id_cliente
                ]);

                $this->escribirLog(
                    "[DELETE] {$client_name} ({$id_cliente})"
                );

                $borrado = true;
            }
        }

        return $borrado;
    }
}
