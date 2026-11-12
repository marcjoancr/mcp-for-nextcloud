<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
/** Scalar display view; no lazy fetches, MIME parser, or attachment bytes. */
final class MailBodyView {
    public function __construct(private array $row){}
    public function getPlainBody():string {return $this->row['body_plain'];}
    public function getHtmlBody(int $messageId):string {return $this->row['body_html'];}
    public function jsonSerialize():array {return ['hasHtmlBody'=>$this->row['body_html']!==''];}
    public function getAttachments():array {return $this->row['attachments'];}
    public function isEncrypted():bool {return false;}
    public function isSigned():bool {return false;}
    public function getReplyTo():object {return new class($this->row['reply_to']) {
        public function __construct(private array $rows){}
        public function iterate():iterable {foreach($this->rows as $row)yield new class($row) {
            public function __construct(private array $row){}
            public function getLabel():string {return $this->row['label'];}
            public function getEmail():string {return $this->row['email'];}
        };}
    };}
}
