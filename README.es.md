<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: README.md -->
<!-- translation-source-revision: b93ec95263f3db1d7b208b7cb3c17cef2e1f3f20 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](README.md).

# Nextcloud MCP

[English](README.md) · **Español** · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)

[![CI](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml?query=branch%3Amain) · **Nextcloud 33–35** · **PHP 8.4+** · [AGPL-3.0-or-later](LICENSE)

Conecta un cliente MCP a tu Nextcloud. Busca archivos, lee notas, sigue conversaciones de Talk o gestiona tareas desde un único punto de acceso. Cada petición respeta los permisos de la persona que ha iniciado sesión.

**Pruébalo:** en ChatGPT o Claude, pregunta «¿Qué conversaciones de Talk tienen mensajes sin leer?». La guía muestra qué herramientas puedes usar con tu cuenta. El administrador puede limitar tanto las herramientas como los recursos a los que acceden.

Nextcloud MCP es un fork independiente de [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud), de Sintax (Jhonatan Jaworski). El desarrollo se realiza en [`main`](https://github.com/marcjoancr/mcp-for-nextcloud/tree/main).

La **versión 2.6.4** conserva el identificador `mcp`. La tienda de aplicaciones de Nextcloud distribuye el proyecto original. Consulta la guía de instalación antes de sustituir una instalación existente.

## Primeros pasos

URL del servidor · `https://cloud.example.com/apps/mcp/`

- [Instalar el fork](docs/es/installation.md)
- [Conectar un cliente](docs/es/guides/clients.md)
- [Explorar las herramientas](docs/es/reference/tools.md)
- [Configurar los permisos](docs/es/guides/permissions.md)

[Documentación](docs/es/README.md) · [Cómo contribuir](CONTRIBUTING.md) · [Registro de cambios](CHANGELOG.md)

## Desarrollo

Crea una rama desde `main` y dirige el pull request a este fork. La [guía de arquitectura (en inglés)](docs/en/development/architecture-specification.md) explica cómo se organizan las herramientas de Files, Deck, Talk y los demás módulos, y cómo comparten el transporte MCP.

## Licencia

AGPL-3.0-or-later, con la condición adicional de atribución de la sección 7(b) que aparece en [NOTICE](NOTICE). La licencia completa está en [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Al redistribuir o publicar en una red versiones modificadas, conserva la atribución de Sintax en la página de administración, la documentación y los avisos de copyright.

## Créditos

El paquete incluye Smalot PdfParser, Symfony Polyfill Mbstring y el cargador automático de Composer. Consulta las versiones, licencias, derechos de autor y dependencias de desarrollo en [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Colaboradores.** Gracias a @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) por la comprobación de visibilidad de la raíz y a @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) por el informe que motivó la auditoría de etiquetas ocultas. Gracias también a @matt-ek por la traducción alemana de la interfaz ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) y a @Zegorax por los clientes OAuth registrados por administradores ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).


Jerarquía de tareas: [@mturgeonferland](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18).

Sintax / Jhonatan Jaworski · [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw).

Developed by Sintax — https://sintax.tech
