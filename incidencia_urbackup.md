# Incidencia UrBackup: reutilización del mismo nombre de cliente

## 1. Resumen ejecutivo

Se ha detectado un comportamiento anómalo en UrBackup Server cuando se elimina un cliente, se desinstala completamente el agente en Windows y posteriormente se vuelve a instalar usando el mismo nombre de cliente.

El agente se instala correctamente, la authkey se genera correctamente y la configuración local se aplica correctamente. Sin embargo, el cliente no consigue conectar con el servidor UrBackup hasta que se reinicia el contenedor del servidor.

La incidencia no se reproduce cuando se usa un nombre nuevo de cliente. Esto indica que el problema está asociado a la reutilización del mismo nombre o identificador de cliente en UrBackup Server.

## 2. Entorno afectado

- Servidor: UrBackup Server en contenedor Docker.
- Contenedor: ix-urbackup-urbackup-1.
- Cliente: Windows con UrBackup Client MSI.
- Automatización: Nemo desplegado desde GLPI Inventory.
- Limpieza local: desinstalación MSI y borrado de carpetas locales.

## 3. Flujo normal de alta

1. El cliente Windows se da de alta en UrBackup Server.
2. UrBackup Server genera una authkey.
3. Nemo instala el agente UrBackup Client.
4. Nemo configura el cliente con URL, nombre y authkey.
5. El cliente conecta correctamente con UrBackup Server.

Resultado esperado:

```text
Cliente conectado correctamente.
```

## 4. Flujo que genera la incidencia

1. El cliente ya existe en UrBackup Server.
2. Se desinstala UrBackup Client del equipo Windows.
3. Se elimina correctamente la carpeta local:

```text
C:\Program Files\UrBackup
```

4. Se limpia la configuración local del cliente.
5. Se elimina el cliente en UrBackup Server.
6. Se vuelve a crear el cliente con el mismo nombre.
7. UrBackup Server genera una nueva authkey.
8. Nemo reinstala el agente en Windows.
9. Nemo configura el cliente con el mismo nombre y la nueva authkey.
10. El cliente no conecta con UrBackup Server.
11. Tras reiniciar el contenedor UrBackup Server, el cliente conecta.

## 5. Pruebas realizadas

### 5.1 Reinstalación con el mismo nombre

Resultado:

```text
El cliente no conecta hasta reiniciar el contenedor UrBackup Server.
```

### 5.2 Reinstalación con nombre nuevo

Resultado:

```text
El cliente conecta correctamente sin reiniciar el contenedor.
```

### 5.3 Cleanup y unknown client

Se ha probado:

- Cleanup de UrBackup Server.
- Limpieza de clientes desconocidos.
- Limpieza local completa del cliente.
- Reconfiguración con nueva authkey.

Resultado:

```text
No resuelve la reconexión cuando se reutiliza el mismo nombre.
```

### 5.4 Reinicio del contenedor

Comando probado:

```bash
docker restart ix-urbackup-urbackup-1
```

Resultado:

```text
El cliente conecta correctamente después del reinicio.
```

## 6. Diagnóstico técnico

El problema no parece estar en:

- MSI de UrBackup Client.
- Nemo.
- GLPI Inventory.
- Red o DNS.
- Authkey como mecanismo general.
- Instalación local del agente.

El comportamiento observado apunta a que UrBackup Server mantiene algún estado interno asociado al cliente anterior cuando se reutiliza el mismo nombre.

Posibles elementos implicados:

- Identificador interno del cliente.
- Nombre del cliente.
- Authkey anterior.
- Sesión previa.
- Estado pendiente de cleanup.
- Caché interna del servidor.

## 7. Impacto operativo

La incidencia puede afectar a clientes que:

1. Ya existían en UrBackup Server.
2. Han sido eliminados del servidor.
3. Han sido reinstalados con el mismo nombre.
4. Han recibido una nueva authkey.

La incidencia no ocurre de forma constante. Depende del número de equipos, del porcentaje de reinstalaciones y de la frecuencia con la que se reutilice el mismo nombre.

Para un entorno aproximado de 100 equipos, se considera prudente establecer una tarea de reinicio semanal del contenedor UrBackup Server.

## 8. Medida correctiva aplicada

Se ha configurado una tarea cron en TrueNAS para reiniciar semanalmente el contenedor UrBackup Server.

Comando:

```bash
docker restart ix-urbackup-urbackup-1
```

La tarea debe ejecutarse fuera de:

- Ventana de copias de seguridad.
- Ventana de limpieza de UrBackup.
- Restauraciones.
- Operaciones administrativas intensivas.

Se recomienda mantener un reinicio semanal del contenedor UrBackup Server en una ventana controlada.

Frecuencia recomendada:

```text
Semanal
```

Horario recomendado:

```text
Fuera del horario de copias y limpieza.
```

Ejemplo:

```text
Domingo a las 23:00 horas.
```

## 9. Criterio para ejecutar el reinicio

Antes de reiniciar, se recomienda comprobar que UrBackup no está realizando tareas activas:

- No hay backups de fichero en curso.
- No hay backups de imagen en curso.
- No hay restauraciones activas.
- No hay cleanup activo.
- No hay operaciones administrativas en curso.

## 10. Regla para Nemo

Nemo no debe reiniciar automáticamente el servidor UrBackup.

Motivo:

```text
Un cliente individual no debe reiniciar un servicio central que puede afectar a otros equipos.
```

Nemo debe limitarse a:

- Instalar el agente.
- Desinstalar el agente.
- Limpiar carpetas locales.
- Configurar el cliente.
- Registrar errores.
- Dejar trazabilidad si el cliente no conecta.

El reinicio del contenedor UrBackup Server debe tratarse como una operación de mantenimiento programada.

## 11. Conclusión

El problema detectado está asociado a la reutilización del mismo nombre de cliente tras eliminarlo y recrearlo en UrBackup Server.

Cuando se utiliza un nombre nuevo, el cliente conecta correctamente. Cuando se reutiliza el mismo nombre, el cliente no conecta hasta reiniciar el contenedor UrBackup Server.

La medida operativa aplicada es un reinicio semanal controlado del contenedor:

```bash
docker restart ix-urbackup-urbackup-1
```

Esta medida es prudente para un entorno aproximado de 100 equipos y reduce la probabilidad de que clientes reinstalados con el mismo nombre queden sin conexión.
