<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/configuration.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/reference/configuration.md).

# Configuração

Utilize as interfaces de administração e definições pessoais para alterar políticas. Escritas diretas com `occ` nas preferências de política contornam as verificações de revisão, a resolução de grupos e a revogação de credenciais; não são uma forma suportada de administrar políticas. Diagnósticos apenas de leitura, como `occ config:app:get mcp service_enabled`, continuam úteis.

| Armazenamento | Chave ou responsável | Significado |
| --- | --- | --- |
| Aplicação `mcp` | `service_enabled` | `1` ativo; `0` ou ausente inativo. Altere pelo comando/interface do serviço. |
| Utilizador, aplicação `mcp` | `eligible` | Exceção individual `1`/`0` a **Pode ligar-se**; a ausência permite herança de grupos, seguida da predefinição inativa. |
| Utilizador, aplicação `mcp` | `connected` | `1` significa ligação pessoal ativa. É diferente de uma credencial OAuth válida. |
| Utilizador, aplicação `mcp` | `grant_<module>_<operation>` | Exceção individual antiga de operação usada nas predefinições de compatibilidade das ferramentas originais. O acesso atual também exige atribuição da ferramenta e seleção na ligação; Mail, Forms e registos começam recusados. |
| Regras de ferramentas | `mcp_tool_grants` | Atribuições de ferramentas individuais/de grupo: Permitir, Bloquear ou herança neutra, com a precedência descrita em permissões. |
| Seleções de ligação | `mcp_oauth_tools` | Nomes de ferramentas selecionados por ligação OAuth; clientes Basic/sessão partilham o perfil Nextcloud separado (ID de ligação zero). |
| Tabelas de política | `mcp_policy_state`, `mcp_policy_events` | Revisão/época global de autenticação e eventos duradouros de auditoria, limpeza e comprovativos. |
| Tabelas de grupos | `mcp_group_grants`, `mcp_group_life` | Política apenas de concessão e identidade/ciclo de vida observados dos grupos. |
| Tabelas de autenticação | `mcp_auth_users`, `mcp_auth_recheck` | Gerações/cortes de credenciais por utilizador e novas verificações diferidas do ciclo de vida. |
| Tabelas de recursos | `mcp_scope_modes`, `mcp_scope_items` | Restrições independentes de Files, Calendar e Tasks. |
| Tabela de clientes | `mcp_oauth_clients` | Estado e gerações autoritativos dos clientes nativos/personalizados geridos após a migração. |
| Aplicação `mcp` | `oauth_client_hosts` | Nomes exatos dos servidores de clientes publicados; ausente ou vazio usa `claude.ai,chatgpt.com`. Edite em Clientes OAuth. |
| Entradas antigas da aplicação | `oauth_native_client_enabled`, `oauth_custom_clients` | Entradas de migração para a tabela de clientes, não a autoridade atual dos clientes geridos. |
| Tabela própria | `mcp_logs_groups` | Grupos em JSON canónico e versão; vazio significa apenas administradores. Importa a configuração antiga na primeira leitura. |
| Aplicação `mcp` | `checkout_max_bytes` | Limite de carregamento de checkout em bytes; predefinição de 50 MiB, limitada pelo `post_max_size` do PHP. |
| Aplicação `mcp` | `file_write_policy` | Lista opcional versionada de nomes/extensões permitidos; ausente significa inativa. Altere em Proteção de dados; um estado inválido/ilegível recusa as escritas afetadas. |
| Aplicação `mcp` | `backup_settings` | Documento de versão 1 com a pasta atual na raiz, nomes históricos reservados e preferência de cópias de Files. Ausente: `MCP backups`, cópias ativas. Altere em Proteção de dados; estado malformado/indisponível recusa operações aplicáveis. |
| Aplicação `mcp` | `hidden_system_tags` | Lista JSON de IDs de etiquetas de sistema excluídas; `[]` desativa as exclusões por etiquetas. |
| Aplicação `mcp` | `logs_hide_file_names` | `1` por predefinição: oculta o texto das mensagens dos registos enquanto houver exclusões; `0` permite o registo completo para diagnóstico. |

## Cópias de segurança MCP

