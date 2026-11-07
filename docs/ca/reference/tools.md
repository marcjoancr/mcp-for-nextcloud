<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../en/reference/tools.md).

# Eines

Els catàlegs dels mòduls contenen com a màxim **84 eines de mòdul**, més `mcp_status` i `mcp_guide` (86 en total). Tres eines de dependències de Deck depenen d’API natives compatibles de NC34/35, de manera que sense elles hi ha com a màxim 81 eines de mòdul (83 en total). La disponibilitat d’aplicacions i l’accés de cada compte redueixen encara més aquests recomptes.

Cada eina només apareix a `tools/list` i només es pot cridar si l'usuari té el permís per a l'operació i l'aplicació necessària està activada. El permís i l'estat de l'aplicació es tornen a comprovar en cada crida. Tot s'executa com a usuari autenticat, dins de la seva pròpia carpeta de Nextcloud, i es continuen aplicant els permisos i els elements compartits de Nextcloud.

### Guia d'eines (`mcp_guide`)

`mcp_guide` (títol **Guia d'eines**) descriu les eines al mateix model perquè no hagi d'endevinar què fa cadascuna:

- **sense arguments** — una línia per mòdul amb els títols de les eines disponibles **per a aquest usuari** (s'aplica el mateix filtre de permisos i aplicacions que a `tools/list`: els mòduls desactivats o sense permís no apareixen);
- **amb `module`** (`files`, `notes`, `calendar`, `contacts`, `tasks`, `deck`, `talk`, `people`, `logs`, `mail`) — cada eina del mòdul: descripció, si llegeix o escriu, paràmetres amb tipus, caràcter obligatori, descripció, valors `enum` i límits, i les confirmacions requerides;
- **amb `tool`** — una única eina i el mòdul al qual pertany.

La guia està escrita en anglès; el model en comunica el contingut en l'idioma de l'usuari.

El text prové de les definicions reals (`ToolModule::definitions()`, `inputSchema`, `annotations` i `ToolPresentation`), de manera que si canvia l'esquema d'una eina també canvia la guia: no hi ha una segona còpia de la documentació. El que no consta a l'esquema —com trobar elements, quins camins i identificadors fer servir, i on s'aturen les escriptures— es descriu a `ToolGuideNotes::guideNotes()`, amb una nota breu per mòdul al costat del codi del mòdul. La resposta inclou el mateix contingut en Markdown i `structuredContent`; l'eina només llegeix definicions i mai no n'executa cap.

Si l'usuari demana un mòdul o una eina que no pot veure, es rebutja la petició amb un missatge que enumera què pot utilitzar. El servidor no desa cap aprovació: la instrucció de demanar confirmació abans d'escriure és a `instructions` de `initialize` i s'aplica a totes les eines que canvien dades.

A més:

- **Confirmació en la conversa abans de cada escriptura (`confirm: true`)**: les eines que canvien dades (`operation != 'read'`) només s'executen amb `confirm: true`. Sense aquest valor, el servidor retorna el pla (`requiresConfirmation: true`) i no canvia res. L'assistent ha de mostrar el pla a l'usuari i repetir la crida amb `confirm: true` només després d'obtenir-ne l'aprovació explícita. El servidor no desa cap estat ni token d'aprovació.
- **OCR amb Workflow OCR**: si està instal·lada l'aplicació Workflow OCR, els PDF escanejats obtenen una capa de text que `files_read` llegeix normalment. Si no hi és, els PDF o les imatges sense text retornen `text_layer: false` i un avís; la pàgina d'administració mostra l'estat de l'OCR i un enllaç per instal·lar-lo. No es bloqueja res.
- **Títols d'eina entenedors** al client («Cerca fitxers», «Llista calendaris») i anotacions MCP (`readOnlyHint`, `destructiveHint`) perquè els clients puguin demanar confirmació abans d'accions arriscades.
- **Confirmacions de seguretat** per als recursos d'altres persones: carpetes compartides, carpetes d'equip, taulers Deck i calendaris d'altres persones. El servidor rebutja la primera crida i retorna un missatge preparat; l'assistent ha de demanar autorització a l'usuari abans de repetir-la amb `confirm_shared: true`. *(Deck i Calendar des de la versió 0.6.6; Files i Notes des de la 0.7.0.)*
- **Respecte per les aplicacions opcionals**: s'amaguen les eines i les columnes de la matriu d'administració de les aplicacions desactivades (per a tothom o per a un usuari concret).
- **Interfície localitzada**: la matriu d'administració, la pàgina personal, la pantalla de consentiment i tots els missatges que mostra una eina segueixen l'idioma del compte Nextcloud. Es cobreixen tots els mòduls (Files, Notes, Calendar, Contacts, Tasks, Deck, Talk, els missatges compartits, les pàgines de checkout i l'autoprova de Calendar). S'inclouen el text font anglès i catàlegs d'interfície alemany, portuguès brasiler i castellà. Alguns missatges nous encara necessiten revisió lingüística humana, inclosa la interfície alemanya d'administració renderitzada. La documentació operativa també conserva esborranys en català i portuguès europeu amb la seva procedència pendent; les descripcions de les eines continuen en anglès perquè les llegeix el model.
- **Dues versions de MCP**: MCP sense estat `2026-07-28` (`server/discover`) i el flux clàssic `initialize` (`2025-06-18` i versions anteriors) al mateix punt d'accés.

- [Fitxers](tools/files.md)
- [Notes](tools/notes.md)
- [Calendar](tools/calendar.md)
- [Contactes](tools/contacts.md)
- [Tasques](tools/tasks.md)
- [Deck](tools/deck.md)
- [Registres](tools/logs.md)
- [Talk](tools/talk.md)
- [Persones](tools/people.md)
- [Mail](tools/mail.md)

`mcp_status` ofereix diagnòstics sense llegir dades de l'usuari. La identitat del servidor a les metadades de protocol, eines, prompts i recursos informa de la versió instal·lada des de Nextcloud en lloc de copiar un número de versió del projecte original.

[Guies operatives](../guides/README.md).

[English](../../en/reference/tools.md) · [Español](../../es/reference/tools.md) · Català · [Português (Brasil)](../../pt-BR/reference/tools.md) · [Português (Portugal)](../../pt-PT/reference/tools.md)
