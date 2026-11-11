<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/forms.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
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

[Totes les eines](../tools.md) · [Permisos](../../guides/permissions.md)

[English](../../../en/reference/tools/forms.md) · [Español](../../../es/reference/tools/forms.md) · **Català** · [Português (Brasil)](../../../pt-BR/reference/tools/forms.md) · [Português (Portugal)](../../../pt-PT/reference/tools/forms.md)
