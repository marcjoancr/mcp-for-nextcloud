<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/calendar.md -->
<!-- translation-source-revision: 2e4144ffeb13c74a1d9da098c262b8424cb24439 -->
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
| `calendar_create_event` | calendar.create | Crea un esdeveniment. `recurrence` admet una regla setmanal o mensual acotada. `attendees` accepta UID interns. |
| `calendar_update_event` | calendar.edit | Actualitza l'esdeveniment o l'`recurrence_id` seleccionat. Els participants d'una sèrie i la seva recurrència tenen restriccions. |
| `calendar_move_event` | calendar.move | MOVE dins del mateix propietari; no s'avisa els participants, com a l'aplicació Calendar. |
| `calendar_delete_event` | calendar.delete | Suprimeix una sèrie a la paperera o exclou una ocurrència original si s'indica `recurrence_id`. |
| `calendar_transfer_event` | calendar.transfer | MOVE a un calendari d'un altre propietari que s'hagi compartit amb permís d'escriptura; rebutja esdeveniments amb participants. |

Calendar ofereix lectura i operacions d'escriptura sense passar pel terminal. Les escriptures comencen desactivades a la matriu d'administració (Administració → MCP) i només apareixen als usuaris que tenen el permís concedit. Cada escriptura també requereix `confirm: true`, l'ACL de Nextcloud (i `confirm_shared` per als calendaris d'una altra persona) i, opcionalment, l'ETag. Compatibilitat: a `info.xml`, l'aplicació declara `min-version` 33 i `max-version` 35; els components interns (el `EmbeddedCalDavServer` del nucli) estan coberts per les proves i aquest interval, i una nova versió de Nextcloud requereix una nova versió de l'aplicació. Calendar ha d'estar activada per a l'usuari. Sabre i Symfony Console provenen del nucli Nextcloud i no s'inclouen a `vendor/` del paquet.

| Eina d'escriptura | Permís | Comportament |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Crea un esdeveniment. `recurrence` admet una regla setmanal o mensual acotada. `attendees` accepta UID interns. |
| `calendar_update_event` | calendar.edit | Actualitza l'esdeveniment o l'`recurrence_id` seleccionat. Els participants d'una sèrie i la seva recurrència tenen restriccions. |
| `calendar_move_event` | calendar.move | MOVE dins del mateix propietari; no s'avisa els participants, com a l'aplicació Calendar. |
| `calendar_delete_event` | calendar.delete | Suprimeix una sèrie a la paperera o exclou una ocurrència original si s'indica `recurrence_id`. |
| `calendar_transfer_event` | calendar.transfer | MOVE a un calendari d'un altre propietari compartit amb permís d'escriptura; rebutja esdeveniments amb participants. |

Per defecte, create, update i delete fan servir `send_invitations: false` (`x-nc-scheduling: false`). Amb `true`, es lliura la invitació al planificador CalDAV de Nextcloud, inclosos els convidats actuals i CANCEL. El pla i el resultat indiquen `imipEnabled`, llegit de la configuració nativa `dav/sendInvitations`: si està desactivada, el servidor no envia cap correu (però l'iTIP intern arriba a la safata d'entrada del convidat) i l'assistent ho ha d'explicar a l'usuari. L'aplicació no confirma mai el lliurament del correu. Les escriptures al calendari d'una altra persona continuen requerint `confirm_shared: true` després de la confirmació de l'usuari.

**Cal confirmar cada escriptura (el servidor no desa cap estat).** Sense `confirm: true`, les operacions d'esdeveniment de la taula només retornen un pla (`requiresConfirmation: true`): valors actuals i proposats, participants afegits/retirats, destinació, conseqüències per a invitacions/CANCEL/paperera i l'`etag` actual. No s'escriu ni es programa res. L'assistent ha de mostrar el pla, demanar-ho a l'usuari i, només després d'un sí explícit, repetir la crida amb `confirm: true` i els mateixos arguments, reenviant l'`etag` del pla (és opcional; si s'envia i no coincideix, es rebutja l'escriptura i cal generar un pla nou). No hi ha cap taula, token ni registre d'aprovació: el servidor no demostra que l'usuari hagi dit que sí, però continuen aplicant-se els permisos, l'ACL, les concessions i `confirm_shared`. L'autoprova crida directament els gestors i no fa servir aquest flux.

Consulta `mcp_guide` en viu per a l'esquema autoritatiu i les operacions disponibles.

[Guies operatives](../../guides/README.md).

## Reunions i reserves

`calendar_search_events` i `calendar_read_event` permeten trobar esdeveniments i llegir-ne els detalls visibles, incloses les respostes a les invitacions. La cerca aplica la confidencialitat abans de comparar el text. Fes servir `calendar_find_slots` amb una finestra, una durada i els identificadors interns dels comptes. Cada franja suggerida indica el resultat de les comprovacions de disponibilitat; aquesta pot ser desconeguda i la proposta no reserva temps.

Fes servir `calendar_create_event_with_external_attendees` o `calendar_update_event_with_external_attendees` per indicar adreces electròniques explícites. Si cal, consulta primer el contacte per triar l'adreça. Aquestes eines tenen concessions independents i respecten la configuració del servidor per als participants externs. La vista prèvia diferencia la programació del lliurament de correu. Fes servir `calendar_respond_to_invitation` per canviar la teva resposta al teu calendari i repeteix la crida confirmada amb l'ETag de la vista prèvia.

