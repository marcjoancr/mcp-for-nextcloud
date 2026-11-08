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
  'contract' => 'mcp-mail-mutations-v3',
  'mail' => '5.12.5',
  'files' =>
  array (
    'lib/AppInfo/Application.php' => 'f3c200239e2acac603643ae4463f65e881464959ff6c63a666680d91ef597db9',
    'lib/Db/MailAccount.php' => '575ef810acf286d9ae933e8c00f28857a9166ac4fe1eb3cc9764499073dcc005',
    'lib/Db/MailAccountMapper.php' => '6a28c2fcf5744a8c2f32bb37b0dc71c053891feb191108f25bd6f726be33ea2e',
    'lib/Db/Mailbox.php' => '0ef3241db3c2e81627a0b3d5a19c2960552518d8251919ab81c3b1f5a88e2629',
    'lib/Db/MailboxMapper.php' => '560bc77370a8cfb39c80d740203a721b4d87b3338470a7b79d855c2f36dd6ec9',
    'lib/Db/Message.php' => '4ec1b9f5a33c0c85d5dd4c062c85d0fbe92704f11418f2eac57d74d5052b1969',
    'lib/Db/MessageMapper.php' => 'cbdbc9ca9fab3ab51f1da934743c2e1db56180e5399d1abad0609ea10f57af51',
    'lib/Events/BeforeMessageDeletedEvent.php' => 'd5d1866857820712163ab2c26a3310e7748753e32d957285711d2e3bb355ef1e',
    'lib/Events/GuardedMailboxesSynchronizedEvent.php' => 'd3577b1568b0f64ee642c37721b533d08081a0d5a0b84f9854436e90ecc274c2',
    'lib/Events/GuardedMessageDeletedEvent.php' => '143b5952ee40de36b29fe39103451f7604433479cc8b0bdfe9b6db43a1500e64',
    'lib/Events/GuardedMessageFlaggedEvent.php' => '7a534a74fdddd18813ee59e2577a16cc2f93943c3697a97d3c454b1b1dccfc27',
    'lib/Events/MailboxesSynchronizedEvent.php' => '1bd9f2ca7608c36e46a91b81852c3f1a94d3ea8de83c3630bbc184da9839b2fc',
    'lib/Events/MessageDeletedEvent.php' => '267909d4a850809b1ae0bf5b7d8af2b5b11bb1c2bf7c8251fda426f15bab823a',
    'lib/IMAP/GuardedFlagMutation.php' => '7ab96f6627f5b0b5ef39622f2267932941502f2d1191c4f7aa19d433892e344e',
    'lib/IMAP/GuardedMailboxMutation.php' => '24ce2a55f4d79f93b65e6e1eadd17b9c03ee7812d310a26d6e0f349a5f7ef138',
    'lib/IMAP/GuardedMoveMutation.php' => '8ed62526efadb4368ff6cc2c0ba9dbdd2e4a5e2a3543cb3d1ae60469438fbd07',
    'lib/IMAP/HordeImapClient.php' => '55e4dbeb9ecfd88ee5622ac12c52924b572365af333d2c19933c44d1b97f84ac',
    'lib/IMAP/IMAPClientFactory.php' => 'b4cba0247ea64b8ff65aed41f83708e50d1f9129fe787371719d7e6dc1986a9f',
    'lib/IMAP/MoveProof.php' => 'd77b93d33e8d4b8bf247adf52754cc09c34328957935b9043c4a3c4abcb18493',
    'lib/IMAP/MutationSelection.php' => 'ea94f8f7dfe226497ba8c269e8b69ef5fa6f6986310ab19d8630cc221fa0ca69',
    'lib/Listener/MailboxesSynchronizedSpecialMailboxesUpdater.php' => '07c7f3cdbc369a791e11bfd6bbb832ab2754a5c8c3e3be94346284cb4a9e0f4e',
    'lib/Listener/MessageCacheUpdaterListener.php' => 'b2a3e3f85b5823cb971dd4a8725433483ab22af9eb1dad0a8a06d9045185391e',
    'lib/Service/AccountService.php' => 'a89446ba6eb2f2d0e942964860da89b8d09e8edff03fe3c62e82c3b5160c3541',
    'lib/Service/GuardedMailboxMutations.php' => 'd839bf48e24898651a76bc971fce00fb0e90245379623a5219fa2eaa55d1866b',
    'lib/Service/GuardedMessageMutations.php' => '49a5a527314ed0f778e389361735741810c61d02860e1e9a3fdcf3df4b698c74',
    'lib/Service/MailManager.php' => 'b87520138a840f0f4bd1530dd028ef639601b5ed5ec4e3b46dbe6c30eb2bad86',
    'lib/Service/MutationFence.php' => '739ca804577fd6dd762add29486ac7e67cc0695ccaf902b83ad8326da8bb60c7',
    'vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Base.php' => '6aeb6f40c2c8ca8ef93f641818c8a4dba0e0e7c43fa4a64fcb74a301aab57d64',
    'vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Socket.php' => 'ea3185a11aa748d7d18b2c91e88404cbf7987d3d435d3636e19a194dcc9fb0ba',
  ),
);
    public const LEGACY_FLAGS_DATA = array (
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
    public const LEGACY_MOVES_DATA = array (
  'contract' => 'mcp-mail-mutations-v2',
  'mail' => '5.12.5',
  'files' =>
  array (
    'lib/AppInfo/Application.php' => 'f3c200239e2acac603643ae4463f65e881464959ff6c63a666680d91ef597db9',
    'lib/Db/MailAccount.php' => '575ef810acf286d9ae933e8c00f28857a9166ac4fe1eb3cc9764499073dcc005',
    'lib/Db/MailAccountMapper.php' => '6a28c2fcf5744a8c2f32bb37b0dc71c053891feb191108f25bd6f726be33ea2e',
    'lib/Db/Mailbox.php' => '0ef3241db3c2e81627a0b3d5a19c2960552518d8251919ab81c3b1f5a88e2629',
    'lib/Db/MailboxMapper.php' => '560bc77370a8cfb39c80d740203a721b4d87b3338470a7b79d855c2f36dd6ec9',
    'lib/Db/Message.php' => '4ec1b9f5a33c0c85d5dd4c062c85d0fbe92704f11418f2eac57d74d5052b1969',
    'lib/Db/MessageMapper.php' => 'cbdbc9ca9fab3ab51f1da934743c2e1db56180e5399d1abad0609ea10f57af51',
    'lib/Events/BeforeMessageDeletedEvent.php' => 'd5d1866857820712163ab2c26a3310e7748753e32d957285711d2e3bb355ef1e',
    'lib/Events/GuardedMessageDeletedEvent.php' => '143b5952ee40de36b29fe39103451f7604433479cc8b0bdfe9b6db43a1500e64',
    'lib/Events/GuardedMessageFlaggedEvent.php' => '7a534a74fdddd18813ee59e2577a16cc2f93943c3697a97d3c454b1b1dccfc27',
    'lib/Events/MessageDeletedEvent.php' => '267909d4a850809b1ae0bf5b7d8af2b5b11bb1c2bf7c8251fda426f15bab823a',
    'lib/IMAP/GuardedFlagMutation.php' => '8d3da19b6f0f9d75446d330cd113b9659f776bdf08d51533364c912f88624e85',
    'lib/IMAP/GuardedMoveMutation.php' => '8ed62526efadb4368ff6cc2c0ba9dbdd2e4a5e2a3543cb3d1ae60469438fbd07',
    'lib/IMAP/HordeImapClient.php' => '227d07f2bc1dd91f9137b8f5616c4e2f92dc30f3e7e7657df71fc9006d18b0c1',
    'lib/IMAP/IMAPClientFactory.php' => 'b4cba0247ea64b8ff65aed41f83708e50d1f9129fe787371719d7e6dc1986a9f',
    'lib/IMAP/MoveProof.php' => 'd77b93d33e8d4b8bf247adf52754cc09c34328957935b9043c4a3c4abcb18493',
    'lib/IMAP/MutationSelection.php' => 'ea94f8f7dfe226497ba8c269e8b69ef5fa6f6986310ab19d8630cc221fa0ca69',
    'lib/Listener/MessageCacheUpdaterListener.php' => 'b2a3e3f85b5823cb971dd4a8725433483ab22af9eb1dad0a8a06d9045185391e',
    'lib/Service/AccountService.php' => 'a89446ba6eb2f2d0e942964860da89b8d09e8edff03fe3c62e82c3b5160c3541',
    'lib/Service/GuardedMessageMutations.php' => '49a5a527314ed0f778e389361735741810c61d02860e1e9a3fdcf3df4b698c74',
    'lib/Service/MailManager.php' => 'b87520138a840f0f4bd1530dd028ef639601b5ed5ec4e3b46dbe6c30eb2bad86',
    'lib/Service/MutationFence.php' => '739ca804577fd6dd762add29486ac7e67cc0695ccaf902b83ad8326da8bb60c7',
    'vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Base.php' => '6aeb6f40c2c8ca8ef93f641818c8a4dba0e0e7c43fa4a64fcb74a301aab57d64',
    'vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Socket.php' => 'ea3185a11aa748d7d18b2c91e88404cbf7987d3d435d3636e19a194dcc9fb0ba',
  ),
);
}
