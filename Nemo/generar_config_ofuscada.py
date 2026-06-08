import base64
import configparser
import hashlib
import secrets
from pathlib import Path


CONFIG_ORIGEN = Path("config.ini")
CONFIG_DIST_DIR = Path("build_config")
CONFIG_DIST = CONFIG_DIST_DIR / "config.ini"
SECRET_MODULE = Path("_nemo_obf_secret.py")

CAMPOS_SENSIBLES = {
    ("glpi", "app_token"),
    ("glpi", "user_token"),
    ("urbackup", "password"),
}

SAL_PROYECTO = b"NEMO-2026"

def b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode("ascii")


def generar_flujo(semilla: bytes, longitud: int) -> bytes:
    salida = b""
    contador = 1

    while len(salida) < longitud:
        bloque = SAL_PROYECTO + b":" + contador.to_bytes(4, "big")
        salida += hashlib.sha256(semilla + bloque).digest()
        contador += 1

    return salida[:longitud]


def xor_bytes(datos: bytes, flujo: bytes) -> bytes:
    return bytes(d ^ f for d, f in zip(datos, flujo))


def ofuscar_valor(valor: str, semilla: bytes) -> str:
    if valor.startswith("OBF:"):
        return valor

    datos = valor.encode("utf-8")
    flujo = generar_flujo(semilla, len(datos))
    datos_ofuscados = xor_bytes(datos, flujo)

    return "OBF:" + b64(datos_ofuscados)


def generar_modulo_secreto(semilla: bytes) -> None:
    """
    Genera un módulo Python con la semilla del build.
    Es ofuscación, no cifrado fuerte.
    """

    parte_1 = semilla[:10]
    parte_2 = semilla[10:21]
    parte_3 = semilla[21:]

    lineas = [
        "# Fichero generado automáticamente durante el empaquetado.",
        "# No editar manualmente.",
        "",
        "import base64",
        "import hashlib",
        "",
        f"SAL_PROYECTO = {SAL_PROYECTO!r}",
        "",
        f"_PARTE_1 = {b64(parte_1)!r}",
        f"_PARTE_2 = {b64(parte_2)!r}",
        f"_PARTE_3 = {b64(parte_3)!r}",
        "",
        "def _b64(valor: str) -> bytes:",
        "    return base64.urlsafe_b64decode(valor.encode('ascii'))",
        "",
        "def obtener_semilla() -> bytes:",
        "    return _b64(_PARTE_1) + _b64(_PARTE_2) + _b64(_PARTE_3)",
        "",
        "def generar_flujo(longitud: int) -> bytes:",
        "    semilla = obtener_semilla()",
        "    salida = b''",
        "    contador = 1",
        "    while len(salida) < longitud:",
        "        bloque = SAL_PROYECTO + b':' + contador.to_bytes(4, 'big')",
        "        salida += hashlib.sha256(semilla + bloque).digest()",
        "        contador += 1",
        "    return salida[:longitud]",
        "",
    ]

    SECRET_MODULE.write_text("\n".join(lineas), encoding="utf-8")


def main() -> None:
    if not CONFIG_ORIGEN.exists():
        raise FileNotFoundError(
            "No existe config.ini. Crea config.ini antes de compilar."
        )

    config = configparser.ConfigParser()
    config.read(CONFIG_ORIGEN, encoding="utf-8")

    semilla = secrets.token_bytes(32)

    for seccion, clave in CAMPOS_SENSIBLES:
        if config.has_option(seccion, clave):
            valor_actual = config.get(seccion, clave)
            config.set(seccion, clave, ofuscar_valor(valor_actual, semilla))

    CONFIG_DIST_DIR.mkdir(exist_ok=True)

    with CONFIG_DIST.open("w", encoding="utf-8") as f:
        config.write(f)

    generar_modulo_secreto(semilla)

    print("Configuración ofuscada generada:", CONFIG_DIST)
    print("Módulo de semilla generado:", SECRET_MODULE)


if __name__ == "__main__":
    main()