<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/troubleshooting.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/guides/troubleshooting.md).

# Resolução de problemas

## Teste manual após a instalação

```sh
URL=https://<instance>/apps/mcp/
AUTH='<user>:<app-password>'
H='-H Content-Type:application/json -H Accept:application/json,text/event-stream'

curl -s -u "$AUTH" $H -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' "$URL"
curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$URL"   # 202
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' "$URL"
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp_status","arguments":{}}}' "$URL"
```

Respostas esperadas:

| Situação | HTTP |
| --- | --- |
| Sem credenciais, credencial inválida ou utilizador desativado | 401 com `WWW-Authenticate: Basic` |
| Serviço desativado, utilizador não elegível ou desligado | 403 |
| `Origin` de outro anfitrião | 403 |
| Falta `Accept: application/json, text/event-stream` ou `Content-Type: application/json` | 406 |
| `MCP-Protocol-Version` desconhecido ou malformado: devolve `UnsupportedProtocolVersionError` (`-32022`, `data.supported`). As versões antigas aceites após `initialize` são 2025-03-26, 2025-06-18 e 2025-11-25; se o cabeçalho estiver ausente, assume-se 2025-03-26 e, em `initialize`, o cabeçalho é ignorado. Também devolve 400 quando `MCP-Protocol-Version`, `Mcp-Method` ou `Mcp-Name` diferem do corpo (`-32020`). O motivo de cada 400 é escrito no registo ao nível de depuração. | 400 |
| JSON inválido (`-32700`), lote ou envelope inválido (`-32600`) | 400 |
| Método desconhecido (`-32601`) ou parâmetros inválidos (`-32602`) | 200 com um erro JSON-RPC |
| GET ou DELETE | 405 |

## Diagnóstico opcional do Calendário (`occ mcp:calendar-selftest`)

Este diagnóstico não é um pré-requisito: nenhuma ferramenta depende dele para aparecer ou funcionar, e executá-lo não ativa nem oculta ferramentas. O diagnóstico cria calendários e eventos temporários e elimina-os no final, conforme descrito abaixo. É uma ferramenta de diagnóstico avançada e opcional para os administradores que queiram observar, passo a passo, a resposta real do CalDAV. Execute-a na raiz do Nextcloud como utilizador do servidor Web. O UID do organizador tem de corresponder a uma conta ativa, com um endereço de e-mail válido e o Calendário ativo. A retenção `dav/calendarRetentionObligation` não pode ser `0` (a limpeza seria permanente, pelo que o diagnóstico falha antes de criar qualquer objeto).

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID
```

Cada execução cria dois calendários com URIs `mcp-selftest-...-a`/`-b`, só altera eventos que ela própria criou e move esses calendários para o lixo no final. Sem opções, as três verificações opcionais apresentam `NOT TESTED`. Para testar convites internos, transferência e ACL:

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID \
  --attendee-uid ATTENDEE_UID \
  --shared-calendar '/remote.php/dav/calendars/ORGANIZER_UID/SHARED_URI/' \
  --acl-probe-user NO_ACCESS_UID
```

Esta execução afeta ambos os utilizadores (convite interno, CANCEL de limpeza e Activity no calendário partilhado) e remove apenas itens com o UID gerado pelo teste. Não comprova o funcionamento de SMTP.

| Etapa do relatório | Resultado esperado |
| --- | --- |
| `preflight`, `calendars` | Conta, aplicação e retenção válidas; dois MKCALENDAR HTTP 201 visíveis. |
| `create` | HTTP 201, UID e ETag lidos novamente, `sync-token` incrementado. |
| `suppression-create`, `suppression-update` | PUT 201/204, ATTENDEE `@example.invalid` sem SCHEDULE-STATUS; SEQUENCE incrementado. |
| `stale-tool`, `stale-dav` | Conflito no handler e HTTP 412 no DAV; dados e ETag intactos. |
| `move`, `overwrite` | MOVE 201, com o UID apenas no destino; Overwrite F devolve 412 e preserva ambos os objetos. |
| `delete` | HTTP 204, UID removido da leitura e colocado no lixo. |
| `invitations`, `transfer`, `acl` | `OK` se solicitados e comprovados; `NOT TESTED` se as opções não forem indicadas. |
| `cleanup`, `result` | Limpeza confirmada com HTTP 204, sessão reposta e resultado final do diagnóstico. |

Qualquer `FAIL` devolve um código diferente de zero. Se a limpeza falhar, `cleanup-resource` indica o caminho/UID a verificar no servidor. O resultado não altera as ferramentas disponíveis.

[Guias de operação](README.md).

[English](../../en/guides/troubleshooting.md) · [Español](../../es/guides/troubleshooting.md) · [Català](../../ca/guides/troubleshooting.md) · [Português (Brasil)](../../pt-BR/guides/troubleshooting.md) · Português (Portugal)

## Rastrear uma chamada autenticada de ferramenta

O administrador da instância pode ativar um rastreio privado para um ID exato de utilizador Nextcloud e uma lista explícita de nomes de ferramentas. Por exemplo, no servidor Nextcloud, crie um novo diário num diretório controlado pelo operador fora da raiz Web, armazenamento dos utilizadores e quaisquer registos expostos pelo MCP. Dê apenas ao processo PHP acesso ao ficheiro (modo 0600). Defina a configuração de sistema seguinte, substituindo os valores de exemplo por uma **data de expiração Unix fixa** absoluta, o caminho visível pelo processo e a conta selecionada. Calcular a expiração a partir da hora atual na configuração prolongaria continuamente a captura.

```php
'mcp.tool_diagnostics' => [
    'user' => '<exact Nextcloud UID>',
    'tools' => ['files_share'],
    'file' => '/var/log/private-mcp/tool-calls.jsonl',
    'until' => 0, // Replace with the Unix timestamp at which capture should stop.
],
```

Cada chamada autenticada regista um comprovativo UTC e um resultado com o mesmo ID aleatório de pedido. Uma pré-visualização que devolva um plano e uma confirmação que o apresente partilham uma referência opaca de plano. Um plano alterado tem uma nova referência. O diário contém classificações fixas e exclui credenciais, IDs de clientes e utilizadores, caminhos, URLs públicos, argumentos em bruto, respostas e mensagens de exceção.

Use uma pré-visualização controlada para verificar a captura antes de interpretar um incidente. Procure `preview_returned`, seguido de um comprovativo `confirmation` e respetivo resultado. Por exemplo, `invalid_plan_state` identifica uma referência de confirmação ausente ou inválida, `new_preview_required` indica que o servidor pediu nova aprovação e `share_refused` indica recusa pela regra de partilha. Um comprovativo isolado deixa o resultado incerto. Consulte o estado nativo antes de repetir uma escrita.

A captura começa no encaminhamento autenticado de ferramentas. Um diário vazio também pode resultar de janela expirada, conta ou ferramenta diferente, permissões do ficheiro, contenção de bloqueios ou limite de 1 MiB. Verifique o estado HTTP do ponto de acesso e os detalhes da ação do cliente para tentativas que não chegaram ao encaminhamento. Remova a configuração e elimine o diário privado após a investigação. Use um ficheiro e expiração novos em cada incidente.
