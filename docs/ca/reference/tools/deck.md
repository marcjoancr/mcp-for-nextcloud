<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/deck.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/deck.md).

# Deck

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Deck** | taulers, piles, targetes i un **seguiment** dels taulers que gestiones: targetes agrupades per responsable, amb data límit, indicador de retard (en la teva zona horària) i enllaç a la targeta | crea, edita, mou i suprimeix targetes, assigna o treu l'assignació als membres del tauler; crea un tauler amb les seves llistes i les primeres targetes, o una llista en un tauler que gestiones; suprimeix un tauler o una llista només si està buit, directament a la paperera de Deck |

## Deck: targetes, taulers i llistes

Les escriptures de Deck utilitzen els permisos `deck.create`, `deck.edit`, `deck.move` i `deck.delete`, tots desactivats inicialment, i `confirm_shared: true` en un tauler d'un altre propietari. L'aplicació sempre confirma amb Deck si l'escriptura s'ha desat, fins i tot després d'un error de Deck.

| Eina d'escriptura | Permís | Comportament |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Crea un tauler del mateix usuari, amb les seves llistes i les primeres targetes (fins a 20 llistes i 100 targetes), i un color opcional. En un tauler nou, les persones assignades són el mateix usuari perquè ningú més no hi té accés. El pla mostra l'arbre sencer; si falla un pas a mig procés, no es desfà res i el resultat inclou `created`, `failed` (amb un motiu segur) i `warnings`, perquè repetir l'acció no dupliqui res. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Afegeix una llista a un tauler que gestiona l'usuari, al final o en una posició concreta. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Suprimeix una llista **només si està buida**: qualsevol targeta, activa o arxivada, rebutja la crida i indica quantes en queden; en confirmar, es torna a calcular el recompte. La llista passa a la paperera de Deck. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Igual per a un tauler; només el propietari el pot suprimir. Si apareix una targeta entre el recompte i la supressió, es restaura el tauler amb la funció de desfer de Deck i es rebutja l'acció indicant que no s'ha suprimit res. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` i l'assignació de responsables fan servir els mateixos permisos i requereixen la mateixa confirmació; les persones assignades han de ser membres del tauler.

Després d'un error de Deck, cada escriptura torna a llegir l'estat real abans de respondre: si l'element s'ha desat, la resposta indica que l'acció s'ha completat i avisa «S'ha desat; Deck ha informat d'un error posterior (notificació o activitat)»; si no, retorna l'error habitual; i si no es pot llegir l'estat, no retorna un error i demana a l'assistent que llegeixi amb `deck_list_cards`, `deck_read_card`, `deck_list_stacks` o `deck_list_boards`. Si només es troba un element per semblança (mateix propietari, mateix títol, creat fa un moment), es retorna `confirmed: "probable"` amb un avís perquè es comprovi; no s'hi escriu res més. Només s'omet aquesta relectura en els rebutjos que l'aplicació fa abans de cridar Deck (regles d'eina, sessió, conflictes i arguments).

La supressió «només si està buida» no és atòmica: Deck no comprova `deleted_at` de la llista quan crea una targeta. Per tant, una targeta creada per una altra petició just després del segon recompte podria anar a la paperera juntament amb la llista o el tauler, des d'on es pot recuperar. Això s'indica a la descripció de l'eina i al gateway.

## Terminis

La creació i l'edició de targetes accepten `duedate` com `YYYY-MM-DD` o `YYYY-MM-DDTHH:MM[:SS]` amb `Z` o desplaçament numèric opcionals. L'entrada sense desplaçament utilitza la zona horària del compte; una data sense hora significa la mitjanit local d'aquell dia. Es rebutgen hores locals explícites inexistents per un canvi de zona horària. Una hora repetida selecciona el primer instant, tret que el desplaçament indicat resolgui l'ambigüitat. En desar es converteix a UTC abans de cridar Deck. Les respostes conserven `duedate` basat en dies, el venciment i `dueBefore`, i afegeixen `dueAt` amb el desplaçament del compte. Les edicions no relacionades conserven l'instant existent.

Algunes escriptures MCP anteriors afectades poden haver desat un dia incorrecte. Revisa aquelles targetes segons la data prevista i corregeix-les explícitament a Deck o amb una nova edició confirmada. No desplacis tots els terminis existents: les dates introduïdes per altres clients ja poden ser correctes. No es reescriu cap data històrica automàticament.

## Etiquetes i fitxers visibles de targetes

| Eina | Permís | Comportament |
| --- | --- | --- |
| `deck_list_labels` `{boardId, limit=50, offset=0}` | deck.read | Etiquetes d'un tauler llegible, fins a 200 per pàgina. |
| `deck_edit_card_labels` `{cardId, add?, remove?}` | deck.edit | ID d'etiquetes incrementals, fins a 100 per llista. Conserva les etiquetes no relacionades; els duplicats/canvis ja satisfets queden sense canvis; es rebutgen etiquetes d'altres taulers i peticions add/remove que se superposen. |
| `deck_list_card_files` `{cardId}` | deck.read i files.read | Només associacions natives existents i visibles de fitxers, ordenades per `attachmentId`. Aquest ID de compartició/associació és diferent del `fileId` original. |
| `deck_unlink_card_file` `{cardId, attachmentId}` | deck.edit i files.read | Retira una associació nativa visible després de l'aprovació, repetint el rebut `file_state` exacte i `plan_state` del pla. Conserva el fitxer original i les comparticions independents; no requereix files.share. |

Les escriptures d'etiquetes i desenllaç requereixen un pla actualitzat, `confirm: true`, `plan_state` i aprovació per al tauler compartit quan correspongui. Les lectures/escriptures tornen a comprovar l'accés natiu. Les referències a fitxers també requereixen àmbit de Files, comprovacions d'etiquetes d'exclusió, restriccions de Guests i READ natiu del node entre els àlies de qui fa la crida. Les referències invisibles s'ometen o reben resposta de no existència, sense recomptes ni identificadors; la resposta de la targeta ja no exposa `attachmentCount` natiu. Les pujades antigues `deck_file` i les carpetes no s'admeten aquí. Crear una associació nativa nova utilitza l'operació d'enllaç autoritzada per separat que es descriu a continuació.

Desenllaçar revoca aquella associació per a tots els membres actuals i futurs de la targeta, també si la va crear un altre editor. Les comparticions independents poden mantenir l'accés; pots perdre el teu últim muntatge de Deck. No hi ha recuperació automàtica; restaurar l'associació pot requerir la interfície nativa de Deck. Un rebut obsolet o no vàlid es rebutja. Confirmar una associació ja absent no té efectes només si el rebut original és vàlid i el fitxer original encara és visible.

## Enllaç natiu de fitxers

`deck_link_card_file` enllaça un fitxer regular visible existent a una targeta activa de Deck. Requereix `deck.edit`, `files.read` i `files.share`, EDIT natiu de la targeta i READ/SHARE del fitxer. La previsualització identifica el propietari, si és una recompartició, el total actual de destinataris i fins a deu noms (80 caràcters Unicode cadascun). Cal aprovar-la amb `confirm: true`, el `plan_state` exacte i `confirm_shared` quan ho exigeixin les regles d'escriptura compartida existents.

L'associació nativa dona accés READ als membres actuals i futurs del tauler, inclosos els heretats de grups/Teams. No puja ni copia el fitxer. No canvia les comparticions independents existents. Un canvi del fitxer, origen, destinataris, política de compartició o permisos invalida la confirmació abans de l'escriptura nativa. Les comprovacions de política del nucli i de qui fa l'acció precedeixen l'expansió privilegiada de Teams; el privilegi temporal natiu de Circles es retira abans d'autoritzacions i escriptures posteriors, i als límits d'operació, inclosos els camins d'error. Una fallada de neteja rebutja abans d'escriure o informa d'un efecte incert després d'intentar l'escriptura.

L'operació rebutja convidats, carpetes, orígens arxivats, destinataris no resolts o no compatibles, compartició desactivada, compartició només amb grups, caducitat interna obligatòria, recompartició prohibida i destinataris de grup/Team quan compartir amb grups està desactivat. També rebutja si no pot determinar identitat o política. No suggereix desactivar aquelles proteccions. Es poden executar hooks i notificacions natius de compartició. La caducitat interna predeterminada opcional no s'aplica a aquest tipus de compartició nativa; la caducitat interna obligatòria es rebutja.

Es requereix un conjunt complet de destinataris: fins a 128 entrades ACL, 1.000 persones locals diferents, 5.000 passos de treball cooperatius i dos segons comprovats entre crides natives. No hi ha èxit amb destinataris parcials. L'assignació nativa de membres i l'E/S bloquejant queden fora d'aquests límits cooperatius. Una previsualització limitada de noms no limita la compartició: l'associació arriba a tots els destinataris natius i els seus membres futurs.

Els canvis de membres/política després de crear-la no provoquen revocació automàtica per MCP; la validació i la compartició nativa no són una transacció atòmica. Aquests límits també importen en interpretar un resultat d'escriptura incert. Inspecciona l'estat actual abans de reintentar; observar una compartició després d'un error no demostra qui la va crear. Igualment, que falti una associació després d'intentar crear-la no demostra que no hagi passat res: un hook o una altra persona podria haver-la retirat després que concedís accés o activés notificacions. Aquestes fallades continuen sent incertes i no autoritzen un reintent a cegues. Desenllaçar només retira l'associació nativa i el seu accés resultant; el fitxer i les comparticions independents es conserven.

La política de noms de fitxer regeix les escriptures de contingut i destinacions cobertes de Files/Notes; una associació nativa de Deck no és una nova escriptura de contingut. Continuen aplicant-se visibilitat, àmbit, exclusions de Files i política nativa de compartició.

## Dependències natives condicionals

Les tres eines de dependències només apareixen i s'executen amb API natives compatibles: NC34 amb Deck >=1.18.5 i <1.19.0, o NC35 amb Deck >=1.19.0 i <1.20.0, i els mètodes/propietats natius necessaris. Els objectius fixats de qualificació són Deck 1.18.5 i 1.19.0; admetre un interval no qualifica tots els pedaços. NC32/33 i formes d'API no compatibles ometen les tres eines i rebutgen les crides directes.

- `deck_read_card_dependencies` llegeix el subgraf complet de sortida llegible.
- `deck_add_card_dependency` afegeix `cardId` → `targetId`.
- `deck_remove_card_dependency` retira només aquella relació dirigida.

Amb `assignDependentCard(A,B)` natiu, B apareix a `dependentCards` d'A; aquestes relacions no bloquegen automàticament el progrés de les tasques. Llegir requereix deck.read; escriure requereix deck.edit, EDIT de l'origen i READ del destí, també per retirar. Les escriptures necessiten aprovació i el `plan_state` retornat. Les altres relacions no canvien; una addició duplicada o retirada inexistent no té efectes.

El recorregut es limita a 100 nodes llegibles inclosa l'arrel, 500 arestes llegibles i profunditat del camí més curt de 20. Superar un límit rebutja el resultat en lloc de retornar un graf incomplet. Els mateixos límits de nodes/arestes llegibles s'apliquen a la instantània completa d'adjacència de l'origen necessària per afegir, retirar i verificar resultats; els orígens massa grans s'han de modificar a la interfície nativa de Deck. Una relació nova també ha de complir aquests límits abans d'aprovar el pla o l'escriptura. L'autorització del destí i les cerques d'entitats s'aturen al primer desbordament llegible. Deck encara materialitza cada adjacència nativa abans de filtrar, i filtrar relacions il·legibles pot exigir cerques addicionals; aquests límits no acoten el treball ni la memòria totals. Les targetes il·legibles no consumeixen pressupost llegible ni revelen ID, recomptes, identificadors ni indicadors de límits ocults. Es rebutgen autorelacions i cicles detectables a través de targetes llegibles; no es poden excloure cicles ocults ni canvis concurrents entre l'última comprovació i l'escriptura nativa. No és una garantia global d'absència de cicles.

Després d'un resultat incert o parcial d'etiquetes/dependències/enllaç/desenllaç, inspecciona l'estat actual de Deck abans d'actuar de nou. Observar l'estat demanat després d'un error no demostra qui el va canviar ni que es completés tota la petició; no repeteixis l'escriptura automàticament.

Consulta `mcp_guide` en viu per a l'esquema autoritatiu i les operacions disponibles.

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/deck.md) · [Español](../../../es/reference/tools/deck.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/deck.md) · [Português (Portugal)](../../../pt-PT/reference/tools/deck.md)
