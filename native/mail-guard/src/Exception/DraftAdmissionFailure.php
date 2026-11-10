<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Exception;
/** No logical draft mutation has been admitted by this refusal. */
final class DraftAdmissionFailure extends ClientException {}
