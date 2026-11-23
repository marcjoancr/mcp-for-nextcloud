<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/contacts.md -->
<!-- translation-source-revision: 2e4144ffeb13c74a1d9da098c262b8424cb24439 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/contacts.md).

# Contactos

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Contactos** | listar libretas de direcciones personales y compartidas; buscar y leer contactos, incluido el catálogo de cuentas de solo lectura de la instancia (compañeros, con `accountId` para invitaciones), según la configuración de enumeración de usuarios del administrador | crear y editar conservando los campos vCard desconocidos; eliminar permanentemente solo después de verificar una copia `.vcf` de seguridad bajo `Contacts` en la carpeta de copias configurada (por defecto `/MCP backups/Contacts`; obligatoria aunque se suspendan las copias de Files) |

[Comportamiento de Contactos y Tareas](contacts-tasks.md).

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

[Guías operativas](../../guides/README.md).

## Grupos, fichas e intercambio vCard

`contacts_list_groups` lista las categorías de una libreta visible. Usa `group` en `contacts_search_contacts` para filtrar por una categoría exacta. Al crear o editar se pueden indicar el nombre y la dirección estructurados, las categorías, el cumpleaños y el correo preferido.

`contacts_export_vcf` devuelve un documento vCard con las fichas seleccionadas que puedes guardar como `.vcf`. Usa `contacts_import_vcf` con un archivo al que tengas acceso en Files o con la ruta del respaldo que devuelve el borrado. La vista previa indica qué fichas se crearán. Con una vista previa actualizada, repetir la importación omite los UID que ya existen.

`contacts_find_duplicates` señala posibles duplicados por coincidencia de correo o teléfono normalizado. Compartir nombre y organización es un indicio secundario. Revisa las fichas y sus ETags antes de elegir qué datos consolidar.

Usa `contacts_merge_contacts` con hasta cinco grupos sin fichas en común. Cada grupo reúne de dos a diez fichas de una libreta editable. Elige qué ficha conservar y revisa cada valor en conflicto. Tras aprobar el plan, repite la llamada con los ETags y el `plan_state` de la vista previa. La herramienta verifica las copias originales y la ficha consolidada antes de eliminar fuentes. Informa de los grupos que todavía necesitan revisión y de sus rutas de respaldo.

## Libretas y traslado de fichas

Usa `contacts_create_addressbook` para crear una libreta personal y `contacts_list_addressbook_shares` para consultar los accesos de una libreta propia. `contacts_share_addressbook` gestiona los accesos directos de usuarios locales. Concede acceso de lectura o escritura, o retira un acceso existente. Revisa el cambio en la vista previa.

`contacts_copy_contact` crea una ficha distinta en un destino editable y mantiene la original. `contacts_move_contact` conserva el UID, comprueba el destino y guarda una copia de seguridad antes de eliminar el origen. Confirma la transferencia con el `plan_state` de la vista previa. Incluye el ETag de origen al mover y consulta el estado de ambas fichas si hay un fallo parcial. Las libretas compartidas requieren una confirmación adicional.

## Mantenimiento por lotes

`contacts_update_categories` añade, retira o renombra una categoría en las fichas seleccionadas de una libreta editable. Incluye sus ETags. La vista previa muestra las categorías antes y después y el resultado informa por ficha.

`contacts_reconcile_vcf` actualiza las fichas con UID exacto y único que coinciden con un VCF visible en Files. La vista previa indica valores actuales, propuestos y conservados. Antes de cada actualización se guarda y verifica una copia completa. Las fichas sin una coincidencia única quedan para revisión. Confirma cada operación con el `plan_state` y reconoce por separado los cambios en libretas compartidas.

[English](../../../en/reference/tools/contacts.md) · Español · [Català](../../../ca/reference/tools/contacts.md) · [Português (Brasil)](../../../pt-BR/reference/tools/contacts.md) · [Português (Portugal)](../../../pt-PT/reference/tools/contacts.md)
