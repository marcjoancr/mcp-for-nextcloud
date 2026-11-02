<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: a1b11b5ee002d138ee7fd9155d6c524a448838f4 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../en/guides/permissions.md).

# Permisos y administración

Abre **Administración → Nextcloud MCP**. Las cinco pestañas son **Vista general**, **Acceso y permisos**, **Clientes y conexiones**, **Protección de datos** y **Avanzado**. Sus direcciones (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) y la navegación por teclado abren la sección correspondiente. En pantallas estrechas, usa el selector Secciones para cambiar entre las cinco vistas. Clientes y conexiones muestra primero la lista de conexiones activas. En el móvil, cada conexión reúne sus datos y acciones.

## Habilitar el acceso

El servicio, la elegibilidad del usuario (**Puede conectarse**) y la conexión personal empiezan desactivados, también para administradores. Activa el servicio, permite al usuario conectarse y deja que este active su conexión en **Ajustes personales → Nextcloud MCP**. La autenticación por sí sola no concede acceso: deben permitir la solicitud la cuenta, el servicio, la elegibilidad, la conexión, la aplicación opcional, el permiso de operación y los permisos nativos de Nextcloud sobre el recurso.

Los registros del servidor, Mail y Forms requieren permisos de lectura explícitos. En Mail, `mail.read` permite consultar cuentas y carpetas, `mail.search` permite buscar cabeceras y `mail.read_message` permite leer mensajes e hilos. En Forms, `forms.read` permite consultar la estructura de los formularios. En Deck, leer tarjetas, consultar comentarios (`deck.read_comments`) y publicar comentarios (`deck.comment`) son permisos independientes. Ambos permisos de comentarios empiezan denegados, también tras una actualización. Compartir archivos con personas/grupos (`files.share`) y crear enlaces públicos (`files.link`) son permisos independientes. Listar recursos compartidos es una operación de lectura.

Vista general muestra el endpoint, la versión, el número de conexiones y el límite de subida de checkout. Este límite se edita en MiB y no puede superar `post_max_size` de PHP. Avanzado contiene los roles y la privacidad de registros y OCR. Protección de datos contiene las etiquetas de exclusión; consulta [protección de archivos](file-protection.md).

## Opciones individuales y herencia de grupos

En **Administración → Nextcloud MCP → Acceso y permisos**, la matriz se abre con el primer grupo del directorio. Pulsa su cabecera para elegir otro grupo o una persona en el desplegable. Ambas listas están disponibles sin escribir. Usa **Añadir columna de comparación** para conservar la columna actual y comparar otra entrada. La columna de un grupo muestra sus reglas asignadas. La de una persona muestra su acceso efectivo, su origen y sus excepciones individuales. El servicio permanece en la primera fila. Abre un catálogo, busca una herramienta o filtra por diferencias o excepciones personales.

Elige **Permitir**, **Bloquear** o la opción neutra directamente en la celda. **Sin asignar** quita la regla del grupo. **Heredar** quita la excepción personal. El botón de información junto al selector despliega la descripción de la herramienta y sus reglas relacionadas debajo de la fila. Para una persona, también muestra qué grupos explican su acceso. Para cambiar varias reglas a la vez, activa **Editar varias reglas**, marca las herramientas y las columnas de destino y elige el valor una sola vez. Los cambios quedan pendientes hasta que los revises y guardes. Revisa y guarda cada columna antes de pasar a la siguiente, ya que un cambio de grupo puede afectar a una persona en otra columna.

Solo aparecen herramientas de apps instaladas, habilitadas y compatibles. Cada persona ve las apps habilitadas para su cuenta. Desactivar una app oculta sus herramientas en MCP y en los ajustes. Al volver a habilitarla, se recuperan los permisos y las selecciones de conexión existentes.

**Permitir**, **Bloquear** y **Sin asignar** se aplican al servicio y a las herramientas. Si Ana tiene una decisión individual, esa decisión prevalece. Si está sin asignar, un bloqueo de cualquiera de sus grupos de confianza prevalece sobre el permiso de otro grupo. Si ningún grupo decide, se aplica el valor predeterminado. Si el servicio global está apagado o la cuenta deshabilitada, ninguna conexión puede usar MCP. Cada herramienta sigue sujeta a la disponibilidad de la aplicación, el rol, las operaciones requeridas y los permisos nativos sobre el recurso.

La pertenencia a grupos procede de Nextcloud. Solo los grupos nativos de base de datos pueden conceder acceso. Si no se puede comprobar la política, la ficha indica que no está disponible. Desde una celda, abre **Reglas de operaciones relacionadas** para acceder a permisos como compartir con personas o crear enlaces públicos.

### Actualización desde 2.0.0

