import binascii
import hashlib
import http.client as http
import json
from base64 import b64encode
from enum import Enum
from urllib.parse import urlencode, urlparse


class SistemaOperativoInstalador(Enum):
    """
    Sistemas operativos soportados por el instalador.
    """

    WINDOWS = "windows"
    LINUX = "linux"


class ErrorUrBackup(Exception):
    """
    Excepción específica para errores relacionados con UrBackup.
    """


VALORES_RECHAZADO = {True, 1, "1", "true", "True", "yes", "YES"}
VALORES_ID_NO_VALIDO = {None, "", "-"}


def calcular_md5(texto):
    """
    Calcula el hash MD5 de un texto.

    Parámetros:
        texto (str): Texto de entrada.

    Devuelve:
        str: Hash MD5 en formato hexadecimal.
    """

    return hashlib.md5(texto.encode()).hexdigest()


def crear_cabeceras(usuario_basico="", contrasena_basica=""):
    """
    Crea las cabeceras HTTP necesarias para llamar a la API de UrBackup.

    Si se informa usuario básico, añade autenticación Basic.
    """

    cabeceras = {
        "Accept": "application/json",
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
    }

    if usuario_basico:
        credenciales = f"{usuario_basico}:{contrasena_basica}"
        credenciales_base64 = b64encode(credenciales.encode()).decode("ascii")
        cabeceras["Authorization"] = f"Basic {credenciales_base64}"

    return cabeceras


def crear_conexion(destino):
    """
    Crea una conexión HTTP o HTTPS según el esquema de la URL.

    Parámetros:
        destino (ParseResult): URL parseada.

    Devuelve:
        HTTPConnection | HTTPSConnection

    Excepciones:
        ErrorUrBackup: si el esquema no es http o https.
    """

    if destino.scheme == "http":
        conexion = http.HTTPConnection(destino.hostname, destino.port)

    elif destino.scheme == "https":
        conexion = http.HTTPSConnection(destino.hostname, destino.port)

    else:
        raise ErrorUrBackup(f"Esquema desconocido: {destino.scheme}")

    return conexion


def obtener_respuesta(
    url_servidor,
    accion,
    parametros,
    metodo,
    sesion="",
    usuario_basico_servidor="",
    contrasena_basica_servidor="",
):
    """
    Realiza una petición HTTP o HTTPS a la API de UrBackup.

    Parámetros:
        url_servidor (str): URL base del servidor UrBackup.
        accion (str): Acción de la API.
        parametros (dict): Parámetros de la petición.
        metodo (str): Método HTTP: GET o POST.
        sesion (str): Sesión de UrBackup.
        usuario_basico_servidor (str): Usuario HTTP básico.
        contrasena_basica_servidor (str): Contraseña HTTP básica.

    Devuelve:
        HTTPResponse: Respuesta del servidor.
    """

    cabeceras = crear_cabeceras(
        usuario_basico=usuario_basico_servidor,
        contrasena_basica=contrasena_basica_servidor,
    )

    parametros_peticion = {
        **(parametros or {}),
        **({"ses": sesion} if sesion else {}),
    }

    url_actual = f"{url_servidor}?{urlencode({'a': accion})}"

    if metodo == "GET":
        url_actual += "&" + urlencode(parametros_peticion)

    destino = urlparse(url_actual)
    cuerpo = urlencode(parametros_peticion) if metodo == "POST" else ""

    conexion = crear_conexion(destino)

    conexion.request(
        metodo,
        destino.path + "?" + destino.query,
        cuerpo,
        cabeceras,
    )

    respuesta = conexion.getresponse()

    return respuesta


def obtener_json(
    url_servidor,
    accion,
    parametros=None,
    metodo="POST",
    sesion="",
    usuario_basico_servidor="",
    contrasena_basica_servidor="",
):
    """
    Ejecuta una llamada a la API de UrBackup y devuelve JSON.

    Devuelve:
        dict: Respuesta en formato JSON. Devuelve {} si la respuesta está vacía.

    Excepciones:
        ErrorUrBackup: si la respuesta no es JSON válido.
    """

    resultado = {}

    respuesta = obtener_respuesta(
        url_servidor=url_servidor,
        accion=accion,
        parametros=parametros or {},
        metodo=metodo,
        sesion=sesion,
        usuario_basico_servidor=usuario_basico_servidor,
        contrasena_basica_servidor=contrasena_basica_servidor,
    )

    try:
        if respuesta.status == 200:
            texto = respuesta.read().decode("utf-8", "ignore").strip()

            if texto:
                try:
                    resultado = json.loads(texto)

                except json.decoder.JSONDecodeError as error:
                    raise ErrorUrBackup(
                        f"La acción '{accion}' no devolvió JSON válido: {texto}"
                    ) from error

    finally:
        respuesta.close()

    return resultado


def validar_respuesta_salt(respuesta_salt):
    """
    Valida la respuesta inicial de UrBackup para iniciar sesión.

    Excepciones:
        ErrorUrBackup: si falta algún dato obligatorio.
    """

    if not respuesta_salt:
        raise ErrorUrBackup("El servidor UrBackup no responde")

    campos_obligatorios = {
        "ses": "el usuario no existe",
        "salt": "no se recibió salt",
        "rnd": "no se recibió rnd",
    }

    campo_faltante = next(
        (campo for campo in campos_obligatorios if campo not in respuesta_salt),
        None,
    )

    if campo_faltante:
        raise ErrorUrBackup(
            f"Error UrBackup: {campos_obligatorios[campo_faltante]}"
        )


