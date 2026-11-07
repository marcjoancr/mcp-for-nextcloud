<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/tasks.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/tasks.md).

# Tasques

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Tasques** | llista calendaris de tasques i tasques VTODO, incloses les que no tenen data i, opcionalment, filtrades per categoria o pare directe; llegeix tasques amb categories i UID del pare | crea, edita, completa i suprimeix tasques simples mitjançant CalDAV, incloses categories i enllaços al pare; la supressió fa servir la paperera del calendari i es rebutja si la retenció és zero |

## Comportament de pares i subtasques

`tasks_create_task` i `tasks_edit_task` accepten `parent`: un UID estableix el pare, `null` fa que la tasca sigui de primer nivell i ometre'l conserva l'enllaç. El pare ha de ser un VTODO actiu i llegible del mateix calendari. Els resultats de lectura/llistat/escriptura inclouen `parent` (UID o null). `tasks_list_tasks` accepta un UID de pare per llistar només fills directes, aplicant aquest filtre abans de paginar i juntament amb els filtres de categoria/completades. No llista descendents recursivament.

L'enllaç utilitza `RELATED-TO;RELTYPE=PARENT`; una relació sense RELTYPE també es llegeix com a pare. Canviar o eliminar un pare conserva CHILD, SIBLING, relacions personalitzades i propietats no relacionades. Els plans mostren títols llegibles amb UID per distingir pares amb noms idèntics. Els pares privats/confidencials d'un calendari compartit no es distingeixen dels inexistents.

La previsualització i la confirmació rebutgen cicles i imbricació superior a 64 nivells, inclosos els descendents d'una tasca moguda. Les escriptures MCP confirmades que estableixen un pare se serialitzen per calendari. Si el bloqueig de fitxers del servidor està desactivat, es rebutgen; eliminar el pare continua disponible per reparar una jerarquia. Altres clients CalDAV encara poden canviar tasques relacionades durant una escriptura. Si la lectura posterior detecta un bucle a través de la tasca desada, la resposta informa del desament correcte amb un avís de reparació. No és una transacció atòmica de jerarquia entre clients.

Completar o suprimir un pare no completa, suprimeix ni mou les seves subtasques. Les tasques recurrents es poden llegir i utilitzar com a pares, però es continua rebutjant editar una sèrie recurrent o una tasca amb participants. Continuen aplicant-se les comprovacions existents d'ETag, àmbit, compartició i confirmació.

[Funcionament de Contactes i Tasques](contacts-tasks.md).

Consulta `mcp_guide` per obtenir l'esquema autoritatiu i les operacions disponibles.

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/tasks.md) · [Español](../../../es/reference/tools/tasks.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/tasks.md) · [Português (Portugal)](../../../pt-PT/reference/tools/tasks.md)
