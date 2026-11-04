<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: a24d67927e4a1a55c6da5d64a09cb0bf3c870590 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/talk.md).

# Talk

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Talk** | conversaciones y mensajes (nunca marca nada como leído) | responder (con la opción de citar un mensaje o enlazar una tarjeta de Deck o un evento del calendario visible para el usuario), enviar un mensaje directo a un usuario, un lote de mensajes, compartir o citar un archivo y crear una conversación de grupo (permiso independiente, desactivado de forma predeterminada). **No se envía nada antes de que el usuario lo apruebe: una llamada sin `confirm: true` solo devuelve el plan (destinatario, texto final, adjuntos y elementos que se crearían o compartirían) y no ejecuta ninguna acción; con `confirm: true`, vuelve a comprobar los permisos y envía. El servidor no conserva ningún ID de aprobación, token ni registro.** |


## Publicar un resumen preparado

`talk_publish_summary` publica el texto `message` que recibe en una conversación existente indicada por `conversation_token`. Requiere el permiso independiente `talk.publish_summary`. Muestra al usuario la vista previa completa y el destino. Tras su aprobación, repite la llamada con `confirm: true` y el `plan_state` recibido. El usuario debe conservar el permiso para escribir en la conversación. Si cambia el texto, el destino, los participantes o el último mensaje, se necesita otra vista previa. Se rechazan conversaciones con más de 1.000 participantes y conversaciones individuales incompletas. La comprobación final y el envío nativo son operaciones separadas, por lo que puede haber cambios concurrentes entre ambas. Un error de entrega puede dejar el resultado incierto. Comprueba la conversación antes de reintentar.

Para resumir respuestas, consulta primero `forms_read_responses` con `forms.read_responses` y permiso nativo para ver resultados. El asistente redacta el resumen a partir de las respuestas accesibles. Revisa los datos personales y quién podrá leer el mensaje antes de aprobar la publicación.

## Leer mensajes anteriores

`talk_read_messages` devuelve primero la página más reciente. Dentro de cada página, los mensajes van del más antiguo al más reciente. Para retroceder, pasa el `next_before_message_id` recibido como `before_message_id` con el mismo `conversation_token`. La página excluye el mensaje del cursor. Un cursor nulo indica que se ha alcanzado el principio del historial disponible. Cada página vuelve a comprobar el acceso y conserva los mensajes no leídos y las notificaciones.

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · Español · [Català](../../../ca/reference/tools/talk.md) · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)
