<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: a24d67927e4a1a55c6da5d64a09cb0bf3c870590 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](../../../en/reference/tools/talk.md).

# Talk

| Mòdul | Lectura | Escriptura |
| --- | --- | --- |
| **Talk** | converses i missatges (mai no marca res com a llegit) | respondre (opcionalment citant un missatge o enllaçant una targeta Deck o un esdeveniment de Calendar que puguis veure), enviar un missatge directe a un usuari, enviar un lot de missatges, compartir o citar un fitxer i crear una conversa de grup (permís separat, desactivat inicialment). **No s'envia res abans que l'usuari ho aprovi: una crida sense `confirm: true` només retorna el pla (destinatari, text final, adjunts i què es crearia o compartiria), sense executar res; amb `confirm: true` torna a comprovar els permisos i envia el contingut. El servidor no desa cap identificador d'aprovació, testimoni ni registre.** |

## Llegir missatges anteriors

`talk_read_messages` retorna primer la pàgina més recent. Dins de cada pàgina, els missatges van del més antic al més recent. Per retrocedir, passa el `next_before_message_id` rebut com a `before_message_id` amb el mateix `conversation_token`. La pàgina exclou el missatge del cursor. Un cursor nul indica el principi de l’historial disponible. Cada pàgina torna a comprovar l’accés i conserva els missatges no llegits i les notificacions.

Consulta `mcp_guide` per obtenir l'esquema autoritatiu i les operacions disponibles.

[Guies operatives](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · [Español](../../../es/reference/tools/talk.md) · Català · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)

`forms_read_responses` consulta respostes amb `forms.read_responses` i el permís natiu per veure resultats. `talk_publish_summary` publica el text `message` en una conversa existent amb el permís independent `talk.publish_summary`. Mostra el text complet i el destí abans de repetir la crida amb `confirm: true` i el `plan_state` rebut. Si canvien, cal una altra vista prèvia.
