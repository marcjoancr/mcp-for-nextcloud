<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;
use OCA\Mcp\Modules\Forms\Exports\FormsDownloadAdmission;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
/** The opaque single-use credential is validated before any export bytes are returned. */
final class FormsDownloadController extends Controller {
    public function __construct(string $appName,IRequest $request,private FormsDownloadAdmission $downloads) {parent::__construct($appName,$request);}
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(string $token):Response {
        try {
            $result=$this->downloads->redeem($token);
            return new DataDisplayResponse($result['content'],200,['Content-Type'=>$result['mime'],'Content-Disposition'=>'attachment; filename="'.$result['name'].'"','Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
        } catch(\Throwable) {return new DataDisplayResponse('',404,['Cache-Control'=>'no-store']);}
    }
}
