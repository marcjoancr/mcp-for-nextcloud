<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/configuration.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../en/reference/configuration.md).

# Configuración

Usa las interfaces de administración y ajustes personales para cambiar la política. Escribir preferencias de política directamente con `occ` elude las comprobaciones de revisión, la resolución de grupos y la revocación de credenciales; no es una forma admitida de administrar la política. Los diagnósticos de solo lectura, como `occ config:app:get mcp service_enabled`, siguen siendo útiles.

| Almacenamiento | Clave o responsable | Significado |
| --- | --- | --- |
| Aplicación `mcp` | `service_enabled` | `1` activado; `0` o ausente, desactivado. Cambiar mediante el comando/interfaz del servicio. |
| Usuario, aplicación `mcp` | `eligible` | Excepción individual `1`/`0` de **Puede conectarse**; su ausencia permite herencia de grupo y después el valor predeterminado desactivado. |
| Usuario, aplicación `mcp` | `connected` | `1` indica conexión personal activa. Es distinto de una credencial OAuth vigente. |
| Usuario, aplicación `mcp` | `grant_<module>_<operation>` | Excepción antigua de operación individual usada por los valores compatibles de las herramientas originales. El acceso actual también requiere asignación de herramienta y selección en la conexión; Mail, Forms y los registros empiezan denegados. |
| Reglas de herramientas | `mcp_tool_grants` | Asignaciones individuales/de grupo: Permitir, Bloquear o herencia neutra, con la precedencia descrita en permisos. |
| Selecciones de conexiones | `mcp_oauth_tools` | Nombres de herramientas seleccionados por conexión OAuth; los clientes Basic/sesión comparten el perfil Nextcloud separado (ID de conexión cero). |
| Tablas de política | `mcp_policy_state`, `mcp_policy_events` | Revisión/época global de autenticación y eventos persistentes de auditoría, limpieza y recibos. |
| Tablas de grupos | `mcp_group_grants`, `mcp_group_life` | Política que solo concede permisos e identidad/ciclo de vida de grupo observados. |
| Tablas de autenticación | `mcp_auth_users`, `mcp_auth_recheck` | Generaciones/invalidaciones de credenciales por usuario y comprobaciones diferidas del ciclo de vida. |
| Tablas de recursos | `mcp_scope_modes`, `mcp_scope_items` | Restricciones independientes de Files, Calendar y Tasks. |
| Tabla de clientes | `mcp_oauth_clients` | Estado autoritativo y generaciones de clientes nativos/personalizados gestionados tras la migración. |
| Aplicación `mcp` | `oauth_client_hosts` | Hosts exactos de clientes publicados; ausente o vacío usa `claude.ai,chatgpt.com`. Editar en clientes OAuth. |
| Entradas antiguas de la aplicación | `oauth_native_client_enabled`, `oauth_custom_clients` | Entradas de migración de la tabla de clientes, no la autoridad vigente de clientes gestionados. |
| Tabla propia | `mcp_logs_groups` | Grupos JSON canónicos y versión; vacío significa solo administradores. Importa la configuración antigua en la primera lectura. |
| Aplicación `mcp` | `checkout_max_bytes` | Límite de subida de checkout en bytes; predeterminado 50 MiB, limitado por `post_max_size` de PHP. |
| Aplicación `mcp` | `file_write_policy` | Lista opcional y versionada de nombres/extensiones permitidos; ausente significa desactivada. Se cambia en Protección de datos; un estado inválido o ilegible rechaza las escrituras afectadas. |
| Aplicación `mcp` | `backup_settings` | Documento de versión 1 con la carpeta raíz actual, los nombres históricos reservados y la preferencia de copias de Files. Ausente: `MCP backups`, copias activadas. Se cambia en Protección de datos; un estado incorrecto o no disponible rechaza las operaciones afectadas. |

| Aplicación `mcp` | `hidden_system_tags` | Lista JSON de ID de etiquetas de sistema excluidas; `[]` desactiva las exclusiones por etiquetas. |
| Aplicación `mcp` | `logs_hide_file_names` | `1` predeterminado: ocultar texto de mensajes de registro mientras haya exclusiones; `0` permite el registro completo para diagnóstico. |

## Copias de seguridad MCP

