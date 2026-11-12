<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
/** Conditional parser-entry caps supplement the pre-token wire budget. Stock Mail behavior is unchanged when unarmed. */
function nativeContentOverlay(Closure $read,array $files):array {
    $take=static fn(string $p):string=>$files[$p]??$read($p);
    $replace=static function(string $s,string $needle,string $next):string {if(substr_count($s,$needle)!==1)throw new RuntimeException('Pinned content source changed: '.substr($needle,0,80));return str_replace($needle,$next,$s);};
    $p='lib/IMAP/HordeImapClient.php';$files[$p]=$replace($take($p),'use \\OCA\\Mail\\Transmission\\SingleAppend;','use \\OCA\\Mail\\Transmission\\SingleAppend, GuardedContentRead;');
    $p='lib/IMAP/GuardedFlagMutation.php';$files[$p]=$replace($take($p),'$this->mcpGuardCopyCommand($cmd,$data);','$this->mcpGuardContentCommand($cmd,$data);$this->mcpGuardCopyCommand($cmd,$data);');
    foreach(['lib/Transmission/SingleAppend.php','lib/IMAP/GuardedRefresh.php'] as $p)$files[$p]=$replace($take($p),'if($this->_isAuthenticated||','if(isset($this->_params[\'mcp_refresh_budget\'])||$this->_isAuthenticated||');
    $p='vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Socket.php';$s=$take($p);
    $s=nativeDraftReplaceBody($s,'_parseBodystructure',static fn(string $body):string=>"\n        \$budget=\$this->getParam('mcp_refresh_budget');\n        if(!\$budget instanceof \\OCA\\Mail\\Content\\ContentBudget)\$budget=null;\n        \$budget?->enterMimeNode();\n        try {".$body."\n        } finally {\$budget?->leaveMimeNode();}\n    ");
    $s=$replace($s,"        \$params = array();\n\n        if (is_null(\$data))", "        \$params = array();\n        \$contentParameters=[];\n        \$contentBudget=\$this->getParam('mcp_refresh_budget');\n        if(!\$contentBudget instanceof \\OCA\\Mail\\Content\\ContentBudget)\$contentBudget=null;\n\n        if (is_null(\$data))");
    $s=$replace($s,'$params[Horde_String::lower($name)] = $data->next();',"\$value=\$data->next();\n            if(\$contentBudget!==null){if(count(\$contentParameters)>=64||!is_string(\$name)||!is_string(\$value)||strlen(\$name)>128||strlen(\$value)>4096)throw new RuntimeException('Content MIME parameters exceed bound.');\$contentParameters[]=\$name;\$contentParameters[]=\$value;}\n            \$params[Horde_String::lower(\$name)] = \$value;");
    $s=$replace($s,"        \$cp = new Horde_Mime_Headers_ContentParam('Unused', \$params);","        \$contentBudget?->parameters(\$contentParameters);\n        \$cp = new Horde_Mime_Headers_ContentParam('Unused', \$params);");$files[$p]=$s;
    $p='vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/Socket/Connection/Socket.php';$s=$take($p);
    $s=$replace($s,'($budget===null?fgets($this->_stream):fgets($this->_stream,8193))','($budget instanceof \OCA\Mail\Content\ContentBudget?$budget->readLine($this->_stream):($budget===null?fgets($this->_stream):fgets($this->_stream,8193)))');
    $s=$replace($s,'$in = fread($this->_stream, min($literal_len, 8192));','$in = $budget instanceof \OCA\Mail\Content\ContentBudget?$budget->readLiteral($this->_stream,min($literal_len,8192)):fread($this->_stream, min($literal_len, 8192));');$files[$p]=$s;
    $p='vendor/bytestream/horde-mime/lib/Horde/Mime/Part.php';$files[$p]=$replace($take($p),'    public function setTransferEncoding($encoding, $options = array())',"    /** Read the original encoding without scanning or decoding contents. */\n    public function mcpTransferEncoding() { return \$this->_transferEncoding; }\n\n    public function setTransferEncoding(\$encoding, \$options = array())");
    return $files;
}
