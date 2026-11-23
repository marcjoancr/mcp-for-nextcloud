<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/calendar.md -->
<!-- translation-source-revision: 43eaec06ad2748a8e6f546fbe8ebf9cd04ba8f52 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/calendar.md).

# Calendar

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Calendar** | listar calendarios y eventos (recurrencias, zonas horarias y eventos de día completo) | crear, editar, mover, eliminar y transferir eventos mediante el flujo real de CalDAV, con participantes. El administrador debe conceder permisos de escritura en la matriz de administración (no se necesita usar el terminal); primero se devuelve un plan: **no se escribe nada hasta que el usuario lo aprueba.** |

| Herramienta | Permiso | Notas |
| --- | --- | --- |
| `calendar_list_calendars` `{}` | calendar.read | Calendarios visibles para el usuario, propios y compartidos. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Eventos del intervalo (predeterminado: los próximos 7 días), con recurrencias expandidas. |
| `calendar_create_event` | calendar.create | Crea un evento. `recurrence` admite una regla semanal o mensual acotada. `attendees` admite UID internos. |
| `calendar_update_event` | calendar.edit | Actualiza un evento o una sola ocurrencia indicada por `recurrence_id`. Los cambios de asistentes y fechas de la serie tienen restricciones. |
| `calendar_move_event` | calendar.move | MOVE dentro del mismo propietario; no se notifica a los asistentes, como sucede en la aplicación Calendar. |
| `calendar_delete_event` | calendar.delete | Envía una serie a la papelera o excluye una sola ocurrencia si se indica `recurrence_id`. |
| `calendar_transfer_event` | calendar.transfer | MOVE a un calendario de otro propietario que se haya compartido con permiso de escritura; rechaza eventos con asistentes. |

Calendar ofrece lectura y escritura mediante MCP. Estas operaciones empiezan desactivadas en la matriz de administración (Administración → MCP) y solo aparecen para quienes tengan el permiso; todas requieren también `confirm: true`, ACL de Nextcloud (y `confirm_shared` en el calendario de otra persona) y, opcionalmente, un ETag. Compatibilidad: en `info.xml`, la aplicación declara `min-version` 33 y `max-version` 35; las pruebas cubren la integración interna (`EmbeddedCalDavServer`) del núcleo y ese intervalo. Una nueva versión de Nextcloud requiere una nueva versión de la aplicación. El usuario debe tener habilitada la aplicación Calendar. Sabre y Symfony Console proceden del núcleo Nextcloud y no se incluyen en `vendor/` del paquete.

De forma predeterminada, create, update y delete usan `send_invitations: false` (`x-nc-scheduling: false`). El valor `true` entrega las invitaciones al planificador CalDAV de Nextcloud, incluidos los invitados existentes y CANCEL. El plan y el resultado informan `imipEnabled`, leído del ajuste nativo `dav/sendInvitations`: si está desactivado, el servidor no envía correo (el iTIP interno sí llega a la bandeja del invitado), y el asistente debe comunicárselo al usuario. La aplicación nunca confirma la entrega del correo. Las escrituras en calendarios de otras personas siguen necesitando `confirm_shared: true` después de que el usuario las apruebe.

**Confirmación antes de cada escritura (el servidor no guarda ningún estado).** Las operaciones sobre eventos de la tabla, si se llaman sin `confirm: true`, solo devuelven un plan (`requiresConfirmation: true`): valores actuales y propuestos, asistentes añadidos/eliminados, destino, consecuencias para invitaciones/CANCEL/papelera y el `etag` actual. No se escribe ni se programa nada. El asistente debe mostrar el plan, pedir aprobación al usuario y repetir la llamada con `confirm: true` y los mismos argumentos solo después de recibir un sí explícito. Debe volver a enviar el `etag` del plan (es opcional; si se incluye y ha cambiado, se rechaza la escritura y hay que solicitar un nuevo plan). No hay tabla, token ni registro de aprobación: el servidor no comprueba que la persona haya dado su aprobación, aunque siguen aplicándose los permisos, ACL, autorizaciones y `confirm_shared`. La prueba propia usa los controladores directamente y no recorre este flujo.

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

[Guías operativas](../../guides/README.md).

## Reuniones con asistentes

- Usa `calendar_search_events` y `calendar_read_event` para buscar citas y consultar sus detalles visibles, incluidas las respuestas a la invitación. La búsqueda aplica la confidencialidad antes de comparar el texto.
- Usa `calendar_find_slots` con una ventana, la duración y los ID de las cuentas internas. Cada horario propuesto indica el resultado de las comprobaciones de disponibilidad. La disponibilidad puede ser desconocida y la sugerencia no reserva tiempo.
- Para indicar direcciones de correo explícitas, usa `calendar_create_event_with_external_attendees` o `calendar_update_event_with_external_attendees`. Si hace falta, consulta primero el contacto para elegir la dirección. Estas herramientas tienen permisos independientes y respetan el ajuste del servidor para invitados externos. La vista previa distingue la programación del envío del correo.
- Usa `calendar_respond_to_invitation` desde tu copia del evento. En la llamada confirmada, incluye el ETag de la vista previa.
- Para actuar sobre una sola ocurrencia, usa el `recurrence_id` original, incluso si la cita se movió. Excluir una ocurrencia puede enviar una actualización de la serie con esa fecha excluida.

## Salas y recursos

Usa `calendar_list_resources` para encontrar las salas y el equipamiento que puedes consultar. Usa `calendar_check_resource_availability` para consultar una franja mediante CalDAV. Un estado desconocido indica que el servidor no pudo verificarla. Usa `calendar_reserve_resource` para solicitar una sala o recurso en un evento sin recurrencia que organizas en tu propio calendario. Revisa el plan y confirma con el ETag. El resultado informa por separado de la actualización del evento y de la respuesta del recurso. Si cambias la hora o cancelas el evento, activa el envío de invitaciones para que la sala reciba el cambio.

