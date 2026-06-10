#!/usr/bin/env python
# -*- coding: utf-8 -*-

"""
Proyecto: GLPI-Nautilus
Repositorio: https://github.com/javiermengu/GLPI-Nautilus
Autor: Francisco Javier Mengual Maldonado
Descripción:
    Nemo automatiza la instalación, reinstalación,
    configuración y desinstalación de UrBackup Client
    desde GLPI Inventory.

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

import argparse
import logging
import os
import subprocess
import sys
import time
import traceback
from pathlib import Path
from typing import Dict, List, Optional, Union
from urllib.parse import urlparse

from config_nemo import cargar_config_nemo
from glpi import (
    obtener_campos_backup,
    obtener_id_glpi,
    obtener_nombre_equipo,
)
from urbackup import (
    alta_urbackup,
    buscar_cliente_status,
    existe_cliente,
    iniciar_sesion_urbackup,
)

VERSION_NEMO = "NEMO_URBACKUP_SETUP_MSI_V4"
NOMBRE_MSI_URBACKUP = "UrBackupClientSetup.msi"

RUTAS_URBACKUP = [
    Path(r"C:\Program Files\UrBackup"),
    Path(r"C:\Program Files (x86)\UrBackup"),
]

RUTA_PROGRAMDATA_URBACKUP = Path(r"C:\ProgramData\UrBackup")

RUTA_NEMO = Path(os.environ.get("ProgramData", r"C:\ProgramData")) / "Nemo"
RUTA_NEMO.mkdir(parents=True, exist_ok=True)

RUTA_LOG_MSI = RUTA_NEMO / "urbackup_msi.log"

TIMEOUT_MSI = 60
TIMEOUT_COMANDO = 30

CODIGO_MSI_NO_INSTALADO = 1605
CODIGO_MSI_OTRA_INSTALACION_EN_CURSO = 1618
CODIGO_MSI_REINICIO_REQUERIDO = 3010

MOSTRAR_COMANDOS_REALES = False

RUTA_LOG_NEMO = (
    Path(os.environ.get("ProgramFiles", r"C:\Program Files"))
    / "GLPI-Agent"
    / "logs"
    / "Nemo.log"
)
RUTA_LOG_NEMO.parent.mkdir(parents=True, exist_ok=True)


class FiltroErrores(logging.Filter):
    """Filtra los registros de log cuyo nivel sea inferior a ERROR para separarlos de la salida de error estándar."""

    def filter(self, record: logging.LogRecord) -> bool:
        return record.levelno < logging.ERROR


logger = logging.getLogger("Nemo")
logger.setLevel(logging.INFO)

formato_log = logging.Formatter(
    "%(asctime)s NEMO_%(levelname)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)

file_handler = logging.FileHandler(RUTA_LOG_NEMO, encoding="utf-8")
file_handler.setFormatter(formato_log)
logger.addHandler(file_handler)

stdout_handler = logging.StreamHandler(sys.stdout)
stdout_handler.setFormatter(logging.Formatter("NEMO_%(levelname)s: %(message)s"))
stdout_handler.addFilter(FiltroErrores())
logger.addHandler(stdout_handler)

stderr_handler = logging.StreamHandler(sys.stderr)
stderr_handler.setLevel(logging.ERROR)
stderr_handler.setFormatter(logging.Formatter("NEMO_%(levelname)s: %(message)s"))
logger.addHandler(stderr_handler)


def log_msi_indica_desinstalacion_correcta() -> bool:
    """Verifica si el archivo de log generado por MSIExec contiene cadenas que confirman el éxito de la desinstalación."""
    if not RUTA_LOG_MSI.exists():
        return False

    contenido = ""
    for codificacion in ["utf-8", "cp1252"]:
        try:
            contenido = RUTA_LOG_MSI.read_text(encoding=codificacion, errors="ignore")
            break
        except Exception:
            continue

    if not contenido:
        return False

    textos_exito = [
        "Removal completed successfully",
        "Resultado de la eliminación: 0",
        "MainEngineThread is returning 0",
        "Verbose logging stopped",
        "Logging stopped",
    ]

    return any(texto in contenido for texto in textos_exito)


def ejecutar_comando(
    comando: str,
    directorio_trabajo: Optional[Union[str, Path]] = None,
    ignorar_error: bool = False,
    timeout_segundos: int = TIMEOUT_COMANDO,
    descripcion_log: Optional[str] = None,
) -> subprocess.CompletedProcess:
    """Ejecuta un comando en la terminal del sistema gestionando tiempos de espera y control de procesos huérfanos."""
    texto_log = descripcion_log or comando
    logger.info(f"Ejecutando: {texto_log}")

    if descripcion_log and MOSTRAR_COMANDOS_REALES:
        logger.info(f"Comando real: {comando}")

    proceso = subprocess.Popen(
        comando,
        shell=True,
        cwd=directorio_trabajo,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="cp850",
        errors="replace",
    )

    try:
        salida, error = proceso.communicate(timeout=timeout_segundos)
    except subprocess.TimeoutExpired:
        logger.error(f"Timeout ejecutando {texto_log}")
        try:
            subprocess.run(
                f"taskkill /F /T /PID {proceso.pid}",
                shell=True,
                check=False,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
        except Exception:
            pass

        try:
            salida, error = proceso.communicate(timeout=5)
        except Exception:
            salida, error = "", ""

        resultado = subprocess.CompletedProcess(
            args=comando, returncode=1, stdout=salida or "", stderr=error or "Timeout"
        )

        if ignorar_error:
            return resultado
        raise RuntimeError(f"Timeout ejecutando {texto_log}")

    resultado = subprocess.CompletedProcess(
        args=comando,
        returncode=proceso.returncode,
        stdout=salida or "",
        stderr=error or "",
    )

    if resultado.returncode != 0 and not ignorar_error:
        detalle = resultado.stderr.strip() or resultado.stdout.strip() or "sin detalle"
        raise RuntimeError(
            f"Error ejecutando comando. Código={resultado.returncode}. Detalle={detalle}"
        )

    return resultado


def ejecutar_msi(
    argumentos: List[str],
    descripcion_log: str,
    validar_log_desinstalacion: bool = False,
) -> subprocess.CompletedProcess:
    """Ejecuta el instalador de Windows (msiexec.exe) con los argumentos proporcionados y monitoriza su estado."""
    comando_visible = "msiexec.exe " + " ".join(
        f'"{arg}"' if " " in str(arg) else str(arg) for arg in argumentos
    )
    logger.info(f"Ejecutando: {descripcion_log}")

    if MOSTRAR_COMANDOS_REALES:
        logger.info(f"Comando real: {comando_visible}")

    if RUTA_LOG_MSI.exists():
        try:
            RUTA_LOG_MSI.unlink()
        except OSError:
            pass

    proceso = subprocess.Popen(
        ["msiexec.exe", *argumentos],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )

    inicio = time.monotonic()

    while True:
        if validar_log_desinstalacion and log_msi_indica_desinstalacion_correcta():
            logger.info(
                "El log MSI indica desinstalación correcta. Se continuará sin esperar más a msiexec."
            )
            return subprocess.CompletedProcess(
                args=["msiexec.exe", *argumentos],
                returncode=0,
                stdout="",
                stderr="MSI finalizado correctamente según log",
            )

        if proceso.poll() is not None:
            logger.info("MSI ha terminado")
            return subprocess.CompletedProcess(
                args=["msiexec.exe", *argumentos],
                returncode=proceso.returncode,
                stdout="",
                stderr="",
            )

        if time.monotonic() - inicio >= TIMEOUT_MSI:
            logger.error(f"Timeout MSI ejecutando {descripcion_log}")
            return subprocess.CompletedProcess(
                args=["msiexec.exe", *argumentos],
                returncode=1,
                stdout="",
                stderr="Timeout MSI",
            )

        time.sleep(1)


def cerrar_urbackup() -> None:
    """Detiene todos los servicios y procesos en ejecución relacionados con UrBackup de manera forzada."""
    logger.info("Cerrando servicios y procesos UrBackup")

    comando_servicios = (
        "powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "
        "\"Get-Service | Where-Object { $_.Name -like '*UrBackup*' -or $_.DisplayName -like '*UrBackup*' } | "
        'Stop-Service -Force -ErrorAction SilentlyContinue"'
    )
    ejecutar_comando(
        comando_servicios,
        ignorar_error=True,
        descripcion_log="parar servicios UrBackup",
    )

    comando_procesos = (
        "powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "
        "\"Get-Process -Name '*UrBackup*' -ErrorAction SilentlyContinue | "
        'Stop-Process -Force -ErrorAction SilentlyContinue"'
    )
    ejecutar_comando(
        comando_procesos, ignorar_error=True, descripcion_log="cerrar procesos UrBackup"
    )
    time.sleep(5)


def obtener_ruta_msi() -> str:
    """Obtiene la ruta absoluta del archivo MSI de UrBackup dependiendo de si se ejecuta empaquetado por PyInstaller o como script."""
    ruta_base = Path(sys._MEIPASS) if hasattr(sys, "_MEIPASS") else Path(".").resolve()
    return str(ruta_base / NOMBRE_MSI_URBACKUP)


def argumentos_log_msi_lista() -> List[str]:
    """Genera la lista de argumentos estandarizados para activar el registro verboso de la ejecución de MSIExec."""
    return ["/L*v", str(RUTA_LOG_MSI)]


def instalar_msi_urbackup(ruta_msi: str) -> None:
    """Realiza una instalación desatendida del cliente UrBackup mediante su paquete MSI."""
    logger.info("Instalando MSI UrBackup")
    argumentos = [
        "/i",
        ruta_msi,
        "/qn",
        "/norestart",
        "ALLUSERS=1",
        "REBOOT=ReallySuppress",
    ] + argumentos_log_msi_lista()
    resultado = ejecutar_msi(
        argumentos=argumentos, descripcion_log="instalar MSI UrBackup"
    )

    if resultado.returncode != 0:
        raise RuntimeError(f"Error instalando MSI. Código={resultado.returncode}")
    logger.info("Instalación MSI finalizada")


def reinstalar_msi_urbackup(ruta_msi: str) -> None:
    """Ejecuta una reinstalación forzada y reparación del cliente UrBackup sobrescribiendo todos los archivos."""
    logger.info("Reinstalando MSI UrBackup")
    argumentos = [
        "/i",
        ruta_msi,
        "REINSTALL=ALL",
        "REINSTALLMODE=vomus",
        "/qn",
        "/norestart",
        "ALLUSERS=1",
        "REBOOT=ReallySuppress",
    ] + argumentos_log_msi_lista()

    resultado = ejecutar_msi(
        argumentos=argumentos, descripcion_log="reinstalar MSI UrBackup"
    )

    if resultado.returncode != 0:
        raise RuntimeError(f"Error reinstalando MSI. Código={resultado.returncode}")
    logger.info("Reinstalación MSI finalizada")


def desinstalar_msi_urbackup(ruta_msi: str) -> bool:
    """Ejecuta la desinstalación desatendida del cliente UrBackup evaluando posibles estados de error comunes."""
    logger.info("Desinstalando MSI UrBackup")

    if not Path(ruta_msi).exists():
        logger.info("No existe MSI de UrBackup")
        return False

    argumentos = [
        "/x",
        ruta_msi,
        "/qn",
        "/norestart",
        "REBOOT=ReallySuppress",
        "MSIRESTARTMANAGERCONTROL=Disable",
    ] + argumentos_log_msi_lista()

    resultado = ejecutar_msi(
        argumentos=argumentos,
        descripcion_log="desinstalar MSI UrBackup",
        validar_log_desinstalacion=True,
    )

    if resultado.returncode == 0:
        logger.info("Desinstalación MSI finalizada correctamente")
        return True

    if resultado.returncode == CODIGO_MSI_NO_INSTALADO:
        logger.info(
            "MSI informa que UrBackup no está instalado actualmente. Se continuará con limpieza."
        )
        return True

    if resultado.returncode == CODIGO_MSI_REINICIO_REQUERIDO:
        logger.info(
            "MSI informa que la operación requiere reinicio. Se continuará con limpieza."
        )
        return True

    if resultado.returncode == CODIGO_MSI_OTRA_INSTALACION_EN_CURSO:
        logger.error(
            "Windows Installer informa que hay otra instalación o desinstalación en curso."
        )

    if log_msi_indica_desinstalacion_correcta():
        logger.info(
            "El proceso msiexec no devolvió código 0, pero el log MSI indica desinstalación correcta."
        )
        return True

    logger.error(f"Desinstalación MSI no completada. Código={resultado.returncode}")
    logger.error("Se continuará con limpieza forzada")
    return False


def obtener_ruta_urbackup() -> Optional[Path]:
    """Identifica y devuelve el directorio de instalación activo de UrBackup en el sistema."""
    return next((ruta for ruta in RUTAS_URBACKUP if ruta.exists()), None)


def preparar_rutas_urbackup(ruta_urbackup: str) -> Dict[str, str]:
    """Genera un diccionario con las rutas absolutas de los binarios y archivos de configuración esenciales de UrBackup."""
    base = Path(ruta_urbackup)
    rutas = {
        "cmd": str(base / "UrBackupClient_cmd.exe"),
        "cliente": str(base / "UrBackupClient.exe"),
        "password": str(base / "pw_change.txt"),
    }

    for clave in ["cmd", "password"]:
        if not Path(rutas[clave]).exists():
            raise FileNotFoundError(f"No se encontró {rutas[clave]}")

    return rutas


def lanzar_icono_urbackup(ruta_cliente: str) -> None:
    """Lanza la interfaz gráfica del cliente de UrBackup en la sesión del usuario interactivo actual mediante una tarea programada."""
    if not Path(ruta_cliente).exists():
        logger.info("No existe UrBackupClient.exe")
        return

    logger.info("Lanzando UrBackupClient.exe en sesión interactiva")
    ruta_script = RUTA_NEMO / "NemoLanzarUrBackup.ps1"

    script = f"""
