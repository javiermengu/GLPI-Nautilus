"""
Nemo.py

Descripción:
    Nemo automatiza la instalación, configuración y desinstalación de
    UrBackup Client desde GLPI Inventory.

Funcionamiento:
    1. Detecta el equipo local.
    2. Consulta GLPI para obtener el ID del equipo y el campo estado_backup.
    3. Si estado_backup está activo:
        - Da de alta el cliente en UrBackup si no existe.
        - Instala UrBackup Client desde MSI.
        - Configura servidor, nombre de cliente y authkey.
        - Lanza UrBackupClient.exe en la sesión del usuario interactivo.
    4. Si estado_backup está inactivo:
        - Comprueba si el cliente existe en UrBackup.
        - Si no existe, desinstala UrBackup Client.
        - Cierra el icono si está abierto.
        - Elimina la entrada de inicio automático si existe.
        - Borra la carpeta residual de UrBackup.

Registro:
    - Mantiene salida estándar y error para GLPI Inventory.
    - Escribe también en:
        C:\\Program Files\\GLPI-Agent\\logs\\Nemo.log
    - Cada línea del fichero local incluye fecha y hora.

Control:
    - Todas las llamadas al sistema tienen timeout.
    - Si un comando supera el timeout, se mata el proceso llamado y sus hijos.
    - Código de salida:
        0 = correcto
        1 = error o timeout

Nota de despliegue GLPI Inventory:
    Comando recomendado:
        cmd /c Nemo.exe 2>&1
"""

import os
import subprocess
import sys
import time
import traceback
import winreg
from datetime import datetime
from pathlib import Path
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

# ============================================================
# CONFIGURACIÓN GENERAL
# ============================================================

NOMBRE_MSI_URBACKUP = "UrBackupClientSetup.msi"

RUTAS_URBACKUP = [
    r"C:\Program Files\UrBackup",
    r"C:\Program Files (x86)\UrBackup",
]

CODIGOS_MSI_CORRECTOS = {0, 3010}
ENCODING_SISTEMA = "cp850" if os.name == "nt" else "utf-8"

RUTA_LOG_NEMO = (
    Path(os.environ.get("ProgramFiles", r"C:\Program Files"))
    / "GLPI-Agent"
    / "logs"
    / "Nemo.log"
)

TIMEOUT_COMANDO_SEGUNDOS = 120
TIMEOUT_MSI_SEGUNDOS = 300
TIMEOUT_CONFIGURACION_SEGUNDOS = 120
TIMEOUT_DESINSTALACION_SEGUNDOS = 300
TIMEOUT_BORRADO_SEGUNDOS = 120


# ============================================================
# LOG
# ============================================================


def escribir_log(tipo, mensaje):
    """
    Escribe un mensaje en consola y en Nemo.log.

    tipo:
        INFO  -> stdout.
        ERROR -> stderr.

    El log local añade fecha y hora. La consola conserva el formato
    NEMO_INFO/NEMO_ERROR para que GLPI Inventory pueda mostrarlo.
    """

    linea = f"NEMO_{tipo}: {mensaje}"

    if tipo == "ERROR":
        print(linea, file=sys.stderr, flush=True)
    else:
        print(linea, flush=True)

    try:
        RUTA_LOG_NEMO.parent.mkdir(parents=True, exist_ok=True)
        fecha = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

        with open(RUTA_LOG_NEMO, "a", encoding="utf-8", errors="replace") as fichero:
            fichero.write(f"{fecha} {linea}\n")

    except Exception:
        pass


def escribir_info(mensaje):
    """Escribe un mensaje informativo."""

    escribir_log("INFO", mensaje)


def escribir_error(mensaje):
    """Escribe un mensaje de error."""

    escribir_log("ERROR", mensaje)


# ============================================================
# UTILIDADES DE SISTEMA
# ============================================================


