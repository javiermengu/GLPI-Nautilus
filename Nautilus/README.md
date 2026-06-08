# GLPI-Nautilus

GLPI-Nautilus es un plugin para GLPI 11 orientado a integrar la información de copias de seguridad gestionadas con UrBackup dentro de la ficha de los equipos de GLPI.

El plugin forma parte de la solución GLPI-Nautilus, compuesta por dos componentes principales:

- **Nautilus**: plugin para GLPI que actúa como punto de control centralizado.
- **Nemo**: ejecutable externo para Windows encargado de aplicar en el equipo final las decisiones definidas en GLPI.

El objetivo del plugin es disponer en GLPI de una visión sencilla del estado de backup de cada equipo, facilitar la sincronización con UrBackup y preparar la automatización posterior mediante Nemo y GLPI Inventory.

---

## Funcionalidades principales

GLPI-Nautilus incorpora las siguientes funcionalidades:

- Creación de campos personalizados en la ficha de los ordenadores, "Backup" y "Fecha Último Backup".
- Configuración de conexión con UrBackup;
- Consulta de clientes registrados en UrBackup;
- Sincronización de la fecha del último backup;
- Limpieza automática de clientes que ya no deben mantenerse en UrBackup;

---

## Contexto del proyecto

GLPI-Nautilus se desarrolla en el marco de un Trabajo Fin de Grado cuyo objetivo es centralizar la gestión de activos TIC y copias de seguridad mediante software libre.

La solución se apoya en los siguientes componentes:

```text
GLPI
GLPI Inventory
GLPI Agent
UrBackup
TrueNAS
```

El diseño busca reducir la gestión fragmentada entre herramientas independientes y ofrecer un punto de control común desde GLPI.

---

## Requisitos

El plugin está diseñado para GLPI 11.

Requisitos principales:

```text
GLPI >= 11.0
PHP compatible con GLPI 11
Plugin Fields activo
Plugin GLPI Inventory activo
Servidor UrBackup accesible desde GLPI
```

El plugin Fields se utiliza para crear y gestionar los campos personalizados asociados a los ordenadores.

GLPI Inventory se utiliza como base para el escenario de despliegue de Nemo en los ordenadores finales.

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
│   ├── cron.class.php
│   └── nautilus.class.php
├── hook.php
├── setup.php
└── README.md
```

Descripción de los ficheros principales:

- `setup.php`: registra el plugin en GLPI, define versión, hooks, requisitos y tareas automáticas.
- `hook.php`: gestiona la instalación y desinstalación del plugin.
- `inc/nautilus.class.php`: contiene la clase principal del plugin y los métodos de integración con la interfaz de GLPI.
- `inc/api.class.php`: contiene la comunicación con la API de UrBackup.
- `inc/cron.class.php`: contiene las tareas automáticas del plugin, urbackup_clean y urbackup_sync
- `inc/config.class.php`: contiene la lógica de configuración del plugin.
- `front/config.form.php`: muestra y guarda la configuración desde la interfaz de GLPI.

---

## Clase principal del plugin

El fichero principal de interfaz del plugin es:

```text
inc/nautilus.class.php
```

Este fichero contiene la clase:

```text
PluginNautilus
```

La clase extiende `CommonGLPI` y proporciona la integración básica del plugin con la interfaz de GLPI.

Funciones principales:

- Define el nombre visible del plugin mediante `getTypeName`;
- Prepara el hook `pre_item_form` para integrarse con los formularios de equipos;
- Permite futuras ampliaciones visuales sobre la ficha de los ordenadores;
- Mantiene la integración con el plugin Fields, que renderiza actualmente los campos personalizados.

En la versión actual, el método `pre_item_form` no modifica directamente el formulario del ordenador. Se mantiene preparado para futuras ampliaciones, como mostrar alertas de estado de UrBackup, cargar estilos propios o añadir lógica visual adicional en la ficha del equipo.

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

## Identificación de clientes en UrBackup

La solución utiliza el identificador interno del equipo en GLPI como nombre del cliente en UrBackup.

Ejemplo:

```text
Equipo GLPI ID: 153
Cliente UrBackup name: 153
```

Esta decisión evita depender del nombre del equipo, que puede cambiar durante el ciclo de vida del activo.

Ventajas:

- El identificador de GLPI es único;
- Se evita la creación de duplicados en UrBackup;
- Se simplifica la relación entre GLPI y UrBackup;
- Se mantiene una relación directa entre activo e historial de backup.

Si un equipo se elimina en GLPI y posteriormente se crea de nuevo, GLPI asignará un nuevo identificador. En ese caso, UrBackup tratará el dispositivo como un nuevo cliente, iniciando un nuevo ciclo de vida.

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

## Permisos recomendados en UrBackup

Se recomienda crear un usuario técnico específico en UrBackup para la integración.

Permisos mínimos recomendados:

```text
status
add_client
remove_client
settings
```

Este usuario debe utilizarse exclusivamente para la integración con GLPI-Nautilus y Nemo.

No se recomienda utilizar una cuenta personal ni una cuenta administrativa general.

---

## Funcionamiento general

El funcionamiento del plugin se basa en tres partes:

```text
GLPI
  -> Campos personalizados del equipo
  -> Tareas automáticas
  -> Comunicación con UrBackup
