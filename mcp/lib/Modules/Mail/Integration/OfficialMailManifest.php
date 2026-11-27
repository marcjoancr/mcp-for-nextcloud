<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

/** Critical runtime files from the signed Mail 5.12.5 archive used in the integration fixture. */
final class OfficialMailManifest {
	public const FILES = [
		'appinfo/signature.json' => '863fa2f00865e45645388c6b569eb5b87e7e94d92ea5d99e85d6c8215f893319',
		'lib/Send/SentMailboxHandler.php' => 'db07837197b05e1e48d8d5550eaa32de49f3d094fe073355227b0ee820db00fc',
		'lib/Send/AntiAbuseHandler.php' => '51429e729a73c012eebb8d29c9c444baaf6e364ec5cfebf2dc163079b56e3c89',
		'lib/Send/SendHandler.php' => 'ba034c0b199dbb3da5fe9b8c574eace4eeb10702e32c183391515c03ffae04ab',
		'lib/Send/CopySentMessageHandler.php' => '2e9d51cef1704cdda2a3021e81740eeb04ee317e46d78f755279b4f370499ec0',
		'lib/Service/MailTransmission.php' => '2dc3035b02cfd2bcfb2b3baa67a9ea3a899b75a7decbbebfd62e452220c98ba8',
		'lib/SMTP/SmtpClientFactory.php' => 'ec7f1647707638d74d26a920d0219e64ed80c2712247485325b53710951bfc59',
	];
}
