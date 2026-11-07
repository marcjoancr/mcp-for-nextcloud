<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/talk.md).

# Talk

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Talk** | converses, cerca de missatges i context (mai no marca res com a llegit) | respondre (opcionalment citant un missatge o enllaçant una targeta Deck o un esdeveniment de Calendar que puguis veure), enviar un missatge directe a un usuari, enviar un lot de missatges, compartir o citar un fitxer i crear una conversa de grup (permís separat, desactivat inicialment). **No s'envia res abans que l'usuari ho aprovi: una crida sense `confirm: true` només retorna el pla (destinatari, text final, adjunts i què es crearia o compartiria), sense executar res; amb `confirm: true` torna a comprovar els permisos i envia el contingut. El servidor no desa cap identificador d'aprovació, testimoni ni registre.** |

## Publicar un resum preparat

`talk_publish_summary` publica el text `message` proporcionat per qui fa la crida en un `conversation_token` existent. Requereix el permís independent `talk.publish_summary`. Mostra la previsualització completa i la destinació a l'usuari i repeteix amb `confirm: true` i el `plan_state` retornat. L'usuari autenticat ha de continuar podent escriure a la conversa. Els canvis de text aprovat, destinació, participants o últim missatge requereixen una altra previsualització. Es rebutgen converses de més de 1.000 participants i converses individuals incompletes. Les comprovacions finals i l'enviament natiu són operacions separades, de manera que hi poden haver canvis concurrents entre elles. Un error de lliurament pot deixar un resultat incert. Comprova la conversa abans de reintentar.

Per a respostes de formularis, utilitza primer `forms_read_responses` amb `forms.read_responses` i permís natiu de resultats. L'assistent prepara un resum a partir de les respostes accessibles. Revisa la informació personal i les persones que poden llegir la destinació abans d'aprovar la publicació.

## Llegir missatges anteriors

`talk_read_messages` retorna primer la pàgina més recent, ordenada de més antic a més nou dins de cada pàgina. Per llegir la pàgina anterior, passa el `next_before_message_id` retornat com `before_message_id` amb el mateix `conversation_token`. El missatge del cursor s'exclou. Un cursor null indica que s'ha arribat al començament de l'historial disponible. Cada pàgina torna a comprovar l'accés i conserva els missatges no llegits i les notificacions.

## Cercar i llegir context

`talk_search_messages` cerca text de missatges ordinaris dins d'un `conversation_token`. Requereix l'operació independent `talk.search`. Els resultats són limitats i paginats; conserva la conversa i la consulta en seguir `next_offset`. `has_more` indica coincidències restants; `pagination_limited` distingeix el límit de seguretat d'offset del final dels resultats. La pàgina conté 50 coincidències per defecte, màxim 200. La cerca exclou esdeveniments de sistema, embolcalls d'adjunts, reaccions i respostes privades.

Utilitza l'ID de missatge d'un resultat amb `talk_read_message_context` per llegir missatges anteriors i posteriors de la mateixa conversa. Passa `message_id`, amb recomptes opcionals `before_limit` i `after_limit` (5 cadascun per defecte, màxim 100 cadascun). `has_more_before` i `has_more_after` indiquen missatges veïns addicionals fora de la finestra sol·licitada. És una assignació d'eina separada classificada com `talk.read`; concedir cerca no concedeix lectura de context. El context és cronològic i el missatge d'ancoratge ha de pertànyer a la conversa sol·licitada. Les comprovacions natives de pertinença i sala d'espera es tornen a aplicar a cada petició. Les referències a fitxers es filtren amb les mateixes comprovacions de visibilitat que l'historial ordinari.

Totes dues eines conserven recomptes de no llegits, notificacions i marcadors de lectura. Els resultats nous de cerca i context limiten el text a 4.000 caràcters i indiquen truncament. No tenen efectes d'enviament.

Consulta `mcp_guide` per obtenir l'esquema autoritatiu i les operacions disponibles.

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · [Español](../../../es/reference/tools/talk.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)
