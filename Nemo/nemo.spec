# -*- mode: python ; coding: utf-8 -*-

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