En administración → **Protección de datos** → **Copias de seguridad MCP**, elige un único nombre de carpeta directamente bajo la raíz personal de Files de cada usuario que actúa. El valor predeterminado es `MCP backups`; se admite una carpeta con punto inicial, como `.mcp-backups`. Los nombres se normalizan a Unicode NFC, tienen un límite de 255 bytes UTF-8 y los valida Nextcloud. Se rechazan rutas, separadores, caracteres de control, `.` y `..`.

Cambiar el nombre no mueve ni elimina las copias existentes. La carpeta se crea con la primera copia. Todos los nombres configurados anteriormente, incluido `MCP backups`, siguen reservados frente a escrituras MCP de Files en la raíz del actor; las carpetas anidadas normales con ese nombre no se reservan automáticamente. Las exclusiones existentes siguen protegiendo las copias de originales ocultos, incluidos los alias compartidos canónicos. No es una ACL del sistema de archivos ni una prohibición general de escribir mediante montajes compartidos renombrados.

Las copias previas a la escritura de Files están activadas por defecto. La administración solo puede suspenderlas con Versions habilitada; se vuelve a comprobar su disponibilidad para el usuario que actúa. Si Versions es desconocida o no está disponible, las copias son obligatorias y nunca se habilita una escritura que ya rechazara la condición previa de Versions. El versionado nativo sigue su propia política y no garantiza recuperar todos los archivos o backends. Los planes y recibos muestran una ruta de copia vacía cuando no se hará o no se hizo una copia MCP y no prometen recuperación mediante MCP. Eliminar contactos siempre requiere una copia completa y verificada del vCard bajo `/<configured folder>/Contacts`, independientemente de la opción de Files.

La API administrativa usa GET/PUT `/api/backup-settings`, con comprobaciones de autenticación, administración y CSRF. PUT envía `folder` completo, el booleano `copies` y la cadena `expectedRevision`. Una revisión ausente u obsoleta devuelve 428/409. El panel conserva los datos sin guardar tras un conflicto o un resultado incierto y exige una recarga verificada antes de guardar de nuevo. Las respuestas verifican que la revisión siga correspondiendo a los ajustes; un cambio concurrente durante la construcción de la respuesta requiere otra lectura. La configuración corrupta no recupera los valores predeterminados ni pierde las reservas históricas; para recuperarla hay que investigar y restaurar su último documento completo conocido, no eliminar la clave.

Las escrituras vuelven a leer las reservas en los puntos de modificación, incluidos los enlaces de checkout y deshacer lotes. Deshacer comprueba todos los extremos registrados antes de mover nada y conserva los directorios registrados que hayan pasado a estar reservados. Los cambios de política y las escrituras nativas del sistema de archivos no forman una transacción atómica: un cambio concurrente posterior a la última comprobación queda fuera de esta garantía. Un rechazo después de copiar o crear puede dejar una copia o un archivo vacío correctamente indicado; no se intenta eliminar ni repetir automáticamente.

## Política de tipos de archivo

En administración → **Protección de datos** → **Tipos de archivo que MCP puede escribir**, elige **Sin restricción (predeterminado)** o **Solo las extensiones indicadas**, revisa y guarda. Se aplica globalmente a las escrituras MCP de Files y Notes, con independencia de los permisos concedidos y nativos, las restricciones de recursos, Guests, exclusiones, bloqueos y confirmaciones. Nunca habilita una escritura que estaría rechazada. Es una regla de nombres/extensiones, no una inspección de MIME o contenido, un detector de malware ni una protección frente a inyección de instrucciones; otros clientes quedan fuera de su alcance.

La lista admite hasta 128 extensiones únicas, separadas por comas o líneas. El punto inicial es opcional; los valores se convierten a minúsculas y se ordenan. Cada uno contiene de 1 a 32 letras/dígitos ASCII, `_` o `-`, empezando por letra o dígito. La evaluación usa la última extensión del nombre final resuelto, sin distinguir mayúsculas. Los nombres sin extensión (incluidos los que empiezan por punto y no tienen otro posterior) requieren una opción explícita independiente, desactivada por defecto. Con la política activa se rechazan nombres vacíos, puntos o espacios finales y nombres mal formados; las extensiones no filtran los nombres de las propias carpetas.

