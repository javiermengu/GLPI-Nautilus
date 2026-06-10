# Nemo

Nemo es el componente cliente de la solución GLPI-Nautilus. Está diseñado para ejecutarse en equipos Windows y aplicar localmente la decisión definida en GLPI sobre si un equipo debe tener o no copia de seguridad activa mediante UrBackup.

Nemo trabaja junto con el plugin Nautilus para GLPI. Nautilus actúa como punto de control centralizado y Nemo actúa como componente de ejecución en el equipo final.

El objetivo principal de Nemo es automatizar la instalación, mantenimiento o retirada del cliente UrBackup en función del estado definido en GLPI.

---

## Relación con GLPI-Nautilus

La solución GLPI-Nautilus está formada por dos componentes principales:

- **Nautilus**: plugin para GLPI 11 encargado de almacenar y sincronizar información de backup.
- **Nemo**: ejecutable Windows encargado de aplicar en el equipo final la decisión definida en GLPI.

Flujo general:

```text
GLPI-Nautilus define estado_backup
Nemo consulta GLPI
Nemo comprueba UrBackup
Nemo instala, mantiene o desinstala el agente UrBackup Client
GLPI-Nautilus sincroniza fecha_ultimo_backup desde UrBackup
```

---

## Funcionalidades principales

Nemo incorpora las siguientes funcionalidades:

- Consulta del equipo local en GLPI mediante API REST;
- Obtención del identificador interno del activo en GLPI;
- Consulta del campo `estado_backup` definido por GLPI-Nautilus;
- Comprobación del estado del cliente en UrBackup;
- Alta del cliente en UrBackup cuando procede;
- Instalación silenciosa del agente UrBackup Client;
- Desinstalación silenciosa del agente cuando el backup está desactivado;
- Uso del identificador de GLPI como nombre del cliente en UrBackup;
- Salida estándar y salida de error compatibles con GLPI Inventory;
- Retorno de código `0` si la ejecución finaliza correctamente;
- Retorno de código distinto de `0` si se produce un error.

---

## Requisitos

Requisitos principales:

```text
Windows 10 o superior
GLPI 11
Plugin GLPI-Nautilus instalado y activo
Plugin GLPI Inventory activo
GLPI Agent instalado en el equipo final
Servidor UrBackup accesible desde el equipo final
Permisos administrativos para instalar o desinstalar UrBackup Client
```

Nemo está pensado para distribuirse mediante GLPI Inventory y ejecutarse en los equipos finales con permisos suficientes para instalar software.

---

## Identificación del equipo

Nemo identifica el equipo local a partir del nombre del sistema Windows.

A partir de ese nombre, consulta GLPI para localizar el activo correspondiente en el inventario.

Una vez localizado el equipo, Nemo obtiene su identificador interno de GLPI.

Ejemplo:

```text
Nombre del equipo Windows: PC-LAB-001
ID del equipo en GLPI: 153
Nombre del cliente en UrBackup: 153
```

El identificador interno de GLPI se utiliza como nombre del cliente en UrBackup.

Esta decisión evita depender del nombre del equipo, ya que el nombre puede cambiar durante el ciclo de vida del activo. El identificador interno de GLPI permanece estable mientras el activo exista.

---

## Campos consultados en GLPI

Nemo consulta los campos creados por GLPI-Nautilus mediante el plugin Fields.

Campos principales:

```text
estado_backup
fecha_ultimo_backup
```

Uso de los campos:

- `estado_backup`: indica si el equipo debe tener copia de seguridad activa.
- `fecha_ultimo_backup`: almacena la última fecha de backup sincronizada por Nautilus.

Nemo utiliza principalmente `estado_backup` para decidir qué acción debe realizar en el equipo final.

---

## Lógica de funcionamiento

La lógica general de Nemo es la siguiente:

```text
1. Obtener el nombre local del equipo.
2. Consultar GLPI mediante API REST.
3. Localizar el activo Computer correspondiente.
4. Leer el campo estado_backup.
5. Consultar UrBackup.
6. Decidir la acción necesaria.
7. Instalar, mantener o desinstalar UrBackup Client.
8. Finalizar con código de salida adecuado.
```

### Caso 1: backup activado

Si `estado_backup` indica que el backup está activado:

```text
estado_backup = SI
```

Nemo comprueba si el cliente existe en UrBackup.

Si no existe, lo crea usando como nombre el identificador interno de GLPI.

Después instala o mantiene el cliente UrBackup en el equipo final.

### Caso 2: backup desactivado

Si `estado_backup` indica que el backup está desactivado:

```text
estado_backup = NO
```

Nemo comprueba si el cliente UrBackup está instalado o registrado.

Si procede, desinstala el agente UrBackup Client del equipo final.

La limpieza del cliente en el servidor UrBackup queda gestionada por las tareas automáticas de GLPI-Nautilus y por los procesos internos de limpieza de UrBackup.

### Caso 3: equipo no encontrado en GLPI

Si Nemo no encuentra el equipo en GLPI, finaliza con error controlado.

Esto evita altas automáticas no autorizadas y mantiene el principio de seguridad por defecto.