def calcular_hash_login(respuesta_salt, contrasena_servidor):
    """
    Calcula el hash de contraseña requerido por el login de UrBackup.

    UrBackup usa salt, rnd y opcionalmente PBKDF2.
    """

    hash_binario = hashlib.md5(
        (respuesta_salt["salt"] + contrasena_servidor).encode()
    ).digest()

    hash_texto = binascii.hexlify(hash_binario).decode()

    rondas_pbkdf2 = int(respuesta_salt.get("pbkdf2_rounds", 0))

    if rondas_pbkdf2 > 0:
        hash_texto = binascii.hexlify(
            hashlib.pbkdf2_hmac(
                "sha256",
                hash_binario,
                respuesta_salt["salt"].encode(),
                rondas_pbkdf2,
            )
        ).decode()

    hash_login = calcular_md5(respuesta_salt["rnd"] + hash_texto)

    return hash_login


def iniciar_sesion_urbackup(
    url_servidor,
    usuario_servidor,
    contrasena_servidor,
    usuario_basico_servidor="",
    contrasena_basica_servidor="",
):
    """
    Inicia sesión en UrBackup.

    Devuelve:
        str: Sesión generada por UrBackup.

    Excepciones:
        ErrorUrBackup: si falla la conexión, el usuario o la contraseña.
    """

    respuesta_salt = obtener_json(
        url_servidor=url_servidor,
        accion="salt",
        parametros={"username": usuario_servidor},
        usuario_basico_servidor=usuario_basico_servidor,
        contrasena_basica_servidor=contrasena_basica_servidor,
    )

    validar_respuesta_salt(respuesta_salt)

    sesion = respuesta_salt["ses"]
    hash_contrasena = calcular_hash_login(
        respuesta_salt=respuesta_salt,
        contrasena_servidor=contrasena_servidor,
    )

    respuesta_login = obtener_json(
        url_servidor=url_servidor,
        accion="login",
        parametros={
            "username": usuario_servidor,
            "password": hash_contrasena,
        },
        sesion=sesion,
        usuario_basico_servidor=usuario_basico_servidor,
        contrasena_basica_servidor=contrasena_basica_servidor,
    )

    if respuesta_login.get("success") is False:
        raise ErrorUrBackup("Contraseña incorrecta en UrBackup")

    return sesion


def buscar_cliente_status(
    id_cliente,
    url_servidor,
    sesion,
    usuario_basico_servidor="",
    contrasena_basica_servidor="",
):
    """
    Busca un cliente en la respuesta status de UrBackup.

    Devuelve:
        dict | None: Cliente si existe. None si no existe.

    Excepciones:
        ErrorUrBackup: si la respuesta de status no es válida.
    """

    respuesta_estado = obtener_json(
        url_servidor=url_servidor,
        accion="status",
        parametros={},
        sesion=sesion,
        usuario_basico_servidor=usuario_basico_servidor,
        contrasena_basica_servidor=contrasena_basica_servidor,
    )

    if "status" not in respuesta_estado:
        raise ErrorUrBackup("Respuesta status inválida")

    cliente_encontrado = next(
        (
            cliente
            for cliente in respuesta_estado["status"]
            if cliente.get("name") == str(id_cliente)
        ),
        None,
    )

    return cliente_encontrado


def existe_cliente(cliente):
    """
    Comprueba si un cliente está realmente dado de alta en UrBackup.

    Devuelve:
        bool:
            True si el cliente existe y es válido.
            False si no existe, está rechazado o no tiene ID válido.
    """

    cliente_valido = False

    if cliente:
        rechazado = cliente.get("rejected", False)
        identificador_cliente = cliente.get("id", None)

        cliente_valido = (
            rechazado not in VALORES_RECHAZADO
            and identificador_cliente not in VALORES_ID_NO_VALIDO
        )

    return cliente_valido


def alta_urbackup(
    id_cliente,
    url_servidor,
    usuario_servidor,
    contrasena_servidor,
    usuario_basico_servidor="",
    contrasena_basica_servidor="",
):
    """
    Da de alta un cliente en UrBackup.

    Devuelve:
        str | None:
            Authkey si se crea el cliente.
            None si el cliente ya existe.

    Excepciones:
        ErrorUrBackup: si ocurre un error creando el cliente.
    """

    authkey = None

    sesion = iniciar_sesion_urbackup(
        url_servidor=url_servidor,
        usuario_servidor=usuario_servidor,
        contrasena_servidor=contrasena_servidor,
        usuario_basico_servidor=usuario_basico_servidor,
        contrasena_basica_servidor=contrasena_basica_servidor,
    )

    cliente = buscar_cliente_status(
        id_cliente=id_cliente,
        url_servidor=url_servidor,
        sesion=sesion,
        usuario_basico_servidor=usuario_basico_servidor,
        contrasena_basica_servidor=contrasena_basica_servidor,
    )

    if not existe_cliente(cliente):
        nuevo_cliente = obtener_json(
            url_servidor=url_servidor,
            accion="add_client",
            parametros={"clientname": str(id_cliente)},
            sesion=sesion,
            usuario_basico_servidor=usuario_basico_servidor,
            contrasena_basica_servidor=contrasena_basica_servidor,
        )

        if not nuevo_cliente:
            raise ErrorUrBackup("add_client devolvió respuesta vacía")

        if "already_exists" not in nuevo_cliente:
            if "new_authkey" not in nuevo_cliente:
                raise ErrorUrBackup("No se recibió new_authkey al crear cliente")

            authkey = nuevo_cliente["new_authkey"]

    return authkey