def obtener_ruta_msi():
    """
    Devuelve la ruta absoluta del MSI incluido con Nemo.

    En desarrollo usa el directorio actual.
    En PyInstaller usa la carpeta temporal interna del ejecutable.
    """

    if hasattr(sys, "_MEIPASS"):
        ruta_base = Path(sys._MEIPASS)
    else:
        ruta_base = Path(".").resolve()

    return str(ruta_base / NOMBRE_MSI_URBACKUP)


def cerrar_arbol_proceso(pid):
    """
    Cierra un proceso y sus hijos.

    Se usa solo cuando un comando supera el timeout.
    """

    if not pid:
        return

    subprocess.run(
        f"taskkill /F /T /PID {pid}",
        shell=True,
        capture_output=True,
        text=True,
        encoding=ENCODING_SISTEMA,
        errors="replace",
    )


def ejecutar_comando(
    comando,
    directorio_trabajo=None,
    ignorar_error=False,
    timeout_segundos=TIMEOUT_COMANDO_SEGUNDOS,
    mostrar_salida=False,
    descripcion=None,
):
    """
    Ejecuta un comando con timeout.

    Parámetros principales:
        comando:
            Comando real a ejecutar.
        descripcion:
            Texto seguro para log. Permite ocultar comandos largos o sensibles.
        ignorar_error:
            Si es True, no lanza excepción con códigos distintos de 0.
        mostrar_salida:
            Si es True, registra stdout/stderr. Por defecto se oculta para evitar ruido.

    Si el comando supera el timeout:
        - mata el proceso y sus hijos;
        - registra el error;
        - lanza excepción salvo que ignorar_error=True.
    """

    texto_log = descripcion or comando
    escribir_info(f"Ejecutando comando: {texto_log}")

    proceso = subprocess.Popen(
        comando,
        shell=True,
        cwd=directorio_trabajo,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding=ENCODING_SISTEMA,
        errors="replace",
    )

    try:
        salida, error = proceso.communicate(timeout=timeout_segundos)

    except subprocess.TimeoutExpired as excepcion:
        escribir_error(
            f"Timeout tras {timeout_segundos} segundos. Comando: {texto_log}"
        )

        cerrar_arbol_proceso(proceso.pid)

        try:
            salida, error = proceso.communicate(timeout=10)
        except Exception:
            salida = ""
            error = ""

        if not ignorar_error:
            raise RuntimeError(
                f"Timeout ejecutando comando. "
                f"Comando: {texto_log}. "
                f"Timeout: {timeout_segundos} segundos."
            ) from excepcion

        return subprocess.CompletedProcess(
            args=comando,
            returncode=1,
            stdout=salida or "",
            stderr=error or "",
        )

    salida = salida or ""
    error = error or ""

    if mostrar_salida and salida.strip():
        for linea in salida.strip().splitlines():
            escribir_info(f"stdout: {linea}")

    if mostrar_salida and error.strip():
        for linea in error.strip().splitlines():
            if ignorar_error:
                escribir_info(f"stderr: {linea}")
            else:
                escribir_error(f"stderr: {linea}")

    resultado = subprocess.CompletedProcess(
        args=comando,
        returncode=proceso.returncode,
        stdout=salida,
        stderr=error,
    )

    if resultado.returncode != 0 and not ignorar_error:
        detalle = error.strip() or salida.strip() or "sin detalle"

        raise RuntimeError(
            f"Error ejecutando comando. "
            f"Código: {resultado.returncode}. "
            f"Comando: {texto_log}. "
            f"Detalle: {detalle}"
        )

    return resultado


