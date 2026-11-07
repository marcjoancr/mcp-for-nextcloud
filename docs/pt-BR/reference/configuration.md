<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/configuration.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../en/reference/configuration.md).

# Configuração

Use as interfaces de administração e configurações pessoais para alterar a política. Gravar preferências de política diretamente com `occ` ignora verificações de revisão, resolução de grupos e revogação de credenciais; não é uma forma suportada de administrar a política. Diagnósticos somente leitura, como `occ config:app:get mcp service_enabled`, continuam úteis.

| Armazenamento | Chave ou responsável | Significado |
| --- | --- | --- |
| Aplicativo `mcp` | `service_enabled` | `1` ativado; `0` ou ausente, desativado. Altere pelo comando/interface do serviço. |
| Usuário, aplicativo `mcp` | `eligible` | Exceção individual `1`/`0` de **Pode conectar**; sua ausência permite herança de grupo e depois o padrão desativado. |
| Usuário, aplicativo `mcp` | `connected` | `1` indica conexão pessoal ativa. É diferente de uma credencial OAuth válida. |
| Usuário, aplicativo `mcp` | `grant_<module>_<operation>` | Exceção individual legada de operação usada pelos padrões de compatibilidade das ferramentas originais. O acesso atual também exige atribuição da ferramenta e seleção na conexão; Mail, Forms e logs começam negados. |
| Regras de ferramentas | `mcp_tool_grants` | Atribuições individuais/de grupo: Permitir, Bloquear ou herança neutra, com a precedência descrita em permissões. |
| Seleções de conexão | `mcp_oauth_tools` | Nomes de ferramentas selecionadas por conexão OAuth; clientes Basic/sessão compartilham o perfil Nextcloud separado (ID de conexão zero). |
| Tabelas de política | `mcp_policy_state`, `mcp_policy_events` | Revisão/época global de autenticação e eventos persistentes de auditoria, limpeza e recibos. |
| Tabelas de grupos | `mcp_group_grants`, `mcp_group_life` | Política que somente concede permissões e identidade/ciclo de vida de grupo observados. |
| Tabelas de autenticação | `mcp_auth_users`, `mcp_auth_recheck` | Gerações/invalidações de credenciais por usuário e verificações adiadas do ciclo de vida. |
| Tabelas de recursos | `mcp_scope_modes`, `mcp_scope_items` | Restrições independentes de Files, Calendar e Tasks. |
| Tabela de clientes | `mcp_oauth_clients` | Estado autoritativo e gerações de clientes nativos/personalizados gerenciados após migrar. |
| Aplicativo `mcp` | `oauth_client_hosts` | Hosts exatos de clientes publicados; ausente ou vazio usa `claude.ai,chatgpt.com`. Edite em clientes OAuth. |
| Entradas antigas do aplicativo | `oauth_native_client_enabled`, `oauth_custom_clients` | Entradas de migração para a tabela de clientes, não a autoridade atual dos clientes gerenciados. |
| Tabela própria | `mcp_logs_groups` | Grupos JSON canônicos e versão; vazio significa somente administradores. Importa a configuração antiga na primeira leitura. |
| Aplicativo `mcp` | `checkout_max_bytes` | Limite de upload de checkout em bytes; padrão 50 MiB, limitado por `post_max_size` do PHP. |
| Aplicativo `mcp` | `file_write_policy` | Lista opcional versionada de nomes de arquivo permitidos; ausente significa desativada. Altere em Proteção de dados; estado inválido/ilegível recusa as escritas afetadas. |
| Aplicativo `mcp` | `backup_settings` | Documento versão 1 com a pasta atual na raiz, nomes históricos reservados e preferência de cópias de Files. Ausente: `MCP backups`, cópias ativadas. Altere em Proteção de dados; estado malformado/indisponível recusa as operações pertinentes. |
| Aplicativo `mcp` | `hidden_system_tags` | Lista JSON de IDs de etiquetas de sistema excluídas; `[]` desativa exclusões por etiquetas. |
| Aplicativo `mcp` | `logs_hide_file_names` | `1` padrão: ocultar texto dos logs enquanto houver exclusões; `0` permite o log completo para diagnóstico. |

## Cópias de backup MCP

