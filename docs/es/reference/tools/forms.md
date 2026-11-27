<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/forms.md -->
<!-- translation-source-revision: ad44b6fd690c2e0063884da06ef5e1117cec64b6 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/forms.md).

# Forms

Las herramientas de Forms requieren Forms 5.3.0 o posterior, disponibilidad para la cuenta autenticada, asignación explícita de herramientas y selección en la conexión. Los permisos nativos de Forms se vuelven a comprobar en cada solicitud.

`forms_list` lista formularios accesibles; `forms_read` lee preguntas y opciones. Ambas usan `forms.read`. `forms_read_responses` requiere la operación independiente `forms.read_responses` y permiso nativo para ver resultados. Poder enviar un formulario o consultar solo la propia respuesta no concede acceso al conjunto completo de resultados.

## Paquete oficial de Forms

Con `app.mcp.official-apps.forms` y Forms 5.4.4 o 5.4.5, estas lecturas de la API OCS v3
también se pueden asignar y seleccionar en la conexión:

| Herramienta | Permiso nativo y datos |
| --- | --- |
| `forms_read_settings` | Acceso vigente al formulario. Ajustes y una página de metadatos de preguntas sin secretos de compartición. |
| `forms_read_question` | Acceso vigente al formulario. Una pregunta con paginación independiente de opciones. |
| `forms_list_shares` | Solo el propietario. Metadatos de compartición con usuarios y grupos sin tokens de enlaces públicos. |
| `forms_read_own_response` | Acceso vigente y un ID de respuesta del propio usuario autenticado. |
| `forms_read_response` | Acceso vigente y permiso nativo explícito de resultados. La identidad del participante requiere un permiso y una selección adicionales en el MCP. |

La lectura de una respuesta omite archivos y respuestas cuyo tipo de pregunta
no se puede verificar. Basic llama a Forms por HTTP. Para OAuth del MCP, la
sesión autenticada de Nextcloud invoca los mismos controladores de lectura de
OCS en el servidor. `forms_capabilities` informa de las lecturas probadas en
`officialReadTools`. El App Store sigue gestionando el paquete oficial. Con
una versión de Forms pendiente de comprobar, el catálogo vuelve a las
herramientas originales hasta verificar las lecturas ampliadas.

### Autoría con el paquete oficial

Este modo permite crear, clonar en privado, editar, abrir, cerrar y eliminar
formularios, además de gestionar sus preguntas y opciones. El propietario puede
crear y revocar enlaces públicos que permiten enviar respuestas. Los permisos
de consulta y análisis de resultados siguen siendo independientes.
`forms_capabilities` informa de las escrituras disponibles en `officialWriteTools`.

Confirma la vista previa con su `operation_id` y `plan_state`. Los enlaces
públicos, los cambios sobre recursos compartidos y el borrado definitivo
requieren confirmación adicional. El MCP vuelve a comprobar el estado y los
permisos antes de llamar a Forms y comprueba después el resultado observado.
Forms puede aceptar cambios concurrentes mientras escribe. Si se pierde la
respuesta o queda sin verificar, el resultado se marca como `uncertain`.
Repetir la confirmación devuelve el recibo de la operación y no envía otra
escritura. Comprueba el estado en Forms antes de reconciliar una operación
incierta. Si se interrumpe el alta después de crear el formulario, puede quedar
un borrador privado sin título.

Para cambiar un tipo, crea otra pregunta. Si el formulario ya tiene respuestas,
clónalo en privado y conserva el original para consultar sus resultados.
El MCP exige observar un conjunto de respuestas vacío para retirar preguntas u
opciones. Al retirar una pregunta, Forms puede dejar de mostrar sus respuestas
anteriores. El borrado definitivo del formulario elimina sus datos asociados y
no genera una copia de recuperación.

## Resumir respuestas

`forms_summarize_responses` requiere `forms.summarize_responses`. Envía `id`, con `offset` y `limit` opcionales (50 por defecto, máximo 100 envíos). Devuelve recuentos deterministas de respuestas por pregunta para esa página, con `submissionsAnalyzed`, `partial`, `hasMore` y `nextOffset`. Sigue el desplazamiento devuelto para continuar; un resumen de página no es un resumen del formulario entero. Se consideran hasta 50 registros de metadatos de preguntas y 200 respuestas por envío, con un límite de 8000 respuestas examinadas y 4 MiB de texto de respuestas. Los límites de texto y recorrido también pueden dar resultados parciales.

