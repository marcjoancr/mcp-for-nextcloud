<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/calendar.md -->
<!-- translation-source-revision: c5ff622fac628b942d9945fe50aa21a4b7a49262 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../../en/reference/tools/calendar.md).

# Calendário

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Calendário** | listar calendários e eventos (recorrência, fusos, dia inteiro) | criar, editar, mover, excluir e transferir eventos pelo pipeline CalDAV de verdade, com participantes. As escritas ficam desligadas até o administrador liberá-las na matriz de permissões (sem passo no terminal), e cada uma devolve antes um plano: **nada é gravado antes de o usuário aprovar.** |

| Ferramenta | Permissão | Observações |
| --- | --- | --- |
| `calendar_list_calendars` `{}` | calendar.read | Calendários visíveis ao usuário, próprios e compartilhados. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Eventos no intervalo (padrão: próximos 7 dias), com recorrência expandida. |
| `calendar_create_event` | calendar.create | Cria um evento. `recurrence` aceita uma regra semanal ou mensal limitada. `attendees` aceita UIDs internos. |
| `calendar_update_event` | calendar.edit | Atualiza o evento ou o `recurrence_id` selecionado. A lista de convidados e a recorrência de uma série têm restrições. |
| `calendar_move_event` | calendar.move | MOVE no mesmo dono; participantes não são avisados, como no app Calendar. |
| `calendar_delete_event` | calendar.delete | Exclui uma série na lixeira ou exclui uma ocorrência original quando `recurrence_id` é informado. |
| `calendar_transfer_event` | calendar.transfer | MOVE para agenda de outro dono compartilhada com escrita; recusa participantes. |

Calendar expõe leitura e operações de escrita, sem nenhum passo no terminal. As escritas nascem desligadas na matriz do admin (Administração → MCP) e só aparecem para quem recebeu o grant; toda escrita exige ainda `confirm: true`, a ACL do Nextcloud (e `confirm_shared` em agenda alheia) e o ETag opcional. Compatibilidade: o app declara `min-version` 33 e `max-version` 35 em `info.xml`; a parte interna (`EmbeddedCalDavServer` do core) é coberta pelos testes e por essa faixa, e uma versão nova do Nextcloud exige uma nova versão do app. O app Calendar precisa estar habilitado para o usuário. Sabre e Symfony Console vêm do core do Nextcloud e ficam fora do `vendor/` do pacote.

| Ferramenta de escrita | Permissão | Comportamento |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Cria um evento. `recurrence` aceita uma regra semanal ou mensal limitada. `attendees` aceita UIDs internos. |
| `calendar_update_event` | calendar.edit | Atualiza o evento ou o `recurrence_id` selecionado. A lista de convidados e a recorrência de uma série têm restrições. |
| `calendar_move_event` | calendar.move | MOVE no mesmo dono; participantes não são avisados, como no app Calendar. |
| `calendar_delete_event` | calendar.delete | Exclui uma série na lixeira ou exclui uma ocorrência original quando `recurrence_id` é informado. |
| `calendar_transfer_event` | calendar.transfer | MOVE para agenda de outro dono compartilhada com escrita; recusa participantes. |

Create, update e delete usam `send_invitations: false` por padrão (`x-nc-scheduling: false`). `true` entrega o convite ao agendamento CalDAV do Nextcloud, inclusive para convidados existentes e CANCEL. O plano e o resultado informam `imipEnabled`, lido da configuração nativa `dav/sendInvitations`: com ela desligada o servidor não envia e-mail (o iTIP interno ainda chega à caixa do convidado) e o assistente deve dizer isso ao usuário. O app nunca confirma recebimento de e-mail. Escritas em agenda de outra pessoa ainda exigem `confirm_shared: true` após confirmação do usuário.

**Confirmação antes de toda escrita (sem estado no servidor).** As escritas de eventos da tabela, chamadas sem `confirm: true`, só devolvem um plano (`requiresConfirmation: true`): valores atuais e propostos, participantes adicionados/removidos, destino, consequência para convites/CANCEL/lixeira e o `etag` atual. Nada é escrito nem agendado. O assistente deve mostrar o plano, perguntar ao usuário e só após um sim explícito repetir a chamada com `confirm: true` e os mesmos argumentos, reenviando o `etag` do plano (opcional; se vier e divergir, a escrita é recusada e um novo plano é necessário). Não há tabela, token nem registro de aprovação: o servidor não prova o sim humano, mas permissões, ACL, grants e `confirm_shared` seguem valendo. O selftest usa os handlers diretamente e não passa por esse fluxo.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

## Reuniões e reservas

`calendar_search_events` e `calendar_read_event` encontram eventos e leem detalhes visíveis, incluindo respostas a convites. A busca aplica a confidencialidade antes de comparar o texto. Use `calendar_find_slots` com um período, duração e IDs de contas internas. Cada horário sugerido informa o resultado das verificações de disponibilidade; ela pode ser desconhecida, e a sugestão não reserva o horário.

Use `calendar_create_event_with_external_attendees` ou `calendar_update_event_with_external_attendees` para informar endereços de e-mail explícitos. Se necessário, consulte o contato primeiro para escolher o endereço. Essas ferramentas têm permissões próprias e respeitam a configuração do servidor para participantes externos. A prévia diferencia o agendamento do envio de e-mail. Use `calendar_respond_to_invitation` para alterar sua resposta no seu calendário e repita a chamada confirmada com o ETag da prévia.