## Enlaces de reserva

MCP admite las herramientas de citas con Calendar 6.6.2, 6.6.3 y 6.6.4. La compatibilidad con 6.6.4 empieza en MCP 2.10.2. Estas herramientas trabajan con configuraciones de citas en calendarios de los que eres titular y a los que puedes acceder a través de MCP.

| Herramienta | Operación | Qué hace |
| --- | --- | --- |
| `calendar_list_appointments` | `calendar.read` | Lista tus configuraciones de citas y sus enlaces de reserva. |
| `calendar_create_appointment` | `calendar.create` | Crea un enlace de reserva con su propia disponibilidad, duración y visibilidad. |
| `calendar_update_appointment` | `calendar.edit` | Modifica una configuración sin cambiar su enlace de reserva. |
| `calendar_delete_appointment` | `calendar.delete` | Retira el enlace. Los eventos ya reservados permanecen en el calendario. |
| `calendar_list_appointment_bookings` | `calendar.read` | Lista las reservas pendientes y confirmadas entre `from` y `to`, opcionalmente para un `appointment_id`. El intervalo máximo es de 93 días. Muestra el nombre del invitado y la franja horaria. Las direcciones de correo y los tokens de confirmación quedan protegidos. |

La persona administradora asigna cada herramienta en **Administración → Nextcloud MCP → Acceso y permisos**. Selecciona las herramientas asignadas para el cliente en **Ajustes personales → Nextcloud MCP** o al autorizar la conexión OAuth. Si falta una herramienta, comprueba la versión de Calendar, la asignación de la cuenta y la selección de la conexión. Después, consulta la [guía de herramientas](../tools.md) desde esa conexión. Consulta [Acceso y permisos](../../guides/permissions.md) para configurar la asignación y la selección.

Revisa la zona horaria, el horario semanal, la duración, el periodo de reserva y la visibilidad en la vista previa. Tras aprobar la operación, repítela con `confirm: true` y su `plan_state`. Las franjas semanales usan la hora local en formato HH:mm. Las duraciones y los márgenes se expresan en minutos, y el horizonte de reserva en días. La visibilidad privada oculta el enlace en tu perfil público. Cualquiera que tenga la URL puede reservar. La visibilidad pública también muestra el enlace en tu perfil.

## Invitaciones, recuperación e intercambio

- Filtra `calendar_search_events` con `participation_status: needs-action` para encontrar las invitaciones que esperan tu respuesta. También puedes consultar `accepted`, `declined` y `tentative`.
- Usa `calendar_list_trashed_events` para obtener el identificador de un evento eliminado y `calendar_restore_trashed_event` para restaurarlo en su calendario original. La recuperación está sujeta a retención y al alcance de calendarios propios. La restauración nativa conserva los datos de la cita sin enviar invitaciones.
- `calendar_create_calendar` crea un calendario personal con alcance de calendarios sin restricciones. `calendar_list_calendar_shares` y `calendar_share_calendar` consultan y gestionan accesos directos de cuentas locales a calendarios propios, con opciones de lectura, escritura y retirada.
- `calendar_import_ics` lee un archivo visible en Files y copia hasta 100 eventos o 1 MiB a un calendario propio. La vista previa identifica duplicados y conflictos. La importación excluye organizadores, asistentes, alarmas y adjuntos. `calendar_export_ics` devuelve un documento con los UID seleccionados de un calendario, incluidos los casos de recurrencia que puedes ver por completo.
- `calendar_set_event_reminders` sustituye los avisos de visualización relativos al inicio del evento o serie. Introduce de cero a cinco tiempos en minutos de antelación. Se conservan las alarmas personalizadas, de correo, absolutas, repetidas y específicas de ocurrencias.

Las escrituras de estos flujos requieren `confirm: true` y el `plan_state` de la vista previa.

## Disponibilidad y conflictos

`calendar_check_availability` requiere el permiso independiente `calendar.availability` y selección explícita de herramienta. Proporciona `from` (inclusivo) y `to` (exclusivo), en una ventana máxima de 31 días. Usa `calendars` opcional para seleccionar hasta 20 rutas de calendarios accesibles; omítelo para usar todos los visibles, con el mismo límite. `min_duration_minutes` filtra intervalos libres (30 por defecto, rango 5–1440).

La respuesta contiene intervalos ocupados UTC, conflictos de solapamiento e intervalos libres comunes. Los intervalos que solo se tocan no entran en conflicto. Las recurrencias, sus excepciones y las zonas horarias siguen las reglas de expansión existentes; los eventos cancelados o transparentes no bloquean tiempo. Las fechas sin hora y los valores flotantes de día completo siguen la convención existente de fecha civil UTC.

No devuelve títulos, descripciones ni participantes de eventos. Se aplican los recursos compartidos nativos, el ámbito de Calendar y la clasificación de eventos. Si eventos ocultos, datos inválidos o límites de recorrido impiden un cálculo fiable, devuelve error en vez de declarar tiempo libre. El recorrido admite hasta 2000 objetos por calendario y 10 000 ocurrencias totales. Los eventos de duración cero también hacen que la disponibilidad no pueda calcularse con esta política conservadora. Es una instantánea de los calendarios accesibles, no una reserva ni una afirmación sobre calendarios inaccesibles.

[English](../../../en/reference/tools/calendar.md) · Español · [Català](../../../ca/reference/tools/calendar.md) · [Português (Brasil)](../../../pt-BR/reference/tools/calendar.md) · [Português (Portugal)](../../../pt-PT/reference/tools/calendar.md)