Em administração → **Proteção de dados** → **Cópias de backup MCP**, escolha um único nome de pasta diretamente na raiz pessoal de Files de cada usuário que executa a ação. O padrão é `MCP backups`; pastas iniciadas por ponto, como `.mcp-backups`, são aceitas. Os nomes são normalizados em Unicode NFC, limitados a 255 bytes UTF-8 e validados pelo Nextcloud. Caminhos, separadores, caracteres de controle, `.` e `..` são recusados.

Alterar o nome não move nem exclui cópias existentes. A pasta é criada na primeira cópia. Todo nome configurado anteriormente, inclusive `MCP backups`, continua reservado contra escritas MCP de Files na raiz do usuário; pastas comuns aninhadas com o mesmo nome não são reservadas automaticamente. As exclusões existentes continuam protegendo espelhos de originais ocultos, inclusive aliases canônicos compartilhados. Isso não é uma ACL do sistema de arquivos nem uma proibição geral de escrita por montagens compartilhadas renomeadas.

As cópias de Files antes da escrita vêm ativadas. Administradores só podem suspendê-las com Versions habilitado; sua disponibilidade é verificada novamente para o usuário que executa a ação. Versions desconhecido ou indisponível torna as cópias obrigatórias e nunca libera uma escrita recusada pela pré-condição existente de Versions. O versionamento nativo segue sua própria política e não garante recuperar todo arquivo/backend. Planos e recibos mostram um caminho de backup vazio quando nenhuma cópia MCP será/foi feita e não prometem recuperação MCP. Excluir contatos sempre exige uma cópia completa verificada do vCard em `/<configured folder>/Contacts`, independentemente da configuração de Files.

A API administrativa usa GET/PUT `/api/backup-settings`, com verificações de autenticação, administrador e CSRF. PUT envia `folder` completo, `copies` booleano e `expectedRevision` string. Revisões ausentes/desatualizadas retornam 428/409. O painel preserva entradas não salvas após conflito ou resultado incerto e exige recarga verificada antes de salvar novamente. As respostas verificam se a revisão ainda corresponde às configurações; um commit concorrente durante sua montagem exige nova leitura. Uma configuração corrompida não retorna aos padrões nem perde reservas históricas; recuperá-la exige investigar e restaurar seu último documento completo conhecido, não excluir a chave.

As escritas releem as reservas nos pontos de alteração, inclusive links de checkout e desfazer lotes. Desfazer verifica todos os pontos de origem/destino registrados antes de mover qualquer item e mantém diretórios registrados que passaram a ser reservados. Commits de política e escritas nativas no sistema de arquivos não formam uma transação atômica: uma alteração concorrente após a verificação final fica fora dessa garantia. Uma recusa após cópia/criação pode deixar um backup ou arquivo vazio corretamente informado; não há exclusão nem repetição automática.

## Política de tipos de arquivo

Em administração → **Proteção de dados** → **Tipos de arquivo graváveis pelo MCP**, escolha **Sem restrição (padrão)** ou **Somente extensões listadas**, revise e salve. A configuração se aplica globalmente às escritas MCP de Files e Notes, independentemente de permissões concedidas, permissões nativas, restrições de recursos, Guests, exclusões, travas e confirmações. Nunca libera uma escrita que seria recusada. É uma regra de nome/extensão, não inspeção MIME/conteúdo, scanner de malware nem proteção contra injeção de prompt; outros clientes ficam fora do seu escopo.

A lista permite no máximo 128 extensões únicas, separadas por vírgulas ou linhas. Um ponto inicial é opcional; os itens são convertidos para minúsculas e ordenados. Cada item contém de 1 a 32 letras/dígitos ASCII, `_` ou `-`, começando por letra/dígito. A avaliação usa a última extensão do nome final resolvido, sem diferenciar maiúsculas. Nomes sem extensão (inclusive um nome iniciado por ponto sem outro ponto posterior) precisam da opção explícita separada, desativada por padrão. Nomes vazios, pontos/espaços finais e nomes malformados são recusados enquanto a política está ativa; os próprios nomes de pastas não são filtrados por extensão.

