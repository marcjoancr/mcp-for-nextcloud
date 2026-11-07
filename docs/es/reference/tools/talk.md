<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/talk.md).

# Talk

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Talk** | conversaciones, búsqueda de mensajes y contexto (nunca marca nada como leído) | responder (con la opción de citar un mensaje o enlazar una tarjeta de Deck o un evento del calendario visible para el usuario), enviar un mensaje directo a un usuario, un lote de mensajes, compartir o citar un archivo y crear una conversación de grupo (permiso independiente, desactivado de forma predeterminada). **No se envía nada antes de que el usuario lo apruebe: una llamada sin `confirm: true` solo devuelve el plan (destinatario, texto final, adjuntos y elementos que se crearían o compartirían) y no ejecuta ninguna acción; con `confirm: true`, vuelve a comprobar los permisos y envía. El servidor no conserva ningún ID de aprobación, token ni registro.** |

## Publicar un resumen preparado

`talk_publish_summary` publica el texto `message` proporcionado por quien llama en un `conversation_token` existente. Requiere el permiso independiente `talk.publish_summary`. Muestra al usuario la vista previa completa y el destino y repite con `confirm: true` y el `plan_state` devuelto. El usuario autenticado debe poder escribir en la conversación. Cambiar el texto aprobado, el destino, los participantes o el último mensaje exige otra vista previa. Se rechazan conversaciones con más de 1000 participantes y conversaciones uno a uno incompletas. Las comprobaciones finales y el envío nativo son operaciones separadas; pueden intercalarse cambios concurrentes. Un error de entrega puede dejar un resultado incierto. Consulta la conversación antes de reintentar.

Para respuestas de formularios, usa primero `forms_read_responses` con `forms.read_responses` y permiso nativo de resultados. El asistente prepara un resumen a partir de las respuestas accesibles. Revisa la información personal y quién puede leer el destino antes de aprobar la publicación.

## Leer mensajes anteriores

`talk_read_messages` devuelve primero la última página, ordenada de más antiguo a más reciente dentro de cada página. Para leer la anterior, envía el `next_before_message_id` devuelto como `before_message_id` con el mismo `conversation_token`. Se excluye el mensaje del cursor. Un cursor nulo indica el inicio del historial disponible. Cada página comprueba de nuevo el acceso y conserva mensajes sin leer y notificaciones.

## Buscar mensajes y leer contexto

`talk_search_messages` busca texto de mensajes normales en un `conversation_token`. Requiere la operación independiente `talk.search`. Los resultados son acotados y paginados; conserva conversación y consulta al seguir `next_offset`. `has_more` indica más coincidencias y `pagination_limited` distingue el tope de seguridad del desplazamiento del final de resultados. Cada página tiene 50 coincidencias por defecto, máximo 200. Se excluyen eventos del sistema, envoltorios de adjuntos, reacciones y respuestas privadas.

Usa el ID de un resultado con `talk_read_message_context` para leer mensajes anteriores y posteriores en la misma conversación. Envía `message_id`, con `before_limit` y `after_limit` opcionales (5 cada uno por defecto, máximo 100 cada uno). `has_more_before` y `has_more_after` indican más vecinos fuera de la ventana. Es una asignación independiente de herramienta clasificada como `talk.read`; permitir buscar no permite leer contexto. El contexto es cronológico y el mensaje de referencia debe pertenecer a la conversación. Las comprobaciones nativas de pertenencia y sala de espera se aplican de nuevo en cada solicitud. Las referencias de archivos pasan las mismas comprobaciones de visibilidad que el historial normal.

Ambas herramientas conservan contadores de no leídos, notificaciones y marcas de lectura. Los nuevos resultados de búsqueda y contexto limitan el texto a 4000 caracteres y avisan si se recorta. No envían mensajes.

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · Español · [Català](../../../ca/reference/tools/talk.md) · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)
