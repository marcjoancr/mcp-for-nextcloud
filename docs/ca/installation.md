<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: d1a2caedc111cdb2f129ae4874291896c43a25fe -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../en/installation.md).

# Instal·lació

## Requisits

Nextcloud MCP 2.1.1 fa servir l’identificador `mcp` i substitueix qualsevol instal·lació prèvia de MCP for Cloud. La botiga del Nextcloud distribueix l’aplicació original. Per instal·lar el fork, fes servir el paquet compilat a partir de la revisió de codi verificada.

L’entorn de referència és Linux x86-64 amb Apache. Fes servir versions amb manteniment de seguretat i les extensions PHP `zip`, `mbstring` i `dom`. SQLite es fa servir en proves locals i assajos de recuperació.

| Nextcloud | PHP | Base de dades |
| --- | --- | --- |
| 35 | 8.5 | MariaDB 10.11 o PostgreSQL 16 |
| 34 | 8.5 | MariaDB 10.11 |
| 33 | 8.4 | MariaDB 10.11 |

Les eines disponibles depenen de les aplicacions del Nextcloud i dels permisos del compte. Les operacions de dependències del Deck necessiten API compatibles a NC34/35. Consulta el [catàleg d’eines](reference/tools.md).

### Instal·lar per SSH

Verifica `mcp-2.1.1.tar.gz` i el kit de codi font corresponent amb el resum de confiança del manifest. Assaja l’actualització i la recuperació en un entorn com el del teu servidor i comprova que les actualitzacions automàtiques conserven el fork. L’ordre `occ app:install mcp` instal·la el paquet original de la botiga.

Abans del desplegament, fes servir MCP Inspector per comprovar l’accés HTTPS, l’autorització i la revocació del client. Després, comprova que un client pot llegir la guia d’eines en una instància de prova d’un sol ús.

## Actualització i recuperació

Abans d'actualitzar, prepara una còpia coherent de l'aplicació, base de dades i configuració/dades necessàries de Nextcloud, seguint el procediment de l'operador. El mode de manteniment impedeix trànsit durant la substitució sense canviar la política de servei MCP ni revocar credencials per una actualització rutinària. Substitueix tot el directori de l'aplicació, executa l'actualització nativa necessària, neteja/reinicia les memòries cau PHP quan calgui i comprova historial de migracions, recursos d'interfície, permisos i connexions existents abans de reobrir el trànsit.

Prioritza reparar cap endavant després d'una actualització fallida. Revertir només els fitxers d'aplicació a 1.0.8 és insegur: aquell codi no aplica les noves revocacions per època/generació ni restriccions de recursos. Una còpia de base de dades també pot contenir credencials revocades després de crear-la.

Per a una recuperació coordinada, mantén Nextcloud en manteniment mentre restaures estats corresponents d'aplicació i base de dades i la configuració/dades necessàries. Abans de reobrir MCP, invalida codis/tokens OAuth restaurats mitjançant les ordres de política admeses, desconnecta explícitament els comptes que s'hagin de reconnectar i revisa permisos efectius i restriccions de recursos. Revisa/revoca per separat les contrasenyes d'aplicació de Nextcloud a Seguretat. Tornar a habilitar el servei no substitueix aquesta revisió. Una versió antiga que no apliqui les restriccions necessàries no es pot reobrir per a aquests usuaris.

La suite nativa d'un sol ús assaja restaurar aplicació/SQLite de la mateixa versió, rebutjar credencials antigues i reconnectar explícitament. No certifica les eines de còpia de l'operador, una reversió només de fitxers ni totes les configuracions d'emmagatzematge de producció.

[Guies operatives](guides/README.md).

[English](../en/installation.md) · [Español](../es/installation.md) · Català · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
