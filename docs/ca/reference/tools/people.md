<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/people.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/people.md).

# Persones

Fes servir `mcp_guide` amb `module: "people"`.

Consulta `mcp_guide` per obtenir l'esquema autoritatiu i les operacions disponibles.

| Mòdul | Eines | Dependència |
| --- | --- | --- |
| Persones | `users_search` | cerca de comptes i, amb `include_groups: true`, cerca de grups que retorna `type` `user` o `group`, mitjançant l'ús compartit de Nextcloud (respecta les restriccions de l'administrador); no requereix cap aplicació opcional |

[Guies operatives](../../guides/README.md).

## Resolució de persones i equips

Fes servir `users_resolve` per consultar lots de noms, adreces electròniques o identificadors de compte. El resultat separa les coincidències exactes natives dels suggeriments i marca els casos ambigus o incomplets. Els camps del perfil segueixen la visibilitat de Nextcloud.

`users_list_teams` i `users_list_team_members` requereixen Circles. La pertinença als equips es consulta com a usuari connectat. El resultat diferencia els comptes locals dels altres tipus de membre. Fes servir l'UID d'un membre local per convidar-lo a un esdeveniment.

## Cerca de perfils i gestió de membres

`users_filter_team_members` filtra els membres locals visibles d'un equip per càrrec professional o organització. El càrrec professional i el rol de membre de l'equip són camps diferents. El resultat indica si l'exploració s'ha truncat.

`users_add_team_member` i `users_remove_team_member` actuen sobre un únic compte local i apliquen els permisos natius de Circles. La vista prèvia identifica el compte, l'equip i el possible canvi d'accés als recursos compartits. Repeteix la crida aprovada amb `confirm: true` i el `plan_state` de la vista prèvia. Les noves incorporacions fan servir el rol de membre.

[English](../../../en/reference/tools/people.md) · [Español](../../../es/reference/tools/people.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/people.md) · [Português (Portugal)](../../../pt-PT/reference/tools/people.md)
