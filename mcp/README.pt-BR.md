<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: mcp/README.md -->
<!-- translation-source-revision: 2da7ebe84d0a21f8222124a6b681a6e7ed546422 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](README.md).

# Nextcloud MCP

**Seu Nextcloud, na conversa.** Busque arquivos, trabalhe com notas, calendários, contatos e tarefas, gerencie cards do Deck e acompanhe o Talk por um cliente MCP. Mail lê mensagens e anexos próprios e gerencia rascunhos locais não enviados, sem enviar mensagens; Forms gerencia formulários, perguntas, compartilhamento, respostas, arquivos e exportações completas por meio de uma extensão nativa qualificada; as leituras por páginas e as exportações CSV anteriores mantêm seus contratos. Operadores autorizados podem inspecionar logs do servidor.

[English](README.md) · [Español](README.es.md) · [Català](README.ca.md) · **Português (Brasil)** · [Português (Portugal)](README.pt-PT.md)

## Primeiros passos

Exige **Nextcloud 33–35** e **PHP 8.4+**. Este fork mantido independentemente de [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud) declara a versão **2.8.2** e mantém o identificador `mcp`. A edição da loja Nextcloud é a do projeto original; use o pacote verificado deste fork para instalar ou substituir.

1. [Instale o aplicativo](../docs/pt-BR/installation.md).
2. [Ative o serviço, a elegibilidade do usuário e a conexão pessoal](../docs/pt-BR/guides/permissions.md#habilitar-o-acesso).
3. [Conecte um cliente](../docs/pt-BR/guides/clients.md) a `https://cloud.example.com/apps/mcp/` usando OAuth ou senha de aplicativo, e selecione suas ferramentas permitidas.
4. Peça o **Guia das ferramentas** e experimente “Quais conversas do Talk têm mensagens não lidas?” ou “Encontre a proposta do projeto e faça um resumo.” São solicitações de exemplo, não transcrições gravadas.

## O acesso continua sob seu controle

Os catálogos abrangem onze módulos, além das ferramentas de guia e status. A descoberta filtra as ferramentas conforme os recursos nativos instalados, as atribuições de ferramentas e as seleções de conexão. Permissões nativas Nextcloud se aplicam a cada solicitação. Administradores podem limitar o acesso a pastas, calendários e listas de tarefas selecionados e excluir arquivos com etiquetas. Ferramentas de escrita retornam uma prévia, salvo confirmação explícita; o assistente deve obter aprovação do usuário. As ferramentas gerais de Arquivos não excluem arquivos. A limpeza confirmada do Forms limita-se aos arquivos que ele gerencia e mantém as evidências de recuperação. Ferramentas Mail, Forms e de logs exigem permissões explícitas. Rascunhos Mail, salvamento de anexos e todas as escritas do Forms exigem confirmação; logs continuam somente leitura e Mail nunca envia mensagens.

Resultados das ferramentas são enviados ao cliente de IA escolhido e estão sujeitos às suas políticas de processamento e retenção.

[Ferramentas](../docs/pt-BR/reference/tools.md) · [Permissões](../docs/pt-BR/guides/permissions.md) · [Proteção de arquivos](../docs/pt-BR/guides/file-protection.md) · [Solução de problemas](../docs/pt-BR/guides/troubleshooting.md)

## Licença

AGPL-3.0-or-later, com o termo adicional de atribuição da seção 7(b) no [NOTICE](NOTICE). Consulte [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt) para ver o texto integral da licença. Preserve a atribuição na página de administração, na documentação e nos avisos de autoria ao redistribuir ou oferecer versões modificadas pela rede.

## Créditos

O pacote inclui Smalot PdfParser, Symfony Polyfill Mbstring e o autoloader do Composer. Consulte [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) para ver versões, direitos autorais, licenças e créditos de desenvolvimento separados.

**Contribuidores.** Agradecimento a @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) pela checagem de visibilidade da raiz e a @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) pelo relato que motivou a auditoria das etiquetas ocultas. Agradecemos também a @matt-ek pela tradução alemã da interface ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) e a @Zegorax pelos clientes OAuth registrados por administradores ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).


Hierarquia de tarefas: [@mturgeonferland](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18).

A documentação original também credita a contribuição de Sintax/Jhonatan Jaworski ao [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw). Esse crédito histórico é preservado sem sugerir que XERJ revisou este fork.

Developed by Sintax — https://sintax.tech
