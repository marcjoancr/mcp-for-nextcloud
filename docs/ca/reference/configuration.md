<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/configuration.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../en/reference/configuration.md).

# Configuració

Utilitza les interfícies d'administració i configuració personal per canviar la política. Escriure preferències de política directament amb `occ` evita les comprovacions de revisió, la resolució de grups i la revocació de credencials; no és una manera admesa d'administrar la política. Els diagnòstics de només lectura, com ara `occ config:app:get mcp service_enabled`, continuen sent útils.

| Emmagatzematge | Clau o responsable | Significat |
| --- | --- | --- |
| Aplicació `mcp` | `service_enabled` | `1` activat; `0` o absent, desactivat. Canvia-ho amb l'ordre/interfície del servei. |
| Usuari, aplicació `mcp` | `eligible` | Excepció individual `1`/`0` de **Pot connectar-se**; l'absència permet herència de grup i després el valor predeterminat desactivat. |
| Usuari, aplicació `mcp` | `connected` | `1` indica connexió personal activa. És diferent d'una credencial OAuth vigent. |
| Usuari, aplicació `mcp` | `grant_<module>_<operation>` | Excepció individual antiga d'operació utilitzada pels valors de compatibilitat de les eines originals. L'accés actual també requereix assignació d'eina i selecció de connexió; Mail, Forms i registres comencen denegats. |
| Regles d'eines | `mcp_tool_grants` | Assignacions individuals/de grup: Permet, Bloqueja o herència neutra, amb la precedència descrita a permisos. |
| Seleccions de connexió | `mcp_oauth_tools` | Noms d'eina seleccionats per connexió OAuth; els clients Basic/de sessió comparteixen el perfil Nextcloud separat (ID de connexió zero). |
| Taules de política | `mcp_policy_state`, `mcp_policy_events` | Revisió/època global d'autenticació i esdeveniments persistents d'auditoria, neteja i rebuts. |
| Taules de grups | `mcp_group_grants`, `mcp_group_life` | Política que només concedeix permisos i identitat/cicle de vida de grup observats. |
| Taules d'autenticació | `mcp_auth_users`, `mcp_auth_recheck` | Generacions/invalidacions de credencials per usuari i comprovacions diferides del cicle de vida. |
| Taules de recursos | `mcp_scope_modes`, `mcp_scope_items` | Restriccions independents de Files, Calendar i Tasks. |
| Taula de clients | `mcp_oauth_clients` | Estat autoritatiu i generacions de clients natius/personalitzats gestionats després de migrar. |
| Aplicació `mcp` | `oauth_client_hosts` | Hosts exactes de clients publicats; absent o buit utilitza `claude.ai,chatgpt.com`. Edita-ho a clients OAuth. |
| Entrades antigues de l'aplicació | `oauth_native_client_enabled`, `oauth_custom_clients` | Entrades de migració per a la taula de clients, no l'autoritat vigent dels clients gestionats. |
| Taula pròpia | `mcp_logs_groups` | Grups JSON canònics i versió; buit significa només administradors. Importa la configuració antiga a la primera lectura. |
| Aplicació `mcp` | `checkout_max_bytes` | Límit de pujada de checkout en bytes; predeterminat 50 MiB, limitat per `post_max_size` de PHP. |
| Aplicació `mcp` | `file_write_policy` | Llista opcional versionada de noms de fitxer permesos; absent vol dir desactivada. Canvia-la a Protecció de dades; un estat no vàlid/il·legible rebutja les escriptures afectades. |
| Aplicació `mcp` | `backup_settings` | Document versió 1 amb la carpeta actual a l'arrel, els noms històrics reservats i la preferència de còpies de Files. Absent: `MCP backups`, còpies actives. Canvia'l a Protecció de dades; un estat malformat/no disponible rebutja les operacions afectades. |
| Aplicació `mcp` | `hidden_system_tags` | Llista JSON d'ID d'etiquetes de sistema excloses; `[]` desactiva les exclusions per etiquetes. |
| Aplicació `mcp` | `logs_hide_file_names` | `1` predeterminat: amaga text dels missatges de registre mentre hi hagi exclusions; `0` permet el registre complet per al diagnòstic. |

## Còpies de seguretat MCP