def cerrar_proceso(nombre_proceso):
    """
    Cierra un proceso si está abierto.

    Primero comprueba si existe para evitar mensajes innecesarios de Windows.
    Ejemplo:
        cerrar_proceso("UrBackupClient")
    """

    escribir_info(f"Comprobando si {nombre_proceso}.exe está abierto")

    comando_comprobar = (
        "powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "
        f'"$p = Get-Process -Name {nombre_proceso} -ErrorAction SilentlyContinue; '
        'if ($p) { exit 0 } else { exit 2 }"'
    )

    resultado = ejecutar_comando(
        comando_comprobar,
        ignorar_error=True,
        mostrar_salida=False,
        descripcion=f"comprobar proceso {nombre_proceso}.exe",
    )

    if resultado.returncode != 0:
        escribir_info(f"{nombre_proceso}.exe no está abierto")
        return

    comando_cerrar = (
        "powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "
        f'"Get-Process -Name {nombre_proceso} -ErrorAction SilentlyContinue | '
        'Stop-Process -Force -ErrorAction SilentlyContinue"'
    )

    ejecutar_comando(
        comando_cerrar,
        ignorar_error=True,
        mostrar_salida=False,
        descripcion=f"cerrar {nombre_proceso}.exe",
    )

    escribir_info(f"Cierre de {nombre_proceso}.exe solicitado correctamente")


# ============================================================
# URBACKUP - RUTAS, REGISTRO Y CONFIGURACIÓN
# ============================================================


def obtener_ruta_urbackup():
    """
    Devuelve la carpeta donde está instalado UrBackup Client.
    """

    return next(
        (ruta for ruta in RUTAS_URBACKUP if os.path.exists(ruta)),
        None,
    )


def preparar_rutas_urbackup(ruta_urbackup):
    """
    Devuelve y valida las rutas necesarias de UrBackup Client.

    Obligatorias:
        - UrBackupClient_cmd.exe
        - pw_change.txt

    Opcional:
        - UrBackupClient.exe, usado para lanzar el icono.
    """

    rutas = {
        "cmd": os.path.join(ruta_urbackup, "UrBackupClient_cmd.exe"),
        "cliente": os.path.join(ruta_urbackup, "UrBackupClient.exe"),
        "password": os.path.join(ruta_urbackup, "pw_change.txt"),
    }

    for clave in ["cmd", "password"]:
        if not os.path.exists(rutas[clave]):
            raise FileNotFoundError(f"No se encontró {rutas[clave]}")

    return rutas


def buscar_urbackup_instalado():
    """
    Busca UrBackup Client en el registro de Windows.

    Devuelve:
        ProductCode si está instalado.
        None si no está instalado.
    """

    ruta_uninstall = r"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"
    vistas_registro = [
        winreg.KEY_WOW64_64KEY,
        winreg.KEY_WOW64_32KEY,
    ]

    for vista in vistas_registro:
        try:
            with winreg.OpenKey(
                winreg.HKEY_LOCAL_MACHINE,
                ruta_uninstall,
                0,
                winreg.KEY_READ | vista,
            ) as clave:
                total_subclaves = winreg.QueryInfoKey(clave)[0]

                for indice in range(total_subclaves):
                    nombre_subclave = winreg.EnumKey(clave, indice)

                    try:
                        with winreg.OpenKey(clave, nombre_subclave) as subclave:
                            try:
                                nombre_producto = winreg.QueryValueEx(
                                    subclave,
                                    "DisplayName",
                                )[0]
                            except OSError:
                                continue

                            if "urbackup" in nombre_producto.lower():
                                return nombre_subclave

                    except OSError:
                        continue

        except PermissionError as error:
            raise PermissionError("No se pudo leer el registro de Windows") from error

        except OSError:
            continue

    return None


def eliminar_registro_icono_urbackup():
    """
    Elimina la entrada de inicio automático de UrBackupClient.exe.

    Si no existe, informa y continúa.
    """

    ruta_run = r"Software\Microsoft\Windows\CurrentVersion\Run"

    try:
        with winreg.OpenKey(
            winreg.HKEY_LOCAL_MACHINE,
            ruta_run,
            0,
            winreg.KEY_SET_VALUE | winreg.KEY_WOW64_64KEY,
        ) as clave:
            winreg.DeleteValue(clave, "UrBackupClient")

        escribir_info("Entrada de inicio automático de UrBackupClient.exe eliminada")

    except FileNotFoundError:
        escribir_info("No existía entrada de inicio automático de UrBackupClient.exe")

    except OSError as error:
        escribir_info(
            "No se pudo eliminar la entrada de inicio automático de "
            f"UrBackupClient.exe. Detalle: {error}"
        )


