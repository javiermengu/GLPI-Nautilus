# GLPI-Nautilus

GLPI-Nautilus es un plugin para GLPI orientado a integrar la información de copias de seguridad gestionadas con UrBackup dentro de la ficha de los equipos de GLPI.

El plugin permite añadir campos específicos en los ordenadores, consultar el estado de los clientes en UrBackup y ejecutar tareas automáticas para mantener sincronizada la información de backup.

El objetivo principal es disponer en GLPI de una visión sencilla del estado de backup de cada equipo y facilitar la automatización posterior mediante Nemo y GLPI Inventory.

---

## Funcionalidades principales

GLPI-Nautilus incorpora las siguientes funcionalidades:


- Creación de campos personalizados en la ficha de los ordenadores;
- Configuración de conexión con UrBackup;
- Consulta de clientes registrados en UrBackup;
- Sincronización de la fecha del último backup;
- Limpieza automática de clientes que ya no deben mantenerse en UrBackup;
- Creación de tareas automáticas de GLPI;
- Creación de perfil y usuario técnico para la integración;
- Creación de cliente API para Nemo;
- Creación de grupo dinámico en GLPI Inventory para despliegue;
- Preparación del entorno para su uso junto con Nemo.

---

## Requisitos

El plugin está diseñado para GLPI 11.

Requisitos principales:

```text
GLPI >= 11.0
Plugin Fields activo
Plugin GLPI Inventory activo
Servidor UrBackup accesible desde GLPI
```

El plugin Fields se utiliza para crear los campos personalizados en los equipos.

GLPI Inventory se utiliza como base para el escenario de despliegue y automatización con agentes.

---

## Estructura del plugin

La estructura principal del plugin es:

```text
nautilus/
├── front/
│   └── config.form.php
├── inc/
│   ├── api.class.php
│   ├── config.class.php
│   └── cron.class.php
├── hook.php
├── setup.php
└── README.md
```

Descripción de los ficheros principales:

- `setup.php`: registra el plugin en GLPI, define versión, hooks, requisitos y tareas cron.
- `hook.php`: gestiona la instalación y desinstalación del plugin.
- `inc/api.class.php`: contiene la comunicación con la API de UrBackup.
- `inc/cron.class.php`: contiene las tareas automáticas del plugin.
- `inc/config.class.php`: contiene el formulario y la lógica de configuración del plugin.
- `front/config.form.php`: muestra y guarda la configuración desde la interfaz de GLPI.

---

## Campos creados en GLPI

Durante la instalación, el plugin crea o reutiliza un bloque de Fields llamado:

```text
GLPI-Nautilus
```

Este bloque se asocia a los equipos de GLPI (`Computer`) y contiene los siguientes campos:

```text
estado_backup
fecha_ultimo_backup
```

Uso de los campos:

- `estado_backup`: indica si el equipo debe tener backup activo.
- `fecha_ultimo_backup`: almacena la fecha del último backup conocido.

Si el bloque ya existe, el plugin no intenta crearlo de nuevo. Esto evita errores al activar o reinstalar el plugin.

---

## Configuración de UrBackup

El plugin dispone de una página de configuración accesible desde la configuración de plugins de GLPI.

La configuración permite indicar:

```text
URL de UrBackup
Usuario de UrBackup
Contraseña de UrBackup
```

La URL de UrBackup puede indicarse sin `/x`. Si se configura así:

```text
http://servidor:55414
```

el plugin la transforma automáticamente en:

```text
http://servidor:55414/x
```

UrBackup utiliza normalmente la ruta `/x` para su API.

---

## Funcionamiento general

El funcionamiento del plugin se basa en tres partes:

```text
GLPI
  -> campos personalizados del equipo
  -> tareas automáticas
  -> comunicación con UrBackup
```

El campo `estado_backup` define si un equipo debe tener copia de seguridad activa.

El campo `fecha_ultimo_backup` se actualiza desde UrBackup mediante una tarea automática.

La comunicación con UrBackup se realiza desde `PluginNautilusApi`, que implementa el flujo de autenticación necesario para la API de UrBackup.

---

## Tareas automáticas

El plugin registra dos tareas automáticas en GLPI:

```text
urbackup_clean
urbackup_sync
```

### urbackup_clean

Esta tarea revisa los clientes existentes en UrBackup y elimina aquellos que no deben mantenerse.

Un cliente puede eliminarse si:

- No existe el equipo correspondiente en GLPI.
- El equipo existe, pero tiene el backup desactivado.
- El cliente no es válido en UrBackup.

La tarea evita eliminar clientes rechazados o sin identificador válido.

### urbackup_sync

Esta tarea consulta UrBackup y sincroniza en GLPI la fecha del último backup.

Si el cliente de UrBackup tiene una fecha válida, se guarda en el campo:

```text
fecha_ultimo_backup
```

---

## Nemo

GLPI-Nautilus está pensado para trabajar junto con Nemo.

El plugin mantiene en GLPI la información necesaria:

```text
estado_backup
fecha_ultimo_backup
```

Nemo puede consultar esta información y actuar en el equipo cliente.

Ejemplo de flujo:

```text
GLPI-Nautilus define estado_backup
Nemo consulta GLPI
Nemo comprueba UrBackup
Nemo instala o desinstala UrBackup Client según corresponda
GLPI-Nautilus sincroniza fecha_ultimo_backup desde UrBackup
```

---

## Instalación

Copiar el plugin en la carpeta de plugins de GLPI:

```text
glpi/plugins/nautilus
```

Después, desde la interfaz de GLPI:

```text
Configuración > Plugins
```

Instalar y activar el plugin.

Durante la instalación se realizan las siguientes acciones:

- Creación del perfil `Nemo`, si no existe;
- Creación del usuario técnico `nemo`, si no existe;
- Activación de API e inventario;
- Creación del cliente API `Nemo-Client`, si no existe;
- Registro de tareas cron;
- Creación o reutilización del bloque Fields `GLPI-Nautilus`;
- Creación de los campos `estado_backup` y `fecha_ultimo_backup`;
- Creación de la tabla de configuración del plugin.

---

## Permisos del bloque GLPI-Nautilus

Durante la instalación, el plugin asigna permisos sobre el bloque de Fields `GLPI-Nautilus`.

El criterio aplicado es el siguiente:

```text
Super-Admin  -> escritura
Admin        -> escritura
Technician   -> escritura
Nemo         -> escritura
Resto        -> lectura

---

## Desinstalación

Durante la desinstalación se eliminan:

```text
tareas cron del plugin
tabla de configuración de Nautilus
```

No se elimina automáticamente el bloque de Fields `GLPI-Nautilus`.

Esta decisión es intencionada. El bloque puede contener datos funcionales o históricos, como:

```text
estado_backup
fecha_ultimo_backup
```

Eliminarlo automáticamente podría provocar pérdida de información. Si se desea una eliminación completa, el administrador puede borrar manualmente el bloque Fields desde GLPI.

---

## Seguridad

El plugin almacena la configuración necesaria para conectarse a UrBackup.

Se recomienda:

- Utilizar un usuario de UrBackup con permisos limitados.
- Proteger el acceso a la configuración del plugin;
- Restringir el acceso administrativo en GLPI;
- No reutilizar credenciales personales;
- Revisar periódicamente los permisos asignados.

El plugin no está diseñado para exponer información públicamente. Su uso previsto es dentro de una red local o entorno institucional controlado.

---

## Logs

El plugin escribe información de diagnóstico en ficheros de log de GLPI.

Logs principales:

```text
nautilus_api
nautilus_cron
nautilus_install
```

Uso previsto:

- `nautilus_api`: comunicación con UrBackup.
- `nautilus_cron`: ejecución de tareas automáticas.
- `nautilus_install`: instalación y preparación del plugin.

Estos logs son útiles para revisar problemas de conexión, autenticación o sincronización.

---

## Consideraciones sobre Fields

El plugin comprueba si el bloque `GLPI-Nautilus` ya existe antes de crearlo.

La comprobación se realiza para evitar duplicados al activar de nuevo el plugin o al reinstalarlo.

Se comprueba tanto por nombre interno como por etiqueta visible:

```text
nautilus_block
GLPI-Nautilus
```

Esto reduce errores en instalaciones donde el bloque ya existe por una instalación anterior.

---

## Estado del proyecto

GLPI-Nautilus se encuentra en fase inicial funcional.

El plugin está orientado a un entorno controlado y a un caso de uso concreto:

```text
GLPI + GLPI Inventory + UrBackup + Nemo
```

Antes de utilizarlo en producción, se recomienda probarlo en un entorno de validación.

---

## Licencia

GPLv2.

---

## Autor

Francisco Javier Mengual Maldonado

Repositorio:

```text
https://github.com/javiermengu/GLPI-Nautilus
```
