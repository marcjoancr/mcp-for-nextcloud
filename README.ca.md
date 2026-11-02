<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: README.md -->
<!-- translation-source-revision: b93ec95263f3db1d7b208b7cb3c17cef2e1f3f20 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](README.md).

# Nextcloud MCP

[English](README.md) · [Español](README.es.md) · **Català** · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)

[![CI](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml?query=branch%3Amain) · **Nextcloud 33–35** · **PHP 8.4+** · [AGPL-3.0-or-later](LICENSE)

Connecta un client MCP al teu Nextcloud. Cerca fitxers, llegeix notes, segueix converses del Talk o gestiona tasques des d’un únic punt d’accés. Cada petició respecta els permisos de qui ha iniciat la sessió.

**Prova-ho:** a ChatGPT o Claude, pregunta «Quines converses del Talk tenen missatges sense llegir?». La guia mostra quines eines pots fer servir amb el teu compte. L’administrador pot limitar tant les eines com els recursos als quals accedeixen.

Nextcloud MCP és un fork independent de [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud), de Sintax (Jhonatan Jaworski). El desenvolupament es fa a [`main`](https://github.com/marcjoancr/mcp-for-nextcloud/tree/main).

La **versió 2.3.1** conserva l’identificador `mcp`. La botiga d’aplicacions del Nextcloud distribueix el projecte original. Consulta la guia d’instal·lació abans de substituir una instal·lació existent.

## Primers passos

URL del servidor · `https://cloud.example.com/apps/mcp/`

- [Instal·la el fork](docs/ca/installation.md)
- [Connecta un client](docs/ca/guides/clients.md)
- [Explora les eines](docs/ca/reference/tools.md)
- [Configura els permisos](docs/ca/guides/permissions.md)

[Documentació](docs/ca/README.md) · [Com contribuir-hi](CONTRIBUTING.md) · [Registre de canvis](CHANGELOG.md)

## Desenvolupament

Crea una branca a partir de `main` i adreça la sol·licitud de fusió a aquest fork. La [guia d’arquitectura (en anglès)](docs/en/development/architecture-specification.md) explica com s’organitzen les eines de Files, Deck, Talk i els altres mòduls, i com comparteixen el transport MCP.

## Llicència

AGPL-3.0-or-later, amb el terme addicional d'atribució de la secció 7(b) a [NOTICE](NOTICE). La llicència completa és a [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). En redistribuir o oferir versions modificades a través d'una xarxa, conserva l'atribució de Sintax a la pàgina d'administració, a la documentació i als avisos de drets d'autor.

## Crèdits

El paquet inclou Smalot PdfParser, Symfony Polyfill Mbstring i l'autoloader de Composer. Consulta les versions, llicències, drets d'autor i dependències de desenvolupament a [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Col·laboradors.** Gràcies a @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) per la comprovació de visibilitat de l'arrel i a @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) per l'informe que va donar lloc a l'auditoria d'etiquetes ocultes. Gràcies també a @matt-ek per la traducció alemanya de la interfície ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) i a @Zegorax pels clients OAuth registrats per administradors ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).


Jerarquia de tasques: [@mturgeonferland](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18).

Sintax / Jhonatan Jaworski · [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw).

Developed by Sintax — https://sintax.tech