```

El campo `estado_backup` define si un equipo debe tener copia de seguridad activa.

El campo `fecha_ultimo_backup` se actualiza desde UrBackup mediante la tarea urbackup_sync.

La comunicación con UrBackup se realiza desde `PluginNautilusApi`, que implementa el flujo de autenticación necesario para consultar información y solicitar acciones sobre clientes.

---

## Tareas automáticas

El plugin registra dos tareas automáticas en GLPI:

```text
urbackup_clean
urbackup_sync
```

### urbackup_clean

Esta tarea revisa los clientes existentes en UrBackup y marca para eliminación aquellos que no deben mantenerse.

Un cliente puede eliminarse si:

- No existe el equipo correspondiente en GLPI;
- El equipo existe, pero tiene el backup desactivado;
- El cliente no es válido en UrBackup.

La eliminación en UrBackup puede no ser inmediata. El cliente puede quedar marcado para eliminación y el borrado físico se completará durante el proceso de limpieza de UrBackup.

### urbackup_sync

Esta tarea consulta UrBackup y sincroniza en GLPI la fecha del último backup.

Si el cliente de UrBackup tiene una fecha válida y tiene el campo activo Backup en GLPI, se guarda en el campo esta fecha:

```text
fecha_ultimo_backup
```

Si no está activo en GLPI, la fecha se establece a null.

---

## Planificación recomendada de tareas

En un entorno de pruebas se pueden ejecutar las tareas con frecuencia alta para validar el funcionamiento del sistema.

En producción se recomienda una planificación más conservadora.

Orden recomendado:

```text
01:00 - 03:00  -> urbackup_clean
03:00 - 07:00  -> ventana de limpieza interna de UrBackup
10:00 - 14:00  -> urbackup_sync
```

Esta planificación evita solapamientos entre tareas y permite que GLPI-Nautilus sincronice información cuando UrBackup ya ha procesado su limpieza interna.

---

## Ejecución del cron de GLPI

Para que las tareas automáticas se ejecuten de forma regular, GLPI debe ejecutar `front/cron.php` en modo CLI.

Ejemplo en contenedor Docker en TrueNAS:

```bash
docker exec ix-glpi-glpi-1 php /var/www/glpi/front/cron.php
```

En producción se recomienda ejecutar el cron de GLPI cada minuto mediante el planificador del sistema o mediante las tareas programadas de TrueNAS.

---

## Limpieza en UrBackup

Durante las pruebas puede ser útil forzar la limpieza de UrBackup.

Ejemplo:

```bash
docker exec ix-urbackup-urbackup-1 urbackupsrv cleanup --amount 0%
```

También puede utilizarse:

```bash
docker exec ix-urbackup-urbackup-1 urbackupsrv remove-unknown
```

Uso recomendado:

- `cleanup --amount 0%`: procesa clientes eliminados y backups pendientes.
- `remove-unknown`: elimina restos físicos no reconocidos por la base de datos de UrBackup.

Estas tareas son útiles en laboratorio, pero no se recomienda ejecutarlas con frecuencia alta en producción.

---

## Nemo

GLPI-Nautilus está pensado para trabajar junto con Nemo.

Nemo es un ejecutable externo para Windows que consulta GLPI, interpreta el valor de `estado_backup` y actúa sobre el equipo final.

El plugin mantiene en GLPI la información necesaria:

```text
estado_backup
fecha_ultimo_backup
```

Flujo de trabajo:

```text
GLPI-Nautilus define estado_backup
Nemo consulta GLPI
Nemo comprueba UrBackup
Nemo instala o desinstala UrBackup Client según corresponda
```

Nemo se despliega mediante GLPI Inventory y GLPI Agent.

---

## Instalación

Copiar el plugin en la carpeta de plugins de GLPI:

```text
glpi/plugins/nautilus
```

En el contenedor Docker oficial de GLPI se utiliza un montaje persistente.

```text
/mnt/pool-backups/glpi/plugins -> /var/www/glpi/plugins
```

La ruta final esperada será:

```text
/var/www/glpi/plugins/nautilus
```

Después, desde la interfaz de GLPI:

```text
Configuración > Plugins
```

Instalar y activar el plugin.

Durante la instalación se realizan las siguientes acciones:

- registro de tareas cron;
- creación o reutilización del bloque Fields `GLPI-Nautilus`;
- creación de los campos `estado_backup` y `fecha_ultimo_backup`;
- creación de la tabla de configuración del plugin;
- preparación de la configuración de conexión con UrBackup.

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
```

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

