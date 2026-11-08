<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
/** Generated from the pinned native source and reviewed overlay. No credentials. */
final class NativeGuardManifest {
    public const DATA = array (
  'contract' => 'mcp-mail-flags-v1',
  'mail' => '5.12.5',
  'files' => 
  array (
    'lib/Db/MailboxMapper.php' => '560bc77370a8cfb39c80d740203a721b4d87b3338470a7b79d855c2f36dd6ec9',
    'lib/Db/MessageMapper.php' => 'cbdbc9ca9fab3ab51f1da934743c2e1db56180e5399d1abad0609ea10f57af51',
    'lib/Events/GuardedMessageFlaggedEvent.php' => '7a534a74fdddd18813ee59e2577a16cc2f93943c3697a97d3c454b1b1dccfc27',
    'lib/IMAP/GuardedFlagMutation.php' => 'a6db505417a0f055fe0acabfe1e0be05dfe7c10687a21d3b80dfb06adf5bba0b',
    'lib/IMAP/HordeImapClient.php' => '10a290e35e1a8a5615389e57c97021e5b6932ef16ad8299e1e54a1ad8022dd4c',
    'lib/IMAP/IMAPClientFactory.php' => 'b4cba0247ea64b8ff65aed41f83708e50d1f9129fe787371719d7e6dc1986a9f',
    'lib/IMAP/MutationSelection.php' => 'ea94f8f7dfe226497ba8c269e8b69ef5fa6f6986310ab19d8630cc221fa0ca69',
    'lib/Listener/MessageCacheUpdaterListener.php' => 'b47b499249be886aad7e8b90055667f3f3d128b5edb90b0f8f971e5b068b8375',
    'lib/Service/GuardedMessageMutations.php' => '6cbef4510cb89d496ce4e4bf5a2c30be50becd10cfbd7a458c940667d52cad2b',
    'lib/Service/MutationFence.php' => 'ceaa1f15aab91a34344dbc39d87629998637d5d3cffc710fc754838669bb7a2c',
    'vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Base.php' => '6aeb6f40c2c8ca8ef93f641818c8a4dba0e0e7c43fa4a64fcb74a301aab57d64',
    'vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Socket.php' => 'ea3185a11aa748d7d18b2c91e88404cbf7987d3d435d3636e19a194dcc9fb0ba',
  ),
);
}
