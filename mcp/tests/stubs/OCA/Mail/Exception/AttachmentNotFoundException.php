<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mail\Exception;
/** Public native error type; only the inheritance used at this boundary is needed by unit tests. */
class ServiceException extends \Exception {}
class AttachmentNotFoundException extends ServiceException {}
