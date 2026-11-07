<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/deck.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../../en/reference/tools/deck.md).

# Deck

| Módulo | Lectura | Escritura |
| --- | --- | --- |
| **Deck** | tableros, pilas y tarjetas, además de un **seguimiento** de los tableros que administras: tarjetas agrupadas por persona asignada, con fecha de vencimiento, indicador de retraso (según tu propia zona horaria) y enlace a la tarjeta | crear, editar, mover y eliminar tarjetas, y asignar o desasignar miembros del tablero; crear un tablero con sus listas y tarjetas iniciales, o crear una lista en un tablero que administres; eliminar un tablero o una lista solo cuando esté vacío, enviándolo directamente a la papelera de Deck |

## Deck: tarjetas, tableros y listas

Las escrituras de Deck usan los permisos `deck.create`, `deck.edit`, `deck.move` y `deck.delete`, todos desactivados de forma predeterminada, y `confirm_shared: true` en un tablero de otro propietario. La aplicación siempre comprueba con Deck si se guardó la escritura, incluso después de recibir un error de Deck.

| Herramienta de escritura | Permiso | Comportamiento |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Crea un tablero propio con sus listas y primeras tarjetas (hasta 20 listas y 100 tarjetas), con un color opcional. En el tablero nuevo, las personas asignadas son el usuario actual porque nadie más tiene acceso. El plan muestra todo el árbol; si falla un paso a mitad de camino, no se revierte nada y el resultado incluye `created`, `failed` (con un motivo seguro) y `warnings`, para que reintentar no duplique elementos. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Añade una lista al tablero que administra el usuario, al final o en una posición determinada. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Elimina una lista **solo si está vacía**: cualquier tarjeta, activa o archivada, rechaza la llamada e indica cuántas quedan; el recuento se repite al confirmar. La lista va a la papelera de Deck. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Aplica la misma regla al tablero, que solo puede eliminar su propietario. Si aparece una tarjeta entre el recuento y la eliminación, el tablero se restaura con la propia función de deshacer de Deck y se rechaza la llamada, indicando que no se eliminó nada. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` y la asignación de personas usan los mismos permisos y requieren la misma confirmación; las personas asignadas deben ser miembros del tablero.

Después de un error de Deck, todas las escrituras vuelven a leer el estado real antes de responder: si el elemento se guardó, la respuesta indica éxito con el aviso «Saved; Deck reported an error afterwards (notification or activity)»; si no, devuelve el error habitual; y si no se puede leer el estado, no devuelve un error y pide al asistente que lea con `deck_list_cards`, `deck_read_card`, `deck_list_stacks` o `deck_list_boards`. Si el elemento solo vuelve a encontrarse por similitud (mismo propietario y título, creado hace poco), se informa `confirmed: "probable"`, con un aviso para comprobarlo, y no se le hacen más cambios. Solo se omite esta relectura cuando la propia aplicación rechaza la llamada antes de usar Deck (reglas de herramientas, sesión, conflictos o argumentos).

La regla de eliminar «solo si está vacío» no es atómica: Deck no consulta `deleted_at` de la lista al crear una tarjeta. Por tanto, una tarjeta creada por otra solicitud justo después del segundo recuento puede ir a la papelera junto con la lista o el tablero, desde donde se puede recuperar. Esto figura en la descripción de la herramienta y en el gateway.

## Vencimientos

Crear y editar tarjetas admite `duedate` como `YYYY-MM-DD` o `YYYY-MM-DDTHH:MM[:SS]`, con `Z` o desplazamiento numérico opcionales. Sin desplazamiento se usa la zona horaria de la cuenta; una fecha sin hora significa la medianoche local de ese día. Se rechazan horas locales explícitas que no existan por una transición de zona horaria. Si una hora se repite, se elige el primer instante salvo que el desplazamiento proporcionado resuelva la ambigüedad. Al guardar se convierte a UTC antes de llamar a Deck. Las respuestas conservan `duedate` por día, el estado vencido y `dueBefore`, y añaden `dueAt` con el desplazamiento de la cuenta. Las ediciones no relacionadas conservan el instante existente.

Las escrituras MCP anteriores afectadas pueden haber guardado el día equivocado. Revisa esas tarjetas frente a la fecha prevista y corrígelas explícitamente en Deck o con una nueva edición confirmada. No desplaces todos los vencimientos: los introducidos por otros clientes pueden ser correctos. No se reescriben automáticamente las fechas históricas.

## Etiquetas y archivos visibles de las tarjetas

| Herramienta | Permiso | Comportamiento |
| --- | --- | --- |
| `deck_list_labels` `{boardId, limit=50, offset=0}` | deck.read | Etiquetas de un tablero legible, hasta 200 por página. |
| `deck_edit_card_labels` `{cardId, add?, remove?}` | deck.edit | ID de etiquetas añadidas o retiradas, hasta 100 por lista. Conserva las no afectadas; los duplicados y cambios ya satisfechos no modifican nada. Rechaza etiquetas de otros tableros y solicitudes que añadan y retiren la misma etiqueta. |
| `deck_list_card_files` `{cardId}` | deck.read y files.read | Solo asociaciones nativas existentes a archivos visibles, ordenadas por `attachmentId`. Este ID de asociación/recurso compartido difiere del `fileId` original. |
| `deck_unlink_card_file` `{cardId, attachmentId}` | deck.edit y files.read | Retira una asociación nativa visible tras la aprobación, devolviendo el recibo `file_state` exacto y `plan_state` del plan. Conserva el archivo original y los recursos compartidos independientes; no requiere files.share. |

Las escrituras de etiquetas y desvinculación exigen un plan nuevo, `confirm: true`, `plan_state` y, cuando corresponda, aprobación de tablero compartido. Las lecturas y escrituras vuelven a comprobar el acceso nativo. Las referencias de archivos también requieren el ámbito de Files, las comprobaciones de etiquetas de exclusión, las restricciones de Guests y READ nativo del nodo a través de los alias del usuario. Las referencias invisibles se omiten o responden como inexistentes, sin recuentos ni identificadores; la respuesta de tarjeta ya no expone `attachmentCount` nativo. No se admiten aquí las cargas antiguas `deck_file` ni carpetas. Crear una asociación nativa nueva usa la operación de enlace autorizada por separado que se explica a continuación.

Desvincular revoca esa asociación para todos los miembros actuales y futuros de la tarjeta, aunque la creara otro editor. Los recursos compartidos independientes pueden conservar el acceso; puedes perder tu último montaje de Deck. No hay recuperación automática; restaurar la asociación puede requerir la interfaz nativa de Deck. Se rechaza un recibo obsoleto o inválido. Confirmar una asociación ya ausente solo no hace nada si el recibo original es válido y el archivo original sigue visible.

## Enlace nativo de archivos

`deck_link_card_file` enlaza un archivo normal existente y visible a una tarjeta activa de Deck. Requiere `deck.edit`, `files.read` y `files.share`, EDIT nativo de la tarjeta y READ/SHARE del archivo. La vista previa identifica al propietario, si se vuelve a compartir, el total actual de destinatarios y hasta diez nombres (80 caracteres Unicode cada uno). Debe aprobarse con `confirm: true`, el `plan_state` exacto y `confirm_shared` cuando lo exijan las reglas de escritura compartida existentes.

La asociación nativa da acceso READ a los miembros actuales y futuros del tablero, incluidos los heredados de grupos/Teams. No sube ni copia el archivo. No modifica los recursos compartidos independientes existentes. Si cambia el archivo, el origen, los destinatarios, la política de compartición o un permiso, se invalida la confirmación antes de escribir. Las comprobaciones de política del núcleo y del actor preceden a la expansión privilegiada de Teams; los privilegios nativos temporales de Circles se retiran antes de las autorizaciones y escrituras posteriores y en los límites de operación, incluidas las rutas de error. Un fallo de limpieza rechaza la operación antes de escribir o informa de un efecto incierto tras un intento de escritura.

La operación rechaza invitados, carpetas, orígenes archivados, destinatarios sin resolver o no compatibles, uso compartido desactivado, compartición solo entre miembros de grupos, caducidad interna obligatoria, prohibición de volver a compartir y destinatarios de grupo/Team cuando la compartición con grupos está desactivada. También rechaza si no puede determinar identidad o política. No propone desactivar esas protecciones. Pueden ejecutarse hooks y notificaciones nativos. La caducidad interna predeterminada opcional no se aplica a este tipo nativo de recurso compartido; la caducidad interna obligatoria se rechaza.

Se requiere el conjunto completo de destinatarios: hasta 128 entradas ACL, 1000 personas locales distintas, 5000 pasos de trabajo cooperativo y dos segundos comprobados entre llamadas nativas. No hay éxito con destinatarios parciales. La asignación nativa de miembros y la E/S bloqueante quedan fuera de esos límites cooperativos. Mostrar pocos nombres en la vista previa no limita el recurso compartido: la asociación alcanza a todos los destinatarios nativos y sus miembros futuros.

Los cambios posteriores de miembros o política no desencadenan revocación automática por MCP; la validación y la compartición nativa no son una transacción atómica. Estos límites también importan al interpretar resultados de escritura inciertos. Consulta el estado antes de reintentar; observar un recurso compartido después de un error no demuestra qué actor lo creó. A la inversa, que no exista una asociación después de intentar crearla no demuestra que no pasara nada: un hook u otro actor pudo retirarla después de conceder acceso o generar notificaciones. Esos fallos siguen siendo inciertos y no autorizan repetir a ciegas. Desvincular retira solo la asociación nativa y el acceso resultante; el archivo y los recursos compartidos independientes permanecen.

La política de nombres rige las escrituras de contenido y destinos de Files/Notes cubiertos; una asociación nativa de Deck no es una nueva escritura de contenido. Siguen aplicándose la visibilidad, el ámbito, las exclusiones de Files y la política nativa de compartición.

## Dependencias nativas condicionales

Las tres herramientas de dependencias solo aparecen y se ejecutan con API nativas compatibles: NC34 con Deck >=1.18.5 y <1.19.0, o NC35 con Deck >=1.19.0 y <1.20.0, y los métodos/propiedades nativos necesarios. Los objetivos fijados de validación son Deck 1.18.5 y 1.19.0; admitir un intervalo no certifica cada revisión. NC32/33 y las API no compatibles omiten las tres herramientas y rechazan las llamadas directas.

- `deck_read_card_dependencies` lee el subgrafo legible completo de relaciones salientes.
- `deck_add_card_dependency` añade `cardId` → `targetId`.
- `deck_remove_card_dependency` retira solo esa relación dirigida.

En `assignDependentCard(A,B)` nativo, B aparece en `dependentCards` de A; estas relaciones no bloquean automáticamente el progreso de las tareas. Las lecturas requieren deck.read; las escrituras, deck.edit, EDIT del origen y READ del destino, incluso para eliminar la relación. Las escrituras necesitan aprobación y el `plan_state` devuelto. Las demás relaciones se conservan; una incorporación duplicada o una retirada inexistente no hacen nada.

El recorrido se limita a 100 nodos legibles incluida la raíz, 500 aristas legibles y profundidad de camino mínimo 20. Superar un límite rechaza el resultado en vez de devolver un grafo incompleto. Los mismos límites de nodos/aristas legibles se aplican a la instantánea completa de adyacencias del origen necesaria para añadir, retirar y verificar resultados; los orígenes que los superen deben modificarse en la interfaz nativa de Deck. Una relación nueva también debe respetar los límites antes de aprobar su plan o escritura. La autorización de destinos y las consultas de entidades se detienen en el primer exceso legible. Deck sigue materializando cada adyacencia nativa antes de filtrarla, y filtrar relaciones ilegibles puede requerir consultas adicionales; estos límites no acotan el trabajo ni la memoria totales. Las tarjetas ilegibles no consumen presupuesto legible ni revelan ID, recuentos, identificadores o indicadores de límites ocultos. Se rechazan las autorrelaciones y los ciclos detectables mediante tarjetas legibles; no se pueden excluir ciclos ocultos ni cambios concurrentes entre la última comprobación y la escritura nativa. No garantiza la ausencia global de ciclos.

Tras un resultado incierto o parcial de etiquetas/dependencias/enlace/desvinculación, consulta el estado actual de Deck antes de seguir. Observar el estado solicitado después de un error no prueba qué actor lo cambió ni que se completara toda la solicitud; no repitas automáticamente la escritura.

Consulta la guía `mcp_guide` activa para obtener el esquema y las operaciones disponibles como referencia definitiva.

[Guías operativas](../../guides/README.md).

[English](../../../en/reference/tools/deck.md) · Español · [Català](../../../ca/reference/tools/deck.md) · [Português (Brasil)](../../../pt-BR/reference/tools/deck.md) · [Português (Portugal)](../../../pt-PT/reference/tools/deck.md)
