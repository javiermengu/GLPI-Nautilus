import re
import socket

import requests


TIEMPO_ESPERA = 30


class ErrorGLPI(Exception):
    """
    Excepción específica para errores relacionados con GLPI.
    """


VALORES_BACKUP_ACTIVO = {
    1,
    "1",
    True,
    "true",
    "True",
    "SI",
    "Sí",
    "si",
    "yes",
    "Yes",
}

VALORES_BACKUP_INACTIVO = {
    0,
    "0",
    False,
    "false",
    "False",
    "NO",
    "No",
    "no",
    None,
}


def obtener_nombre_equipo():
    """
    Devuelve el nombre local del equipo.
    """

    return socket.gethostname()


def normalizar_texto(texto):
    """
    Normaliza textos para comparar nombres de bloques y campos.

    Ejemplos:
        GLPI-Nautilus -> glpinautilus
        estado_backup -> estadobackup
    """

    texto_normalizado = ""

    if texto is not None:
        texto_normalizado = re.sub(
            r"[^a-zA-Z0-9]",
            "",
            str(texto),
        ).lower()

    return texto_normalizado


def crear_cabeceras_glpi(
    token_aplicacion,
    token_sesion=None,
    token_usuario=None,
):
    """
    Crea las cabeceras necesarias para llamar a la API de GLPI.

    Para iniciar sesión usa token_usuario.
    Para el resto de peticiones usa token_sesion.
    """

    cabeceras = {
        "Content-Type": "application/json",
        "App-Token": token_aplicacion,
    }

    if token_usuario:
        cabeceras["Authorization"] = f"user_token {token_usuario}"

    if token_sesion:
        cabeceras["Session-Token"] = token_sesion

    return cabeceras


def iniciar_sesion_glpi(url_glpi, token_aplicacion, token_usuario):
    """
    Inicia sesión en GLPI y devuelve el token de sesión.

    Si la conexión falla o GLPI no devuelve session_token, lanza ErrorGLPI.
    """

    cabeceras = crear_cabeceras_glpi(
        token_aplicacion=token_aplicacion,
        token_usuario=token_usuario,
    )

    try:
        respuesta = requests.get(
            f"{url_glpi}/initSession",
            headers=cabeceras,
            timeout=TIEMPO_ESPERA,
        )

        respuesta.raise_for_status()
        datos = respuesta.json()

    except Exception as error:
        raise ErrorGLPI(f"Error iniciando sesión en GLPI: {error}") from error

    if "session_token" not in datos:
        raise ErrorGLPI("GLPI no devolvió session_token")

    return datos["session_token"]


def cerrar_sesion_glpi(url_glpi, token_aplicacion, token_sesion):
    """
    Cierra la sesión activa en GLPI.

    Si falla el cierre de sesión, no se interrumpe la ejecución.
    """

    cabeceras = crear_cabeceras_glpi(
        token_aplicacion=token_aplicacion,
        token_sesion=token_sesion,
    )

    try:
        requests.get(
            f"{url_glpi}/killSession",
            headers=cabeceras,
            timeout=TIEMPO_ESPERA,
        )

    except Exception:
        pass


def consultar_glpi(
    url_glpi,
    token_aplicacion,
    token_sesion,
    endpoint,
    parametros=None,
):
    """
    Ejecuta una petición GET contra la API de GLPI.

    Devuelve:
        dict | list: respuesta JSON devuelta por GLPI.

    Lanza:
        ErrorGLPI: si falla la petición o la respuesta no es válida.
    """

    cabeceras = crear_cabeceras_glpi(
        token_aplicacion=token_aplicacion,
        token_sesion=token_sesion,
    )

    try:
        respuesta = requests.get(
            f"{url_glpi}{endpoint}",
            headers=cabeceras,
            params=parametros or {},
            timeout=TIEMPO_ESPERA,
        )

        respuesta.raise_for_status()
        datos = respuesta.json()

    except Exception as error:
        raise ErrorGLPI(
            f"Error consultando GLPI {endpoint}: {error}"
        ) from error

    return datos


def buscar_equipo_glpi(
    nombre_equipo,
    url_glpi,
    token_aplicacion,
    token_sesion,
    campos_extra=None,
):
    """
    Busca un equipo en GLPI por nombre.

    Permite añadir campos extra mediante forcedisplay.

    Devuelve:
        dict: respuesta de la búsqueda en GLPI.
    """

    parametros = {
        "criteria[0][field]": "1",
        "criteria[0][searchtype]": "contains",
        "criteria[0][value]": nombre_equipo,
        "forcedisplay[0]": "2",
    }

    for indice, id_campo in enumerate(campos_extra or [], start=1):
        parametros[f"forcedisplay[{indice}]"] = id_campo

    datos = consultar_glpi(
        url_glpi=url_glpi,
        token_aplicacion=token_aplicacion,
        token_sesion=token_sesion,
        endpoint="/search/Computer",
        parametros=parametros,
    )

    return datos