---

## Comunicación con GLPI

Nemo se comunica con GLPI mediante API REST.

La configuración necesaria incluye:

```text
URL de la API de GLPI
App token
User token
```

Ejemplo de configuración:

```ini
[glpi]
url = http://glpi.example.local/apirest.php
app_token = APP_TOKEN
user_token = USER_TOKEN
```

Se recomienda utilizar una cuenta técnica con permisos mínimos y acceso limitado a las operaciones necesarias.

---

## Comunicación con UrBackup

Nemo se comunica con UrBackup para comprobar o preparar el cliente de backup.

La configuración necesaria incluye:

```text
URL de UrBackup
Usuario de UrBackup
Contraseña de UrBackup
```

Ejemplo de configuración:

```ini
[urbackup]
url = http://urbackup.example.local:55414/x
username = alta
password = PASSWORD_ALTA
```

El usuario técnico de UrBackup debe tener únicamente los permisos necesarios para la integración.

Permisos recomendados:

```text
status
add_client
settings
remove_client
```

---

## Instalación silenciosa del Agernte UrBackup Client

Nemo ejecuta la instalación de UrBackup Client mediante `msiexec`.

Instalación:
```text
msiexec /i UrBackupClientSetup.msi /qn /norestart
```

Parámetros:

```text
/qn        instalación silenciosa
/qb        instalación con interfaz básica
/norestart evita reinicio automático
```

Posteriormente realiza la configuración:

```text
"c:\Program Files\UrBackup\UrBackupClient_cmd.exe" set-setting --server-url urbackup://urbackup.dominio --name IdGLPI --authkey KEY_CLIENT_URBACKUP -n -p "c:\Program Files\UrBackup\pw_change.txt"
```

El valor `CLIENTNAME` debe corresponder al identificador interno del equipo en GLPI.

---

## Estructura del proyecto

Estructura orientativa del proyecto Nemo:

```text
nemo/
├── nemo.py
├── glpi.py
├── urbackup.py
├── config_nemo.py
├── generar_config_ofuscada.py
├── config.ini
├── nemo.spec
├── UrBackupClientSetup.msi
└── README.md
```

Descripción de los ficheros principales:

- `nemo.py`: programa principal de Nemo.
- `glpi.py`: funciones de comunicación con la API de GLPI.
- `urbackup.py`: funciones de comunicación con UrBackup.
- `config_nemo.py`: lectura de configuración y desofuscación de valores sensibles.
- `generar_config_ofuscada.py`: generación de configuración ofuscada antes del empaquetamiento.
- `config.ini`: fichero de configuración base.
- `nemo.spec`: fichero de empaquetamiento para PyInstaller.
- `UrBackupClientSetup.msi`: instalador del cliente UrBackup incluido en el paquete.

---

## Configuración

El fichero `config.ini` contiene los valores necesarios para conectar con GLPI y UrBackup.

Ejemplo:

```ini
[glpi]
url = http://glpi.example.local/apirest.php
app_token = APP_TOKEN
user_token = USER_TOKEN

[urbackup]
url = http://urbackup.example.local:55414/x
username = alta
password = PASSWORD_ALTA
```

Los valores sensibles no deben almacenarse en texto claro en el paquete final publicado, solo se conservan durante el empaquetamiento.

---

## Ofuscación de credenciales

Nemo utiliza un mecanismo de ofuscación simple para evitar que los valores sensibles aparezcan directamente en texto claro dentro del fichero `config.ini` distribuido.

Valores ofuscados:

```text
app_token
user_token
password
```

Los valores ofuscados se identifican mediante el prefijo:

```text
OBF:
```

El procedimiento general es:

```text
valor sensible
  -> generación de flujo mediante SHA-256
  -> operación XOR
  -> codificación Base64
  -> valor con prefijo OBF:
```

La ofuscación no equivale a cifrado fuerte. Su finalidad es evitar la exposición directa o accidental de credenciales.

Si Nemo puede recuperar una credencial para usarla, una persona con acceso suficiente al equipo y conocimientos técnicos podría llegar a recuperarla. Por este motivo, la seguridad debe apoyarse también en medidas compensatorias.

---

## Medidas compensatorias de seguridad

Se recomienda aplicar las siguientes medidas:

- Distribuir Nemo solo en red local o entorno controlado.
- Usar GLPI Inventory como mecanismo de despliegue o Directorio Activo.
- Ejecutar Nemo con permisos administrativos controlados;
- Limitar los permisos de los usuarios técnicos de GLPI y UrBackup;
- NO usar credenciales personales;
- NO exponer GLPI ni UrBackup directamente a Internet, sin utilización de VPN.
- NO incluir credenciales en texto claro en el repositorio;
- NO usar empaquetado `onefile` si genera falsos positivos en antivirus;

La ofuscación es una medida complementaria. No sustituye a una correcta gestión de permisos.

---

## Empaquetamiento con PyInstaller

Nemo se empaqueta como ejecutable Windows mediante PyInstaller.

Comando de construcción:

```bash
python -m PyInstaller --clean --noconfirm nemo.spec
```