Per a una ocurrència d'una sèrie, indica el `recurrence_id` original, no pas l'inici nou d'una excepció moguda. Excloure una ocurrència pot enviar una actualització de la sèrie amb aquella data exclosa.

## Sales i recursos

Fes servir `calendar_list_resources` per descobrir les sales i els equips que tens visibles. `calendar_check_resource_availability` consulta una finestra a través de CalDAV; un estat desconegut vol dir que el servidor no l'ha pogut verificar. Fes servir `calendar_reserve_resource` en un esdeveniment no recurrent que organitzis al teu calendari. Revisa el pla de programació i confirma'l amb l'ETag. El resultat mostra per separat la resposta de la sala i l'actualització de l'esdeveniment. Si canvies l'hora o cancel·les l'esdeveniment, activa la programació perquè la sol·licitud també arribi a la sala.

## Enllaços de reserva

Fes servir `calendar_list_appointments` per trobar configuracions de reserva als teus calendaris visibles. Crea, edita o retira una configuració amb `calendar_create_appointment`, `calendar_update_appointment` i `calendar_delete_appointment`. Revisa la zona horària, l'horari setmanal, la durada de les cites, el període de reserva i la visibilitat. Les franges setmanals fan servir l'hora local HH:mm; les durades i els marges són en minuts, i l'horitzó de reserva és en dies. Repeteix l'escriptura aprovada amb el `plan_state` del pla.

La visibilitat privada manté l'enllaç fora del perfil públic, però qualsevol persona que tingui l'URL pot reservar. La visibilitat pública també el mostra al perfil. En eliminar la configuració, es retira l'enllaç. Calendar 6.6.2 i 6.6.3 ofereixen aquestes eines quan l'administrador les concedeix i la connexió les selecciona.

## Invitacions, recuperació i intercanvi

- Filtra `calendar_search_events` amb `participation_status: needs-action` per trobar invitacions pendents de resposta. També s'admeten `accepted`, `declined` i `tentative`.
- Fes servir `calendar_list_trashed_events` per trobar l'ID d'un esdeveniment suprimit i `calendar_restore_trashed_event` per restaurar-lo al calendari original. La recuperació depèn de la retenció i de l'abast dels calendaris propis. La restauració nativa conserva les dades sense enviar invitacions.
- `calendar_create_calendar` crea un calendari personal quan l'abast de calendaris no està restringit. `calendar_list_calendar_shares` i `calendar_share_calendar` llisten i gestionen l'accés directe de comptes locals als calendaris propis: lectura, escriptura o retirada.
- `calendar_import_ics` llegeix un document visible a Files i copia fins a 100 esdeveniments o 1 MiB en un calendari propi. La vista prèvia identifica duplicats i conflictes. La importació omet organitzadors, participants, alarmes i adjunts. `calendar_export_ics` retorna un document iCalendar dels UID seleccionats d'un calendari, incloses les excepcions de sèrie completament visibles.
- `calendar_list_appointment_bookings` llista les reserves pendents i confirmades dels enllaços visibles de Calendar 6.6.2 o 6.6.3. Filtra per data i, opcionalment, per configuració. El resultat inclou el nom de la persona i la franja, i manté privats el correu i els tokens de confirmació.
- `calendar_set_event_reminders` substitueix les alarmes de visualització relatives a l'inici d'un esdeveniment o sèrie. Indica de zero a cinc antelacions en minuts. Es conserven les alarmes personalitzades, de correu, absolutes, repetides i específiques d'ocurrències.

Les escriptures d'aquests fluxos requereixen `confirm: true` i el `plan_state` de la vista prèvia.

## Disponibilitat i conflictes

`calendar_check_availability` requereix el permís independent `calendar.availability` i selecció explícita de l'eina. Proporciona `from` (inclusiu) i `to` (exclusiu), amb una finestra màxima de 31 dies. Utilitza `calendars` opcional per seleccionar fins a 20 camins de calendaris accessibles; omet-lo per utilitzar tots els calendaris visibles, amb el mateix límit. `min_duration_minutes` filtra intervals lliures (30 per defecte, interval 5–1440).

La resposta conté intervals ocupats UTC, conflictes de superposició i intervals lliures comuns. Els intervals que només es toquen no entren en conflicte. Els esdeveniments recurrents, excepcions i zones horàries segueixen les regles existents d'expansió de calendaris; els cancel·lats i transparents no bloquegen temps. Els arguments només de data i els valors flotants de dia sencer segueixen la convenció existent de data civil UTC.

No es retorna títol, descripció ni dades dels participants. S'apliquen comparticions natives, restriccions d'àmbit de Calendar i classificació d'esdeveniments. Si esdeveniments ocults, dades no vàlides o límits d'exploració impedeixen un càlcul fiable, l'eina retorna un error en lloc d'afirmar que el temps és lliure. S'admeten fins a 2.000 objectes de calendari per calendari i 10.000 ocurrències en total. Amb aquesta política conservadora, els esdeveniments de durada zero també fan que la disponibilitat no estigui disponible. La disponibilitat és una instantània dels calendaris accessibles, no una reserva ni una afirmació sobre calendaris inaccessibles per a qui fa la crida.

[English](../../../en/reference/tools/calendar.md) · [Español](../../../es/reference/tools/calendar.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/calendar.md) · [Português (Portugal)](../../../pt-PT/reference/tools/calendar.md)
