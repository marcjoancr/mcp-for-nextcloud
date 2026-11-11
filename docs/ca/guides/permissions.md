<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../en/guides/permissions.md).

# Permisos i administració

Obre **Administració → Nextcloud MCP**. Les cinc pestanyes són **Resum**, **Accés i permisos**, **Clients i connexions**, **Protecció de dades** i **Avançat**. Les adreces (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) i la navegació amb teclat obren la secció corresponent. En pantalles estretes, utilitza el selector Seccions per canviar entre les mateixes cinc vistes. Clients i connexions s'obre amb la llista de connexions actives. Al mòbil, cada connexió té les seves files etiquetades i accions.

## Habilitar l'accés

El servei, l'elegibilitat de l'usuari (**Pot connectar-se**) i la connexió personal comencen desactivats, també per als administradors. Activa el servei, permet que l'usuari es connecti i deixa que activi la connexió a **Configuració personal → Nextcloud MCP**. L'autenticació per si sola no concedeix accés: el compte, el servei, l'elegibilitat, la connexió, l'aplicació opcional, el permís d'operació i els permisos natius de Nextcloud sobre el recurs han de permetre la petició.

Les eines de lectura originals de 2.0.0 conserven els valors predeterminats, excepte Mail i els registres del servidor, que comencen denegats. Cada eina afegida requereix una assignació explícita d'usuari o grup i selecció a la connexió. Assignar una eina nova no en concedeix cap altra: llegir un missatge de correu no habilita llegir-ne un fil, i llegir preguntes de formulari no habilita llegir respostes. A la matriu, concedeix les eines individuals de cerca de Mail, lectura de missatges i fils; llistat, preguntes i respostes de Forms; lectura i publicació de comentaris de Deck; i publicació de resums a Talk.

Les definicions d'eines conserven claus d'operació per classificar-les: `mail.read` cobreix comptes i carpetes, `mail.search` la cerca de capçaleres i `mail.read_message` el contingut de missatges i fils. Forms utilitza `forms.read` per a l'estructura i `forms.read_responses` per a respostes; els resultats també requereixen el permís natiu de resultats de Forms. Els comentaris de Deck utilitzen `deck.read_comments` i `deck.comment`; els resums de Talk, `talk.publish_summary`, independentment de les respostes. Llegir adjunts de Mail (`mail.read_attachment`), desar-los (`mail.save_attachment`), llegir esborranys (`mail.read_drafts`), escriure'ls (`mail.write_drafts`) i suprimir-los (`mail.delete_drafts`) són operacions separades. Desar un adjunt requereix a més `files.create`, els permisos natius de destinació i l'àmbit de Files. La cerca de missatges de Talk utilitza `talk.search`; la lectura contextual, `talk.read`, amb una assignació d'eina independent. Les eines noves continuen sense assignar ni seleccionar en actualitzar. Continuen aplicant-se l'accés natiu als recursos i els requisits previs addicionals de cada operació. Compartir fitxers amb persones/grups (`files.share`) i crear enllaços públics (`files.link`) continuen sent permisos independents. Llistar comparticions és lectura.

En connectar, MCP llista les eines permeses a l'usuari i seleccionades per a aquella connexió. Cada eina també requereix que l'aplicació del seu mòdul i qualsevol aplicació addicional que utilitzi estiguin instal·lades i habilitades per a l'usuari. Per exemple, llistar, crear i retirar comparticions de fitxers requereix `files_sharing`. Editar, substituir i fer checkout d'un fitxer existent requereix `files_versions`. Les lectures ordinàries funcionen amb qualsevol de les dues aplicacions desactivada. El servidor torna a comprovar els mateixos requisits en una crida directa.

Resum mostra l'endpoint, la versió, el nombre de connexions i el límit de pujada de checkout. Aquest límit s'edita en MiB i no pot superar `post_max_size` de PHP. Avançat conté els rols i la privadesa dels registres i OCR. Protecció de dades conté les etiquetes d'exclusió; consulta [protecció de fitxers](file-protection.md).

## Restriccions de l'aplicació Guests

Des de la versió 1.1.2, MCP torna a aplicar les restriccions de Guests després d'autenticar una contrasenya d'aplicació o un token OAuth. Es rebutja un convidat si la seva llista d'aplicacions permeses exclou MCP. MCP retorna HTTP 403; Guests pot rebutjar abans l'inici de sessió amb contrasenya d'aplicació a Nextcloud (a Nextcloud 35 aquest rebuig es mostra com HTTP 500). Les eines, els recursos de Notes i les referències de Talk d'aplicacions no permeses s'amaguen o es rebutgen; les eines de versions de fitxers també requereixen que Guests permeti `files_versions`. Files utilitza l'aplicació sempre permesa `files`. Tasks requereix `tasks` a la llista permesa del convidat i utilitza `dav` per a l'emmagatzematge.

