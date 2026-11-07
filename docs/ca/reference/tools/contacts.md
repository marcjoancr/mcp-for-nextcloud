<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/contacts.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/contacts.md).

# Contactes

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Contactes** | llista llibretes d'adreces pròpies o compartides, cerca i llegeix contactes, inclòs el catàleg de comptes de només lectura de la instància (companys, amb `accountId` per a invitacions) segons la configuració d'enumeració d'usuaris de l'administrador | crea i edita preservant els camps vCard desconeguts; suprimeix definitivament només després d'una còpia `.vcf` verificada sota `Contacts` a la carpeta de còpies configurada (predeterminada `/MCP backups/Contacts`; obligatòria encara que se suspenguin les còpies de Files) |

[Funcionament de Contactes i Tasques](contacts-tasks.md).

Consulta `mcp_guide` per obtenir l'esquema autoritatiu i les operacions disponibles.

| Mòdul | Eines | Dependència |
| --- | --- | --- |
| Contactes | `contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact` | aplicació Contacts activada; CardDAV del nucli |

Les eines de Contactes requereixen que l'aplicació Contacts estigui activada per a l'usuari. La lectura s'autoritza inicialment; l'administrador ha de concedir els permisos de creació, edició i supressió.

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/contacts.md) · [Español](../../../es/reference/tools/contacts.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/contacts.md) · [Português (Portugal)](../../../pt-PT/reference/tools/contacts.md)