A administració → **Protecció de dades** → **Còpies de seguretat MCP**, tria un únic nom de carpeta directament sota l'arrel personal de Files de cada usuari que executa l'acció. El valor predeterminat és `MCP backups`; s'admet una carpeta amb punt inicial com `.mcp-backups`. Els noms es normalitzen a Unicode NFC, es limiten a 255 bytes UTF-8 i els valida Nextcloud. Es rebutgen camins, separadors, caràcters de control, `.` i `..`.

Canviar el nom no mou ni elimina les còpies existents. La carpeta es crea amb la primera còpia. Tots els noms configurats anteriorment, inclòs `MCP backups`, continuen reservats contra escriptures MCP de Files a l'arrel de qui fa l'acció; les carpetes ordinàries imbricades amb el mateix nom no es reserven automàticament. Les exclusions existents continuen protegint les còpies d'originals ocults, inclosos els àlies compartits canònics. Això no és una ACL del sistema de fitxers ni una prohibició general d'escriptura a través de muntatges compartits canviats de nom.

Les còpies prèvies a l'escriptura de Files estan actives per defecte. L'administrador només les pot suspendre si Versions està habilitada; la disponibilitat es torna a comprovar per a qui fa l'acció. Si Versions és desconeguda o no està disponible, les còpies són obligatòries i mai no s'habilita una escriptura que ja rebutgés el requisit previ de Versions. El control de versions natiu segueix la seva pròpia política i no garanteix recuperar qualsevol fitxer o emmagatzematge. Els plans i rebuts mostren un camí de còpia buit quan no es farà/no s'ha fet una còpia MCP i no prometen recuperació MCP. Suprimir contactes sempre requereix una còpia vCard completa verificada sota `/<configured folder>/Contacts`, independentment de l'opció de Files.

L'API d'administració utilitza GET/PUT `/api/backup-settings`, amb autenticació i comprovacions d'administrador i CSRF. PUT envia els valors complets `folder`, `copies` booleà i `expectedRevision` de tipus cadena. Si manca la revisió o és obsoleta, retorna 428/409. El panell conserva les dades no desades després d'un conflicte o resultat incert i exigeix una recàrrega verificada abans de tornar a desar. Les respostes verifiquen que la revisió encara coincideixi amb la configuració; una confirmació concurrent durant la preparació de la resposta exigeix una altra lectura. Una configuració corrupta no torna als valors predeterminats ni perd reserves històriques; recuperar-la exigeix investigar i restaurar l'últim document complet conegut, no eliminar la clau.

Les escriptures rellegeixen les reserves als límits de mutació, inclosos els enllaços de checkout i desfer lots. Desfer comprova tots els extrems registrats abans de moure res i conserva els directoris registrats que han passat a estar reservats. Desar la política i les escriptures natives del sistema de fitxers no són una transacció atòmica: un canvi concurrent després de l'última comprovació queda fora d'aquesta garantia. Un rebuig després de copiar/crear pot deixar una còpia o fitxer buit del qual s'informa amb precisió; no s'intenta eliminar ni repetir automàticament.

## Política de tipus de fitxer

A administració → **Protecció de dades** → **Tipus de fitxer modificables via MCP**, tria **Sense restriccions (predeterminat)** o **Només les extensions de la llista**, revisa i desa. L'opció s'aplica globalment a les escriptures MCP de Files i Notes, independentment dels permisos concedits, permisos natius, restriccions de recursos, Guests, exclusions, bloquejos i confirmacions. Mai no permet una escriptura que altrament es rebutjaria. És una regla de nom/extensió, no inspecció MIME/contingut, un detector de programari maliciós ni protecció contra injecció d'instruccions; no cobreix altres clients.

La llista permet fins a 128 extensions úniques, separades per comes o línies. El punt inicial és opcional; els valors es passen a minúscules i s'ordenen. Cadascun conté d'1 a 32 lletres/dígits ASCII, `_` o `-`, començant per lletra/dígit. S'avalua l'última extensió del nom final resolt, sense distingir majúscules. Els noms sense extensió (inclòs un nom amb punt inicial sense cap punt posterior) necessiten l'opció explícita separada, desactivada per defecte. Amb la política activa es rebutgen noms buits, amb punts/espais finals o malformats; els noms de carpeta no es filtren per extensió.

