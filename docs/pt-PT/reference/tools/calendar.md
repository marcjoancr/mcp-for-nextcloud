<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/calendar.md -->
<!-- translation-source-revision: 43eaec06ad2748a8e6f546fbe8ebf9cd04ba8f52 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/calendar.md).

# Calendário

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Calendário** | listar calendários e eventos (recorrência, fusos, dia inteiro) | criar, editar, mover, excluir e transferir eventos pelo pipeline CalDAV de verdade, com participantes. As escritas ficam desligadas até o administrador liberá-las na matriz de permissões (sem passo no terminal), e cada uma devolve antes um plano: **nada é gravado antes de o utilizador aprovar.** |

| Tool | Permissão | Notas |
| --- | --- | --- |
| `calendar_list_calendars` `{}` | calendar.read | Calendários visíveis ao utilizador, próprios e partilhados. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Eventos no intervalo (predefinição: próximos 7 dias), com recorrência expandida. |
| `calendar_create_event` | calendar.create | Cria um evento. `recurrence` aceita uma regra semanal ou mensal limitada. `attendees` aceita UIDs internos. |
| `calendar_update_event` | calendar.edit | Atualiza o evento ou o `recurrence_id` selecionado. A lista de convidados e a recorrência de uma série têm restrições. |
| `calendar_move_event` | calendar.move | MOVE no mesmo dono; participantes não são avisados, como na aplicação Calendário. |
| `calendar_delete_event` | calendar.delete | Elimina uma série no lixo ou exclui uma ocorrência original quando `recurrence_id` é indicado. |
| `calendar_transfer_event` | calendar.transfer | MOVE para agenda de outro proprietário partilhada com permissão de escrita; recusa participantes. |

Calendário expõe leitura e operações de escrita, sem nenhum passo no terminal. As escritas nascem desligadas na matriz da administração (Administração → MCP) e só aparecem para quem recebeu o grant; toda escrita exige ainda `confirm: true`, a ACL do Nextcloud (e `confirm_shared` em agenda alheia) e o ETag opcional. Compatibilidade: a aplicação declara `min-version` 33 e `max-version` 35 em `info.xml`; a parte interna (`EmbeddedCalDavServer` do core) é coberta pelos testes e por essa faixa, e uma versão nova do Nextcloud exige uma nova versão do app. O app Calendário precisa estar ativado para o utilizador. Sabre e Symfony Console vêm do core do Nextcloud e ficam fora do `vendor/` do pacote.

| Tool de escrita | Grant | Comportamento |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Cria um evento. `recurrence` aceita uma regra semanal ou mensal limitada. `attendees` aceita UIDs internos. |
| `calendar_update_event` | calendar.edit | Atualiza o evento ou o `recurrence_id` selecionado. A lista de convidados e a recorrência de uma série têm restrições. |
| `calendar_move_event` | calendar.move | MOVE no mesmo dono; participantes não são avisados, como na aplicação Calendário. |
| `calendar_delete_event` | calendar.delete | Elimina uma série no lixo ou exclui uma ocorrência original quando `recurrence_id` é indicado. |
| `calendar_transfer_event` | calendar.transfer | MOVE para agenda de outro proprietário partilhada com permissão de escrita; recusa participantes. |

Create, update e delete usam `send_invitations: false` por predefinição (`x-nc-scheduling: false`). `true` entrega o convite ao agendamento CalDAV do Nextcloud, inclusive para convidados existentes e CANCEL. O plano e o resultado informam `imipEnabled`, lido da configuração nativa `dav/sendInvitations`: com ela desligada o servidor não envia e-mail (o iTIP interno ainda chega à caixa do convidado) e o assistente deve dizer isso ao utilizador. O app nunca confirma recebimento de e-mail. Escritas em agenda de outra pessoa ainda exigem `confirm_shared: true` após confirmação do utilizador.