Em Administração → **Proteção de dados** → **Cópias de segurança MCP**, escolha um único nome de pasta diretamente na raiz pessoal de Files de cada utilizador que executa a operação. A predefinição é `MCP backups`; uma pasta iniciada por ponto, como `.mcp-backups`, é suportada. Os nomes são normalizados para Unicode NFC, limitados a 255 bytes UTF-8 e validados pelo Nextcloud. Caminhos, separadores, caracteres de controlo, `.` e `..` são recusados.

Mudar o nome não move nem elimina cópias existentes. A pasta é criada na primeira cópia. Todos os nomes anteriormente configurados, incluindo `MCP backups`, ficam reservados contra escritas MCP Files na raiz do utilizador; pastas comuns aninhadas com o mesmo nome não ficam automaticamente reservadas. As exclusões existentes continuam a proteger espelhos de originais ocultos, incluindo aliases canónicos partilhados. Isto não é uma ACL do sistema de ficheiros nem uma proibição geral de escrita através de montagens partilhadas cujo nome foi alterado.

As cópias de Files anteriores à escrita estão ativas por predefinição. Os administradores só podem suspendê-las com Versions ativo; a disponibilidade é novamente verificada para o utilizador que executa a operação. Versions desconhecido ou indisponível torna as cópias obrigatórias e nunca permite uma escrita recusada pela precondição existente de Versions. O controlo nativo de versões segue a sua própria política e não garante a recuperação de todos os ficheiros/sistemas de armazenamento. Planos e comprovativos mostram um caminho de cópia vazio quando não será/foi criada uma cópia MCP e não prometem recuperação por MCP. Eliminar um contacto exige sempre uma cópia integral verificada do vCard em `/<configured folder>/Contacts`, independentemente da definição de Files.

A API de administração usa GET/PUT `/api/backup-settings`, com verificações de autenticação, administrador e CSRF. PUT envia `folder` completo, `copies` booleano e `expectedRevision` como cadeia de texto. Revisões ausentes/desatualizadas devolvem 428/409. O painel preserva entradas não guardadas após um conflito ou resultado incerto e exige uma atualização verificada antes de voltar a guardar. As respostas verificam se a revisão ainda corresponde às definições; uma alteração simultânea durante a construção da resposta exige nova leitura. Uma configuração corrompida não regressa às predefinições nem perde reservas históricas; a recuperação exige investigar e restaurar o último documento completo conhecido, em vez de eliminar a chave.

As escritas voltam a ler as reservas nos pontos de alteração, incluindo ligações de checkout e anulação de lotes. A anulação verifica todos os extremos registados antes de mover algo e conserva os diretórios registados que passaram a estar reservados. As alterações de política e as escritas nativas no sistema de ficheiros não formam uma transação atómica: uma alteração simultânea após a última verificação fica fora desta garantia. Uma recusa após cópia/criação pode deixar uma cópia de segurança ou ficheiro vazio corretamente indicado; não se tenta eliminar ou repetir automaticamente.

## Política de tipos de ficheiro

Em Administração → **Proteção de dados** → **Tipos de ficheiro graváveis através de MCP**, escolha **Sem restrições (predefinição)** ou **Apenas as extensões indicadas**, reveja e guarde. A definição aplica-se globalmente às escritas MCP Files e Notes, independentemente de permissões concedidas, permissões nativas, restrições de recursos, Guests, exclusões, bloqueios e confirmações. Nunca disponibiliza uma escrita recusada por outro motivo. É uma regra de nomes/extensões, não uma inspeção de MIME/conteúdo, um detetor de software malicioso ou proteção contra injeção de instruções; os outros clientes ficam fora do seu âmbito.

A lista aceita no máximo 128 extensões únicas, separadas por vírgulas ou linhas. Um ponto inicial é opcional; os valores são convertidos para minúsculas e ordenados. Cada um contém 1–32 letras/dígitos ASCII, `_` ou `-`, começando por letra/dígito. A avaliação usa a última extensão do nome final resolvido, sem distinguir maiúsculas. Nomes sem extensão (incluindo os iniciados por ponto sem outro ponto posterior) exigem uma opção explícita separada, inativa por predefinição. Nomes vazios, terminados em ponto/espaço ou malformados são recusados enquanto a política estiver ativa; os nomes das pastas não são filtrados por extensão.

