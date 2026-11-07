<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/calendar.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/calendar.md).

# Calendar

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Calendar** | llista calendaris i esdeveniments (recurrència, zones horàries, tot el dia) | crea, edita, mou, suprimeix i transfereix esdeveniments mitjançant el flux CalDAV real, amb participants. Les escriptures estan desactivades fins que l'administrador les concedeixi a la matriu d'administració (sense passar pel terminal), i primer cadascuna retorna un pla: **no s'escriu res fins que l'usuari ho aprova.** |

| Eina | Permís | Detalls |
| --- | --- | --- |
| `calendar_list_calendars` `{}` | calendar.read | Calendaris visibles per a l'usuari, propis i compartits. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Esdeveniments de l'interval (predeterminat: els pròxims 7 dies), amb recurrències desplegades. |
| `calendar_create_event` | calendar.create | Esdeveniment senzill; els `attendees` són UID interns que es resolen a l'adreça electrònica del compte. |
| `calendar_update_event` | calendar.edit | Actualitza text/dates i substitueix la llista de participants; `attendees: []` els elimina tots. Es rebutja canviar participants d'una sèrie o d'un esdeveniment organitzat per una altra persona. |
| `calendar_move_event` | calendar.move | MOVE dins del mateix propietari; no s'avisa els participants, com a l'aplicació Calendar. |
| `calendar_delete_event` | calendar.delete | Suprimeix la sèrie a la paperera; requereix retenció activa. |
| `calendar_transfer_event` | calendar.transfer | MOVE a un calendari d'un altre propietari que s'hagi compartit amb permís d'escriptura; rebutja esdeveniments amb participants. |

Calendar ofereix lectura i cinc operacions d'escriptura sense passar pel terminal. Les escriptures comencen desactivades a la matriu d'administració (Administració → MCP) i només apareixen als usuaris que tenen el permís concedit. Cada escriptura també requereix `confirm: true`, l'ACL de Nextcloud (i `confirm_shared` per als calendaris d'una altra persona) i, opcionalment, l'ETag. Compatibilitat: a `info.xml`, l'aplicació declara `min-version` 33 i `max-version` 35; els components interns (el `EmbeddedCalDavServer` del nucli) estan coberts per les proves i aquest interval, i una nova versió de Nextcloud requereix una nova versió de l'aplicació. Calendar ha d'estar activada per a l'usuari. Sabre i Symfony Console provenen del nucli Nextcloud i no s'inclouen a `vendor/` del paquet.

| Eina d'escriptura | Permís | Comportament |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Esdeveniment senzill; els `attendees` són UID interns que es resolen a l'adreça electrònica del compte. |
| `calendar_update_event` | calendar.edit | Actualitza text/dates i substitueix els participants; `attendees: []` els elimina tots. Es rebutja canviar participants d'una sèrie o d'un esdeveniment organitzat per una altra persona. |
| `calendar_move_event` | calendar.move | MOVE dins del mateix propietari; no s'avisa els participants, com a l'aplicació Calendar. |
| `calendar_delete_event` | calendar.delete | Suprimeix la sèrie a la paperera; requereix retenció activa. |
| `calendar_transfer_event` | calendar.transfer | MOVE a un calendari d'un altre propietari compartit amb permís d'escriptura; rebutja esdeveniments amb participants. |

Per defecte, create, update i delete fan servir `send_invitations: false` (`x-nc-scheduling: false`). Amb `true`, es lliura la invitació al planificador CalDAV de Nextcloud, inclosos els convidats actuals i CANCEL. El pla i el resultat indiquen `imipEnabled`, llegit de la configuració nativa `dav/sendInvitations`: si està desactivada, el servidor no envia cap correu (però l'iTIP intern arriba a la safata d'entrada del convidat) i l'assistent ho ha d'explicar a l'usuari. L'aplicació no confirma mai el lliurament del correu. Les escriptures al calendari d'una altra persona continuen requerint `confirm_shared: true` després de la confirmació de l'usuari.

**Cal confirmar cada escriptura (el servidor no desa cap estat).** Sense `confirm: true`, les cinc operacions només retornen un pla (`requiresConfirmation: true`): valors actuals i proposats, participants afegits/retirats, destinació, conseqüències per a invitacions/CANCEL/paperera i l'`etag` actual. No s'escriu ni es programa res. L'assistent ha de mostrar el pla, demanar-ho a l'usuari i, només després d'un sí explícit, repetir la crida amb `confirm: true` i els mateixos arguments, reenviant l'`etag` del pla (és opcional; si s'envia i no coincideix, es rebutja l'escriptura i cal generar un pla nou). No hi ha cap taula, token ni registre d'aprovació: el servidor no demostra que l'usuari hagi dit que sí, però continuen aplicant-se els permisos, l'ACL, les concessions i `confirm_shared`. L'autoprova crida directament els gestors i no fa servir aquest flux.

Consulta `mcp_guide` en viu per a l'esquema autoritatiu i les operacions disponibles.

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/calendar.md) · [Español](../../../es/reference/tools/calendar.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/calendar.md) · [Português (Portugal)](../../../pt-PT/reference/tools/calendar.md)

## Disponibilitat i conflictes

`calendar_check_availability` requereix el permís independent `calendar.availability` i selecció explícita de l'eina. Proporciona `from` (inclusiu) i `to` (exclusiu), amb una finestra màxima de 31 dies. Utilitza `calendars` opcional per seleccionar fins a 20 camins de calendaris accessibles; omet-lo per utilitzar tots els calendaris visibles, amb el mateix límit. `min_duration_minutes` filtra intervals lliures (30 per defecte, interval 5–1440).

La resposta conté intervals ocupats UTC, conflictes de superposició i intervals lliures comuns. Els intervals que només es toquen no entren en conflicte. Els esdeveniments recurrents, excepcions i zones horàries segueixen les regles existents d'expansió de calendaris; els cancel·lats i transparents no bloquegen temps. Els arguments només de data i els valors flotants de dia sencer segueixen la convenció existent de data civil UTC.

No es retorna títol, descripció ni dades dels participants. S'apliquen comparticions natives, restriccions d'àmbit de Calendar i classificació d'esdeveniments. Si esdeveniments ocults, dades no vàlides o límits d'exploració impedeixen un càlcul fiable, l'eina retorna un error en lloc d'afirmar que el temps és lliure. S'admeten fins a 2.000 objectes de calendari per calendari i 10.000 ocurrències en total. Amb aquesta política conservadora, els esdeveniments de durada zero també fan que la disponibilitat no estigui disponible. La disponibilitat és una instantània dels calendaris accessibles, no una reserva ni una afirmació sobre calendaris inaccessibles per a qui fa la crida.