**Confirmação antes de toda escrita (sem estado no servidor).** As escritas de eventos da tabela, chamadas sem `confirm: true`, só devolvem um plano (`requiresConfirmation: true`): valores atuais e propostos, participantes adicionados/removidos, destino, consequência para convites/CANCEL/lixo e o `etag` atual. Nada é escrito nem agendado. O assistente deve mostrar o plano, perguntar ao utilizador e só após um sim explícito repetir a chamada com `confirm: true` e os mesmos argumentos, reenviando o `etag` do plano (opcional; se vier e divergir, a escrita é recusada e um novo plano é necessário). Não há tabela, token nem registo de aprovação: o servidor não prova o sim humano, mas permissões, ACL, grants e `confirm_shared` seguem valendo. O selftest usa os handlers diretamente e não passa por esse fluxo.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

## Reuniões e reservas

`calendar_search_events` e `calendar_read_event` permitem encontrar eventos e consultar os detalhes visíveis, incluindo respostas a convites. A pesquisa aplica a confidencialidade antes de comparar o texto. Use `calendar_find_slots` com um período, duração e IDs de contas internas. Cada horário sugerido indica o resultado das verificações de disponibilidade; esta pode ser desconhecida e a sugestão não reserva o horário.

Use `calendar_create_event_with_external_attendees` ou `calendar_update_event_with_external_attendees` para indicar endereços de e-mail explícitos. Se necessário, consulte primeiro o contacto para escolher o endereço. Estas ferramentas têm permissões próprias e respeitam a configuração do servidor para participantes externos. A pré-visualização distingue o agendamento do envio de e-mail. Use `calendar_respond_to_invitation` para alterar a sua resposta no seu calendário e repita a chamada confirmada com o ETag da pré-visualização.

Para uma ocorrência de uma série, indique o `recurrence_id` original, não o novo início de uma exceção movida. Excluir uma ocorrência pode enviar uma atualização da série com essa data excluída.

## Salas e recursos

Use `calendar_list_resources` para descobrir as salas e os equipamentos visíveis para si. `calendar_check_resource_availability` consulta um período através do CalDAV; um estado desconhecido significa que o servidor não conseguiu verificá-lo. Use `calendar_reserve_resource` num evento não recorrente que organiza no seu calendário. Reveja o plano de agendamento e confirme-o com o ETag. O resultado indica separadamente a resposta da sala e a atualização do evento. Ao alterar a hora ou cancelar o evento, ative o agendamento para que o pedido também chegue à sala.

## Ligações de agendamento

O MCP disponibiliza as ferramentas de marcação de horários no Calendar 6.6.2, 6.6.3 e 6.6.4. O suporte à versão 6.6.4 começa no MCP 2.10.2. Estas ferramentas usam configurações de agendamento em calendários de que é titular e aos quais tem acesso através do MCP.

| Ferramenta | Operação | O que faz |
| --- | --- | --- |
| `calendar_list_appointments` | `calendar.read` | Lista as suas configurações e ligações de agendamento. |
| `calendar_create_appointment` | `calendar.create` | Cria uma ligação de agendamento com disponibilidade, duração e visibilidade próprias. |
| `calendar_update_appointment` | `calendar.edit` | Atualiza uma configuração e mantém a ligação de agendamento. |
| `calendar_delete_appointment` | `calendar.delete` | Retira a ligação. Os eventos já reservados permanecem no calendário. |
| `calendar_list_appointment_bookings` | `calendar.read` | Lista as reservas pendentes e confirmadas entre `from` e `to`, opcionalmente para um `appointment_id`. O intervalo máximo é de 93 dias. Mostra o nome da pessoa convidada e o horário. Os endereços de correio eletrónico e os tokens de confirmação são privados. |

A administração atribui cada ferramenta em **Administração → Nextcloud MCP → Acesso e permissões**. Selecione as ferramentas atribuídas ao cliente em **Definições pessoais → Nextcloud MCP** ou ao autorizar a ligação OAuth. Se faltar uma ferramenta, verifique a versão do Calendar, a atribuição da conta e a seleção da ligação. Em seguida, consulte o [guia de ferramentas](../tools.md) a partir dessa ligação. Consulte [Acesso e permissões](../../guides/permissions.md) para configurar a atribuição e a seleção.

