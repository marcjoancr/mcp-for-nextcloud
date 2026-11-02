<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: b93ec95263f3db1d7b208b7cb3c17cef2e1f3f20 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../en/installation.md).

# Instalación

## Requisitos

Nextcloud MCP 2.2.0 usa el identificador `mcp` y sustituye cualquier instalación previa de MCP for Cloud. La tienda de Nextcloud distribuye la aplicación original. Para instalar el fork, usa el paquete compilado a partir de la revisión de código verificada.

El entorno de referencia es Linux x86-64 con Apache. Usa versiones con mantenimiento de seguridad y las extensiones PHP `zip`, `mbstring` y `dom`. SQLite se usa en pruebas locales y ensayos de recuperación.

| Nextcloud | PHP | Base de datos |
| --- | --- | --- |
| 35 | 8.5 | MariaDB 10.11 o PostgreSQL 16 |
| 34 | 8.5 | MariaDB 10.11 |
| 33 | 8.4 | MariaDB 10.11 |

Las herramientas disponibles dependen de las aplicaciones de Nextcloud y de los permisos de la cuenta. Las operaciones de dependencias de Deck requieren API compatibles en NC34/35. Consulta el [catálogo de herramientas](reference/tools.md).

### Instalar por SSH

Verifica `mcp-2.2.0.tar.gz` y el kit de fuentes correspondiente con el hash de confianza del manifiesto. Ensaya la actualización y la recuperación en un entorno como el de tu servidor y comprueba que las actualizaciones automáticas conservan el fork. El comando `occ app:install mcp` instala el paquete original de la tienda.

Antes del despliegue, usa MCP Inspector para comprobar el acceso HTTPS, la autorización y revocación del cliente. Después, confirma que un cliente puede consultar la guía de herramientas en una instancia de prueba desechable.

## Actualización y recuperación

Antes de actualizar, prepara una copia coherente de aplicación, base de datos y configuración/datos necesarios de Nextcloud, siguiendo el procedimiento del operador. El modo de mantenimiento impide el tráfico durante la sustitución sin cambiar la política de servicio MCP ni revocar credenciales por una actualización rutinaria. Sustituye todo el directorio de la aplicación, ejecuta la actualización nativa necesaria, limpia/reinicia las cachés PHP cuando corresponda y comprueba historial de migraciones, recursos de interfaz, permisos y conexiones existentes antes de reabrir el tráfico.

Prioriza reparar hacia delante tras una actualización fallida. Revertir solo los archivos de aplicación a 1.0.8 es inseguro: ese código no aplica las nuevas revocaciones por época/generación ni restricciones de recursos. Una copia de base de datos también puede contener credenciales revocadas después de crearla.

Para una recuperación coordinada, mantén Nextcloud en mantenimiento mientras restauras estados correspondientes de aplicación y base de datos y la configuración/datos necesarios. Antes de reabrir MCP, invalida códigos/tokens OAuth restaurados mediante los comandos de política admitidos, desconecta explícitamente las cuentas que deban reconectarse y revisa permisos efectivos y restricciones de recursos. Revisa/revoca por separado las contraseñas de aplicación de Nextcloud en Seguridad. Volver a habilitar el servicio no sustituye esta revisión. Una versión antigua que no aplique las restricciones necesarias no puede reabrirse para esos usuarios.

La suite nativa desechable ensaya restaurar aplicación/SQLite de la misma versión, rechazar credenciales antiguas y reconectar explícitamente. No certifica las herramientas de copia del operador, una reversión solo de archivos ni todas las configuraciones de almacenamiento de producción.

[Guías operativas](guides/README.md).

[English](../en/installation.md) · Español · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
