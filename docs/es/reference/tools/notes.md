<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/notes.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/notes.md).

# Notas

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Notes** | listar, leer y buscar (por título y contenido Markdown) | crear, editar, mover entre categorías y eliminar (solo si la papelera puede recuperarla) |

| Herramienta | Permiso | Notas |
| --- | --- | --- |
| `notes_list` `{}` | notes.read | Notas `.md`/`.txt` de la carpeta de la aplicación Notes (preferencia `notesPath`, valor predeterminado `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Busca por título y contenido Markdown (hasta 1 MiB) y devuelve un fragmento contextual de la coincidencia. Se puede restringir a una categoría. |
| `notes_read` `{id}` | notes.read | Nota con contenido; límite de 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Nunca sobrescribe; si ya existe, usa `Title (2)`. |
| `notes_edit` `{id, content?, append?, old?, new?, title?, etag?, plan_state?}` | notes.edit | Contenido completo, texto añadido o sustitución de un fragmento exacto; los tres modos de contenido son excluyentes. Sigue admitiendo editar solo el título. La confirmación parcial requiere el ETag y el estado opaco del plan devueltos en la vista previa. |
| `notes_move` `{id, category, etag?}` | notes.move | Cambia la categoría (subcarpeta), sin sobrescribir. |
| `notes_delete` `{id, etag?}` | notes.delete | Requiere `confirm: true`, que la papelera (`files_trashbin`) esté activa y que abarque el almacenamiento de la nota; se bloquea la eliminación en almacenamiento externo sin papelera. |

## Ediciones parciales

Usa `append` para añadir texto en una línea nueva conservando el estilo de finales de línea de la nota, o el par `old`/`new` para sustituir un fragmento. No los combines con `content`. El fragmento anterior debe ser no vacío, UTF-8 válido y de hasta 64 KiB en bytes; se rechazan las ausencias o las coincidencias múltiples superpuestas sin indicar su número. El resultado debe seguir dentro del límite de 1 MiB por nota.

Lee la nota, solicita un plan, muéstralo al usuario y repite los mismos argumentos del cambio con `confirm: true`, `etag` y `plan_state`. Se rechaza una nota modificada o un plan obsoleto, incluido contenido modificado sin un ETag nuevo. El contenido se vuelve a leer justo antes de escribir, pero el almacenamiento nativo no tiene aquí una comparación y escritura atómicas: aún puede producirse una escritura concurrente después de la última lectura. Siguen aplicándose los bloqueos, permisos, visibilidad, confirmación de escritura compartida y la política opcional de nombres de archivo.

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

Las herramientas Notes requieren que el usuario tenga habilitada la aplicación Notes. La lectura empieza permitida; el administrador debe conceder los permisos de crear, editar, mover y eliminar, que inicialmente están denegados. No hay un tiempo de espera propio: PHP realiza la lectura localmente y el límite de bytes protege frente a archivos grandes. El tiempo de ejecución de PHP (`max_execution_time`) limita el almacenamiento externo lento.

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/notes.md) · Español · [Català](../../../ca/reference/tools/notes.md) · [Português (Brasil)](../../../pt-BR/reference/tools/notes.md) · [Português (Portugal)](../../../pt-PT/reference/tools/notes.md)
