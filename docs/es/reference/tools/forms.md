<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/forms.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/forms.md).

# Forms

Las herramientas de Forms requieren Forms 5.3.0 o posterior, disponibilidad para la cuenta autenticada, asignación explícita de herramientas y selección en la conexión. Los permisos nativos de Forms se vuelven a comprobar en cada solicitud.

`forms_list` lista formularios accesibles; `forms_read` lee preguntas y opciones. Ambas usan `forms.read`. `forms_read_responses` requiere la operación independiente `forms.read_responses` y permiso nativo para ver resultados. Poder enviar un formulario o consultar solo la propia respuesta no concede acceso al conjunto completo de resultados.

## Resumir respuestas

`forms_summarize_responses` requiere `forms.summarize_responses`. Envía `id`, con `offset` y `limit` opcionales (50 por defecto, máximo 100 envíos). Devuelve recuentos deterministas de respuestas por pregunta para esa página, con `submissionsAnalyzed`, `partial`, `hasMore` y `nextOffset`. Sigue el desplazamiento devuelto para continuar; un resumen de página no es un resumen del formulario entero. Se consideran hasta 50 registros de metadatos de preguntas y 200 respuestas por envío, con un límite de 8000 respuestas examinadas y 4 MiB de texto de respuestas. Los límites de texto y recorrido también pueden dar resultados parciales.

El resumen no contiene identificadores de las personas que respondieron. Se omiten respuestas de archivos subidos; no se abre ni descarga ningún archivo. Se exige permiso nativo de resultados aunque el usuario pueda leer ya las preguntas del formulario.

## Exportar respuestas a Files

`forms_export_responses` requiere `forms.export_responses` **y** `files.create`, además de acceso a los resultados del formulario y a la carpeta de destino. Envía `id`, un destino `.csv` nuevo en `path` y, opcionalmente, `offset` y `limit` (50 por defecto, máximo 100 envíos).

La primera llamada prepara una vista previa sin escribir. Muestra al usuario la página de origen, el destino y el aviso de resultado parcial. Tras la aprobación, repite los mismos argumentos con `confirm: true` y el estado del plan devuelto. Si cambia la página de origen o el destino, se necesita otra vista previa. Un destino compartido también requiere la confirmación de recurso compartido existente de Files. No se sobrescriben archivos.

El CSV contiene una fila por respuesta, con ID del envío, fecha/hora, ID de la pregunta, pregunta y respuesta. Se excluyen las identidades de los participantes y las respuestas de archivos subidos. Se neutralizan las celdas que puedan interpretarse como fórmulas de hoja de cálculo. La exportación se limita a 1 MiB y 8000 filas; los resultados parciales se indican explícitamente. Las celdas de texto de pregunta y respuesta se limitan a 1024 bytes y la vista previa detalla los recortes y filas omitidas. Se mantienen el ámbito de Files y los permisos nativos de la carpeta.

[Todas las herramientas](../tools.md) · [Permisos](../../guides/permissions.md)

[English](../../../en/reference/tools/forms.md) · Español · [Català](../../../ca/reference/tools/forms.md) · [Português (Brasil)](../../../pt-BR/reference/tools/forms.md) · [Português (Portugal)](../../../pt-PT/reference/tools/forms.md)