# ============================================================
# ICONO DE USUARIO
# ============================================================


def obtener_usuario_interactivo():
    """
    Devuelve el usuario con sesión gráfica iniciada.

    Se obtiene a partir del propietario de explorer.exe.
    """

    script = r"""
$proceso = Get-CimInstance Win32_Process -Filter "name = 'explorer.exe'" |
    Select-Object -First 1

if ($null -ne $proceso) {
    $propietario = Invoke-CimMethod -InputObject $proceso -MethodName GetOwner

    if ($propietario.User) {
        if ($propietario.Domain) {
            Write-Output ($propietario.Domain + "\" + $propietario.User)
        } else {
            Write-Output $propietario.User
        }
    }
}
"""

    resultado = subprocess.run(
        [
            "powershell.exe",
            "-NoProfile",
            "-ExecutionPolicy",
            "Bypass",
            "-Command",
            script,
        ],
        capture_output=True,
        text=True,
        encoding=ENCODING_SISTEMA,
        errors="replace",
    )

    usuario = resultado.stdout.strip()

    return usuario or None


def lanzar_icono_urbackup(ruta_cliente):
    """
    Lanza UrBackupClient.exe en la sesión del usuario interactivo.

    Nemo se ejecuta normalmente como SYSTEM desde GLPI Inventory.
    Por eso se crea una tarea programada temporal con /IT.
    """

    if not os.path.exists(ruta_cliente):
        escribir_info(f"No se encontró {ruta_cliente}. No se lanza el icono.")
        return

    usuario = obtener_usuario_interactivo()

    if not usuario:
        escribir_info(
            "No hay usuario interactivo detectado. "
            "No se lanza UrBackupClient.exe ahora."
        )
        return

    escribir_info(f"Usuario interactivo detectado: {usuario}")

    ruta_scripts = Path(os.environ.get("ProgramData", r"C:\ProgramData")) / "Nemo"
    ruta_scripts.mkdir(parents=True, exist_ok=True)

    nombre_tarea = "NemoLanzarUrBackupClient"
    archivo_cmd = ruta_scripts / f"{nombre_tarea}.cmd"

    archivo_cmd.write_text(
        f'@echo off\nstart "" "{ruta_cliente}"\nexit /b 0\n',
        encoding="utf-8",
    )

    hora_ejecucion = time.strftime("%H:%M", time.localtime(time.time() + 60))

    comando_crear = (
        f"schtasks /Create "
        f'/TN "{nombre_tarea}" '
        f'/TR "\\"{archivo_cmd}\\"" '
        f"/SC ONCE "
        f"/ST {hora_ejecucion} "
        f'/RU "{usuario}" '
        f"/IT "
        f"/F"
    )

    resultado_crear = ejecutar_comando(
        comando_crear,
        ignorar_error=True,
        descripcion="crear tarea temporal UrBackupClient.exe",
    )

    if resultado_crear.returncode != 0:
        escribir_info(
            "No se pudo crear la tarea interactiva. No se lanza UrBackupClient.exe."
        )
        return

    ejecutar_comando(
        f'schtasks /Run /TN "{nombre_tarea}"',
        ignorar_error=True,
        descripcion="ejecutar tarea temporal UrBackupClient.exe",
    )

    time.sleep(10)

    ejecutar_comando(
        f'schtasks /Delete /TN "{nombre_tarea}" /F',
        ignorar_error=True,
        descripcion="eliminar tarea temporal UrBackupClient.exe",
    )

    escribir_info("Tarea temporal de UrBackupClient.exe finalizada")