Para uma ocorrência de uma série, informe o `recurrence_id` original, não o novo início de uma exceção movida. Excluir uma ocorrência pode enviar uma atualização da série com aquela data excluída.

## Salas e recursos

Use `calendar_list_resources` para descobrir salas e equipamentos visíveis para você. `calendar_check_resource_availability` consulta um período pelo CalDAV; um status desconhecido significa que o servidor não conseguiu verificá-lo. Use `calendar_reserve_resource` em um evento não recorrente que você organiza no seu calendário. Revise o plano de agendamento e confirme com o ETag. O resultado informa separadamente a resposta da sala e a atualização do evento. Ao alterar o horário ou cancelar o evento, ative o agendamento para que a solicitação também chegue à sala.

## Links de agendamento

Use `calendar_list_appointments` para encontrar configurações de agendamento nos seus calendários visíveis. Crie, edite ou retire uma configuração com `calendar_create_appointment`, `calendar_update_appointment` e `calendar_delete_appointment`. Revise o fuso horário, a programação semanal, a duração, o período de agendamento e a visibilidade. Os horários semanais usam HH:mm local; durações e intervalos são em minutos, e o horizonte de agendamento é em dias. Repita a escrita aprovada com o `plan_state` da prévia.

A visibilidade privada mantém o link fora do perfil público, mas qualquer pessoa com a URL pode agendar. A visibilidade pública também o lista no perfil. Excluir a configuração desativa o link. O Calendar 6.6.2, 6.6.3 e 6.6.4 oferecem essas ferramentas quando o administrador as concede e a conexão as seleciona.

## Convites, recuperação e intercâmbio

- Filtre `calendar_search_events` por `participation_status: needs-action` para encontrar convites aguardando resposta. Também aceita `accepted`, `declined` e `tentative`.
- Use `calendar_list_trashed_events` para localizar o ID de um evento excluído e `calendar_restore_trashed_event` para restaurá-lo ao calendário original. A recuperação depende da retenção e do escopo dos calendários próprios. A restauração nativa mantém os dados do evento sem enviar convites.
- `calendar_create_calendar` cria um calendário pessoal quando o escopo de calendários não é restrito. `calendar_list_calendar_shares` e `calendar_share_calendar` listam e gerenciam o acesso direto de contas locais aos calendários próprios, com acesso de leitura, escrita ou remoção.
- `calendar_import_ics` lê um documento visível no Files e copia até 100 eventos ou 1 MiB para um calendário próprio. A prévia identifica duplicatas e conflitos. A importação omite organizadores, participantes, alarmes e anexos. `calendar_export_ics` retorna um documento iCalendar dos UIDs selecionados de um calendário, incluindo exceções de séries totalmente visíveis.
- `calendar_list_appointment_bookings` lista reservas pendentes e confirmadas dos links visíveis de agendamento do Calendar 6.6.2, 6.6.3 ou 6.6.4. Filtre por data e, opcionalmente, por configuração. O resultado inclui o nome do convidado e o horário; e-mail e tokens de confirmação permanecem privados.
- `calendar_set_event_reminders` substitui alarmes de exibição relativos ao início de um evento ou série. Informe de zero a cinco antecedências em minutos. Alarmes personalizados, de e-mail, absolutos, repetidos e específicos de ocorrências são preservados.

As escritas desses fluxos exigem `confirm: true` e o `plan_state` da prévia.

## Disponibilidade e conflitos

`calendar_check_availability` exige a permissão independente `calendar.availability` e seleção explícita da ferramenta. Informe `from` (inclusivo) e `to` (exclusivo), com janela máxima de 31 dias. Use `calendars` opcional para selecionar até 20 caminhos de calendários acessíveis; omita para usar todos os visíveis, sujeito ao mesmo limite. `min_duration_minutes` filtra intervalos livres (padrão 30, intervalo 5–1440).

A resposta contém intervalos ocupados em UTC, conflitos de sobreposição e intervalos livres em comum. Intervalos que apenas se tocam não conflitam. Eventos recorrentes, exceções de recorrência e fusos seguem as regras existentes de expansão de calendários; eventos cancelados e transparentes não bloqueiam tempo. Argumentos somente data e valores flutuantes de dia inteiro seguem a convenção UTC existente de data civil.

Nenhum título, descrição ou dado de participante de eventos é retornado. Compartilhamentos nativos, restrições de escopo Calendar e classificação de eventos se aplicam. Se eventos ocultos, dados inválidos ou limites de varredura impedem um cálculo confiável, a ferramenta retorna erro em vez de afirmar que o tempo está livre. A varredura permite até 2.000 objetos por calendário e 10.000 ocorrências no total. Eventos de duração zero também tornam a disponibilidade indisponível nesta política conservadora. A disponibilidade é um retrato dos calendários acessíveis, não uma reserva nem uma afirmação sobre calendários inacessíveis ao solicitante.

[English](../../../en/reference/tools/calendar.md) · [Español](../../../es/reference/tools/calendar.md) · [Català](../../../ca/reference/tools/calendar.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/calendar.md)