Los ficheros se generan en el directorio de logs de GLPI:

```text
files/_log/
```

Ejemplo:

```text
files/_log/nautilus_cron.log
files/_log/nautilus_api.log
files/_log/nautilus_install.log
```

---

## Seguridad

El plugin almacena la configuración necesaria para conectarse a UrBackup.

Se recomienda:

- utilizar un usuario de UrBackup con permisos limitados;
- proteger el acceso a la configuración del plugin;
- restringir el acceso administrativo en GLPI;
- no reutilizar credenciales personales;
- revisar periódicamente los permisos asignados;
- mantener GLPI, UrBackup y sus plugins actualizados;
- no exponer los servicios directamente a Internet.

El plugin no está diseñado para exponer información públicamente. Su uso previsto es dentro de una red local o entorno institucional controlado.

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

## Consideraciones sobre Docker y TrueNAS

En despliegues con Docker no se recomienda crear enlaces simbólicos manuales dentro del contenedor para alojar el plugin.

La opción recomendada es montar directamente la carpeta de plugins como volumen persistente.

Ejemplo:

```text
/mnt/pool-backups/glpi/plugins -> /var/www/glpi/plugins
```

Con esta configuración, el plugin queda disponible en:

```text
/var/www/glpi/plugins/nautilus
```

Esta opción evita que el plugin desaparezca al reiniciar, actualizar o recrear el contenedor.

---


## Nota sobre el mecanismo de wakeup del GLPI Agent

Debido a las limitaciones presentadas en el mecanismo de *wakeup* del GLPI Agent, se ha optado por deshabilitar la tarea automática `wakeupAgents`. Esta decisión evita ejecuciones fallidas y comportamientos inconsistentes observados en entornos reales.

En su lugar, la ejecución de tareas se delega en el propio agente en modo servicio, permitiendo que éste gestione de forma autónoma su ciclo de ejecución.

La frecuencia de ejecución dependerá de la planificación definida en GLPI Inventory, recomendándose un intervalo aproximado de **4 a 5 horas**, suficiente para garantizar la actualización periódica de la información sin generar carga innecesaria en el sistema.

En caso de requerir una ejecución inmediata, es posible acceder directamente a la interfaz HTTP del agente y "forzar un inventario", si se ha incluido en la opción del agente HTTP_TRUST en el fichero .bat:

```text
http://direccion_equipo:62354/
```

---

## Estado del proyecto

GLPI-Nautilus se encuentra en fase inicial funcional.

El plugin está orientado a un entorno controlado y a un caso de uso concreto:

```text
GLPI + GLPI Inventory + UrBackup + Nemo
```

Antes de utilizarlo en producción, se recomienda probarlo en un entorno de validación.

---

## Trabajo futuro

Posibles líneas de mejora:

- añadir control independiente para copias de archivos e imágenes;
- añadir nuevos campos de estado relacionados con errores de backup;
- mejorar la visualización dentro de la ficha del ordenador;
- incorporar avisos visuales en la interfaz de GLPI;
- ampliar los controles de sincronización con UrBackup;
- documentar una instalación completa de laboratorio y producción.

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
GLPI-Nautilus

Copyright (C) 2026 Francisco Javier Mengual Maldonado

License: GPL-2.0
```
