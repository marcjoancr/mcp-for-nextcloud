<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/tasks.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/tasks.md).

# Tareas

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Tareas** | listar calendarios de tareas y tareas VTODO, incluidas las que no tienen fecha, con la opción de filtrar por categoría o tarea principal directa; leer tareas con sus categorías y el UID de su tarea principal | crear, editar, completar y eliminar tareas sencillas mediante CalDAV, incluidas las categorías y relaciones con la tarea principal; la eliminación usa la papelera del calendario y se rechaza si el periodo de conservación es cero |

## Tareas principales y subtareas

`tasks_create_task` y `tasks_edit_task` aceptan `parent`: un UID establece la tarea principal, `null` deja la tarea en el nivel superior y omitirlo conserva la relación. La tarea principal debe ser un VTODO activo y legible del mismo calendario. Los resultados de lectura/listado/escritura incluyen `parent` (UID o null). `tasks_list_tasks` acepta un UID de tarea principal para listar solo subtareas directas, aplicando el filtro antes de paginar y junto a los filtros de categoría/tareas completadas. No lista descendientes recursivamente.

La relación usa `RELATED-TO;RELTYPE=PARENT`; una relación sin RELTYPE también se interpreta como tarea principal. Cambiar o eliminar la tarea principal conserva CHILD, SIBLING, relaciones personalizadas y propiedades no afectadas. Los planes muestran títulos legibles con UID para distinguir tareas principales con el mismo nombre. Las tareas principales privadas/confidenciales de un calendario compartido se tratan como inexistentes.

La vista previa y la confirmación rechazan ciclos y anidamientos de más de 64 niveles, incluidos los descendientes de una tarea movida. Las escrituras MCP confirmadas que establecen una tarea principal se serializan por calendario. Se rechazan si el bloqueo de archivos del servidor está desactivado; borrar la relación sigue disponible para reparar una jerarquía. Otros clientes CalDAV aún pueden modificar tareas relacionadas durante una escritura. Si la relectura detecta un ciclo que pase por la tarea guardada, la respuesta informa del guardado correcto con un aviso de reparación. No es una transacción atómica de jerarquía entre clientes.

Completar o eliminar una tarea principal no completa, elimina ni mueve sus subtareas. Las tareas recurrentes se pueden leer y usar como principales, pero se sigue rechazando editar una serie recurrente o una tarea con participantes. Se mantienen las comprobaciones de ETag, ámbito, uso compartido y confirmación.

[Comportamiento de Contactos y Tareas](contacts-tasks.md).

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/tasks.md) · Español · [Català](../../../ca/reference/tools/tasks.md) · [Português (Brasil)](../../../pt-BR/reference/tools/tasks.md) · [Português (Portugal)](../../../pt-PT/reference/tools/tasks.md)
