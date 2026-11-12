<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only public inheritance declarations; never autoload or require this file.
// Source: https://github.com/nextcloud/mail/blob/v5.12.5/lib/Exception/ServiceException.php
// SHA-256: 16f687574b68a9eb0cf0ef1b4b03484b2c42d5082419d4e7e771e0d98d7aa67d
// Source: https://github.com/nextcloud/mail/blob/v5.12.5/lib/Exception/AttachmentNotFoundException.php
// SHA-256: 81a3f8ffc43b7217e30b7a9d3d4c00cc58a627556f8bfe8b1a37badf01bc5c47
namespace OCA\Mail\Exception;
class ServiceException extends \Exception {}
class AttachmentNotFoundException extends ServiceException {}
