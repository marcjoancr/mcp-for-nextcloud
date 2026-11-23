<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/people.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/people.md).

# Personas

Usa `mcp_guide` con `module: "people"`.

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

| Módulo | Herramientas | Dependencia |
| --- | --- | --- |
| Personas | `users_search` | búsqueda de cuentas y, con `include_groups: true`, de grupos; devuelve el campo `type` con el valor `user` o `group` mediante el sistema de uso compartido de Nextcloud (respeta las restricciones del administrador); no requiere aplicaciones opcionales |

[Guías operativas](../../guides/README.md).

## Identificar personas y equipos

Usa `users_resolve` para consultar varios nombres, correos o ID de cuenta. Devuelve coincidencias exactas y sugerencias e indica cuándo la resolución es ambigua o incompleta. Los campos de perfil respetan la visibilidad en Nextcloud.

`users_list_teams` y `users_list_team_members` requieren Circles. La consulta de miembros respeta los equipos visibles para la cuenta conectada. Utiliza el UID de un miembro local cuando lo invites a un evento.

## Buscar perfiles y gestionar miembros

`users_filter_team_members` filtra los miembros locales visibles de un equipo por cargo u organización. El cargo profesional y el rol de membresía son campos distintos. Si la consulta se recorta, el resultado lo indica.

`users_add_team_member` y `users_remove_team_member` actúan sobre una cuenta local concreta y aplican los permisos nativos de Circles. La vista previa identifica la cuenta, el equipo y el posible cambio de acceso a sus recursos compartidos. Repite la llamada aprobada con `confirm: true` y su `plan_state`. Las altas usan el rol de miembro.

[English](../../../en/reference/tools/people.md) · Español · [Català](../../../ca/reference/tools/people.md) · [Português (Brasil)](../../../pt-BR/reference/tools/people.md) · [Português (Portugal)](../../../pt-PT/reference/tools/people.md)