Na pré-visualização, reveja o fuso horário, o horário semanal, a duração, o período de reserva e a visibilidade. Depois de aprovar a operação, repita o pedido com `confirm: true` e o respetivo `plan_state`. As faixas semanais usam a hora local no formato HH:mm. As durações e os intervalos são indicados em minutos, e o horizonte de reserva em dias. A visibilidade privada retira a ligação do seu perfil público. Qualquer pessoa com o URL pode reservar. A visibilidade pública também apresenta a ligação no perfil.

## Convites, recuperação e intercâmbio

- Filtre `calendar_search_events` por `participation_status: needs-action` para encontrar convites a aguardar resposta. Também são aceites `accepted`, `declined` e `tentative`.
- Use `calendar_list_trashed_events` para encontrar o ID de um evento eliminado e `calendar_restore_trashed_event` para o restaurar no calendário original. A recuperação depende da retenção e do âmbito dos calendários próprios. O restauro nativo mantém os dados sem enviar convites.
- `calendar_create_calendar` cria um calendário pessoal quando o âmbito dos calendários não é restrito. `calendar_list_calendar_shares` e `calendar_share_calendar` listam e gerem o acesso direto de contas locais aos calendários próprios, com acesso de leitura, escrita ou remoção.
- `calendar_import_ics` lê um documento visível em Files e copia até 100 eventos ou 1 MiB para um calendário próprio. A pré-visualização identifica duplicados e conflitos. A importação omite organizadores, participantes, alarmes e anexos. `calendar_export_ics` devolve um documento iCalendar dos UIDs selecionados de um calendário, incluindo exceções de séries totalmente visíveis.
- `calendar_set_event_reminders` substitui alarmes de apresentação relativos ao início de um evento ou série. Indique de zero a cinco antecedências em minutos. Os alarmes personalizados, de e-mail, absolutos, repetidos e específicos de ocorrências são preservados.

As escritas destes fluxos exigem `confirm: true` e o `plan_state` da pré-visualização.

## Disponibilidade e conflitos

`calendar_check_availability` exige a permissão independente `calendar.availability` e seleção explícita da ferramenta. Indique `from` (inclusivo) e `to` (exclusivo), com uma janela máxima de 31 dias. Use `calendars` opcional para selecionar até 20 caminhos de calendários acessíveis; omita-o para usar todos os calendários visíveis, sujeitos ao mesmo limite. `min_duration_minutes` filtra intervalos livres (30 por predefinição, intervalo 5–1440).

A resposta contém intervalos ocupados UTC, conflitos por sobreposição e intervalos livres comuns. Intervalos que apenas se tocam não entram em conflito. Eventos recorrentes, exceções de recorrência e fusos horários seguem as regras existentes de expansão do calendário; eventos cancelados e transparentes não bloqueiam tempo. Argumentos apenas de data e valores flutuantes de dia inteiro seguem a convenção existente de data civil UTC.

Não são devolvidos títulos, descrições ou dados de participantes de eventos. Aplicam-se partilhas nativas, restrições de âmbito Calendar e classificação de eventos. Se eventos ocultos, dados inválidos ou limites de análise impedirem um cálculo fiável, a ferramenta devolve erro em vez de afirmar que o período está livre. A análise permite até 2.000 objetos de calendário por calendário e 10.000 ocorrências no total. Eventos de duração zero também tornam a disponibilidade indisponível nesta política conservadora. A disponibilidade é um retrato dos calendários acessíveis, não uma reserva nem uma afirmação sobre calendários a que quem chama não pode aceder.

[English](../../../en/reference/tools/calendar.md) · [Español](../../../es/reference/tools/calendar.md) · [Català](../../../ca/reference/tools/calendar.md) · [Português (Brasil)](../../../pt-BR/reference/tools/calendar.md) · Português (Portugal)