Abrange criação/carregamento, edição e substituição, carregamento de checkout, restauro de versão, cópia/movimentação, lotes ordenados e anulação, e criação/edição/edição parcial/movimentação de Notes. As cópias internas de recuperação de Files e as obrigatórias de Contacts mantêm a isenção; uma escrita pública com `.bak` ou `.vcf` não está isenta. A pré-visualização e a confirmação verificam a política; os pontos de alteração voltam a lê-la, pelo que um carregamento de checkout entretanto bloqueado é recusado e uma anulação bloqueada conserva os dados e o comprovativo. Uma configuração indisponível ou malformada recusa o acesso. Uma gravação incerta não é repetida automaticamente; use o fluxo de leitura/nova tentativa da interface para consultar o que está guardado.

Com a política ativa, as operações sobre pastas verificam previamente no máximo 2.000 nós inspecionados/projetados por ponto de alteração; as cópias mantêm também o limite de 1 GiB. A cópia de pastas verifica a árvore visível/legível e copia a partir desse manifesto verificado, com novas verificações por entrada. Adições posteriores são omitidas; uma falha pode deixar uma cópia parcial indicada no resultado, nunca eliminada automaticamente. Mover pastas também verifica previamente os descendentes, mas continua a ser uma única operação nativa: ficheiros que outro cliente insira após a última verificação podem acompanhar a pasta. As atualizações de política e as escritas no sistema de ficheiros não partilham uma transação atómica, e o armazenamento/conteúdo nativo pode mudar após uma verificação. Alterações de visibilidade podem ocultar detalhes desatualizados dos comprovativos, conservando os dados já escritos. Reveja resultados incertos antes de nova ação; não se promete reversão automática ou repetição segura.

Estas tabelas descrevem persistência, não uma API pública de alteração por SQL. Editar linhas ou restaurar apenas algumas tabelas pode quebrar as garantias de revisão, ciclo de vida e revogação. Consulte [permissões](../guides/permissions.md), [OAuth](oauth.md) e [atualização/recuperação](../installation.md).

O Nextcloud gere `enabled`, `installed_version`, `types`, `levels` e `ocsid`; não são interruptores de política MCP. Em particular, `enabled=yes` do Nextcloud é diferente de `service_enabled=1`.

[Guias de operação](../guides/README.md).

[English](../../en/reference/configuration.md) · [Español](../../es/reference/configuration.md) · [Català](../../ca/reference/configuration.md) · [Português (Brasil)](../../pt-BR/reference/configuration.md) · Português (Portugal)

## Entrega do código-fonte

O operador configura separadamente o destino do código-fonte, na configuração de sistema do Nextcloud (`config.php`), depois de preparar o kit completo:

```php
'mcp_source_offer' => [
    'version' => '2.7.0',
    'sourceCommit' => 'FULL_40_CHARACTER_COMMIT_SHA',
    'manifestSha256' => 'SEPARATELY_TRUSTED_64_CHARACTER_MANIFEST_SHA256',
    'baseUrl' => 'https://sources.example.invalid/immutable-release-directory',
],
```

Esta definição de sistema contém metadados da versão, não uma preferência de utilizador ou de funcionalidades. Substitua a revisão e o resumo criptográfico de exemplo pelos do kit aprovado; substitua o destino por um diretório que sirva os cinco ficheiros: `manifest.json`, `source.bundle`, `runtime-dependencies.tar.gz`, `mcp-<version>.tar.gz` e `BUILDING.md`. Guarde o resumo esperado num registo do operador de confiança independente, fora dos ficheiros da aplicação abrangidos pelo manifesto.

A versão e o commit de origem devem corresponder ao `appinfo/source.json` gerado do pacote e à versão instalada da aplicação. Pacotes de desenvolvimento com alterações não registadas não têm identidade de origem. Configuração ausente, inválida ou incompatível apresenta uma oferta indisponível nas definições pessoais e na descoberta MCP; não concede acesso nem altera a autenticação. Uma oferta configurada ainda exige obtenção bem-sucedida e verificação do resumo criptográfico antes da aceitação da versão.

Use HTTPS sem credenciais, consulta, fragmento, codificação percentual ou segmentos de travessia de diretórios. HTTP com endereço literal de loopback fica reservado a testes de aceitação isolados. Não publique um URL de teste como destino de uma versão nem envie credenciais Nextcloud a um servidor de código-fonte. A aplicação apenas apresenta as ligações; não as obtém. Publicação pública, comportamento de integridade suportado e qualificação da atualização/recuperação no servidor de destino continuam a ser critérios de aceitação separados.
