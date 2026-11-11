<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: mcp/README.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](README.md).

# Nextcloud MCP

**O seu Nextcloud, na conversa.** Pesquise ficheiros, trabalhe com notas, calendários, contactos e tarefas, gira cartões do Deck e ponha o Talk em dia através de um cliente MCP. Mail lê mensagens e anexos próprios e gere rascunhos locais não enviados, sem enviar; Forms lê resultados permitidos e exporta páginas CSV limitadas. Operadores autorizados podem consultar os registos do servidor.

[English](README.md) · [Español](README.es.md) · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · **Português (Portugal)**

## Primeiros passos

Exige **Nextcloud 33–35** e **PHP 8.4+**. Este fork independente de [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud) declara a versão **2.7.0** e mantém o identificador `mcp`. A edição da Nextcloud App Store é a do projeto original; use o pacote revisto deste fork para instalar ou substituir a aplicação.

1. [Instale a aplicação](../docs/pt-PT/installation.md).
2. [Ative o serviço, a elegibilidade do utilizador e a ligação pessoal](../docs/pt-PT/guides/permissions.md#ativar-o-acesso).
3. [Ligue um cliente](../docs/pt-PT/guides/clients.md) a `https://cloud.example.com/apps/mcp/` com OAuth ou uma palavra-passe de aplicação, e selecione as ferramentas permitidas.
4. Peça o **Guia das ferramentas** e experimente «Que conversas do Talk têm mensagens por ler?» ou «Encontra a proposta do projeto e resume-a.». São pedidos de exemplo, não transcrições de sessões reais.

## O acesso continua sob o seu controlo

Até **112 ferramentas** em onze módulos, incluindo as de guia/estado, são filtradas pelas aplicações disponíveis, atribuições de ferramentas e seleções da ligação. As permissões nativas do Nextcloud aplicam-se em cada pedido. Os administradores podem limitar o acesso a pastas, calendários e listas de tarefas selecionados e excluir ficheiros com determinadas etiquetas. As ferramentas de escrita devolvem uma pré-visualização salvo confirmação explícita; o assistente deve obter a aprovação do utilizador. Não existe ferramenta de eliminação de ficheiros. As ferramentas Mail, Forms e de registos exigem permissões explícitas. Rascunhos Mail, gravação de anexos e exportações Forms exigem confirmação; os registos continuam apenas de leitura e Mail nunca envia mensagens.

Os resultados são enviados ao cliente de IA escolhido, sujeito às suas próprias políticas de tratamento e retenção.

[Ferramentas](../docs/pt-PT/reference/tools.md) · [Permissões](../docs/pt-PT/guides/permissions.md) · [Proteção de ficheiros](../docs/pt-PT/guides/file-protection.md) · [Resolução de problemas](../docs/pt-PT/guides/troubleshooting.md)

## Licença

AGPL-3.0-or-later, com a cláusula adicional de atribuição da secção 7(b) em [NOTICE](NOTICE). Consulte [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt) para obter a licença completa. Preserve a atribuição na página de administração, na documentação e nos avisos de direitos de autor ao redistribuir ou disponibilizar versões modificadas numa rede.

## Créditos

Estão incluídos Smalot PdfParser, Symfony Polyfill Mbstring e o autoloader do Composer. Consulte [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) para ver versões, direitos de autor, licenças e créditos de desenvolvimento separados.

**Contribuidores.** Agradecemos a @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) pela verificação da visibilidade da raiz e a @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) pelo relatório que esteve na origem da auditoria de etiquetas ocultas. Agradecemos também a @matt-ek pela tradução alemã da interface ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) e a @Zegorax pelos clientes OAuth registados por administradores ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).


Hierarquia de tarefas: [@mturgeonferland](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18).

A documentação original também reconhece a contribuição de Sintax/Jhonatan Jaworski para o [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw). Este crédito histórico é preservado sem sugerir que o XERJ tenha revisto este fork.

Developed by Sintax — https://sintax.tech