El fichero `nemo.spec` controla el proceso de empaquetamiento y permite incluir los ficheros necesarios en el paquete final.

Resultado esperado:

```text
dist/
└── Nemo/
    ├── Nemo.exe
    └── _internal/
        ├── config.ini
        └── UrBackupClientSetup.msi
```

El fichero que se distribuye mediante GLPI Inventory es:

```text
Nemo.exe
```

---

## Despliegue mediante GLPI Inventory

Nemo se distribuye mediante el módulo de despliegue de GLPI Inventory.

Configuración recomendada del paquete:

```text
Paquete: Nemo
Fichero: Nemo.exe
Etiqueta de la acción: Lanzar Nemo
Líneas de salida a recuperar: 100
Validación: código de retorno igual a 0
```

La acción será:

```cmd
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command ^
"$p = Start-Process '.\Nemo.exe'-PassThru -RedirectStandardOutput nemo_out.log -RedirectStandardError nemo_err.log; ^
if (-not ($p.WaitForExit(300000))) { ^
    taskkill /F /T /PID $p.Id; ^
    Write-Output 'NEMO_ERROR: Timeout global de 5 minutos'; ^
    exit 1 ^
}; ^
if (Test-Path nemo_out.log) { Get-Content nemo_out.log }; ^
if (Test-Path nemo_err.log) { Get-Content nemo_err.log }; ^
exit $p.ExitCode"
```

Esto facilita revisar mensajes de ejecución desde GLPI Inventory.

---

## Códigos de salida

Nemo debe finalizar con código de salida `0` cuando la ejecución termina correctamente.

```text
0 = ejecución correcta
```

Si se produce un error, debe devolver un código distinto de `0`.

```text
1 = error general
2 = error de conexión con GLPI
3 = equipo no encontrado en GLPI
4 = error de conexión con UrBackup
5 = error durante instalación o desinstalación
```

## Logs

Nautilus escribe logs específicos en GLPI.

```text
nautilus_api
nautilus_cron
nautilus_install
```

Los ficheros se generan normalmente en:

```text
files/_log/
```

Nemo debe escribir mensajes claros en salida estándar y salida de error, de forma que GLPI Inventory pueda recoger el resultado de la ejecución.

Los ficheros log de Nemo se generan en el equipo destino en la carpeta C:\ProgramFiles\GLIPI-Agent\Nemo.log

Ejemplos:

```text
NEMO_INFO: Equipo localizado en GLPI
NEMO_INFO: estado_backup=SI
NEMO_ERROR: No se pudo conectar con UrBackup
```

---

## Configuración de la tarea de despliegue

```text
Tarea: Despliegue Nemo
Destino/Paquete: Nemo
Actores: grupo de inventario Nautilus Todos los ordenadores
Número de agentes a activar: 25
Intervalo de activación del agente: 300 minutos (5 horas)
Permit to re-prepare task after run: activo
```

Esta configuración evita una ejecución masiva y reduce la carga sobre GLPI, UrBackup y la red.

---

## Consideraciones para producción

Antes de utilizar Nemo en producción se recomienda:

- Probarlo en un entorno de validación;
- Revisar permisos del usuario técnico de GLPI;
- Revisar permisos del usuario técnico de UrBackup;
- Validar la instalación silenciosa del MSI;
- Comprobar que el antivirus o EDR no bloquea el ejecutable;
- Desplegar primero en un grupo reducido de equipos;
- Vevisar la salida de GLPI Inventory;

---

## Relación con el ENS

Nemo contribuye a la automatización del ciclo de vida de las copias de seguridad de los equipos inventariados.

Su función principal es aplicar de forma controlada la decisión definida en GLPI, evitando altas manuales y reduciendo la exposición de claves globales o mecanismos de registro anónimo.

El diseño busca alinearse con principios de:

- inventario actualizado de activos;
- seguridad por defecto;
- mínimo privilegio;
- control centralizado;
- automatización trazable.

---

## Estado del proyecto

Nemo se encuentra en fase inicial funcional y forma parte del proyecto GLPI-Nautilus.

Está orientado a un entorno controlado y a un caso de uso concreto:

```text
GLPI + GLPI Inventory + UrBackup + Nautilus
```

Antes de utilizarlo en producción, se recomienda realizar pruebas controladas.

---

## Licencia

Este proyecto se distribuye bajo licencia **GPL-2.0**.

```text
Copyright (C) 2026 Francisco Javier Mengual Maldonado
```

Este programa es software libre: puedes redistribuirlo y/o modificarlo bajo los términos de la Licencia Pública General GNU publicada por la Free Software Foundation, GPL Versión 2.

Este programa se distribuye con la esperanza de que sea útil, pero sin ninguna garantía; ni siquiera la garantía implícita de comerciabilidad o adecuación para un propósito particular.

---

## Autor

Francisco Javier Mengual Maldonado

Repositorio:

```text
https://github.com/javiermengu/GLPI-Nautilus
```

---

## Copyright

```text
Nemo

Copyright (C) 2026 Francisco Javier Mengual Maldonado

License: GPL-2.0
```