El resumen no contiene identificadores de las personas que respondieron. Se omiten respuestas de archivos subidos; no se abre ni descarga ningún archivo. Se exige permiso nativo de resultados aunque el usuario pueda leer ya las preguntas del formulario.

## Exportar respuestas a Files

`forms_export_responses` requiere `forms.export_responses` **y** `files.create`, además de acceso a los resultados del formulario y a la carpeta de destino. Envía `id`, un destino `.csv` nuevo en `path` y, opcionalmente, `offset` y `limit` (50 por defecto, máximo 100 envíos).

La primera llamada prepara una vista previa sin escribir. Muestra al usuario la página de origen, el destino y el aviso de resultado parcial. Tras la aprobación, repite los mismos argumentos con `confirm: true` y el estado del plan devuelto. Si cambia la página de origen o el destino, se necesita otra vista previa. Un destino compartido también requiere la confirmación de recurso compartido existente de Files. No se sobrescriben archivos.

El CSV contiene una fila por respuesta, con ID del envío, fecha/hora, ID de la pregunta, pregunta y respuesta. Se excluyen las identidades de los participantes y las respuestas de archivos subidos. Se neutralizan las celdas que puedan interpretarse como fórmulas de hoja de cálculo. La exportación se limita a 1 MiB y 8000 filas; los resultados parciales se indican explícitamente. Las celdas de texto de pregunta y respuesta se limitan a 1024 bytes y la vista previa detalla los recortes y filas omitidas. Se mantienen el ámbito de Files y los permisos nativos de la carpeta.

## Flujos nativos cualificados

El catálogo define 65 herramientas de Forms; las disponibles dependen de la
extensión nativa instalada, los permisos actuales y la selección de la conexión.
Consulta `mcp_guide` con `module: "forms"` para ver los esquemas y permisos
vigentes, y `forms_capabilities` para comprobar compatibilidad y límites.
Las cinco herramientas anteriores conservan su compatibilidad con Forms 5.3.
Los demás flujos ampliados necesitan la extensión independiente de Forms 5.4.2 y una
combinación cualificada de Nextcloud, base de datos, almacenamiento y escritores
nativos. El número de versión por sí solo no habilita estas funciones. Consulta
`native/forms-guard/README.md` en el paquete de código fuente correspondiente
para la instalación, verificación y recuperación de la extensión.

La ampliación incluye búsqueda y ajustes; creación y clonación de formularios;
estado y bloqueos; construcción y ordenación de preguntas y opciones;
compartición, enlaces públicos y contenido incrustado; envío y gestión de
respuestas propias; gestión independiente de resultados completos y archivos;
comentarios nativos y ajustes administrativos; transferencia, borrado,
recuperación e historial de operaciones. Cada función debe estar cualificada y
disponible para la cuenta. Clonar reinicia respuestas, comparticiones y vínculo
de exportación. Los bloqueos permanentes y determinados cambios de estado siguen
reservados al propietario.

Se pueden crear 12 tipos de pregunta. Las preguntas `datetime` existentes
siguen siendo legibles y admiten respuestas; no se puede crear ese tipo
heredado ni cambiar el tipo de una pregunta existente. Las cuadrículas requieren
el subtipo `radio`, `checkbox` o `number`. La
[referencia completa en inglés](../../../en/reference/tools/forms.md) enumera
los tipos y las familias de herramientas.

## Permisos y confirmaciones

Cada operación nueva empieza denegada y cada herramienta nueva sin asignar ni
seleccionar, también después de una actualización. Leer la estructura no concede
acceso a ajustes, capacidades de compartición, resultados, participantes ni
archivos. Consultar las respuestas propias no concede acceso a las de otras
personas. Se mantienen los roles nativos, ámbitos de recursos, exclusiones y
restricciones de invitados.

