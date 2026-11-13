<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCA\Forms\Controller\{ApiController, ShareApiController, ConfigController};
use OCA\Forms\Service\{FormsService, ConfigService};
use OCP\Server;
final class NativeActions {
    public const WRITES = ['forms_create','forms_clone','forms_update','forms_set_state','forms_set_lock','forms_release_lock','forms_transfer',
        'forms_delete_form','forms_create_question','forms_clone_question','forms_update_question','forms_delete_question','forms_reorder_questions',
        'forms_add_options','forms_update_option','forms_delete_option','forms_reorder_options','forms_share','forms_update_share','forms_unshare',
        'forms_submit_response','forms_update_response','forms_update_own_response','forms_delete_response','forms_delete_own_response','forms_delete_responses',
        'forms_update_admin_settings','forms_upload_response_files','forms_save_response_file','forms_restore'];
    public static function invoke(string $action, array $i): array {
        $api = Server::get(ApiController::class);
        $id = (int)($i['id'] ?? 0);
        $q = (int)($i['question_id'] ?? 0);
        $s = (int)($i['submission_id'] ?? 0);
        $ref = $id > 0 ? ['form_id'=>$id] : [];
        switch ($action) {
            case 'forms_create': case 'forms_clone':
                $r=$api->newForm($action === 'forms_clone' ? $id : null); $data=$r->getData(); $id=(int)$data['id'];
                $fields=array_intersect_key($i,array_flip(['title','description']));
                if ($fields) {
                    $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);
                    $folder=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormsFolder($form->getOwnerId());
                    if ($folder!==null) {foreach ($folder->getDirectoryListing() as $node) {
                        if (str_starts_with($node->getName(),$id.' - ')) {throw new \OCA\Forms\Mcp\BeforeEffect('Pre-existing upload folder requires recovery');}
                    }}
                    $api->updateForm($id,$fields);
                }
                return ['references'=>['form_id'=>$id],'data'=>$data];
            case 'forms_update': $r=$api->updateForm($id,$i['fields']); break;
            case 'forms_set_state': $r=$api->updateForm($id,['state'=>$i['state']]); break;
            case 'forms_set_lock':
                if ($i['permanent']) {$r=$api->updateForm($id,['lockedUntil'=>0]);}
                else {
                    $service=Server::get(FormsService::class);$form=$service->getFormIfAllowed($id,'edit');
                    $service->obtainFormLock($form);Server::get(\OCA\Forms\Db\FormMapper::class)->update($form);
                    $r=new \OCP\AppFramework\Http\DataResponse($id);
                }
                break;
            case 'forms_release_lock': $r=$api->updateForm($id,['lockedUntil'=>null]); break;
            case 'forms_transfer': $r=$api->updateForm($id,['ownerId'=>$i['owner_id']]); break;
            case 'forms_delete_form': $r=$api->deleteForm($id); break;
            case 'forms_create_question': case 'forms_clone_question':
                $r=$api->newQuestion($id,$i['type']??null,$i['subtype']??null,$i['text']??'', $action==='forms_clone_question' ? $q : null,$i['position']??null);
                $ref['question_id']=$r->getData()['id']; break;
            case 'forms_update_question': $r=$api->updateQuestion($id,$q,$i['fields']); break;
            case 'forms_delete_question': $r=$api->deleteQuestion($id,$q); break;
            case 'forms_reorder_questions': $r=$api->reorderQuestions($id,$i['order']); break;
            case 'forms_add_options': $r=$api->newOption($id,$q,$i['texts'],$i['option_type']??'choice'); break;
            case 'forms_update_option': $r=$api->updateOption($id,$q,$i['option_id'],['text'=>$i['text']]); break;
            case 'forms_delete_option': $r=$api->deleteOption($id,$q,$i['option_id']); break;
            case 'forms_reorder_options': $r=$api->reorderOptions($id,$q,$i['order'],$i['option_type']??null); break;
            case 'forms_share':
                $kind=['user'=>0,'group'=>1,'circle'=>7,'link'=>3][$i['kind']] ?? throw new \InvalidArgumentException('Unknown share kind');
                $r=Server::get(ShareApiController::class)->newShare($id,$kind,$i['recipient']??'',$i['permissions']);
                $ref['share_id']=$r->getData()['id']; break;
            case 'forms_update_share':
                $fields=array_intersect_key($i,array_flip(['permissions','token']));
                $r=Server::get(ShareApiController::class)->updateShare($id,$i['share_id'],$fields); break;
            case 'forms_unshare': $r=Server::get(ShareApiController::class)->deleteShare($id,$i['share_id']); break;
            case 'forms_submit_response':
                $r=$api->newSubmission($id,$i['answers'],$i['share_hash']??'');
                $ref['submission_id']=(int)Server::get(\OCP\IDBConnection::class)->executeQuery('SELECT MAX(id) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$id])->fetchOne(); break;
            case 'forms_update_response': case 'forms_update_own_response': $r=$api->updateSubmission($id,$s,$i['answers']); break;
            case 'forms_delete_response': case 'forms_delete_own_response': $r=$api->deleteSubmission($id,$s); break;
            case 'forms_delete_responses': $r=$api->deleteAllSubmissions($id); break;
            case 'forms_update_admin_settings':
                foreach ($i['fields'] as $key=>$value) {
                    $r=Server::get(ConfigController::class)->updateAppConfig($key,$value);
                    if ($r->getStatus()>=400) {throw new \RuntimeException('Native Forms rejected the settings');}
                }
                break;
            default: throw new \InvalidArgumentException('Unsupported native Forms operation');
        }
        if ($r->getStatus() >= 400) { throw new \RuntimeException('Native Forms rejected the request'); }
        if ($q > 0) {$ref['question_id']=$q;}
        if ($s > 0) {$ref['submission_id']=$s;}
        if (isset($i['share_id'])) {$ref['share_id']=$i['share_id'];}
        return ['references'=>$ref,'data'=>$r->getData()];
    }
}
