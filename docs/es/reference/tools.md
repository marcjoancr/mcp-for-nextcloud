<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

**Traducción pendiente de revisión humana:** consulta la [versión en inglés](../../en/reference/tools.md).

# Herramientas

Los catálogos contienen como máximo **110 herramientas de módulos**, más `mcp_status` y `mcp_guide` (112 en total). Tres herramientas de dependencias de Deck requieren API nativas compatibles de NC34/35; sin ellas quedan como máximo 107 herramientas de módulos (109 en total). La disponibilidad de aplicaciones y el acceso de cada cuenta reducen aún más estas cifras.

Cada herramienta solo aparece en `tools/list` y solo se puede llamar si está asignada al usuario, seleccionada para la conexión y esa persona tiene habilitadas las aplicaciones y los requisitos de operación necesarios. Ambos requisitos se vuelven a comprobar en cada llamada. Todo se ejecuta como el usuario autenticado, a través de su propia carpeta de Nextcloud, y se siguen aplicando los permisos y recursos compartidos de Nextcloud.

### Guía de herramientas (`mcp_guide`)

`mcp_guide` (título **Guía de herramientas**) describe las herramientas al propio modelo, para que no tenga que adivinar qué hace cada una:

- **Sin argumentos**: una línea por módulo con los títulos de las herramientas disponibles **para ese usuario** (se aplican los mismos filtros de permisos y aplicaciones que en `tools/list`: un módulo desactivado o sin permiso no aparece).
- **Con `module`** (`files`, `notes`, `calendar`, `contacts`, `tasks`, `deck`, `talk`, `people`, `logs`, `mail`, `forms`): cada herramienta del módulo, con descripción, indicación de si lee o escribe, parámetros (tipo, obligatoriedad, descripción, `enum` y límites) y las confirmaciones necesarias.
- **Con `tool`**: una sola herramienta y su módulo.

La guía está escrita en inglés; el modelo se la comunica al usuario en su idioma.

El contenido procede de las definiciones reales (`ToolModule::definitions()`, `inputSchema`, `annotations` y `ToolPresentation`), por lo que, al cambiar el esquema de una herramienta, también cambia la guía: no hay una segunda copia de la documentación que haya que mantener. La información que el esquema no incluye —cómo encontrar elementos, qué rutas e ID usar y dónde se detienen las escrituras— se describe en `ToolGuideNotes::guideNotes()`, una nota breve por módulo que se mantiene junto a este. La respuesta contiene el mismo texto en Markdown y en `structuredContent`; la herramienta es de solo lectura: consulta las definiciones, nunca ejecuta herramientas.

Si el usuario no puede ver un módulo o herramienta, se rechaza la solicitud y se indica qué puede utilizar. El servidor no almacena ninguna aprobación: la regla de confirmar antes de escribir está en las `instructions` de `initialize` y se aplica a todas las herramientas que modifican datos.

Además:

- **Confirmación en la conversación antes de cada escritura (`confirm: true`)**: las herramientas que modifican datos solo se ejecutan si se llaman con `confirm: true`. Sin ese argumento, el servidor devuelve el plan (`requiresConfirmation: true`) y no modifica nada. El asistente debe presentar el plan al usuario y repetir la llamada con `confirm: true` solo después de que este dé su aprobación explícita. El servidor no conserva tokens ni el estado de aprobación.
- **OCR mediante Workflow OCR**: si está instalada la aplicación Workflow OCR, los PDF escaneados reciben una capa de texto que `files_read` lee con normalidad. Sin ella, un PDF o imagen sin texto devuelve `text_layer: false` y un aviso; la página de administración muestra el estado de OCR y un enlace para instalarlo. No se bloquea nada.
- **Títulos claros de las herramientas** en el cliente («Buscar archivos», «Listar calendarios») y anotaciones MCP (`readOnlyHint`, `destructiveHint`) para que los clientes puedan pedir confirmación antes de operaciones arriesgadas.
- **Confirmaciones de seguridad** para recursos que pertenecen a otras personas: carpetas compartidas, carpetas de equipo y tableros y calendarios de otros usuarios. El servidor rechaza la primera llamada y devuelve un mensaje listo para presentar; el asistente debe pedir permiso al usuario antes de repetirla con `confirm_shared: true`. *(Deck y Calendar desde 0.6.6; Files y Notes desde 0.7.0.)*
- **Respeto por las aplicaciones opcionales**: se ocultan las herramientas y las columnas de la matriz de administración de una aplicación desactivada (para todos o para ese usuario).
- **Interfaz localizada**: la matriz de administración, la página personal, la pantalla de consentimiento y todos los mensajes de las herramientas usan el idioma de la cuenta Nextcloud. Se incluyen todos los módulos (Files, Notes, Calendar, Contacts, Tasks, Deck, Talk, los mensajes compartidos, las páginas de checkout y la prueba de Calendar). Se incluyen las fuentes en inglés y los catálogos de interfaz en alemán, portugués de Brasil y español. Los nuevos mensajes seleccionados aún requieren revisión humana, incluida la interfaz de administración renderizada en alemán. La documentación operativa también conserva borradores en catalán y portugués europeo con su propia procedencia y revisión pendiente; las descripciones de herramientas siguen en inglés porque las lee el modelo.
- **Compatibilidad con ambas generaciones de MCP**: MCP sin estado `2026-07-28` (`server/discover`) y el flujo clásico `initialize` (`2025-06-18` y anteriores), en el mismo endpoint.

- [Files](tools/files.md)
- [Notes](tools/notes.md)
- [Calendar](tools/calendar.md)
- [Contacts](tools/contacts.md)
- [Tasks](tools/tasks.md)
- [Deck](tools/deck.md)
- [Logs](tools/logs.md)
- [Talk](tools/talk.md)
- [People](tools/people.md)
- [Mail](tools/mail.md)
- [Forms](tools/forms.md) — `forms_list` lista formularios accesibles y `forms_read` lee preguntas y opciones. Requieren Forms 5.3.0 o posterior y el permiso separado `forms.read`. `forms_read_responses` lee páginas de envíos con el permiso independiente `forms.read_responses` y permiso nativo de resultados. Usa los desplazamientos devueltos para recuperar preguntas y opciones posteriores. Los textos de más de 1 KiB indican recorte.

Para `forms_read_responses`, envía `id`, `limit` (50 por defecto, máximo 100) y `offset`. Sigue `nextOffset` mientras `hasMore` sea verdadero. Cada envío incluye hasta 200 respuestas, con `answersTruncated` para las omitidas. Los textos de más de 1 KiB tienen `textTruncated: true`. Se omiten archivos subidos y se informa mediante `fileAnswersOmitted`. Las respuestas anónimas omiten ID de participantes aunque después cambie la opción de anonimato. Poder consultar la propia respuesta no concede acceso al conjunto completo mediante esta herramienta.

`mcp_status` proporciona diagnósticos sin leer datos de usuario. La identidad del servidor en los metadatos de protocolo, herramientas, prompts y recursos informa de la versión instalada desde Nextcloud, en vez de copiar un número de versión del proyecto original.

[Guías operativas](../guides/README.md).

[English](../../en/reference/tools.md) · Español · [Català](../../ca/reference/tools.md) · [Português (Brasil)](../../pt-BR/reference/tools.md) · [Português (Portugal)](../../pt-PT/reference/tools.md)