Cubre creación/subida, edición y sustitución, subida de checkout, restauración de versiones, copia/movimiento, lotes ordenados y deshacer, así como creación/edición/edición parcial/movimiento de Notes. Las copias internas de recuperación de Files y las obligatorias de Contacts están exentas; una escritura pública con `.bak` o `.vcf` no tiene esa exención. La vista previa y la confirmación comprueban la política; los puntos de modificación la leen de nuevo, por lo que se rechaza una subida de checkout recién bloqueada y un deshacer bloqueado conserva los datos y su recibo. Una configuración no disponible o mal formada falla cerrada. Un guardado incierto no se repite automáticamente; usa el flujo de lectura y reintento de la interfaz para consultar lo almacenado.

Con la política activa, las operaciones de carpetas comprueban antes de actuar hasta 2000 nodos inspeccionados/proyectados por punto de modificación; las copias conservan además el límite de 1 GiB. Copiar una carpeta comprueba el árbol visible/legible y copia desde ese manifiesto validado, con comprobaciones nuevas por entrada. Se omiten las incorporaciones posteriores; un fallo puede dejar una copia parcial indicada, que nunca se elimina automáticamente. Mover una carpeta también comprueba previamente los descendientes, pero sigue siendo un único movimiento nativo: pueden moverse con ella los archivos que otro cliente añada después de la última comprobación. Los cambios de política y las escrituras del sistema de archivos no comparten una transacción atómica, y el almacenamiento o contenido nativos pueden cambiar tras una comprobación. Los cambios de visibilidad pueden ocultar detalles de recibos obsoletos mientras se conservan los datos ya escritos. Revisa los resultados inciertos antes de actuar de nuevo; no se promete reversión automática ni repetición segura.

Estas tablas describen persistencia, no una API pública de mutación SQL. Editar filas o restaurar solo algunas tablas puede romper las garantías de revisión, ciclo de vida y revocación. Consulta [permisos](../guides/permissions.md), [OAuth](oauth.md) y las [indicaciones de actualización/recuperación](../installation.md).

Nextcloud controla `enabled`, `installed_version`, `types`, `levels` y `ocsid`; no son interruptores de política MCP. En particular, `enabled=yes` de Nextcloud es distinto de `service_enabled=1`.

[Guías operativas](../guides/README.md).

[English](../../en/reference/configuration.md) · Español · [Català](../../ca/reference/configuration.md) · [Português (Brasil)](../../pt-BR/reference/configuration.md) · [Português (Portugal)](../../pt-PT/reference/configuration.md)

## Entrega de código fuente

Un operador configura por separado el destino de las fuentes mediante la configuración de sistema de Nextcloud (`config.php`), después de preparar el kit completo:

```php
'mcp_source_offer' => [
    'version' => '2.7.0',
    'sourceCommit' => 'FULL_40_CHARACTER_COMMIT_SHA',
    'manifestSha256' => 'SEPARATELY_TRUSTED_64_CHARACTER_MANIFEST_SHA256',
    'baseUrl' => 'https://sources.example.invalid/immutable-release-directory',
],
```

Este ajuste de sistema contiene metadatos de la versión, no una preferencia de usuario o capacidad. Sustituye la revisión y el hash de ejemplo por los del kit de fuentes aprobado; cambia el destino por un directorio que sirva sus cinco archivos: `manifest.json`, `source.bundle`, `runtime-dependencies.tar.gz`, `mcp-<version>.tar.gz` y `BUILDING.md`. Conserva el hash esperado en un registro del operador de confianza independiente, fuera de los archivos de la aplicación incluidos en ese manifiesto.

La versión y el commit de fuentes deben coincidir con `appinfo/source.json` generado en el paquete y con la versión instalada. Los paquetes de desarrollo con cambios sin confirmar no tienen identidad de fuentes. Una configuración ausente, inválida o que no coincida muestra la oferta como no disponible en los ajustes personales y el descubrimiento MCP; no concede acceso ni cambia la autenticación. Una oferta configurada aún requiere recuperar los archivos y verificar los hashes antes de aceptar una versión.

Usa HTTPS sin credenciales, consulta, fragmento, codificación porcentual ni segmentos de recorrido de directorios. HTTP con una dirección literal de loopback queda reservado a las pruebas aisladas de aceptación. No publiques una URL de prueba como destino de una versión ni envíes credenciales de Nextcloud al servidor de fuentes. La aplicación solo presenta los enlaces; no los descarga. La publicación pública, el comportamiento de integridad admitido y la validación de actualización/recuperación en el servidor de destino siguen siendo controles de versión separados.
