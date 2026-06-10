<?php

/*
Proyecto: GLPI-Nautilus
Repositorio: https://github.com/javiermengu/GLPI-Nautilus
Autor: Francisco Javier Mengual Maldonado
Descripción:
    GLPI-Nautilus es una solución basada en software libre para centralizar 
    la gestión de copias de seguridad de equipos inventariados en GLPI.

    Contiene la clase principal del plugin y los métodos de integración con la interfaz de GLPI.

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

// ==========================================================================
// SEGURIDAD OBLIGATORIA GLPI: Impide el acceso directo al archivo por URL
// ==========================================================================
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

/**
 * Plugin Nautilus - Clase Principal de Interfaz
 * Manejo de visualización y hooks de formulario para Ordenadores
 * Adaptado para el framework GLPI 11 moderno (PHP 8.x)
 */
class PluginNautilus extends CommonGLPI {

    /**
     * Define el tipo de objeto o pestaña que se inyectará en la interfaz.
     */
    static function getTypeName($nb = 0) {
        return __('GLPI-Nautilus', 'nautilus');
    }

    /**
     * Hook pre_item_form
     * Este método se ejecuta automáticamente antes de pintar el formulario del activo.
     * Al haberlo registrado en el setup.php para 'Computer', el core nos pasa el objeto.
     * * @param CommonDBTM $item El elemento del inventario (En este caso, un objeto Computer)
     */
    static function pre_item_form(CommonDBTM $item) {
        // En este TFG, el bloque visual se gestiona a través del contenedor del plugin 'Fields'.
        // El propio framework de Fields renderiza los inputs de "Estado Backup" y "Fecha".
        
        // Este método queda preparado en la arquitectura por si
        // futuras versiones se necesita inyectar alertas de salud del agente UrBackup,
        // bloquear la edición si el ordenador está apagado, o cargar JS/CSS personalizado.
        
        return true;
    }
}