# ============================================================
# INSTALACIÓN, CONFIGURACIÓN Y LIMPIEZA LOCAL
# ============================================================


def ejecutar_msi(comando, accion, timeout_segundos):
    """
    Ejecuta una operación MSI y valida su código de salida.

    Códigos aceptados:
        0    -> correcto.
        3010 -> correcto, pero requiere reinicio.
    """

    resultado = ejecutar_comando(
        comando,
        timeout_segundos=timeout_segundos,
        mostrar_salida=False,
        descripcion=f"{accion} MSI de UrBackup",
    )

    if resultado.returncode not in CODIGOS_MSI_CORRECTOS:
        detalle = resultado.stderr.strip() or resultado.stdout.strip() or "sin detalle"

        raise RuntimeError(
            f"Error al {accion} UrBackup. "
            f"Código: {resultado.returncode}. "
            f"Detalle: {detalle}"
        )

    return resultado


def configurar_cliente_urbackup(nombre_cliente, auth_key, url_servidor, ruta_urbackup):
    """
    Configura UrBackup Client mediante UrBackupClient_cmd.exe.

    También convierte la URL al formato urbackup://.
    """

    rutas_cliente = preparar_rutas_urbackup(ruta_urbackup)

    if url_servidor.startswith("urbackup://"):
        url_cliente = url_servidor
    elif url_servidor.startswith(("http://", "https://")):
        url_parseada = urlparse(url_servidor)
        url_cliente = f"urbackup://{url_parseada.hostname}"
    else:
        raise RuntimeError(f"No se pudo convertir la URL de UrBackup: {url_servidor}")

    comando = (
        f'"{rutas_cliente["cmd"]}" set-settings '
        f'--server-url "{url_cliente}" '
        f'--name "{nombre_cliente}" '
        f'--authkey "{auth_key}" '
        f"-n "
        f'-p "{rutas_cliente["password"]}"'
    )

    ejecutar_comando(
        comando,
        directorio_trabajo=ruta_urbackup,
        timeout_segundos=TIMEOUT_CONFIGURACION_SEGUNDOS,
        mostrar_salida=False,
        descripcion="configurar UrBackup Client",
    )

    return rutas_cliente


def eliminar_carpeta_urbackup(ruta_urbackup):
    """
    Elimina completamente la carpeta de instalación de UrBackup.

    Se hacen varios intentos porque Windows puede tardar unos segundos
    en liberar ficheros después de una desinstalación.
    """

    if not ruta_urbackup:
        return

    if not os.path.exists(ruta_urbackup):
        escribir_info(f"La carpeta de UrBackup ya no existe: {ruta_urbackup}")
        return

    for intento in range(1, 6):
        escribir_info(
            f"Eliminando carpeta de UrBackup. Intento {intento}/5: {ruta_urbackup}"
        )

        ejecutar_comando(
            f'rmdir /S /Q "{ruta_urbackup}"',
            ignorar_error=True,
            timeout_segundos=TIMEOUT_BORRADO_SEGUNDOS,
            descripcion="eliminar carpeta UrBackup",
        )

        if not os.path.exists(ruta_urbackup):
            escribir_info(f"Carpeta de UrBackup eliminada: {ruta_urbackup}")
            return

        time.sleep(3)

    raise RuntimeError(f"No se pudo eliminar la carpeta de UrBackup: {ruta_urbackup}")