Cobre criação/upload, edição e substituição, upload de checkout, restauração de versões, cópia/movimentação, lotes ordenados e desfazer, além de criar/editar/editar parcialmente/mover Notes. Backups internos de recuperação de Files e obrigatórios de Contacts continuam isentos; uma escrita pública usando `.bak` ou `.vcf` não tem isenção. Prévia e confirmação verificam a política; os pontos de alteração a leem novamente, portanto um upload de checkout recém-bloqueado é recusado e um desfazer bloqueado mantém os dados e seu recibo. Configuração indisponível ou malformada resulta em recusa segura. Um salvamento incerto não é repetido automaticamente; use o fluxo de leitura/nova tentativa da interface para inspecionar o que foi armazenado.

Com a política ativa, operações de pasta verificam previamente no máximo 2.000 nós inspecionados/projetados por ponto de alteração; cópias mantêm também o limite de 1 GiB. A cópia de pasta verifica a árvore visível/legível e copia a partir desse manifesto verificado, com novas verificações por entrada. Adições posteriores são omitidas; uma falha pode deixar uma cópia parcial informada, que nunca é excluída automaticamente. Mover pasta também verifica descendentes previamente, mas continua uma única movimentação nativa: arquivos inseridos por outro cliente após a última verificação podem ir junto. Atualizações de política e escritas no sistema de arquivos não compartilham transação atômica, e armazenamento/conteúdo nativo pode mudar após a verificação. Alterações de visibilidade podem ocultar detalhes desatualizados do recibo preservando os dados já gravados. Revise resultados incertos antes de qualquer nova ação; não há promessa de reversão automática nem repetição segura.

Essas tabelas descrevem persistência, não uma API pública de mutação SQL. Editar linhas ou restaurar apenas algumas tabelas pode quebrar garantias de revisão, ciclo de vida e revogação. Consulte [permissões](../guides/permissions.md), [OAuth](oauth.md) e as [orientações de atualização/recuperação](../installation.md).

Nextcloud controla `enabled`, `installed_version`, `types`, `levels` e `ocsid`; não são interruptores de política MCP. Em particular, `enabled=yes` do Nextcloud é diferente de `service_enabled=1`.

[Guias de operação](../guides/README.md).

[English](../../en/reference/configuration.md) · [Español](../../es/reference/configuration.md) · [Català](../../ca/reference/configuration.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/reference/configuration.md)

## Entrega do código-fonte

Após preparar o kit completo, o operador configura separadamente o destino do código-fonte na configuração de sistema do Nextcloud (`config.php`):

```php
'mcp_source_offer' => [
    'version' => '2.7.0',
    'sourceCommit' => 'FULL_40_CHARACTER_COMMIT_SHA',
    'manifestSha256' => 'SEPARATELY_TRUSTED_64_CHARACTER_MANIFEST_SHA256',
    'baseUrl' => 'https://sources.example.invalid/immutable-release-directory',
],
```

Essa configuração de sistema contém metadados da versão, não preferências de usuário ou capacidade. Substitua os marcadores de revisão e hash pelos do kit de código-fonte aprovado; substitua o destino de exemplo por um diretório que sirva seus cinco arquivos: `manifest.json`, `source.bundle`, `runtime-dependencies.tar.gz`, `mcp-<version>.tar.gz` e `BUILDING.md`. Mantenha o hash esperado em um registro de confiança separado do operador, fora dos arquivos do aplicativo cobertos por esse manifesto.

A versão e o commit do código-fonte devem corresponder ao `appinfo/source.json` gerado no pacote e à versão instalada do aplicativo. Pacotes de desenvolvimento com alterações não commitadas não têm identidade de código-fonte. Configuração ausente, inválida ou divergente mostra uma oferta indisponível nas configurações pessoais e na descoberta MCP; não concede acesso nem altera a autenticação. Uma oferta configurada ainda precisa ser obtida com sucesso e ter seu hash verificado antes da aceitação da versão.

Use HTTPS sem credenciais, consulta, fragmento, codificação percentual ou segmentos de travessia. HTTP com endereço literal de loopback fica reservado a ambientes isolados de aceitação. Não publique uma URL de teste como destino de versão nem envie credenciais Nextcloud ao servidor de código-fonte. O aplicativo apenas apresenta os links; não os busca. Publicação pública, comportamento de integridade suportado e qualificação de atualização/recuperação no servidor de destino continuam critérios de lançamento separados.
