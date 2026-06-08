<?php

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