<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: mcp/README.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](README.md).

# Nextcloud MCP

**Tu Nextcloud, dentro de la conversación.** Busca archivos, trabaja con notas, calendarios, contactos y tareas, gestiona tarjetas de Deck y ponte al día en Talk desde un cliente MCP. Mail muestra las cuentas propias y los contadores de carpetas en caché; los operadores autorizados pueden consultar los registros del servidor.

[English](README.md) · **Español** · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)

## Primeros pasos

Requiere **Nextcloud 33–35** y **PHP 8.4+**. Este fork independiente de [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud) declara la versión **2.0.0** y conserva el identificador `mcp`. La edición de la tienda de Nextcloud corresponde al proyecto original; usa el paquete revisado de este fork para instalarlo o sustituir otra instalación.

1. [Instala la aplicación](../docs/es/installation.md).
2. [Activa el servicio, la elegibilidad del usuario y la conexión personal](../docs/es/guides/permissions.md#habilitar-el-acceso).
3. [Conecta un cliente](../docs/es/guides/clients.md) a `https://cloud.example.com/apps/mcp/` mediante OAuth o una contraseña de aplicación.
4. Pide la **Guía de herramientas** y prueba «¿Qué conversaciones de Talk tienen mensajes sin leer?» o «Busca la propuesta del proyecto y resúmela». Son ejemplos, no transcripciones de sesiones reales.

## El acceso sigue bajo tu control

Hasta **86 herramientas** de diez módulos, incluidas las de guía y estado, se filtran según las aplicaciones disponibles y los permisos de la cuenta. Los permisos nativos de Nextcloud se aplican en cada petición. La administración puede limitar el acceso a carpetas, calendarios y listas de tareas seleccionados y excluir archivos etiquetados. Las herramientas de escritura devuelven una vista previa salvo confirmación explícita; el asistente debe obtener la aprobación del usuario. No hay herramienta para eliminar archivos. Mail y los registros son de solo lectura y requieren permisos explícitos.

Los resultados se envían al cliente de IA elegido y quedan sujetos a sus políticas de tratamiento y conservación.

[Herramientas](../docs/es/reference/tools.md) · [Permisos](../docs/es/guides/permissions.md) · [Protección de archivos](../docs/es/guides/file-protection.md) · [Solución de problemas](../docs/es/guides/troubleshooting.md)

## Licencia

AGPL-3.0-or-later, con la condición adicional de atribución de la sección 7(b) que aparece en [NOTICE](NOTICE). Consulta [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt) para ver la licencia completa. Al redistribuir o proporcionar versiones modificadas a través de una red, conserva la atribución en la página de administración, la documentación y los avisos de copyright.

## Créditos

Se incluyen Smalot PdfParser, Symfony Polyfill Mbstring y el cargador automático de Composer. Consulta [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) para ver versiones, derechos de autor, licencias y créditos de desarrollo independientes.

**Colaboradores.** Gracias a @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) por la comprobación de visibilidad de la raíz y a @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) por el informe que motivó la auditoría de etiquetas ocultas. Gracias también a @matt-ek por la traducción alemana de la interfaz ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) y a @Zegorax por los clientes OAuth registrados por administradores ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).


Jerarquía de tareas: [@mturgeonferland](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18).

Sintax / Jhonatan Jaworski · [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw). Este crédito histórico se conserva sin implicar que XERJ haya revisado este fork.

Developed by Sintax — https://sintax.tech