def limpiar_urbackup_local():
    """
    Desinstala UrBackup Client y elimina restos locales.

    Hace:
        1. Detecta carpeta y ProductCode.
        2. Cierra UrBackupClient.exe si está abierto.
        3. Elimina entrada de inicio automático.
        4. Desinstala MSI si existe.
        5. Vuelve a cerrar el icono por seguridad.
        6. Borra carpeta residual si existe.
    """

    ruta_urbackup = obtener_ruta_urbackup()
    product_code = buscar_urbackup_instalado()

    if not ruta_urbackup and not product_code:
        escribir_info("UrBackup no está instalado y no hay carpeta residual")
        return

    if ruta_urbackup:
        escribir_info(f"Ruta de UrBackup detectada: {ruta_urbackup}")

    escribir_info("Cerrando icono de UrBackup")
    cerrar_proceso("UrBackupClient")

    escribir_info("Eliminando arranque automático del icono de UrBackup")
    eliminar_registro_icono_urbackup()

    time.sleep(3)

    if product_code:
        escribir_info("Desinstalando UrBackup existente")

        ejecutar_msi(
            comando=f"msiexec /x {product_code} /qn /norestart",
            accion="desinstalar",
            timeout_segundos=TIMEOUT_DESINSTALACION_SEGUNDOS,
        )

        escribir_info("UrBackup desinstalado correctamente")
    else:
        escribir_info("UrBackup no está instalado")

    time.sleep(3)

    cerrar_proceso("UrBackupClient")

    if ruta_urbackup:
        escribir_info("Eliminando carpeta completa de UrBackup")
        eliminar_carpeta_urbackup(ruta_urbackup)

    escribir_info("Limpieza de UrBackup finalizada")


def instalar_y_configurar_urbackup(nombre_cliente, auth_key, url_servidor):
    """
    Instala y configura UrBackup Client desde cero.

    Secuencia:
        1. Comprueba MSI.
        2. Cierra icono si está abierto.
        3. Limpia instalación previa.
        4. Instala MSI.
        5. Configura cliente.
        6. Lanza icono en sesión interactiva.
    """

    ruta_msi = obtener_ruta_msi()

    if not os.path.exists(ruta_msi):
        raise FileNotFoundError(f"No se encontró el MSI: {ruta_msi}")

    cerrar_proceso("UrBackupClient")

    escribir_info("Comprobando instalación previa de UrBackup")
    limpiar_urbackup_local()

    time.sleep(5)

    escribir_info("Instalando MSI de UrBackup")

    resultado_instalacion = ejecutar_msi(
        comando=f'msiexec /i "{ruta_msi}" /qn /norestart',
        accion="instalar",
        timeout_segundos=TIMEOUT_MSI_SEGUNDOS,
    )

    time.sleep(20)

    ruta_urbackup = obtener_ruta_urbackup()

    if not ruta_urbackup:
        raise FileNotFoundError("No se encontró la carpeta de instalación de UrBackup")

    escribir_info("Configurando cliente UrBackup")

    rutas_cliente = configurar_cliente_urbackup(
        nombre_cliente=nombre_cliente,
        auth_key=auth_key,
        url_servidor=url_servidor,
        ruta_urbackup=ruta_urbackup,
    )

    escribir_info("Lanzando UrBackupClient.exe")
    lanzar_icono_urbackup(rutas_cliente["cliente"])

    if resultado_instalacion.returncode == 3010:
        escribir_info(
            "La instalación indica que requiere reinicio, "
            "pero el cliente se ha lanzado correctamente"
        )


# ============================================================
# GLPI Y URBACKUP
# ============================================================


def obtener_estado_backup_glpi(
    nombre_equipo, url_glpi, token_aplicacion, token_usuario
):
    """
    Obtiene únicamente el campo estado_backup desde GLPI.
    """

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
    id_glpi, url_urbackup, usuario_urbackup, contrasena_urbackup
):
    """
    Procesa estado_backup activo.

    Si UrBackup ya conoce al cliente:
        - no reinstala.

    Si el cliente no existe:
        - lo crea en UrBackup;
        - recibe authkey;
        - instala y configura el cliente local.
    """

    auth_key = alta_urbackup(
        id_cliente=id_glpi,
        url_servidor=url_urbackup,
        usuario_servidor=usuario_urbackup,
        contrasena_servidor=contrasena_urbackup,
    )

    if auth_key is None:
        escribir_info("Cliente ya existente en UrBackup")
        return

    escribir_info("Cliente nuevo creado en UrBackup")
    escribir_info("Authkey generada correctamente")

    instalar_y_configurar_urbackup(
        nombre_cliente=str(id_glpi),
        auth_key=auth_key,
        url_servidor=url_urbackup,
    )