Cobreix creació/pujada, edició i substitució, pujada de checkout, restauració de versions, còpia/moviment, lots ordenats i desfer, i creació/edició/edició parcial/moviment de Notes. Les còpies internes de recuperació de Files i les obligatòries de Contacts n'estan exemptes; una escriptura pública amb `.bak` o `.vcf` no té exempció. La previsualització i la confirmació comproven la política; als límits de mutació es llegeix de nou, de manera que una pujada de checkout acabada de bloquejar es rebutja i un desfer bloquejat conserva les dades i el rebut. Una configuració no disponible o malformada denega l'operació. Un desament incert no es repeteix automàticament; utilitza el flux de lectura/reintent de la interfície per inspeccionar què s'ha desat.

Amb la política activa, les operacions de carpeta comproven prèviament fins a 2.000 nodes inspeccionats/projectats per límit; les còpies mantenen també el límit d'1 GiB. Copiar una carpeta comprova l'arbre visible/llegible i copia a partir del manifest comprovat, amb comprovacions noves per entrada. Les incorporacions posteriors s'ometen; una fallada pot deixar una còpia parcial de la qual s'informa i que mai no s'elimina automàticament. Moure una carpeta també comprova prèviament els descendents, però continua sent un moviment natiu únic: els fitxers que un altre client hi afegeixi després de l'última comprovació poden moure's amb la carpeta. Els canvis de política i les escriptures del sistema de fitxers no comparteixen cap transacció atòmica, i l'emmagatzematge/contingut natiu pot canviar després d'una comprovació. Els canvis de visibilitat poden amagar detalls obsolets del rebut conservant les dades ja escrites. Revisa els resultats incerts abans d'una nova acció; no es promet reversió automàtica ni repetició segura.

Aquestes taules descriuen persistència, no una API pública de mutació SQL. Editar files o restaurar només algunes taules pot trencar les garanties de revisió, cicle de vida i revocació. Consulta [permisos](../guides/permissions.md), [OAuth](oauth.md) i les [indicacions d'actualització/recuperació](../installation.md).

Nextcloud controla `enabled`, `installed_version`, `types`, `levels` i `ocsid`; no són interruptors de política MCP. En particular, `enabled=yes` de Nextcloud és diferent de `service_enabled=1`.

[Guies operatives](../guides/README.md).

[English](../../en/reference/configuration.md) · [Español](../../es/reference/configuration.md) · Català · [Português (Brasil)](../../pt-BR/reference/configuration.md) · [Português (Portugal)](../../pt-PT/reference/configuration.md)

## Lliurament del codi font

Un operador configura per separat la destinació del codi font mitjançant la configuració de sistema de Nextcloud (`config.php`), després de preparar el kit complet:

```php
'mcp_source_offer' => [
    'version' => '2.7.0',
    'sourceCommit' => 'FULL_40_CHARACTER_COMMIT_SHA',
    'manifestSha256' => 'SEPARATELY_TRUSTED_64_CHARACTER_MANIFEST_SHA256',
    'baseUrl' => 'https://sources.example.invalid/immutable-release-directory',
],
```

Aquesta opció de sistema són metadades de versió, no una preferència d'usuari o capacitat. Substitueix la revisió i el resum de mostra pels del kit aprovat; substitueix la destinació d'exemple per un directori que serveixi els seus cinc fitxers: `manifest.json`, `source.bundle`, `runtime-dependencies.tar.gz`, `mcp-<version>.tar.gz` i `BUILDING.md`. Conserva el resum esperat en un registre d'operador de confiança independent, fora dels fitxers de l'aplicació coberts pel manifest.

La versió i el commit de codi font han de coincidir amb `appinfo/source.json` generat al paquet i amb la versió instal·lada. Els paquets de desenvolupament amb canvis sense confirmar no tenen identitat de codi font. Una configuració absent, no vàlida o no coincident mostra una oferta no disponible a la configuració personal i al descobriment MCP; no concedeix accés ni canvia l'autenticació. Una oferta configurada encara necessita una recuperació correcta i verificació del resum abans d'acceptar la versió.

Utilitza HTTPS sense credencials, consulta, fragment, codificació percentual ni segments de travessa. HTTP de loopback literal es reserva per a perfils d'acceptació aïllats. No publiquis un URL de prova com a destinació de versió ni enviïs credencials de Nextcloud a un host de codi font. L'aplicació només presenta els enllaços; no els recupera. La publicació pública, el comportament d'integritat admès i la qualificació d'actualització/recuperació del host de destinació continuen sent requisits separats per publicar una versió.