L'emmagatzematge extern ocult continua inaccessible, inclosos els muntatges de Nextcloud 35 resolts directament per camí. Les referències estructurades a fitxers de Talk i Tasks, inclosa la publicació d'adjunts del Talk, utilitzen la mateixa comprovació d'emmagatzematge ocult. Si Guests amaga altres comptes, compartir continua limitat als grups del convidat. Un estat il·legible de Guests denega l'accés.

Els enllaços de checkout i pujada apliquen les mateixes restriccions pertinents al seu propietari abans de resoldre un fitxer. Un convidat rebutjat rep la resposta d'un enllaç desconegut (404), sense consumir-lo. Les versions de Guests anteriors a 4.8.0 no poden aplicar aquestes restriccions sense sessió i, per tant, es rebutgen els enllaços de convidats. Els comptes no convidats i les instal·lacions sense Guests conserven el comportament existent.

## Opcions individuals i herència de grups

A **Administració → Nextcloud MCP → Accés i permisos**, la matriu s'obre amb el primer grup del directori. Prem la capçalera de la columna per triar un altre grup o una persona al desplegable. Les dues llistes estan disponibles abans d'escriure. Utilitza **Afegeix una columna de comparació** per conservar la columna actual i comparar una altra entrada. Un grup mostra les regles assignades. Una persona mostra l'accés efectiu, la procedència i les excepcions individuals. La fila del servei continua a dalt. Obre un catàleg, cerca una eina o filtra per diferències o excepcions personals.

Tria **Permet**, **Bloqueja** o l'opció neutra directament en una cel·la. **Sense assignació** elimina una regla de grup. **Hereta** elimina una excepció personal. El botó d'informació al costat del selector obre la descripció de l'eina i les regles relacionades sota la fila. Per a una persona, també mostra els grups que determinen la decisió. Per canviar diverses assignacions alhora, activa **Edita diverses regles**, marca les eines i columnes de destinació i tria la regla una vegada. Els canvis continuen pendents fins que els revises i deses. Revisa l'impacte de cada columna i desa-la abans de revisar la següent, ja que un canvi de grup pot afectar una persona d'una altra columna.

Només apareixen eines d'aplicacions instal·lades, habilitades i compatibles. Una persona veu les aplicacions habilitades per al seu compte. Desactivar una aplicació amaga les eines de MCP i de les pantalles de configuració. Els permisos i les seleccions de connexió existents tornen a estar disponibles en reactivar-la.

**Permet**, **Bloqueja** i **Sense assignació** s'apliquen tant al servei com a les eines. La decisió explícita d'una persona preval. Si no té assignació, el bloqueig d'un grup de confiança preval sobre les autoritzacions de grup. Sense decisió de grup, s'aplica el valor predeterminat del catàleg. Desactivar el servei global o un compte impedeix que qualsevol connexió utilitzi MCP. Una eina continua necessitant l'aplicació opcional, el rol, els requisits previs d'operació i l'accés natiu als recursos.

La pertinença a grups prové de Nextcloud. Només els grups natius amb base de dades poden concedir accés. Si no es pot resoldre una política, s'informa que no està disponible. Des d'una cel·la d'eina, obre **Regles d'operació relacionades** per als controls com compartir amb persones o crear enllaços públics. Canviar una d'aquestes opcions afecta totes les eines que necessiten aquella operació.

Quan Nextcloud crea un compte, MCP elimina els permisos individuals d'eines i les seleccions de connexió deixats per un compte anterior amb el mateix ID d'usuari. Si falla la transacció, la creació del compte s'atura. Reintenta-la quan el magatzem de polítiques estigui disponible.

### Actualització des de 2.0.0

L'actualització conserva les opcions individuals i de grup originals. Les 84 eines incloses a 2.0.0 hereten aquestes opcions fins que l'administrador canvia una assignació. Una operació antiga de grup no marcada continua sense assignació. Restablir una eina individual a **Sense assignació** també elimina la seva excepció individual d'operació heretada per a aquella eina. La migració verifica les decisions antigues i noves abans d'activar-les. Una diferència inesperada o una política il·legible atura l'actualització.

Les connexions existents reben una selecció limitada als seus permisos d'operació anteriors. Les eines afegides necessiten assignació i selecció noves. Conserva juntes les còpies prèvies a l'actualització de base de dades i aplicació. Després que algú canviï seleccions o bloquegi accés al nou editor, la recuperació ha de conservar aquelles decisions. Una aplicació anterior no les pot fer complir.

## Anàlisi de Forms, cerca de Deck i disponibilitat de Calendar

Aquestes incorporacions comencen sense assignació i se seleccionen per separat a cada connexió:

