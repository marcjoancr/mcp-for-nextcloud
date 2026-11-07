<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: mcp/README.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

**Traducció pendent de revisió humana:** aquesta pàgina és una traducció al català. Consulta la [pàgina original en anglès](README.md).

# Nextcloud MCP

**El teu Nextcloud, dins de la conversa.** Cerca fitxers, treballa amb notes, calendaris, contactes i tasques, gestiona targetes de Deck i posa't al dia del Talk des d'un client MCP. Mail exposa comptes propis i recomptes de carpetes en memòria cau; els operadors autoritzats poden consultar els registres del servidor.

[English](README.md) · [Español](README.es.md) · **Català** · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)

## Primers passos

Requereix **Nextcloud 33–35** i **PHP 8.4+**. Aquest fork mantingut independentment de [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud) declara la versió **2.0.0** i conserva l'identificador `mcp`. L'edició de la botiga és el projecte original; fes servir el paquet revisat d'aquest fork per instal·lar-lo o substituir-la.

1. [Instal·la l'aplicació](../docs/ca/installation.md).
2. [Habilita el servei, l'elegibilitat de l'usuari i la connexió personal](../docs/ca/guides/permissions.md#habilitar-laccés).
3. [Connecta un client](../docs/ca/guides/clients.md) a `https://cloud.example.com/apps/mcp/` amb OAuth o una contrasenya d'aplicació.
4. Demana la **Guia d'eines** i prova «Quines converses del Talk tenen missatges sense llegir?» o «Troba la proposta del projecte i resumeix-la». Són exemples de peticions, no transcripcions enregistrades.

## L'accés continua sota el teu control

Fins a **86 eines** de deu mòduls, incloses guia i estat, es filtren segons les aplicacions disponibles i els permisos del compte. Els permisos natius de Nextcloud s'apliquen a cada petició. Els administradors poden limitar l'accés a carpetes, calendaris i llistes de tasques seleccionats i excloure fitxers etiquetats. Les eines d'escriptura retornen una previsualització si no es confirmen explícitament; l'assistent ha d'obtenir l'aprovació de l'usuari. No hi ha cap eina de supressió de fitxers. Mail i els registres són de només lectura i requereixen permisos explícits.

Els resultats de les eines s'envien al client d'IA triat i queden subjectes a les seves polítiques de tractament i retenció.

[Eines](../docs/ca/reference/tools.md) · [Permisos](../docs/ca/guides/permissions.md) · [Protecció de fitxers](../docs/ca/guides/file-protection.md) · [Resolució de problemes](../docs/ca/guides/troubleshooting.md)

## Llicència

AGPL-3.0-or-later, amb el terme addicional d'atribució de la secció 7(b) a [NOTICE](NOTICE). Consulta la llicència completa a [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). En redistribuir o oferir versions modificades a través d'una xarxa, conserva l'atribució a la pàgina d'administració, a la documentació i als avisos de drets d'autor.

## Crèdits

Smalot PdfParser, Symfony Polyfill Mbstring i l'autoloader de Composer estan inclosos. Consulta les versions, els drets d'autor, les llicències i els crèdits de desenvolupament separats a [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

**Col·laboradors.** Gràcies a @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) per la comprovació de visibilitat de l'arrel i a @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) per l'informe que va donar lloc a l'auditoria d'etiquetes ocultes. Gràcies també a @matt-ek per la traducció alemanya de la interfície ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) i a @Zegorax pels clients OAuth registrats per administradors ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).


Jerarquia de tasques: [@mturgeonferland](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18).

La documentació original també reconeix la contribució de Sintax/Jhonatan Jaworski a [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw). Conservem aquest crèdit històric sense donar a entendre que XERJ hagi revisat aquest fork.

Developed by Sintax — https://sintax.tech
