<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/forms.md -->
<!-- translation-source-revision: ad44b6fd690c2e0063884da06ef5e1117cec64b6 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** consulta la [versió en anglès](../../../en/reference/tools/forms.md).

# Forms

Les eines de Forms requereixen Forms 5.3.0 o posterior, disponibilitat per al compte autenticat, assignacions explícites d'eines i selecció a la connexió. Els permisos natius de Forms es tornen a comprovar a cada petició.

`forms_list` llista els formularis accessibles; `forms_read` llegeix preguntes i opcions. Totes dues utilitzen `forms.read`. `forms_read_responses` requereix l'operació separada `forms.read_responses` i el permís natiu per veure resultats. Poder enviar un formulari o veure només la pròpia resposta no concedeix accés al conjunt complet de resultats.

## Resumir respostes

`forms_summarize_responses` requereix `forms.summarize_responses`. Passa `id`, amb `offset` i `limit` opcionals (50 enviaments per defecte, màxim 100). Retorna recomptes deterministes de respostes per pregunta d'aquella pàgina, amb `submissionsAnalyzed`, `partial`, `hasMore` i `nextOffset`. Segueix l'offset retornat per continuar; un resum de pàgina no és un resum de tot el formulari. Es consideren com a màxim 50 registres de metadades de preguntes i 200 respostes per enviament, amb un límit de 8.000 respostes examinades i 4 MiB de text de resposta. Els límits de text i exploració també poden fer que els resultats siguin parcials.

El resum no conté identificadors dels participants. Les respostes de fitxers pujats s'ometen; no s'obre ni es baixa cap fitxer. El permís natiu de resultats és necessari encara que qui fa la crida ja pugui llegir les preguntes del formulari.

## Exportar respostes a Files

`forms_export_responses` requereix `forms.export_responses` **i** `files.create`, i accés als resultats del formulari i a la carpeta de destinació. Passa `id`, un `path` de destinació `.csv` nou, i `offset` i `limit` opcionals (50 enviaments per defecte, màxim 100).

La primera crida prepara una previsualització sense escriure. Mostra a l'usuari la pàgina d'origen, la destinació i l'avís de resultat parcial. Després de l'aprovació, repeteix els mateixos arguments amb `confirm: true` i l'estat del pla retornat. Si canvien la pàgina d'origen o la destinació, cal una nova previsualització. Una destinació compartida també requereix la confirmació existent de recursos compartits de Files. No se sobreescriuen fitxers existents.

El CSV té una fila per resposta, amb identificador d'enviament, marca de temps, identificador de pregunta, pregunta i resposta. S'exclouen les identitats dels participants i les respostes de fitxers pujats. Es neutralitzen les cel·les que podrien ser fórmules de full de càlcul. L'exportació es limita a 1 MiB i 8.000 files; els resultats parcials s'indiquen explícitament. Les cel·les de text de pregunta i resposta es limiten a 1.024 bytes, amb detalls de truncament i files omeses a la previsualització. Continuen aplicant-se les restriccions d'àmbit de Files i els permisos natius de la carpeta.

## Fluxos natius qualificats