def obtener_id_glpi(
    nombre_equipo,
    url_glpi,
    token_aplicacion,
    token_usuario,
):
    """
    Obtiene el ID de GLPI de un ordenador dado su nombre.

    Devuelve:
        str | int | None:
            ID del ordenador si existe.
            None si no se encuentra.
    """

    id_glpi = None

    token_sesion = iniciar_sesion_glpi(
        url_glpi=url_glpi,
        token_aplicacion=token_aplicacion,
        token_usuario=token_usuario,
    )

    try:
        datos = buscar_equipo_glpi(
            nombre_equipo=nombre_equipo,
            url_glpi=url_glpi,
            token_aplicacion=token_aplicacion,
            token_sesion=token_sesion,
        )

        if datos.get("totalcount", 0) > 0:
            fila = datos["data"][0]
            id_glpi = fila.get("2")

            if id_glpi is None:
                raise ErrorGLPI(
                    "GLPI encontró el ordenador, pero no devolvió el ID"
                )

    finally:
        cerrar_sesion_glpi(
            url_glpi=url_glpi,
            token_aplicacion=token_aplicacion,
            token_sesion=token_sesion,
        )

    return id_glpi


def texto_bloque_fields(datos_campo):
    """
    Construye el texto usado para localizar el bloque de GLPI Fields.
    """

    texto = normalizar_texto(
        f"{datos_campo.get('table', '')} "
        f"{datos_campo.get('name', '')} "
        f"{datos_campo.get('uid', '')}"
    )

    return texto


def texto_campo_fields(datos_campo):
    """
    Construye el texto usado para localizar un campo de GLPI Fields.
    """

    texto = normalizar_texto(
        f"{datos_campo.get('field', '')} "
        f"{datos_campo.get('name', '')} "
        f"{datos_campo.get('uid', '')}"
    )

    return texto


def obtener_ids_campos_fields(
    url_glpi,
    token_aplicacion,
    token_sesion,
    nombre_bloque,
    campo_estado_backup,
    campo_fecha_ultimo_backup,
):
    """
    Localiza dinámicamente los IDs de los campos personalizados de GLPI Fields.

    Usa:
        /listSearchOptions/Computer

    Busca:
        - el bloque indicado;
        - el campo estado_backup;
        - el campo fecha_ultimo_backup.
    """

    opciones = consultar_glpi(
        url_glpi=url_glpi,
        token_aplicacion=token_aplicacion,
        token_sesion=token_sesion,
        endpoint="/listSearchOptions/Computer",
    )

    bloque_buscado = normalizar_texto(nombre_bloque)
    estado_buscado = normalizar_texto(campo_estado_backup)
    fecha_buscada = normalizar_texto(campo_fecha_ultimo_backup)

    campos_bloque = {
        str(id_campo): datos_campo
        for id_campo, datos_campo in opciones.items()
        if bloque_buscado in texto_bloque_fields(datos_campo)
    }

    id_estado = next(
        (
            id_campo
            for id_campo, datos_campo in campos_bloque.items()
            if estado_buscado in texto_campo_fields(datos_campo)
        ),
        None,
    )

    id_fecha = next(
        (
            id_campo
            for id_campo, datos_campo in campos_bloque.items()
            if fecha_buscada in texto_campo_fields(datos_campo)
        ),
        None,
    )

    if id_estado is None:
        raise ErrorGLPI(
            f"No se ha localizado el campo '{campo_estado_backup}' "
            f"en el bloque '{nombre_bloque}'"
        )

    if id_fecha is None:
        raise ErrorGLPI(
            f"No se ha localizado el campo '{campo_fecha_ultimo_backup}' "
            f"en el bloque '{nombre_bloque}'"
        )

    return id_estado, id_fecha


def obtener_campos_backup(
    nombre_equipo,
    url_glpi,
    token_aplicacion,
    token_usuario,
    nombre_bloque="GLPI-Nautilus",
    campo_estado_backup="estado_backup",
    campo_fecha_ultimo_backup="fecha_ultimo_backup",
):
    """
    Obtiene estado_backup y fecha_ultimo_backup de un ordenador.

    No usa IDs fijos. Los obtiene dinámicamente desde:

        /listSearchOptions/Computer

    Devuelve:
        dict:
            {
                "estado_backup": ...,
                "fecha_ultimo_backup": ...
            }
    """

    campos_backup = {}

    token_sesion = iniciar_sesion_glpi(
        url_glpi=url_glpi,
        token_aplicacion=token_aplicacion,
        token_usuario=token_usuario,
    )

    try:
        id_estado, id_fecha = obtener_ids_campos_fields(
            url_glpi=url_glpi,
            token_aplicacion=token_aplicacion,
            token_sesion=token_sesion,
            nombre_bloque=nombre_bloque,
            campo_estado_backup=campo_estado_backup,
            campo_fecha_ultimo_backup=campo_fecha_ultimo_backup,
        )

        datos = buscar_equipo_glpi(
            nombre_equipo=nombre_equipo,
            url_glpi=url_glpi,
            token_aplicacion=token_aplicacion,
            token_sesion=token_sesion,
            campos_extra=[id_estado, id_fecha],
        )

        if datos.get("totalcount", 0) == 0:
            raise ErrorGLPI(
                f"No se ha encontrado el ordenador '{nombre_equipo}' en GLPI"
            )

        fila = datos["data"][0]

        campos_backup = {
            "estado_backup": fila.get(id_estado),
            "fecha_ultimo_backup": fila.get(id_fecha),
        }

    finally:
        cerrar_sesion_glpi(
            url_glpi=url_glpi,
            token_aplicacion=token_aplicacion,
            token_sesion=token_sesion,
        )

    return campos_backup


def normalizar_estado_backup(valor):
    """
    Normaliza el valor booleano devuelto por GLPI.

    Devuelve:
        True  -> backup activo.
        False -> backup desactivado.
        None  -> valor no reconocido.
    """

    estado = None

    if valor in VALORES_BACKUP_ACTIVO:
        estado = True

    elif valor in VALORES_BACKUP_INACTIVO:
        estado = False

    return estado