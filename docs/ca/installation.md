<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../en/installation.md).

# Instal·lació

Nextcloud MCP s'executa com una aplicació nativa de Nextcloud. Utilitza el PHP i l'emmagatzematge del servidor existent; no necessita cap dimoni MCP separat ni entorn Node.js.

## Requisits

| Component | Requisit |
| --- | --- |
| Nextcloud | **33–35**, segons el manifest de l'aplicació |
| PHP | **8.4 o posterior**, també compatible amb la versió de Nextcloud triada |
| Extensions PHP | `zip`, `mbstring` i `dom`, a més dels requisits de Nextcloud |
| Accés del client | Un URL HTTPS accessible pel client MCP triat |
| Aplicacions opcionals | Habilita les aplicacions corresponents per a Notes, Tasks, Calendar, Contacts, Deck, Talk, Mail i Forms |

Les eines també depenen d'assignacions individuals, la selecció de la connexió i els permisos natius dels recursos. Mail necessita una versió coneguda d'almenys 5.0.0; Forms necessita una versió coneguda d'almenys 5.3.0. Les seves eines necessiten assignació i selecció explícites, i aquests mínims d'API no certifiquen totes les combinacions d'aplicació/servidor. Les eines de dependències de Deck necessiten API compatibles a NC34/35. Consulta el [catàleg d'eines](reference/tools.md).

Les eines de col·laboració també proven PHP 8.2/8.3; aquests entorns de prova no redueixen el requisit d'instal·lació de PHP 8.4. La matriu nativa cobreix Linux x86-64/Apache amb NC33/34/35 i MariaDB 10.11, i NC35 amb PostgreSQL 16 o SQLite. Un perfil LinuxServer/Nginx separat prova NC35/PostgreSQL/S3. Són perfils de prova, no una garantia exhaustiva de compatibilitat de desplegament.

## Tria el paquet del fork

El fork conserva l'identificador **`mcp`** i el manifest declara **2.7.0**. Substitueix una instal·lació existent de MCP for Cloud amb aquest mateix identificador. La botiga de Nextcloud distribueix el projecte original: **`occ app:install mcp` instal·la l'edició de la botiga**.

Utilitza `mcp-2.7.0.tar.gz` construït a partir de la revisió revisada del fork, amb el kit de codi font corresponent i el resum del manifest de confiança independent. Un arxiu de codi font de GitHub o una còpia del directori `mcp/` sense construir no són paquets d'instal·lació. El repositori complet conté les instruccions d'empaquetament a `docs/en/development/packaging.md` i `docs/en/development/source-delivery.md` (en anglès).

## Instal·lació nova

Per a una instància sense `mcp` instal·lat:

1. Verifica el paquet i el kit de codi font corresponent amb el resum de confiança del manifest.
2. Extreu l'únic directori `mcp/` de l'arxiu en una arrel d'aplicacions configurada a `apps_paths` de Nextcloud. Configura propietat i accés de lectura segons els rols de servei i manteniment del teu desplegament.
3. Com a usuari de manteniment de Nextcloud, executa `php /absolute/path/to/nextcloud/occ app:enable mcp` amb el binari PHP triat per a aquella instància. Els desplegaments amb contenidors executen l'ordre equivalent dins del seu entorn de manteniment.
4. Obre **Administració → Nextcloud MCP**. Habilita el servei, permet que els usuaris previstos es connectin i assigna les eines individuals que poden fer servir.
5. Cada usuari habilita la connexió a **Configuració personal → Nextcloud MCP**, selecciona les eines per al client i segueix la [configuració de clients](guides/clients.md).

Fes servir el punt d'accés mostrat a la Visió general d'administració, normalment:

```text
https://cloud.example.com/apps/mcp/
```

Inclou el subdirectori d'instal·lació de Nextcloud si n'hi ha. El servei, l'elegibilitat de l'usuari i la connexió personal comencen desactivats, també per als administradors. Les escriptures comencen denegades; Mail, Forms i els registres del servidor també necessiten permisos explícits. Les eines noves comencen sense assignació ni selecció. Consulta [permisos i administració](guides/permissions.md).

Les respostes de les eines arriben al client d'IA connectat. Tria permisos i restriccions de recursos segons les dades que aquell client ha de rebre; allotjar el connector no determina la política de tractament o retenció de dades del client.

## Actualització i recuperació

Abans d'actualitzar, prepara una còpia coherent de l'aplicació, base de dades i configuració/dades necessàries de Nextcloud, seguint el procediment de còpies de l'operador. Assaja la substitució i recuperació en un perfil aïllat equivalent i impedeix que les actualitzacions automàtiques de la botiga substitueixin el fork.

El mode de manteniment impedeix trànsit durant la substitució sense canviar la política de servei MCP ni revocar credencials per una actualització rutinària. Substitueix tot el directori de l'aplicació, executa l'actualització nativa `occ upgrade`, neteja/reinicia les memòries cau PHP quan calgui i comprova historial de migracions, recursos d'interfície, permisos i connexions existents abans de reobrir el trànsit. Mantén les metadades de lliurament del codi font alineades amb el kit instal·lat.

Protegeix l'arrel efectiva de l'aplicació, els seus avantpassats modificables i la configuració que selecciona `apps_paths`. Un muntatge de només lectura a `mcp/` no impedeix que un procés seleccioni codi substitutiu si el pare o la configuració continuen sent modificables. Separa l'accés del servei del manteniment de l'operador quan el desplegament ho requereixi.

Prioritza reparar cap endavant després d'una actualització fallida. Revertir només els fitxers d'aplicació a 1.0.8 és insegur: aquell codi no aplica les noves revocacions per època/generació ni restriccions de recursos. Una còpia de base de dades també pot contenir credencials revocades després de crear-la.

Per a una recuperació coordinada, mantén Nextcloud en manteniment mentre restaures estats corresponents d'aplicació i base de dades i la configuració/dades necessàries. Abans de reobrir MCP, invalida codis/tokens OAuth restaurats mitjançant les ordres de política admeses, desconnecta explícitament els comptes que s'hagin de reconnectar i revisa permisos efectius i restriccions de recursos. Revisa/revoca per separat les contrasenyes d'aplicació de Nextcloud a Seguretat. Tornar a habilitar el servei no substitueix aquesta revisió. Una versió antiga que no apliqui les restriccions necessàries no es pot reobrir per a aquests usuaris.

La suite nativa d'un sol ús assaja restaurar aplicació/SQLite de la mateixa versió, rebutjar credencials antigues i reconnectar explícitament. No certifica les eines de còpia de l'operador, una reversió només de fitxers ni totes les configuracions d'emmagatzematge de producció.

[Guies operatives](guides/README.md).

[English](../en/installation.md) · [Español](../es/installation.md) · Català · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
