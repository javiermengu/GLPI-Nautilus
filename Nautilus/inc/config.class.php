<?php

/*
Proyecto: GLPI-Nautilus
Repositorio: https://github.com/javiermengu/GLPI-Nautilus
Autor: Francisco Javier Mengual Maldonado
Descripción:
    GLPI-Nautilus es una solución basada en software libre para centralizar 
    la gestión de copias de seguridad de equipos inventariados en GLPI.

    Contiene la lógica de configuración del plugin.

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

class PluginNautilusConfig extends CommonDBTM {

    public static $rightname = 'config';

    public static function getTypeName($nb = 0) {
        return __('Configuración Nautilus', 'nautilus');
    }

    public static function getIcon() {
        return 'ti ti-settings';
    }

    public function showForm($ID, array $options = []) {

        global $CFG_GLPI;

        if (!Session::haveRight('config', UPDATE)) {
            Html::displayRightError();
        }

        $this->getFromDB(1);

        echo "<div class='container-fluid'>";

        echo "<form method='post' action='" .
            $CFG_GLPI['root_doc'] .
            "/plugins/nautilus/front/config.form.php?itemtype=pluginnautilusconfig&glpi_tab=1'>";

        echo "<input type='hidden' name='id' value='1'>";

        echo "<div class='card'>";

        echo "<div class='card-header'>";
        echo "<h3 class='card-title'>";
        echo __('Configuración API UrBackup', 'nautilus');
        echo "</h3>";
        echo "</div>";

        echo "<div class='card-body'>";

        echo "<div class='row g-3'>";

        // URL UrBackup
        echo "<div class='col-md-6'>";
        echo "<label class='form-label' for='urbackup_url'>";
        echo __('URL UrBackup', 'nautilus');
        echo "</label>";

        echo "<input type='text'
                     class='form-control'
                     id='urbackup_url'
                     name='urbackup_url'
                     placeholder='http://servidor-urbackup:55414'
                     value='" . Html::entities_deep($this->fields['urbackup_url'] ?? '') . "'>";

        echo "<div class='form-hint'>";
        echo __('Ejemplo: http://urbackup.midominio.local:55414', 'nautilus');
        echo "</div>";
        echo "</div>";

        // Usuario UrBackup
        echo "<div class='col-md-6'>";
        echo "<label class='form-label' for='urbackup_username'>";
        echo __('Usuario API UrBackup', 'nautilus');
        echo "</label>";

        echo "<input type='text'
                     class='form-control'
                     id='urbackup_username'
                     name='urbackup_username'
                     value='" . Html::entities_deep($this->fields['urbackup_username'] ?? '') . "'>";
        echo "</div>";

        // Password UrBackup
        echo "<div class='col-md-6'>";
        echo "<label class='form-label' for='urbackup_password'>";
        echo __('Password API UrBackup', 'nautilus');
        echo "</label>";

        echo "<input type='password'
                     class='form-control'
                     id='urbackup_password'
                     name='urbackup_password'
                     autocomplete='new-password'
                     value=''>";

        echo "<div class='form-hint'>";
        echo __('Déjalo vacío para mantener el password actual.', 'nautilus');
        echo "</div>";
        echo "</div>";

        echo "</div>"; // row

        echo "</div>"; // card-body

        echo "<div class='card-footer d-flex justify-content-end'>";
        echo "<button type='submit' name='update' class='btn btn-primary'>";
        echo "<i class='ti ti-device-floppy'></i>&nbsp;";
        echo __('Guardar', 'nautilus');
        echo "</button>";
        echo "</div>";

        echo "</div>"; // card

        Html::closeForm();

        echo "</div>"; // container-fluid

        return true;
    }
}