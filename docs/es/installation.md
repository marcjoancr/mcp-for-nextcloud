<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../en/installation.md).

# Instalación

Nextcloud MCP se ejecuta como aplicación nativa de Nextcloud. Usa el PHP y el almacenamiento del servidor existente; no necesita un proceso MCP independiente ni Node.js en ejecución.

## Requisitos

| Componente | Requisito |
| --- | --- |
| Nextcloud | **33–35**, como declara el manifiesto de la aplicación |
| PHP | **8.4 o posterior**, también compatible con la versión de Nextcloud elegida |
| Extensiones PHP | `zip`, `mbstring` y `dom`, además de los requisitos propios de Nextcloud |
| Acceso del cliente | Una URL HTTPS accesible desde el cliente MCP elegido |
| Aplicaciones opcionales | Habilita las correspondientes a Notes, Tasks, Calendar, Contacts, Deck, Talk, Mail y Forms para usar sus funciones |

Las herramientas también dependen de las asignaciones individuales, la selección de la conexión y los permisos nativos sobre los recursos. Mail requiere una versión conocida de al menos 5.0.0; Forms requiere una versión conocida de al menos 5.3.0. Sus herramientas necesitan asignación explícita y selección; esos mínimos de API no certifican todas las combinaciones de aplicación y servidor. Las dependencias de Deck requieren API compatibles de NC34/35. Consulta el [catálogo de herramientas](reference/tools.md).

Las herramientas de desarrollo también prueban PHP 8.2/8.3; esos entornos no reducen el requisito de PHP 8.4 para instalar la aplicación. La matriz de pruebas nativas cubre Linux x86-64/Apache con NC33/34/35 y MariaDB 10.11, además de NC35 con PostgreSQL 16 o SQLite. Una prueba independiente de LinuxServer/Nginx usa NC35/PostgreSQL/S3. Son perfiles de prueba, no una garantía exhaustiva de compatibilidad de despliegues.

## Elige el paquete del fork

El fork conserva el identificador **`mcp`** y el manifiesto declara la versión **2.7.0**. Sustituye una instalación existente de MCP for Cloud con ese mismo identificador. La tienda de Nextcloud distribuye el proyecto original: **`occ app:install mcp` instala la edición de la tienda**.

Usa `mcp-2.7.0.tar.gz` construido desde una revisión examinada del fork, con su kit de fuentes correspondiente y el hash del manifiesto obtenido por un canal de confianza independiente. Un archivo de código fuente de GitHub o una copia del directorio `mcp/` sin construir no son paquetes de instalación. El checkout completo contiene las instrucciones de empaquetado en `docs/en/development/packaging.md` y `docs/en/development/source-delivery.md` (en inglés).

## Instalación nueva

Para una instancia donde `mcp` aún no esté instalada:

1. Verifica el paquete y el kit de fuentes correspondiente con el hash de confianza del manifiesto.
2. Extrae el único directorio `mcp/` del archivo en una raíz de aplicaciones configurada en `apps_paths` de Nextcloud. Ajusta la propiedad y el acceso de lectura según los roles de servicio y mantenimiento de tu despliegue.
3. Como usuario de mantenimiento de Nextcloud, ejecuta `php /absolute/path/to/nextcloud/occ app:enable mcp` con el PHP seleccionado para esa instancia. En despliegues con contenedores, ejecuta el equivalente dentro de su entorno de mantenimiento.
4. Abre **Administración → Nextcloud MCP**. Activa el servicio, permite conectarse a los usuarios previstos y asigna las herramientas individuales que puedan usar.
5. Cada usuario activa su conexión en **Ajustes personales → Nextcloud MCP** selecciona las herramientas para el cliente y sigue la [configuración de clientes](guides/clients.md).

Usa el endpoint que muestra Resumen en administración, normalmente:

```text
https://cloud.example.com/apps/mcp/
```

Incluye el subdirectorio de instalación de Nextcloud si lo tiene. El servicio, la elegibilidad del usuario y la conexión personal empiezan desactivados, también para administradores. La escritura empieza denegada; el acceso a Mail, Forms y los registros también requiere permisos explícitos. Las herramientas nuevas empiezan sin asignar ni seleccionar. Consulta [permisos y administración](guides/permissions.md).

Las respuestas de las herramientas llegan al cliente de IA conectado. Elige permisos y restricciones de recursos según los datos que ese cliente deba recibir; alojar el conector en tu servidor no determina las políticas de tratamiento o conservación del cliente.

## Actualización y recuperación

Antes de actualizar, prepara una copia coherente de la aplicación, la base de datos y la configuración/datos de Nextcloud necesarios, siguiendo el procedimiento del operador. Ensaya la sustitución y recuperación en un perfil aislado equivalente y asegúrate de que las actualizaciones automáticas de la tienda no puedan sustituir el fork.

El modo de mantenimiento impide el tráfico durante la sustitución sin cambiar la política de servicio MCP ni revocar credenciales solo por una actualización rutinaria. Sustituye el directorio completo de la aplicación, ejecuta `occ upgrade` nativo, vacía o reinicia las cachés PHP cuando proceda y verifica el historial de migraciones, los recursos, los permisos y las conexiones existentes antes de reabrir el tráfico. Mantén los metadatos de entrega de fuentes alineados con el kit instalado.

Protege la raíz efectiva de aplicaciones, sus directorios ascendientes escribibles y la configuración que selecciona `apps_paths`. Un montaje de solo lectura de `mcp/` no impide que un proceso seleccione código sustituto si el directorio padre o la configuración siguen siendo escribibles. Separa el acceso de servicio del mantenimiento del operador cuando el despliegue requiera esa protección.

Tras una actualización fallida, prefiere reparar hacia delante. Restaurar solo los archivos de la aplicación a 1.0.8 es inseguro: ese código no aplica las nuevas revocaciones por época/generación ni las restricciones de recursos. Una copia de la base de datos también puede contener credenciales revocadas después de crearla.

Para una recuperación coordinada, mantén Nextcloud en mantenimiento mientras restauras estados coincidentes de aplicación y base de datos, así como la configuración/datos necesarios. Antes de reabrir MCP, invalida los códigos y tokens OAuth restaurados mediante los comandos de política admitidos, desconecta explícitamente las cuentas que deban volver a conectarse y revisa permisos efectivos y restricciones de recursos. Revisa o revoca por separado las contraseñas de aplicación en los controles nativos de Seguridad. Reactivar el servicio por sí solo no sustituye esa revisión. Una versión antigua incapaz de aplicar las restricciones necesarias no puede reabrirse para esos usuarios.

La suite nativa desechable ensaya una restauración coordinada de aplicación/SQLite de la misma versión, el rechazo de credenciales antiguas y la reconexión explícita. No certifica las herramientas de copia del operador, una reversión solo de archivos ni todas las distribuciones de almacenamiento de producción.

[Guías operativas](guides/README.md).

[English](../en/installation.md) · Español · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
