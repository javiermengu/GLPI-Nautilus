# -*- mode: python ; coding: utf-8 -*-

"""
Proyecto: GLPI-Nautilus
Repositorio: https://github.com/javiermengu/GLPI-Nautilus
Autor: Francisco Javier Mengual Maldonado
Descripción:
    Nemo automatiza la instalación, reinstalación,
    configuración y desinstalación de UrBackup Client
    desde GLPI Inventory.

    Fichero de empaquetamiento para PyInstaller.

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
"""

import subprocess
import sys


# Genera build_config/config.ini y _nemo_obf_secret.py antes de empaquetar.
subprocess.check_call([
    sys.executable,
    "generar_config_ofuscada.py"
])


datas = [
    ("build_config/config.ini", "."),
    ("UrBackupClientSetup.msi", "."),
]


a = Analysis(
    ["nemo.py"],
    pathex=[],
    binaries=[],
    datas=datas,
    hiddenimports=[
        "config_nemo",
        "_nemo_obf_secret",
        "urbackup",
        "glpi",
        "configparser",
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[],
    noarchive=False,
)

pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.zipfiles,
    a.datas,
    [],
    name="Nemo",
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    upx_exclude=[],
    runtime_tmpdir=None,
    console=True,
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
)