Las 84 herramientas publicadas en 2.0.0 heredan los ajustes originales de usuario y grupo hasta que se edita una asignación. Una casilla de grupo que estaba desmarcada conserva el estado *Sin asignar*. Al devolver una herramienta individual a *Sin asignar*, se elimina también la excepción individual anterior para esa herramienta. La actualización contrasta el resultado del sistema antiguo con el nuevo antes de activar el cambio. Si hay diferencias o la política no se puede leer, se detiene.

Cada conexión existente recibe una selección limitada a sus operaciones anteriores. Una herramienta nueva requiere asignación y selección. Para recuperar el sistema, conserva juntas la copia de la aplicación anterior y la de la base de datos. Si ya se han cambiado permisos en el nuevo editor, la recuperación debe preservar esos cambios: la versión anterior no sabe aplicar las nuevas denegaciones.

## Guardar, conflictos y deshacer

Las escrituras de política incluyen la revisión mostrada en la interfaz. Una vista previa obsoleta o un cambio concurrente produce un conflicto y una recarga; el navegador no repite silenciosamente la escritura. Una respuesta incierta no se presenta como un guardado correcto. Los borradores de grupos, recursos y clientes siguen siendo borradores hasta su acción Guardar.

Las solicitudes de restablecimiento/lote admiten como máximo 50 usuarios y 2500 celdas. La vista previa vale 120 segundos; los recibos del servidor caducan a los 600 segundos. La interfaz ofrece **Deshacer** durante 10 segundos mientras el control esté disponible y pausa el temporizador al mantener encima el puntero o el foco del teclado. Deshacer exige la revisión guardada y un recibo válido: no puede sobrescribir cambios posteriores. Restaurar un permiso no restaura credenciales revocadas ni vuelve a iniciar sesiones.

## Restricciones de recursos e inspección

El administrador puede restringir los recursos de **Files**, **Calendar** y **Tasks** de un usuario desde el panel de acceso. Cada módulo es independiente: **Todos los recursos** significa sin restricción; **Solo los seleccionados**, con selección vacía, no permite ninguno. El límite es de 200 recursos por usuario y módulo. Las restricciones solo reducen los permisos existentes y nativos; cambiarlas no revoca la conexión. Se aplican a la siguiente operación.

Notes, Contacts, Deck y Talk no tienen restricción de recursos por módulo en 1.1.0. Las referencias estructuradas a archivos y las operaciones con adjuntos pueden requerir comprobaciones del ámbito de Files; consulta [protección de archivos](file-protection.md). Restringir una carpeta no restringe de forma general todo lo que otros módulos guardan debajo de ella.

El inspector, exclusivo de administradores, informa **Permitido**, **Bloqueado** o **Desconocido**, la comprobación que bloquea y el origen del permiso. Puede explicar una ruta excluida al administrador; los errores MCP mantienen denegaciones genéricas. Las consultas y cambios pueden generar mensajes de auditoría de nivel informativo sin nombres ni rutas de recursos. Dependen del nivel de registro del servidor y no garantizan una pista de auditoría.

## Clientes y ajustes personales

En **Ajustes personales → Nextcloud MCP**, elige un cliente para gestionar sus herramientas. El mismo selector aparece al autorizar una nueva conexión OAuth. Cada cliente conserva su selección al renovar el token. Una retirada administrativa se aplica en la siguiente petición. Las contraseñas de aplicación y las sesiones comparten el perfil de acceso mediante Nextcloud. El ámbito de recursos sigue limitando las carpetas, calendarios y listas de tareas accesibles.


Gestiona los hosts de clientes publicados, el cliente nativo y los clientes personalizados registrados en **Clientes y conexiones**; consulta [OAuth](../reference/oauth.md). Las API administrativas exigen administrador y protección CSRF de Nextcloud. Las API personales de conexión/ámbito obtienen la identidad de la sesión y solo muestran el estado de ese usuario. Las respuestas nunca contienen hashes de tokens.

Los ajustes personales muestran el estado de conexión, los clientes conectados, los controles de revocación y las restricciones de recursos de solo lectura del usuario. Para HTTP Basic, crea una contraseña de aplicación en **Ajustes personales → Seguridad**. MCP no pide ni almacena la contraseña principal. Desconectar MCP no elimina esa contraseña de aplicación de Nextcloud; consulta [revocar el acceso](clients.md#revocar-el-acceso).

Persiste un riesgo residual de escritura: una denegación genérica puede distinguir un destino no disponible de otro donde la creación tendría éxito, aunque no revele el motivo.

[Guías operativas](README.md).

[English](../../en/guides/permissions.md) · Español · [Català](../../ca/guides/permissions.md) · [Português (Brasil)](../../pt-BR/guides/permissions.md) · [Português (Portugal)](../../pt-PT/guides/permissions.md)
