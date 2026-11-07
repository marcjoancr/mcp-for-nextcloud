<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/troubleshooting.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../en/guides/troubleshooting.md).

# Solución de problemas

## Prueba manual tras la instalación

```sh
URL=https://<instance>/apps/mcp/
AUTH='<user>:<app-password>'
H='-H Content-Type:application/json -H Accept:application/json,text/event-stream'

curl -s -u "$AUTH" $H -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' "$URL"
curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$URL"   # 202
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' "$URL"
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp_status","arguments":{}}}' "$URL"
```

Respuestas esperadas:

| Situación | HTTP |
| --- | --- |
| Sin credenciales, credenciales inválidas o usuario deshabilitado | 401 con `WWW-Authenticate: Basic` |
| Servicio desactivado, usuario no elegible o desconectado | 403 |
| `Origin` de otro host | 403 |
| Falta `Accept: application/json, text/event-stream` o `Content-Type: application/json` | 406 |
| `MCP-Protocol-Version` desconocido o mal formado: responde `UnsupportedProtocolVersionError` (`-32022`, `data.supported`). Las versiones heredadas aceptadas después de `initialize` son 2025-03-26, 2025-06-18 y 2025-11-25; si falta el encabezado, se usa 2025-03-26, y durante `initialize` se ignora el encabezado. También responde 400 si `MCP-Protocol-Version`, `Mcp-Method` o `Mcp-Name` no coinciden con el cuerpo (`-32020`). El motivo de cada 400 se registra con nivel debug. | 400 |
| JSON inválido (`-32700`), lote o sobre no válido (`-32600`) | 400 |
| Método desconocido (`-32601`) o parámetros inválidos (`-32602`) | 200 con un error JSON-RPC |
| GET o DELETE | 405 |

## Diagnóstico opcional de Calendar (`occ mcp:calendar-selftest`)

No es un requisito previo: nada depende de este diagnóstico para que las herramientas aparezcan o funcionen, y ejecutarlo no las habilita ni las oculta. La prueba escribe calendarios y eventos temporales y luego los limpia, como se describe más abajo. Es una herramienta de diagnóstico avanzada y opcional para administradores del servidor que quieran comprobar paso a paso la respuesta real de CalDAV. Ejecútala desde la raíz de Nextcloud como usuario del servidor web. El UID del organizador debe corresponder a una cuenta habilitada, con un correo válido y Calendar habilitado; la retención `dav/calendarRetentionObligation` no debe ser `0` (la limpieza sería permanente, así que el comando falla antes de crear objetos).

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID
```

Cada ejecución crea dos calendarios con los URI `mcp-selftest-...-a`/`-b`, solo modifica los eventos que ha creado y al final traslada esos calendarios a la papelera. Sin opciones, las tres pruebas opcionales muestran `NOT TESTED`. Para probar invitaciones internas, transferencias y ACL:

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID \
  --attendee-uid ATTENDEE_UID \
  --shared-calendar '/remote.php/dav/calendars/ORGANIZER_UID/SHARED_URI/' \
  --acl-probe-user NO_ACCESS_UID
```

Esta ejecución tiene efectos en ambas cuentas (invitación interna, limpieza CANCEL y actividad en el calendario compartido) y solo elimina elementos con el UID generado por la prueba. No demuestra que SMTP funcione.

| Paso del informe | Resultado esperado |
| --- | --- |
| `preflight`, `calendars` | Cuenta, aplicación y retención válidas; dos MKCALENDAR visibles con HTTP 201. |
| `create` | HTTP 201, UID y ETag vuelven a leerse, el sync-token aumenta. |
| `suppression-create`, `suppression-update` | PUT 201/204, ATTENDEE `@example.invalid` sin SCHEDULE-STATUS; SEQUENCE aumenta. |
| `stale-tool`, `stale-dav` | Conflicto en el controlador y HTTP 412 en DAV; datos y ETag intactos. |
| `move`, `overwrite` | MOVE 201 con el UID solo en el destino; Overwrite F devuelve 412 y conserva ambos objetos. |
| `delete` | HTTP 204, UID ausente de la lectura y presente en la papelera. |
| `invitations`, `transfer`, `acl` | `OK` si se pidió y se demostró; `NOT TESTED` si no se especificaron las opciones. |
| `cleanup`, `result` | Limpieza HTTP 204 confirmada, sesión restaurada y resultado final del diagnóstico. |

Cualquier `FAIL` devuelve un código distinto de cero. Si falla la limpieza, `cleanup-resource` indica la ruta/UID que hay que revisar en el servidor. El resultado no cambia qué herramientas están disponibles.

[Guías operativas](README.md).

[English](../../en/guides/troubleshooting.md) · Español · [Català](../../ca/guides/troubleshooting.md) · [Português (Brasil)](../../pt-BR/guides/troubleshooting.md) · [Português (Portugal)](../../pt-PT/guides/troubleshooting.md)

## Rastrear una llamada autenticada a herramientas

Un administrador puede activar un rastreo privado para un ID exacto de usuario de Nextcloud y una lista explícita de herramientas. Por ejemplo, crea en el servidor un diario nuevo en un directorio controlado por el operador, fuera de la raíz web, el almacenamiento de usuarios y los registros expuestos por MCP. Da acceso al archivo solo al proceso PHP (modo 0600). Configura lo siguiente en el sistema, sustituyendo los ejemplos por una **marca Unix fija de caducidad**, la ruta visible por el proceso y la cuenta seleccionada. Calcular la caducidad desde la hora actual en la configuración prolongaría continuamente la captura.

```php
'mcp.tool_diagnostics' => [
    'user' => '<exact Nextcloud UID>',
    'tools' => ['files_share'],
    'file' => '/var/log/private-mcp/tool-calls.jsonl',
    'until' => 0, // Replace with the Unix timestamp at which capture should stop.
],
```

Cada llamada autenticada registra una recepción UTC y un resultado con el mismo ID aleatorio de solicitud. Una vista previa que devuelve un plan y una confirmación que lo presenta comparten una referencia opaca. Si cambia el plan, cambia la referencia. El diario usa clasificaciones fijas y excluye credenciales, ID de cliente y usuario, rutas, URL públicas, argumentos sin procesar, respuestas y mensajes de excepción.

Usa una vista previa controlada para verificar la captura antes de interpretar un incidente. Busca `preview_returned`, seguido de una recepción `confirmation` y su resultado. `invalid_plan_state` indica una referencia ausente o inválida, `new_preview_required` que el servidor pidió otra aprobación y `share_refused` que una regla de compartición rechazó la llamada. Una recepción aislada deja el resultado incierto. Comprueba el estado nativo antes de reintentar una escritura.

La captura empieza al despachar una herramienta autenticada. Un diario vacío también puede deberse a una ventana caducada, otra cuenta o herramienta, permisos del archivo, contención de bloqueo o el límite de 1 MiB. Comprueba el estado HTTP del endpoint y los detalles de la acción del cliente para los intentos que no llegaron al despacho. Retira la configuración y elimina el diario privado tras investigar. Usa un archivo y caducidad nuevos para cada incidente.