def procesar_backup_inactivo(
    id_glpi, url_urbackup, usuario_urbackup, contrasena_urbackup
):
    """
    Procesa estado_backup inactivo.

    Si el cliente existe en UrBackup:
        - no se toca el agente local.

    Si el cliente no existe en UrBackup:
        - se desinstala y limpia UrBackup Client local.
    """

    sesion_urbackup = iniciar_sesion_urbackup(
        url_servidor=url_urbackup,
        usuario_servidor=usuario_urbackup,
        contrasena_servidor=contrasena_urbackup,
        usuario_basico_servidor="",
        contrasena_basica_servidor="",
    )

    cliente = buscar_cliente_status(
        id_cliente=id_glpi,
        url_servidor=url_urbackup,
        sesion=sesion_urbackup,
        usuario_basico_servidor="",
        contrasena_basica_servidor="",
    )

    if existe_cliente(cliente):
        escribir_info("El cliente existe en UrBackup. No se realiza desinstalación")
        return

    escribir_info("El cliente no existe en UrBackup. Se desinstala UrBackup Client")
    limpiar_urbackup_local()


def ejecutar_nemo():
    """
    Ejecuta el flujo principal de Nemo.
    """

    configuracion = cargar_config_nemo()
    nombre_equipo = obtener_nombre_equipo()

    escribir_info(f"Equipo detectado: {nombre_equipo}")

    url_glpi = configuracion["glpi_url"]
    token_aplicacion = configuracion["app_token"]
    token_usuario = configuracion["user_token"]

    url_urbackup = configuracion["urbackup_url"]
    usuario_urbackup = configuracion["urbackup_username"]
    contrasena_urbackup = configuracion["urbackup_password"]

    id_glpi = obtener_id_glpi(
        nombre_equipo=nombre_equipo,
        url_glpi=url_glpi,
        token_aplicacion=token_aplicacion,
        token_usuario=token_usuario,
    )

    if id_glpi is None:
        escribir_info("Equipo no encontrado en GLPI. No se realiza ninguna acción")
        return

    estado_backup = obtener_estado_backup_glpi(
        nombre_equipo=nombre_equipo,
        url_glpi=url_glpi,
        token_aplicacion=token_aplicacion,
        token_usuario=token_usuario,
    )

    escribir_info(f"ID GLPI: {id_glpi}")
    escribir_info(f"Estado backup: {estado_backup}")

    if estado_backup == 1:
        procesar_backup_activo(
            id_glpi=id_glpi,
            url_urbackup=url_urbackup,
            usuario_urbackup=usuario_urbackup,
            contrasena_urbackup=contrasena_urbackup,
        )
    else:
        procesar_backup_inactivo(
            id_glpi=id_glpi,
            url_urbackup=url_urbackup,
            usuario_urbackup=usuario_urbackup,
            contrasena_urbackup=contrasena_urbackup,
        )


def main():
    """
    Punto de entrada principal de Nemo.

    Devuelve:
        0 si la ejecución termina correctamente.
        1 si se produce cualquier excepción.
    """

    try:
        escribir_info("Inicio de ejecución")
        ejecutar_nemo()
        escribir_info("Ejecución finalizada correctamente")
        sys.exit(0)

    except Exception as error:
        escribir_error("Excepción durante la ejecución de Nemo")
        escribir_error(str(error))

        for linea in traceback.format_exc().strip().splitlines():
            escribir_error(linea)

        print("NEMO_ERROR:", file=sys.stderr, flush=True)
        traceback.print_exc(file=sys.stderr)

        sys.exit(1)


if __name__ == "__main__":
    main()
