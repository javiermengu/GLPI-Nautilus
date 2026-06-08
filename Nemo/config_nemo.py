import base64
import configparser
import sys
from pathlib import Path

from _nemo_obf_secret import generar_flujo


CONFIG_FILE = "config.ini"


def ruta_base() -> Path:
    """
    Devuelve la carpeta donde PyInstaller deja los ficheros de datos.

    En modo onedir, normalmente estarán en sys._MEIPASS.
    """

    if getattr(sys, "frozen", False):
        return Path(sys._MEIPASS)

    return Path(__file__).resolve().parent


def xor_bytes(datos: bytes, flujo: bytes) -> bytes:
    return bytes(d ^ f for d, f in zip(datos, flujo))


def desofuscar_valor(valor: str) -> str:
    if not valor.startswith("OBF:"):
        return valor

    datos_ofuscados = base64.urlsafe_b64decode(valor[4:].encode("ascii"))
    flujo = generar_flujo(len(datos_ofuscados))
    datos_claros = xor_bytes(datos_ofuscados, flujo)

    return datos_claros.decode("utf-8")


def cargar_config_nemo() -> dict:
    ruta_config = ruta_base() / CONFIG_FILE

    if not ruta_config.exists():
        raise FileNotFoundError(
            f"No existe el fichero de configuración: {ruta_config}"
        )

    config = configparser.ConfigParser()
    config.read(ruta_config, encoding="utf-8")

    return {
        "glpi_url": config.get("glpi", "url"),
        "app_token": desofuscar_valor(config.get("glpi", "app_token")),
        "user_token": desofuscar_valor(config.get("glpi", "user_token")),
        "urbackup_url": config.get("urbackup", "url"),
        "urbackup_username": config.get("urbackup", "username"),
        "urbackup_password": desofuscar_valor(config.get("urbackup", "password")),
    }