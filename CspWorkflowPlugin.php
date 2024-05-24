<?php

/**
 * @file plugins /generic/cspUser/CspUserPlugin.inc.php
 *
 * Copyright (c) 2020-2023 Lívia Gouvêa
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CspUserPlugin
 * @brief Customizes User profile fields
 */

namespace APP\plugins\generic\cspWorkflow;

use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use APP\facades\Repo;
use PKP\session\SessionManager;
use APP\template\TemplateManager;
use PKP\submission\PKPSubmission;
use PKP\db\DAORegistry;
use APP\core\Application;
use PKP\controllers\grid\GridColumn;
use PKP\security\Role;
use PKP\log\SubmissionEmailLogEntry;
use APP\core\Services;
use PKP\mail\mailables\RevisedVersionNotify;
use Illuminate\Support\Facades\Mail;

class CspWorkflowPlugin extends GenericPlugin {


    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null){
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled()) {

            $request = Application::get()->getRequest();
			$url = $request->getBaseUrl() . '/' . $this->getPluginPath() . '/styles/style.css';
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->addStyleSheet('CspWorkflow', $url, ['contexts' => 'backend']);

            Hook::add('TemplateManager::display', [$this, 'templateManagerDisplay']);
            Hook::add('TemplateResource::getFilename', [$this, '_overridePluginTemplates']);
            Hook::add('TemplateManager::fetch', [$this, 'templateManagerFetch']);
            Hook::add('submissionfilesuploadform::execute', [$this, 'submissionfilesuploadformExecute']);
            Hook::add('submissionfilesmetadataform::execute', [$this, 'submissionfilesmetadataformExecute']);
            Hook::add('Form::config::after', [$this, 'FormConfigAfter']);
            Hook::add('stageparticipantgridhandler::initfeatures', [$this, 'stageparticipantgridhandlerInitfeatures']);
            Hook::add('submissionfilesuploadform::display', [$this, 'submissionfilesuploadformDisplay']);
            Hook::add('ReviewerAction::confirmReview', [$this, 'reviewerActionConfirmReview']);
            Hook::add('SubmissionFile::add', [$this, 'submissionFileAdd']);
        }

        return $success;
    }
    
    /**
     * Provide a name for this plugin
     *
     * The name will appear in the Plugin Gallery where editors can
     * install, enable and disable plugins.
     */
    public function getDisplayName()
    {
        return __('plugins.generic.cspWorkflow.displayName');
    }

    /**
     * Provide a description for this plugin
     *
     * The description will appear in the Plugin Gallery where editors can
     * install, enable and disable plugins.
     */
    public function getDescription()
    {
        return __('plugins.generic.cspWorkflow.description');
    }

    public function templateManagerDisplay($hookName, $args) {
        if ($args[1] == "dashboard/index.tpl") {
            $userGroupsAbbrev = array();
            $array_sort = array();
            $request = \Application::get()->getRequest();
            if(!$request->getUserVar('substage')){
                $currentUser = $request->getUser();
                $context = $request->getContext();
                $stages = array();

                $user = Repo::userGroup()
                ->getCollector()
                ->filterByUserIds([$currentUser->getData('id')])
                ->getMany()
                ->toArray();
                
                foreach($user as $userGroup){
                    $userGroupsAbbrev[] = $userGroup->getLocalizedName();
                }                
                
                $requestRoleAbbrev = $request->getUserVar('requestRoleAbbrev');
                $sessionManager = SessionManager::getManager();
                $session = $sessionManager->getUserSession();
                if($requestRoleAbbrev){
                    $session->setSessionVar('role', $requestRoleAbbrev);
                }
                $role = $session->getSessionVar('role');

                if ($role == 'Ed. chefe' or $role == 'Gerente') {
                    $stages['Pré-avaliação']["'pre_aguardando_editor_chefe'"][1] = "Aguardando decisão (0)";
                    $stages['Avaliação']["'ava_consulta_editor_chefe'"][1] = "Consulta ao editor chefe (0)";
                }
                if ($role == 'Ed. associado' or $role == 'Gerente') {
                    $stages['Avaliação']["'ava_aguardando_editor_chefe'"][1] = "Aguardando decisão da editoria (0)";
                    $status = "'ava_com_editor_associado','ava_aguardando_avaliacao'";
                    $stages['Avaliação'][$status][1] = "Com o editor associado (0)";
                    $stages['Avaliação']["'ava_aguardando_autor'"][1] = "Aguardando autor (0)";
                    $stages['Avaliação']["'ava_aguardando_autor_mais_60_dias'"][1] = "Há mais de 60 dias com o autor (0)";
                    $stages['Avaliação']["'ava_aguardando_secretaria'"][1] = "Aguardando secretaria (0)";
                }
                if ($role == 'Avaliador') {
                    $stages['Avaliação']["'ava_aguardando_avaliacao'"][1] = "Aguardando avaliacao (0)";
                }
                if ($role == 'Secretaria' or $role == 'Gerente') {

                    $queuedSubmissions = Repo::submission()->getCollector()
                    ->filterByContextIds([1])
                    ->filterByStatus([PKPSubmission::STATUS_QUEUED])
                    ->getCount();

                    $stages['Pré-avaliação']["'pre_aguardando_secretaria'"][1] = "Aguardando secretaria (".$queuedSubmissions.")";



                    $stages['Pré-avaliação']["'pre_pendencia_tecnica'"][1] = "Pendência técnica (0)";
                    $stages['Avaliação']["'ava_aguardando_autor_mais_60_dias'"][1] = "Há mais de 60 dias com o autor (0)";
                    $stages['Avaliação']["'ava_aguardando_secretaria'"][1] = "Aguardando secretaria (0)";
                }
                if ($role == 'Autor') {
                    $stages['Pré-avaliação']["'em_progresso'"][1] = "Em progresso (0)";
                    $status = "'pre_aguardando_secretaria','pre_aguardando_editor_chefe'";
                    $stages['Pré-avaliação'][$status][1] = "Submetidas (0)";
                    $stages['Pré-avaliação']["'pre_pendencia_tecnica'"][1] = "Pendência técnica (0)";
                    $status = "'ava_aguardando_editor_chefe','ava_consulta_editor_chefe','ava_com_editor_associado','ava_aguardando_secretaria'";
                    $stages['Avaliação'][$status][1] = "Em avaliação (0)";
                    $stages['Avaliação']["'ava_aguardando_autor'"][1] = "Modificações solicitadas (0)";
                    $status = "'ed_text_envio_carta_aprovacao','ed_text_para_revisao_traducao','ed_text_em_revisao_traducao','ed_texto_traducao_metadados','edit_aguardando_padronizador','edit_pdf_padronizado','edit_em_prova_prelo','ed_text_em_avaliacao_ilustracao','edit_em_formatacao_figura','edit_em_diagramacao','edit_aguardando_publicacao'";
                    $stages['Pós-avaliação'][$status][1] = "Aprovadas (0)";
                }
                if ($role == 'Ed. assistente' or $role == 'Gerente' or $role == 'Revisor - Tradutor') {
                    $stages['Edição de texto']["'ed_text_envio_carta_aprovacao'"][1] = "Envio de Carta de aprovação (0)";
                    $stages['Edição de texto']["'ed_text_para_revisao_traducao'"][1] = "Para revisão/Tradução (0)";
                    $stages['Edição de texto']["'ed_text_em_revisao_traducao'"][1] = "Em revisão/Tradução (0)";
                    $stages['Edição de texto']["'ed_texto_traducao_metadados'"][1] = "Tradução de metadados (0)";
                }
                if ($role == 'Ed. assistente' or $role == 'Gerente') {
                    $stages['Editoração']["'edit_aguardando_padronizador'"][1] = "Aguardando padronizador (0)";
                    $stages['Editoração']["'edit_pdf_padronizado'"][1] = "PDF padronizado (0)";
                    $stages['Editoração']["'edit_em_prova_prelo'"][1] = "Em prova de prelo (0)";
                }
                if ($role == 'Ed. Layout' or $role == 'Gerente') {
                    $stages['Edição de texto']["'ed_text_em_avaliacao_ilustracao'"][1] = "Em avaliação de ilustração (0)";
                    $stages['Editoração']["'edit_em_formatacao_figura'"][1] = "Em formatação de Figura (0)";
                    $stages['Editoração']["'edit_em_diagramacao'"][1] = "Em diagramação (0)";
                    $stages['Editoração']["'edit_aguardando_publicacao'"][1] = "Aguardando publicação (0)";
                }
                if ($role == 'Ed. Layout' or $role == 'Gerente') {
                    $stages['Edição de texto']["'ed_text_em_avaliacao_ilustracao'"][1] = "Em avaliação de ilustração (0)";
                    $stages['Editoração']["'edit_em_formatacao_figura'"][1] = "Em formatação de Figura (0)";
                    $stages['Editoração']["'edit_em_diagramacao'"][1] = "Em diagramação (0)";
                    $stages['Editoração']["'edit_aguardando_publicacao'"][1] = "Aguardando publicação (0)";
                }
                if($role){
                    $stages['Finalizadas']["'publicada'"][3] = "Publicadas (0)";
                    $stages['Finalizadas']["'rejeitada'"][4] = "Rejeitadas (0)";
                    $stages['Finalizadas']["'fin_consulta_editor_chefe'"][4] = "Consulta a Ed. Chefe (0)";
                }
                $array_sort = array('pre_aguardando_secretaria',
                                    'pre_pendencia_tecnica',
                                    'pre_aguardando_editor_chefe',
                                    'ava_com_editor_associado',
                                    'ava_aguardando_autor',
                                    'ava_aguardando_autor_mais_60_dias',
                                    'ava_aguardando_secretaria',
                                    'ava_aguardando_editor_chefe',
                                    'ava_consulta_editor_chefe',
                                    'ed_text_em_avaliacao_ilustracao',
                                    'ed_text_envio_carta_aprovacao',
                                    'ed_text_para_revisao_traducao',
                                    'ed_text_em_revisao_traducao',
                                    'ed_texto_traducao_metadados',
                                    'edit_aguardando_padronizador',
                                    'edit_em_formatacao_figura',
                                    'edit_em_prova_prelo',
                                    'edit_pdf_padronizado',
                                    'edit_em_diagramacao',
                                    'edit_aguardando_publicacao',
                                    'publicada',
                                    'rejeitada'
                                );
            }
            if(in_array('Autor',$userGroupsAbbrev) == False){
                $userGroupsAbbrev[] = 'Autor';
            }

            $args[0]->assign(array(
                'userGroupsAbbrev' => array_unique($userGroupsAbbrev),
                'stages' => $stages,
                'substage' => $request->getUserVar('substage'),
                'requestRoleAbbrev' => $role,
                'array_sort' => array_flip($array_sort)
            ));
        }
        return false;
    }


    public function templateManagerFetch($hookName, $args) {
        $templateVars = $args[0]->getTemplateVars();
        // Limita a 5 os itens da lista de avaliadores em caixa de Adicionar avaliador
        if($args[1] == "controllers/grid/users/reviewer/form/advancedSearchReviewerForm.tpl"){
            $args[0]
            ->tpl_vars["selectReviewerListData"]
            ->value["components"]["selectReviewer"]["items"] = array_slice(
                $args[0]->tpl_vars["selectReviewerListData"]->value["components"]["selectReviewer"]["items"],
                0,
                5
            );
        }
        /* Em caixa de Adicionar comentário, exibe somente a secretaria como opção de participantes da conversa
        para perfis que não são Gerente, Admin, Editor Chefe ou Assistente de Edição*/
        if($args[1] == "controllers/grid/queries/form/queryForm.tpl"){
            $request = Application::get()->getRequest();
            $userRoles = $request->getUser()->getRoles($request->getContext()->getId());
            foreach ($userRoles as $roles => $role) {
                $userRolesArray[] = $role->getData('id');
            }
            if(array_intersect(
                $userRolesArray,
                [
                    Role::ROLE_ID_MANAGER,
                    Role::ROLE_ID_SITE_ADMIN,
                    Role::ROLE_ID_ASSISTANT,
                    Role::ROLE_ID_SUB_EDITOR
                ]
                ) == null){
                foreach ($templateVars["allParticipants"] as $participant => $value) {
                    $userGroups = Repo::userGroup()
                    ->getCollector()
                    ->filterByUserIds([$participant])
                    ->getMany()
                    ->toArray();
                    $userGroupsAbbrev = array();
                    foreach($userGroups as $userGroup){
                        $userGroupsAbbrev[] = $userGroup->getLocalizedData('abbrev');
                    }
                    if(!in_array('SECRETARIA',$userGroupsAbbrev) && ($participant != $args[0]->tpl_vars["assignedParticipants"]->value[0])){
                        unset($args[0]->tpl_vars["allParticipants"]->value[$participant]);
                    }else{
                        $args[0]->tpl_vars["assignedParticipants"]->value[] = $participant;
                    }
                }
            }
        }
        // Restringe Recomendação de avaliador a Aceitar, Rejeitar e Correções obrigatórias
        if($args[1] == "reviewer/review/step3.tpl"){
            foreach ($templateVars["reviewerRecommendationOptions"] as $key => $value) {
                if(!in_array($value,["common.chooseOne", "reviewer.article.decision.accept", "reviewer.article.decision.pendingRevisions", "reviewer.article.decision.decline" ])){
                    unset($args[0]->tpl_vars["reviewerRecommendationOptions"]->value[$key]);
                }
            }
        }
        if($args[1] == "controllers/grid/gridRow.tpl"){
            if(substr($templateVars["grid"]->_id,0,10) == "grid-files"){
                /**
                 * Em lista (grid) de arquivos,
                 * substitui tipo do arquivo por comentário sobre o arquivo e adiciona nome de pessoa que incluiu o arquivo
                 */
                $args[0]->tpl_vars["columns"]->value['notes'] = new GridColumn('notes', 'common.note');
                $noteDao = DAORegistry::getDAO('NoteDAO'); /** @var NoteDAO $noteDao */
                $notes = $noteDao->getByAssoc(Application::ASSOC_TYPE_SUBMISSION_FILE, $args[0]->tpl_vars["row"]->value->_id)->toArray();;
                foreach ($notes as $key => $value) {
                    $content[] = $value->getContents('contents');
                }
                $note = $content <> null ? implode('<hr>', $content) : "";
                $typePosition = array_search("type", array_keys($args[0]->tpl_vars["columns"]->value));
                $args[0]->tpl_vars["cells"]->value[$typePosition] = "<span id='cell-".
                                                        $templateVars["row"]->_id.
                                                        "-note' class='gridCellContainer'>
                                                        <span class='label'>".$note.
                                                        "</span></span>";

                $args[0]->tpl_vars["columns"]->value['user'] = new GridColumn('notes', 'user.name');
                if(isset($templateVars["row"]->_data["submissionFile"])){
                    $user = Repo::user()->get($templateVars["row"]->_data["submissionFile"]->_data["uploaderUserId"])->getGivenName($templateVars["currentLocale"]);
                    $args[0]->tpl_vars["cells"]->value[] = "<span id='cell-".$user.
                                                            "-user' class='gridCellContainer'>
                                                            <span class='label'>".$user.
                                                            "</span></span>";
                }

            }
        }
        // Altera a largura das colunas (grid) em diferentes estágios
        if($args[1] == "controllers/grid/grid.tpl"){
            if(in_array($templateVars["grid"]->_id,
                        ["grid-files-submission-editorsubmissiondetailsfilesgrid", 
                        "grid-files-review-editorreviewfilesgrid",
                        "grid-files-review-workflowreviewrevisionsgrid",
                        "grid-files-final-finaldraftfilesgrid", 
                        "grid-files-copyedit-copyeditfilesgrid",
                        "grid-files-productionready-productionreadyfilesgrid"])){
                $args[0]->tpl_vars["columns"]->value["name"]->_flags["width"] = 60;
                $args[0]->tpl_vars["columns"]->value["date"]->_flags["width"] = 20;
            }
            if($templateVars["grid"]->_id == "grid-files-final-managefinaldraftfilesgrid"){
                $args[0]->tpl_vars["columns"]->value["select"]->_flags["width"] = 13;
                $args[0]->tpl_vars["columns"]->value["name"]->_flags["width"] = 60;
                $args[0]->tpl_vars["columns"]->value["type"]->_flags["width"] = 25;
            }
            if($templateVars["grid"]->_id == "grid-users-reviewer-reviewergrid"){
                $args[0]->tpl_vars["columns"]->value["name"]->_flags["width"] = 20;
                $args[0]->tpl_vars["columns"]->value["considered"]->_flags["width"] = 20;
                $args[0]->tpl_vars["columns"]->value["method"]->_flags["width"] = 20;
                $args[0]->tpl_vars["columns"]->value["actions"]->_flags["width"] = 40;
            }
        }
    }

    public function submissionfilesuploadformExecute($hookName, $args) {
        $request = \Application::get()->getRequest();
        $context = $request->getContext();
        $file =& $args[1];
        $fileArray = explode('.', $file->getData('path'));
        $submission = Repo::submission()->get((int) $args[1]->getData('submissionId'));
        // Renomeia arquivos de primeira versão enviados por autor
        if($args[0]->getData('revisedFileId') == "" && $args[0]->getData('fileStage') == 2){
            $file->setData('name', 'csp_' . str_replace('/', '_', $submission->getData('submissionIdCSP')) .'_V1.' . $fileArray[1], $file->getData('locale'));
            $file->setData('name', 'csp_' . str_replace('/', '_', $submission->getData('submissionIdCSP')) .'_V1.' . $fileArray[1], $context->getData('primaryLocale'));
            Repo::submissionFile()->edit($file, $file->_data);
        }
        // Renomeia arquivos de segunda versão enviados por autor excluindo arquivos enviados por avaliadores ($file->getData('fileStage') <> 5)
        if($args[0]->getData('revisedFileId') == "" && $args[0]->_reviewRound->_data["stageId"] == 3 && $args[0]->_reviewRound->_data["status"] = 15 && $file->getData('fileStage') <> 5){
            $file->setData('name', 'csp_' . str_replace('/', '_', $submission->getData('submissionIdCSP')) .'_V' . ($args[0]->_reviewRound->_data["round"]+1) . "." . $fileArray[1], $file->getData('locale'));
            $file->setData('name', 'csp_' . str_replace('/', '_', $submission->getData('submissionIdCSP')) .'_V' . ($args[0]->_reviewRound->_data["round"]+1) . "." . $fileArray[1], $context->getData('primaryLocale'));
            Repo::submissionFile()->edit($file, $file->_data);
        }
    }

    public function submissionfilesmetadataformExecute($hookName, $args) {
        $request = Application::get()->getRequest();
        $user = $request->getUser();

        $noteDao = DAORegistry::getDAO('NoteDAO'); /** @var NoteDAO $noteDao */
        $note = $noteDao->newDataObject();

        $note->setUserId($user->getId());
        $note->setContents($request->getUserVar('newNote'));
        $note->setAssocType(Application::ASSOC_TYPE_SUBMISSION_FILE);
        $note->setAssocId($request->getUserVar('submissionFileId'));
        $noteDao->insertObject($note);
    }


    /**
     * Em formulário de "Exigir Nova Rodada de Avaliação",
     * passa segundo campo selecionado ("Solicitar modificações ao autor que estarão sujeitos a avaliação futura.")
     */
    public function FormConfigAfter($hookName, $args) {
        if($args[0]["id"] == "selectRevisionDecision"){
            $revisionDecisionForm = $args[1];
            $config =& $args[0];
            $fieldDecision = $revisionDecisionForm->getField('decision');
            $config["fields"][0]["value"] = $fieldDecision->options[1]["value"];
        }
    }

    /**
     * Quando novo participante é adicionado na etapa de avaliação,
     * recebe restrição de fazer recomendação apenas, não podendo tomar uma decisão editorial
     */
    public function stageparticipantgridhandlerInitfeatures($hookName, $args) {
        if($args[2]["stageId"] == 3){
            $args[2]["recommendOnly"] = "on";
            $args[1]->_requestVars["recommendOnly"] = "on";
        }
    }

    /**
     * Adiciona texto para auxiliar entendimento de usuário
     */
    public function submissionfilesuploadformDisplay($hookName, $args) {
        foreach ($args[0]->getData('submissionFileOptions') as $key => $value) {
            if($key){
                $args[0]->_data["submissionFileOptions"][$key] = 'Nova versão para ' . $value;
            }
        }
    }

    // Remove envio de email ao avaliador aceitar realizar avaliação
    public function reviewerActionConfirmReview($hookName, $args) {
        unset($args[2]->to);
    }

    // Envia email para secretaria quando autor faz upload de nova versão
    // e remove uploaderUserId para que não seja enviado email para editores
    public function submissionFileAdd($hookName, $args) {
        $authorUserIds = [];
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRound = $reviewRoundDao->getById($args[0]->getData('assocId'));
        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var StageAssignmentDAO $stageAssignmentDao */
        $authorAssignments = $stageAssignmentDao->getBySubmissionAndRoleIds($args[0]->getData('submissionId'), [Role::ROLE_ID_AUTHOR]);
        while ($assignment = $authorAssignments->next()) {
            if($_SESSION["userId"] == $assignment->getUserId()){
                $authorUserIds[] = (int) $assignment->getUserId();
            }
        }

        if (in_array($args[0]->getData('uploaderUserId'), $authorUserIds)) {
            $submission = Repo::submission()->get($args[0]->getData('submissionId'));
            $context = Services::get('context')->get($submission->getData('contextId'));
            $uploader = Repo::user()->get($args[0]->getData('uploaderUserId'));
            $user = Application::get()->getRequest()->getUser();

            // Fetch the latest notification email timestamp
            $submissionEmailLogDao = DAORegistry::getDAO('SubmissionEmailLogDAO');
            /** @var SubmissionEmailLogDAO $submissionEmailLogDao */
            $submissionEmails = $submissionEmailLogDao->getByEventType(
                $submission->getId(),
                SubmissionEmailLogEntry::SUBMISSION_EMAIL_AUTHOR_NOTIFY_REVISED_VERSION
            );
            $lastNotification = null;
            $sentDates = [];
            if ($submissionEmails) {
                while ($email = $submissionEmails->next()) {
                    if ($email->getDateSent()) {
                        $sentDates[] = $email->getDateSent();
                    }
                }
                if (!empty($sentDates)) {
                    $lastNotification = max(array_map('strtotime', $sentDates));
                }
            }

            // Get editors assigned to the submission, consider also the recommendOnly editors
            $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var ReviewRoundDAO $reviewRoundDao */
            $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var StageAssignmentDAO $stageAssignmentDao*/
            $reviewRound = $reviewRoundDao->getById($args[0]->getData('assocId'));
            $editorsStageAssignments = $stageAssignmentDao->getEditorsAssignedToStage(
                $submission->getId(),
                $reviewRound->getStageId()
            );

            $recipients = [];
            foreach ($editorsStageAssignments as $editorsStageAssignment) {
                $userGroup = Repo::userGroup()->get($editorsStageAssignment->getData('userGroupId'));
                $userGroupAbbrev = $userGroup->getLocalizedData('abbrev');
                $editor = Repo::user()->get($editorsStageAssignment->getUserId());
                // IF no prior notification exists
                // OR if editor has logged in after the last revision upload
                // OR the last upload and notification was sent more than a day ago,
                // THEN send a new notification
                if ($userGroupAbbrev == 'SECRETARIA' && (is_null($lastNotification) || strtotime($editor->getDateLastLogin()) > $lastNotification || strtotime('-1 day') > $lastNotification)) {
                    $recipients[] = $editor;
                }
            }

            if (!empty($recipients)) {
                $mailable = new RevisedVersionNotify($context, $submission, $uploader, $reviewRound);
                $template = Repo::emailTemplate()->getByKey($context->getId(), RevisedVersionNotify::getEmailTemplateKey());
                $mailable->body($template->getLocalizedData('body'))
                    ->subject($template->getLocalizedData('subject'))
                    ->sender($user)
                    ->recipients($recipients)
                    ->replyTo($context->getData('contactEmail'), $context->getData('contactName'));

                Mail::send($mailable);
                $submissionEmailLogDao = DAORegistry::getDAO('SubmissionEmailLogDAO'); /** @var SubmissionEmailLogDAO $submissionEmailLogDao */
                $submissionEmailLogDao->logMailable(
                    SubmissionEmailLogEntry::SUBMISSION_EMAIL_AUTHOR_NOTIFY_REVISED_VERSION,
                    $mailable,
                    $submission,
                    $user
                );
            }

            $args[0]->setData('uploaderUserId', '');
        }
    }

}