$query = query user | Select-String "Activo|Active"
if ($query) {{
    $line = $query[0].ToString() -replace '^\\s*>?\\s*', ''
    $parts = $line -split '\\s+'
    $usuario = $parts[0]
    if ($usuario -notmatch "\\\\") {{
        $usuario = "$env:COMPUTERNAME\\$usuario"
    }}
    $accion = New-ScheduledTaskAction -Execute "{ruta_cliente}"
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddSeconds(20)
    Register-ScheduledTask -TaskName "NemoUrBackupLaunch" -Action $accion -Trigger $trigger -User $usuario -RunLevel Highest -Force
    Start-Sleep -Seconds 3
    Start-ScheduledTask -TaskName "NemoUrBackupLaunch"
    Start-Sleep -Seconds 10
    Unregister-ScheduledTask -TaskName "NemoUrBackupLaunch" -Confirm:$false
}} else {{
    Write-Output "No hay usuario interactivo activo"
}}
"""
    ruta_script.write_text(script, encoding="utf-8")
    ejecutar_comando(
        f'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "{ruta_script}"',
        ignorar_error=True,
        descripcion_log="lanzar icono UrBackup con ScheduledTask",
    )


def eliminar_carpeta_urbackup(ruta_urbackup: Union[str, Path]) -> None:
    """Aplica permisos máximos (takeown e icacls) para forzar la eliminación de un directorio residual de UrBackup."""
    ruta_obj = Path(ruta_urbackup)
    if not ruta_obj.exists():
        logger.info(f"No existe carpeta {ruta_obj}")
        return

    for intento in range(1, 6):
        logger.info(f"Intento {intento}/5 eliminando {ruta_obj}")
        cerrar_urbackup()

        ejecutar_comando(
            f'takeown /F "{ruta_obj}" /R /D Y',
            ignorar_error=True,
            descripcion_log="takeown UrBackup",
        )
        ejecutar_comando(
            f'icacls "{ruta_obj}" /grant *S-1-1-0:F /T /C /Q',
            ignorar_error=True,
            descripcion_log="icacls UrBackup",
        )
        resultado = ejecutar_comando(
            f'cmd /c rmdir /S /Q "{ruta_obj}"',
            ignorar_error=True,
            descripcion_log="eliminar carpeta UrBackup",
        )

        if not ruta_obj.exists():
            logger.info(f"Carpeta eliminada: {ruta_obj}")
            return

        logger.error(f"No se pudo eliminar {ruta_obj} en el intento {intento}")
        if resultado.stdout.strip():
            logger.error(f"STDOUT rmdir: {resultado.stdout.strip()}")
        if resultado.stderr.strip():
            logger.error(f"STDERR rmdir: {resultado.stderr.strip()}")
        time.sleep(5)

    logger.error(f"No se pudo eliminar la carpeta: {ruta_obj}")


def desinstalar_y_limpiar_urbackup() -> None:
    """Ejecuta el flujo completo de desinstalación incluyendo la parada de servicios y purgado de archivos huérfanos."""
    logger.info("Iniciando desinstalación y limpieza")
    ruta_msi = obtener_ruta_msi()
    cerrar_urbackup()

    logger.info("Esperando 5 segundos antes de desinstalar MSI")
    time.sleep(5)
    desinstalar_msi_urbackup(ruta_msi)
    cerrar_urbackup()

    logger.info("Esperando 10 segundos antes de limpiar carpetas")
    time.sleep(10)

    for ruta in [*RUTAS_URBACKUP, RUTA_PROGRAMDATA_URBACKUP]:
        eliminar_carpeta_urbackup(ruta)

    logger.info("Limpieza finalizada")


def instalar_y_configurar_urbackup(
    nombre_cliente: str, auth_key: str, url_servidor: str
) -> None:
    """Administra el proceso de instalación limpia o reinstalación y posterior configuración del cliente mediante línea de comandos."""
    logger.info("Iniciando gestión de UrBackup")
    ruta_msi = obtener_ruta_msi()

    if not Path(ruta_msi).exists():
        raise FileNotFoundError(f"No existe MSI: {ruta_msi}")

    if obtener_ruta_urbackup():
        logger.info("UrBackup ya instalado. Se realizará reinstalación MSI.")
        try:
            reinstalar_msi_urbackup(ruta_msi)
        except Exception:
            logger.error("Falló reinstalación MSI. Se hará instalación limpia.")
            desinstalar_y_limpiar_urbackup()
            instalar_msi_urbackup(ruta_msi)
    else:
        logger.info("UrBackup no instalado. Instalación limpia.")
        desinstalar_y_limpiar_urbackup()
        instalar_msi_urbackup(ruta_msi)

    logger.info("Esperando 15 segundos tras MSI")
    time.sleep(15)

    ruta_urbackup = obtener_ruta_urbackup()
    if not ruta_urbackup:
        raise RuntimeError("No existe carpeta UrBackup")

    rutas_cliente = preparar_rutas_urbackup(str(ruta_urbackup))

    if url_servidor.startswith("urbackup://"):
        url_cliente = url_servidor
    elif url_servidor.startswith(("http://", "https://")):
        url_cliente = f"urbackup://{urlparse(url_servidor).hostname}"
    else:
        raise RuntimeError(f"URL inválida: {url_servidor}")

    logger.info("Configurando UrBackup Client")
    comando_configuracion = (
        f'"{rutas_cliente["cmd"]}" set-settings --server-url "{url_cliente}" '
        f'--name "{nombre_cliente}" --authkey "{auth_key}" -n -p "{rutas_cliente["password"]}"'
    )

    ejecutar_comando(
        comando_configuracion,
        directorio_trabajo=str(ruta_urbackup),
        descripcion_log="configurar UrBackup",
    )

    logger.info("Esperando 15 segundos antes de lanzar icono")
    time.sleep(15)
    lanzar_icono_urbackup(rutas_cliente["cliente"])
    logger.info("Gestión UrBackup finalizada")


def obtener_estado_backup_glpi(
    nombre_equipo: str, url_glpi: str, token_aplicacion: str, token_usuario: str
) -> int:
    """Consulta la API de GLPI para recuperar el flag que determina si un equipo debe tener habilitado el sistema de backup."""
    campos_backup = obtener_campos_backup(
        nombre_equipo=nombre_equipo,
        url_glpi=url_glpi,
        token_aplicacion=token_aplicacion,
        token_usuario=token_usuario,
        nombre_bloque="GLPI-Nautilus",
        campo_estado_backup="estado_backup",
        campo_fecha_ultimo_backup=None,
    )
    return campos_backup["estado_backup"]


def procesar_backup_activo(
    id_glpi: int, url_urbackup: str, usuario_urbackup: str, contrasena_urbackup: str
) -> None:
    """Registra el equipo en el servidor central de UrBackup y despliega su cliente si no se encuentra registrado previamente."""
    auth_key = alta_urbackup(
        id_cliente=id_glpi,
        url_servidor=url_urbackup,
        usuario_servidor=usuario_urbackup,
        contrasena_servidor=contrasena_urbackup,
    )

    if auth_key is None:
        logger.info("Cliente ya existente en UrBackup")
        return

    logger.info("Cliente creado en UrBackup")
    instalar_y_configurar_urbackup(
        nombre_cliente=str(id_glpi), auth_key=auth_key, url_servidor=url_urbackup
    )


def procesar_backup_inactivo(
    id_glpi: int, url_urbackup: str, usuario_urbackup: str, contrasena_urbackup: str
) -> None:
    """Verifica si un cliente deshabilitado en GLPI existe en el servidor UrBackup y lo desinstala del equipo local si procede."""
    sesion = iniciar_sesion_urbackup(
        url_servidor=url_urbackup,
        usuario_servidor=usuario_urbackup,
        contrasena_servidor=contrasena_urbackup,
        usuario_basico_servidor="",
        contrasena_basica_servidor="",
    )

    cliente = buscar_cliente_status(
        id_cliente=id_glpi,
        url_servidor=url_urbackup,
        sesion=sesion,
        usuario_basico_servidor="",
        contrasena_basica_servidor="",
    )

    if existe_cliente(cliente):
        logger.info("Cliente existe en UrBackup")
        return

    logger.info("Cliente inexistente en UrBackup")
    desinstalar_y_limpiar_urbackup()


def forzar_instalacion_desde_parametros(
    nombre_cliente: str, auth_key: str, url_servidor: str
) -> None:
    """Inicia la instalación y configuración manual pasando los datos de conexión de manera explícita por línea de comandos."""
    logger.info("Modo forzado: instalación manual por parámetros")
    instalar_y_configurar_urbackup(
        nombre_cliente=nombre_cliente, auth_key=auth_key, url_servidor=url_servidor
    )


def forzar_instalacion_desde_glpi() -> None:
    """Realiza la instalación del cliente obteniendo el ID y credenciales directamente desde la base de datos de GLPI."""
    logger.info("Modo forzado: instalación usando datos GLPI/UrBackup")
    configuracion = cargar_config_nemo()
    nombre_equipo = obtener_nombre_equipo()
    logger.info(f"Equipo detectado: {nombre_equipo}")

    id_glpi = obtener_id_glpi(
        nombre_equipo=nombre_equipo,
        url_glpi=configuracion["glpi_url"],
        token_aplicacion=configuracion["app_token"],
        token_usuario=configuracion["user_token"],
    )

    if id_glpi is None:
        raise RuntimeError("Equipo no encontrado en GLPI")

    auth_key = alta_urbackup(
        id_cliente=id_glpi,
        url_servidor=configuracion["urbackup_url"],
        usuario_servidor=configuracion["urbackup_username"],
        contrasena_servidor=configuracion["urbackup_password"],
    )

    if auth_key is None:
        raise RuntimeError(
            "El cliente ya existe en UrBackup y no se ha recibido auth_key. "
            "Para forzar instalación manual usa: "
            "nemo.exe instalar --nombre-cliente ID --authkey CLAVE --url-servidor URL"
        )

    instalar_y_configurar_urbackup(
        nombre_cliente=str(id_glpi),
        auth_key=auth_key,
        url_servidor=configuracion["urbackup_url"],
    )


def ejecutar_nemo() -> None:
    """Flujo de ejecución estándar: lee el estado del equipo en GLPI y activa o desactiva UrBackup según corresponda."""
    configuracion = cargar_config_nemo()
    nombre_equipo = obtener_nombre_equipo()
    logger.info(f"Equipo detectado: {nombre_equipo}")

    id_glpi = obtener_id_glpi(
        nombre_equipo=nombre_equipo,
        url_glpi=configuracion["glpi_url"],
        token_aplicacion=configuracion["app_token"],
        token_usuario=configuracion["user_token"],
    )

    if id_glpi is None:
        logger.info("Equipo no encontrado en GLPI")
        return

    estado_backup = obtener_estado_backup_glpi(
        nombre_equipo=nombre_equipo,
        url_glpi=configuracion["glpi_url"],
        token_aplicacion=configuracion["app_token"],
        token_usuario=configuracion["user_token"],
    )

    logger.info(f"ID GLPI: {id_glpi}")
    logger.info(f"Estado backup: {estado_backup}")

    if estado_backup == 1:
        procesar_backup_activo(
            id_glpi=id_glpi,
            url_urbackup=configuracion["urbackup_url"],
            usuario_urbackup=configuracion["urbackup_username"],
            contrasena_urbackup=configuracion["urbackup_password"],
        )
    else:
        procesar_backup_inactivo(
            id_glpi=id_glpi,
            url_urbackup=configuracion["urbackup_url"],
            usuario_urbackup=configuracion["urbackup_username"],
            contrasena_urbackup=configuracion["urbackup_password"],
        )


def parsear_argumentos() -> argparse.Namespace:
    """Configura e interpreta los parámetros y subcomandos enviados a través de la terminal."""
    texto_copyright = (
        "Proyecto: GLPI-Nautilus\n"
        "Repositorio: https://github.com/javiermengu/GLPI-Nautilus\n"
        "Copyright (C) 2026 Francisco Javier Mengual Maldonado (Licencia GPLv2)"
    )

    parser = argparse.ArgumentParser(
        description="Nemo - Gestión de UrBackup Client",
        epilog=texto_copyright,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )

    parser.add_argument(
        "-v",
        "--version",
        action="version",
        version=f"Nemo (GLPI-Nautilus) v1.0.0\n{texto_copyright}",
        help="Muestra la información de versión, autor y copyright.",
    )

    subparsers = parser.add_subparsers(
        dest="accion",
        help="Acción a realizar. Sin parámetros ejecuta el modo normal desde GLPI.",
    )

    parser_instalar = subparsers.add_parser(
        "instalar",
        help="Instalación manual del cliente UrBackup.",
        epilog=texto_copyright,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )

    parser_instalar.add_argument(
        "--nombre-cliente",
        dest="nombre_cliente",
        default="nombrecliente",
        help="Nombre del cliente UrBackup para instalación manual. (Por defecto: nombrecliente)",
    )

    parser_instalar.add_argument(
        "--authkey",
        dest="auth_key",
        default="sinkey",
        help="AuthKey de UrBackup para instalación manual. (Por defecto: sinkey)",
    )

    parser_instalar.add_argument(
        "--url-servidor",
        dest="url_servidor",
        default="urbackup://sinhost",
        help="URL del servidor UrBackup. (Por defecto: urbackup//sinhost)",
    )

    subparsers.add_parser(
        "desinstalar",
        help="Desinstalación del cliente UrBackup.",
        epilog=texto_copyright,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )

    return parser.parse_args()


def main() -> None:
    """Punto de entrada principal del aplicativo que redirige el flujo lógico según los argumentos proporcionados."""
    global MOSTRAR_COMANDOS_REALES

    try:
        logger.info("=== INICIO DE EJECUCIÓN ===")
        logger.info(f"Versión Nemo: {VERSION_NEMO}")

        argumentos = parsear_argumentos()

        if argumentos.accion:
            MOSTRAR_COMANDOS_REALES = True

        if argumentos.accion == "desinstalar":
            logger.info("Parámetro recibido: desinstalar")
            desinstalar_y_limpiar_urbackup()

        elif argumentos.accion == "instalar":
            logger.info("Parámetro recibido: instalar")
            if (
                argumentos.nombre_cliente
                and argumentos.auth_key
                and argumentos.url_servidor
            ):
                forzar_instalacion_desde_parametros(
                    nombre_cliente=argumentos.nombre_cliente,
                    auth_key=argumentos.auth_key,
                    url_servidor=argumentos.url_servidor,
                )
            else:
                forzar_instalacion_desde_glpi()
        else:
            logger.info("Sin parámetros. Modo normal GLPI.")
            ejecutar_nemo()

        logger.info("=== EJECUCIÓN FINALIZADA ===")
        sys.exit(0)

    except Exception as error:
        logger.error(f"Excepción fatal: {error}")
        for linea in traceback.format_exc().splitlines():
            logger.error(linea)
        sys.exit(1)


if __name__ == "__main__":
    main()