El catàleg defineix 65 eines de Forms. La disponibilitat depèn de l'extensió
nativa, dels permisos actuals i de la selecció de la connexió.
`mcp_guide` amb `module: "forms"` mostra els esquemes i permisos;
`forms_capabilities` informa de la compatibilitat i els límits.
Les cinc eines anteriors mantenen el mínim de Forms 5.3. Les funcions ampliades
requereixen l'extensió independent de Forms 5.4.2 i una combinació qualificada de
Nextcloud, base de dades, emmagatzematge i escriptors natius. La versió per si
sola no les habilita. Consulta la
[guia d'instal·lació i recuperació](../../../../native/forms-guard/README.md).

L'ampliació inclou cerca i ajustos, creació i clonació, estat i bloquejos,
preguntes i opcions, compartició i enllaços, respostes pròpies i resultats,
fitxers, exportacions, comentaris, administració, transferència, supressió i
recuperació. La clonació reinicia respostes, comparticions i exportació vinculada.
Es mantenen les regles natives de propietat i bloqueig permanent.
Hi ha 12 tipus de pregunta que es poden crear; `datetime` només es conserva
per a lectura i resposta. El tipus d'una pregunta és immutable. Les graelles
requereixen `radio`, `checkbox` o `number`. La
[referència completa en anglès](../../../en/reference/tools/forms.md) enumera
els tipus i les eines.

## Permisos i confirmació

Les operacions noves comencen denegades i les eines noves sense assignar ni
seleccionar, també després d'actualitzar. Llegir l'estructura no concedeix accés
a ajustos, enllaços, resultats, identitats ni fitxers. Llegir les respostes pròpies
no permet llegir les dels altres. S'apliquen els rols natius, àmbits de recursos,
exclusions i restriccions de convidats.

L'audiència i els enllaços públics requereixen `forms.public_link`; els correus
de confirmació afegeixen `forms.configure_notifications`. Compartir fitxers
pot afegir `files.share`. Participants i fitxers tenen permisos independents.
Les exportacions requereixen els permisos de Files declarats i accés al destí.
Continuar, cancel·lar i consultar evidències revalida els permisos i la selecció
actuals de l'acció original.

Revisa la previsualització i repeteix els mateixos arguments amb
`confirm: true`, `plan_state` i `operation_id`; afegeix
`confirm_shared: true` quan es demani. Els canvis d'origen, permisos, connexió
o destí exigeixen una previsualització nova. La previsualització no adquireix el
bloqueig natiu de quinze minuts. Generar tokens i preparar pujades també són
escriptures confirmades.

## Resultats complets i exportacions

Els treballs complets processen una instantània estable en passos acotats.
Continua l'operació original fins que la finalització estigui verificada; un
resultat pendent no és complet. S'admeten CSV, ODS i XLSX, amb anonimat i permisos
addicionals per a participants i fitxers. Es neutralitzen les fórmules.
Les descàrregues són d'un sol ús, vinculades a l'actor i la connexió, i duren
com a màxim cinc minuts. La memòria cau privada caduca als set dies; les
evidències i còpies de recuperació tenen un cicle independent.

Substituir un fitxer exigeix `overwrite`, l'`etag` aprovat, permís d'edició i
recuperació verificada. Una exportació contínua necessita una connexió OAuth
persistent i aprovar les escriptures inicials i futures. Els canvis d'audiència,
origen, destí o autoritat poden pausar-la. Desvincular conserva la sortida.

## Supressió i recuperació

Inspecciona totes les col·leccions amb `forms_read_operation_impact` abans de
confirmar. Els recomptes d'una còpia de pregunta descriuen la recuperació de tot
el formulari, no la supressió de totes les respostes. Les evidències
`authoring_effects_v1` de títol i compartició no són restaurables.

Suprimir una pregunta l'oculta i conserva les respostes i opcions històriques.
Només s'eliminen els fitxers exactes gestionats per Forms aprovats, amb còpia
verificada. La supressió del formulari també elimina els seus comentaris natius
i registres de compartició gestionats. Es conserven altres formularis, fitxers
fora del manifest i sortides vinculades. La transferència conserva originals i
evidències i informa separadament dels efectes de compartició i exportació.

`forms_restore` crea un formulari nou, privat i tancat; no torna a publicar
comentaris ni recrea tokens o treballs. Exigeix l'actor original i l'autoritat
nativa actual de recuperació. Una còpia obtinguda només amb permís d'edició no
concedeix accés als resultats. `forms_read_recovered_comments` requereix el
permís actual de comentaris i l'autoritat original; les identitats històriques
no verificades s'indiquen.

`bulk_v1:` és el format original. Una còpia posterior amb referències
històriques ja absents usa `bulk_history_v2:` i
`whole_form_with_unavailable_history`: declara el recompte i la recuperació
incompleta dels bytes històrics, sense inventar fitxers ni reutilitzar IDs
penjants. La còpia anterior a la neteja conserva els bytes verificats.

Davant una resposta perduda, consulta `forms_get_operation` amb l'ID exacte.
No repeteixis efectes incerts ni n'alliberis la reserva. Cancel·lar pot conservar
còpies i evidències i no desfà necessàriament canvis confirmats. Les fases de
base de dades, Files i esdeveniments s'informen separadament; un intent de
notificació no prova el lliurament.

[Totes les eines](../tools.md) · [Permisos](../../guides/permissions.md)

[English](../../../en/reference/tools/forms.md) · [Español](../../../es/reference/tools/forms.md) · **Català** · [Português (Brasil)](../../../pt-BR/reference/tools/forms.md) · [Português (Portugal)](../../../pt-PT/reference/tools/forms.md)