| Eina | Operació | Accés natiu addicional |
| --- | --- | --- |
| `forms_summarize_responses` | `forms.summarize_responses` | Permís complet de resultats del formulari |
| `forms_export_responses` | `forms.export_responses` i `files.create` | Resultats del formulari i creació a la carpeta de Files seleccionada |
| `deck_search_cards` | `deck.search` | Permís de lectura a cada tauler retornat |
| `calendar_check_availability` | `calendar.availability` | Àmbit de Calendar, comparticions natives i classificació d'esdeveniments |

Els permisos de lectura existents no habiliten automàticament aquestes eines. L'exportació de formularis és l'única escriptura d'aquest conjunt: requereix una previsualització actual i confirmació, més confirmació de destinació compartida quan correspongui. Ni assignar una eina ni seleccionar-la a una connexió evita les restriccions natives d'accés d'un altre usuari.

## Desar, conflictes i desfer

Les escriptures de política inclouen la revisió mostrada a la interfície. Una previsualització obsoleta o un canvi concurrent produeix un conflicte i una recàrrega; el navegador no repeteix silenciosament l'escriptura. Una resposta incerta no es presenta com un desament correcte. Els esborranys de grups, recursos i clients continuen sent esborranys fins a l'acció Desa corresponent.

Les peticions de restabliment/lot es limiten a 50 usuaris i 2500 cel·les. La previsualització val 120 segons; els rebuts del servidor caduquen als 600 segons. La interfície ofereix **Desfés** durant 10 segons mentre el control estigui disponible i pausa el temporitzador amb el punter a sobre o el focus del teclat. Desfer exigeix la revisió desada i un rebut vàlid: no pot sobreescriure canvis posteriors. Restaurar un permís no restaura credencials revocades ni torna a iniciar sessions.

## Restriccions de recursos i inspecció

L'administrador pot restringir els recursos de **Files**, **Calendar** i **Tasks** d'un usuari des del panell d'accés. Cada mòdul és independent: **Tots els recursos** vol dir sense restricció; **Només els seleccionats**, amb una selecció buida, no permet cap recurs. El límit és de 200 recursos per usuari i mòdul. Les restriccions només redueixen les concessions i els permisos natius existents; canviar-les no revoca la connexió. S'apliquen a l'operació següent.

Notes, Contacts, Deck i Talk no tenen restricció de recursos per mòdul. Mail tampoc no ofereix selecció de recursos per compte. Les referències estructurades a fitxers i les operacions amb adjunts poden requerir comprovacions de l'àmbit de Files; consulta [protecció de fitxers](file-protection.md). Restringir una carpeta no restringeix de manera general tot el que altres mòduls hi emmagatzemen a sota.

L'inspector, exclusiu dels administradors, informa **Permès**, **Bloquejat** o **Desconegut**, la comprovació que bloqueja i l'origen del permís. Pot explicar una ruta exclosa a l'administrador; els errors MCP mantenen denegacions genèriques. Les consultes i els canvis poden generar missatges d'auditoria de nivell informatiu sense noms ni rutes de recursos. Depenen del nivell de registre del servidor i no garanteixen una traça d'auditoria.

## Clients i configuració personal

Obre **Configuració personal → Nextcloud MCP** i tria un client connectat per gestionar les seves eines. El mateix selector apareix en autoritzar una connexió OAuth nova. Selecciona només el que aquell client necessita. Les retirades administratives s'apliquen a la petició següent, també amb tokens existents. Renovar un token OAuth conserva la selecció d'aquella connexió. L'autenticació Basic i els clients de sessió comparteixen el perfil Nextcloud etiquetat per separat. L'àmbit de recursos continua limitant les carpetes, calendaris i llistes de tasques als quals pot accedir el client.

Gestiona els hosts de clients publicats, el client natiu i els clients personalitzats registrats a **Clients i connexions**; consulta [OAuth](../reference/oauth.md). Les API administratives exigeixen un administrador i protecció CSRF de Nextcloud. Les API personals de connexió/àmbit obtenen la identitat de la sessió i només exposen l'estat d'aquell usuari. Les respostes mai no contenen hashes de tokens.

La configuració personal mostra l'estat de connexió, els clients connectats, els controls de revocació i les restriccions de recursos de només lectura de l'usuari. Per a HTTP Basic, crea una contrasenya d'aplicació a **Configuració personal → Seguretat**. MCP no demana ni emmagatzema la contrasenya principal. Desconnectar MCP no elimina aquesta contrasenya d'aplicació de Nextcloud; consulta [revocar l'accés](clients.md#revocar-laccés).

Persisteix un risc residual d'escriptura: una denegació genèrica pot distingir una destinació no disponible d'una altra on la creació tindria èxit, tot i que no en reveli el motiu.

[Guies operatives](README.md).

[English](../../en/guides/permissions.md) · [Español](../../es/guides/permissions.md) · Català · [Português (Brasil)](../../pt-BR/guides/permissions.md) · [Português (Portugal)](../../pt-PT/guides/permissions.md)