Cambiar la audiencia o enlaces públicos requiere `forms.public_link`; configurar
correos de confirmación requiere además `forms.configure_notifications`.
Compartir archivos puede añadir `files.share`. Incluir participantes o
referencias de archivos exige sus permisos específicos. Las exportaciones a
Files requieren los permisos de creación o edición declarados y acceso al
destino. Continuar, cancelar o consultar evidencias vuelve a comprobar los
permisos y la selección actuales de la acción original.

Una escritura devuelve primero una vista previa. Tras aprobar sus efectos,
repite los mismos argumentos con `confirm: true`, `plan_state` y
`operation_id`; añade `confirm_shared: true` cuando se solicite. Si cambian
origen, permisos, conexión o destino, se necesita otra vista previa. La vista
previa no adquiere el bloqueo de edición de quince minutos. Generar un token y
preparar una subida también requieren confirmación.

## Resultados completos y exportaciones continuas

`forms_summarize_results`, `forms_download_results` y
`forms_export_results` procesan una instantánea estable mediante pasos
acotados. Un resultado pendiente todavía no es una exportación completa.
Continúa la operación original hasta su finalización verificada. Se admiten
CSV, ODS y XLSX. Se conservan el anonimato y los permisos adicionales para
participantes y archivos; se neutralizan las fórmulas de hoja de cálculo.

Las descargas son de un solo uso, ligadas a la cuenta y conexión, y duran como
máximo cinco minutos. La caché privada de resultados caduca a los siete días;
las evidencias y copias de recuperación tienen ciclos de vida independientes.
Sustituir un archivo requiere `overwrite`, su `etag` aprobado, permiso de
edición y recuperación verificada.

Vincular una exportación continua requiere una conexión OAuth persistente y
aprobar las escrituras iniciales y futuras. Los cambios de audiencia, origen,
destino, conexión o permisos pueden pausarla. Desvincular conserva el archivo
existente. Consulta el estado antes de reconciliar una operación.

## Borrado acotado e historial recuperable

Inspecciona todas las colecciones y sus cursores mediante
`forms_read_operation_impact` antes de confirmar. En una pregunta, los
recuentos de recuperación abarcan el formulario completo: no significan que se
borren todas sus respuestas. Las evidencias `authoring_effects_v1` de cambios
de título o compartición se identifican expresamente como no restaurables.

Borrar una pregunta la oculta y conserva sus respuestas y opciones históricas.
La limpieza aprobada solo elimina archivos exactos gestionados por Forms con
recuperación verificada. El borrado completo también elimina los comentarios
nativos y registros de compartición gestionados de ese formulario. Se conservan
archivos fuera del manifiesto, otros formularios y salidas vinculadas.
La transferencia copia los archivos verificados y conserva originales y
evidencias; los efectos sobre compartición y exportaciones se informan aparte.

`forms_restore` crea un formulario nuevo, privado y cerrado. No vuelve a
publicar comentarios ni recrea tokens o trabajos. Exige la identidad original y
autoridad nativa actual para recuperar; disponer de una copia obtenida con
permiso de edición no concede acceso a sus resultados.
`forms_read_recovered_comments` muestra el historial con el permiso actual de
comentarios y la autoridad original de recuperación; identifica los autores
históricos no verificados.

`bulk_v1:` identifica el formato original de copia sellada. Si ya faltaban
archivos históricos de preguntas ocultas, una copia posterior usa
`bulk_history_v2:` y `whole_form_with_unavailable_history`, con recuento
explícito y recuperación incompleta de bytes históricos. Conserva ese historial
sin inventar archivos ni reutilizar identificadores colgantes. La copia anterior
a la limpieza aprobada conserva sus bytes verificados.

El historial distingue resultados pendientes, parciales, inciertos y verificados,
con evidencias separadas de base de datos, Files y eventos. Tras perder una
respuesta, consulta `forms_get_operation` con el identificador exacto. No
repitas efectos inciertos ni elimines su reserva para reintentarlos. Cancelar
puede conservar copias y evidencias; no promete deshacer cambios confirmados.
Un intento de notificación no prueba su entrega.

[Todas las herramientas](../tools.md) · [Permisos](../../guides/permissions.md)

[English](../../../en/reference/tools/forms.md) · Español · [Català](../../../ca/reference/tools/forms.md) · [Português (Brasil)](../../../pt-BR/reference/tools/forms.md) · [Português (Portugal)](../../../pt-PT/reference/tools/forms.md)
