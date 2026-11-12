<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
/** Explicit authoritative withdrawal/identity change before a claim; other failures remain unknown. */
final class BeforeWireBlock extends \RuntimeException {}
