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
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use APP\core\Application;
use PKP\controllers\grid\GridColumn;
use PKP\security\Role;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PKP\facades\Locale;
use PKP\components\forms\FieldTextarea;
use PKP\components\forms\FieldText;
use PKP\components\forms\FieldRadioInput;
use APP\decision\Decision;
use PKP\core\PKPString;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\mail\traits\ReviewerComments;

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

            Hook::add('TemplateResource::getFilename', [$this, '_overridePluginTemplates']);
            Hook::add('TemplateManager::fetch', [$this, 'templateManagerFetch']);
            Hook::add('submissionfilesuploadform::execute', [$this, 'submissionfilesuploadformExecute']);
            Hook::add('submissionfilesmetadataform::execute', [$this, 'submissionfilesmetadataformExecute']);
            Hook::add('Form::config::after', [$this, 'FormConfigAfter']);
            Hook::add('stageparticipantgridhandler::initfeatures', [$this, 'stageparticipantgridhandlerInitfeatures']);
            Hook::add('submissionfilesuploadform::display', [$this, 'submissionfilesuploadformDisplay']);
            Hook::add('ReviewerAction::confirmReview', [$this, 'reviewerActionConfirmReview']);
            Hook::add('Submission::Collector', [$this, 'submissionCollector']);
            Hook::add('TemplateManager::display', [$this, 'templateManagerDisplay']);
            Hook::add('Publication::edit', [$this, 'publicationEdit']);
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

    // Grava campos adicionais ao editar metadados da submissão e ao utilizar plugin de submissão rápida
    public function publicationEdit($hookName, $args){
        $request = Application::get()->getRequest();
        $submission = Repo::submission()->get((int) $args[0]->getData('submissionId'));

        if ($request->getUserVar('submissionIdCSP')) {
            $args[1]->setData('submissionIdCSP', $request->getUserVar('submissionIdCSP'));
        }
        if ($request->getUserVar('agradecimentos')) {
            $params['agradecimentos'] = $request->getUserVar('agradecimentos');
        }
        if ($request->getUserVar('conflitoInteresse')) {
            $params['conflitoInteresse'] = $request->getUserVar('conflitoInteresse');
        }
        if ($request->getUserVar('consideracoesEticas')) {
            $params['consideracoesEticas'] = $request->getUserVar('consideracoesEticas');
        }
        if($request->getUserVar('dateAccepted')){
            $params['dateAccepted'] = $request->getUserVar('dateAccepted');
            DB::table('edit_decisions')->updateOrInsert(
                ['submission_id' => (int) $args[0]->getData('submissionId'),
                'review_round_id' => null,
                'stage_id' => 1,
                'round' => null,
                'editor_id' => 1,
                'decision' => Decision::ACCEPT,
                'date_decided' => $request->getUserVar('dateAccepted')]
            );
        }
        if ($request->getUserVar('dateSubmitted')) {
            $params['dateSubmitted'] = $request->getUserVar('dateSubmitted');
        }
        if ($params) {
            Repo::submission()->edit($submission, $params);
        }
    }

    public function templateManagerDisplay($hookName, $args){
        /* Adiciona CSS específico para remover visualização status do fluxo 
        para usuários que não tenham nenhum dos seguintes papéis:
        Gerente, Editor de seção, Assistente a Admin */
        if($args[1] == "dashboard/index.tpl" or $args[1] == "authorDashboard/authorDashboard.tpl"){
            $request = Application::get()->getRequest();
            $userRoles = $request->getUser()->getRoles($request->getContext()->getId());
            foreach ($userRoles as $roles => $role) {
                $userRolesArray[] = $role->getData('id');
            }
            if(array_intersect(
                $userRolesArray,
                [
                    Role::ROLE_ID_MANAGER,
                    Role::ROLE_ID_SUB_EDITOR,
                    Role::ROLE_ID_ASSISTANT,
                    Role::ROLE_ID_SITE_ADMIN
                ]
                ) == null){
                    $url = $request->getBaseUrl() . '/' . $this->getPluginPath() . '/styles/hideStatus.css';
                    $templateMgr = TemplateManager::getManager($request);
                    $templateMgr->addStyleSheet('Author', $url, ['contexts' => 'backend']);
            }
        }

        // Exibe somente avaliações lidas e encaminhadas em template de email com variável allReviewerComments
        // Remove recomendação de avaliadores em email de solicitação de modificações ao autor
        if($args[1] == "decision/record.tpl"){
            $steps = $args[0]->getState('steps');
            $locale = Locale::getLocale();
            $templateVars = $args[0]->getTemplateVars();
            $request = Application::get()->getRequest();
            foreach ($steps as $stepKey => $step ) {
                $variables = $step->variables[$locale];
                foreach ($variables as $variableKey => $variable) {
                    if ($variable["key"] == "allReviewerComments") {
                        $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO');
                        $reviewAssignments = $reviewAssignmentDao->getBySubmissionId($templateVars["submission"]->getData('id'), $request->getUserVar('reviewRoundId'), $templateVars["submission"]->getData('stageId'));
                        $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO');
                        $reviewerNumber = 0;
                        $comments = [];
                        foreach ($reviewAssignments as $reviewAssignment) {
                            if (in_array($reviewAssignment->getData('considered'), [2,3])) {
                                $reviewerNumber++;
                                $submissionComments = $submissionCommentDao->getReviewerCommentsByReviewerId(
                                    $templateVars["submission"]->getData('id'),
                                    $reviewAssignment->getReviewerId(),
                                    $reviewAssignment->getId(),
                                    true
                                );
                                $reviewerIdentity = $reviewAssignment->getReviewMethod() == ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN
                                    ? $reviewAssignment->getReviewerFullName()
                                    : __('submission.comments.importPeerReviews.reviewerLetter', ['reviewerLetter' => $reviewerNumber]);
                                $recommendation = $reviewAssignment->getLocalizedRecommendation();
                                $commentsBody = '';
                                /** @var SubmissionComment $comment */
                                while ($comment = $submissionComments->next()) {
                                    // If the comment is viewable by the author, then add the comment.
                                    if ($comment->getViewable()) {
                                        $commentsBody .= PKPString::stripUnsafeHtml($comment->getComments());
                                    }
                                }
                                if ($step->initialTemplateKey == "EDITOR_RECOMMENDATION") {
                                    $comments[] =
                                        '<p>'
                                        . '<strong>' . $reviewerIdentity . '</strong>'
                                        . '<br>'
                                        . __('submission.recommendation', ['recommendation' => $recommendation])
                                        . '</p>'
                                        . $commentsBody;
                                }
                                if ($step->initialTemplateKey == "EDITOR_DECISION_RESUBMIT") {
                                    $comments[] =
                                    '<p>'
                                    . '<strong>' . $reviewerIdentity . '</strong>'
                                    . $commentsBody;
                                }
                            }
                        }
                        $steps[$stepKey]->variables[$locale][$variableKey]["value"] = join('', $comments);
                    }
                }
            }
            $args[0]->setState(["steps" => $steps]);
        }
        if($args[1] == "workflow/workflow.tpl"){
            $currentPublication = $args[0]->getState('currentPublication');
            $section = Repo::section()->get($currentPublication["sectionId"]);
            $sectioTitle = $section->getLocalizedData('title');
            $currentPublication["sectioTitle"] = $sectioTitle;
            $args[0]->setState(["currentPublication" => $currentPublication]);
        }
    }
    public function submissionCollector($hookName, $args){
        // Inclui campo submissionIdCSP em retorno de busca
        $request = Application::get()->getRequest();
        if ($request->_requestVars["searchPhrase"] <> "") {
            $keywords = collect(Application::getSubmissionSearchIndex()
                ->filterKeywords($request->_requestVars["searchPhrase"], true, true, true))
                ->unique();
            foreach ($keywords as $key => $value) {
                $args[0]->bindings["where"][] = 'submissionIdCSP';
                $args[0]->bindings["where"][] = $request->_requestVars["searchPhrase"];
            }
            $likePattern = DB::raw("CONCAT('%', LOWER(?), '%')");
            $lastKey = array_key_last($args[0]->wheres);
            $args[0]->wheres[$lastKey]["query"]->orWhere(fn (Builder $q) => $keywords
                ->map(
                    fn (string $keyword) => $q
                        ->orWhereIn(
                            's.submission_id',
                            fn (Builder $query) => $query
                                ->select('ps.publication_id')
                                ->from('publication_settings AS ps')
                                ->where('ps.setting_name', '=', 'submissionIdCSP')
                                ->where(DB::raw('LOWER(ps.setting_value)'), 'LIKE', $likePattern)->addBinding($keyword)
                        )
                ));
        }
        // Ordena a lista de submissões em ordem decrescente de data de modificação
        $args[0]->orders[0]["column"] = 's.date_last_activity';
        $args[1]->orderBy = 'dateLastActivity';
    }

    public function templateManagerFetch($hookName, $args) {
        $templateVars = $args[0]->getTemplateVars();
        $request = Application::get()->getRequest();
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
        if($args[1] == "reviewer/review/step3.tpl" or $args[1] == "controllers/grid/users/reviewer/readReview.tpl"){
            foreach ($templateVars["reviewerRecommendationOptions"] as $key => $value) {
                if(!in_array($value,["common.chooseOne", "reviewer.article.decision.accept", "reviewer.article.decision.pendingRevisions", "reviewer.article.decision.decline" ])){
                    unset($args[0]->tpl_vars["reviewerRecommendationOptions"]->value[$key]);
                }
            }
        }
        if($args[1] == "controllers/grid/gridRow.tpl"){
            $user = Repo::user()->get($_SESSION["userId"], true);
            $submissionId = $request->getUserVar('submissionId');
            if ($submissionId) {
                $submission = Repo::submission()->get((int) $submissionId);
                $publication = Repo::publication()->get((int) $submission->getData('currentPublicationId'));
                foreach ($publication->_data["authors"] as $key => $value) {
                    if ($value->getData('email') == $user->getData('email')) {
                        return;
                    }
                }
                /**
                 * Em lista (grid) de arquivos,
                 * substitui tipo do arquivo por comentário sobre o arquivo e adiciona nome de pessoa que incluiu o arquivo
                 */
                if(substr($templateVars["grid"]->_id,0,10) == "grid-files"){
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
                        $user = Repo::user()->get($templateVars["row"]->_data["submissionFile"]->_data["uploaderUserId"]);
                        $user = $user->getGivenName($templateVars["currentLocale"]);
                        $args[0]->tpl_vars["cells"]->value[] = "<span id='cell-".$user.
                                                                "-user' class='gridCellContainer'>
                                                                <span class='label'>".$user.
                                                                "</span></span>";
                    }
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
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $file =& $args[1];
        $fileArray = explode('.', $file->getData('path'));
        $submission = Repo::submission()->get((int) $args[1]->getData('submissionId'));
        $publication = Repo::publication()->get((int) $submission->getData('currentPublicationId'));
        // Renomeia arquivos de primeira versão enviados por autor
        if($args[0]->getData('revisedFileId') == "" && $args[0]->getData('fileStage') == 2){
            $file->setData('name', 'csp_' . str_replace('/', '_', $publication->getData('submissionIdCSP')) .'_V1.' . $fileArray[1], $file->getData('locale'));
            $file->setData('name', 'csp_' . str_replace('/', '_', $publication->getData('submissionIdCSP')) .'_V1.' . $fileArray[1], $context->getData('primaryLocale'));
            Repo::submissionFile()->edit($file, $file->_data);
        }
        // Renomeia arquivos de segunda versão enviados por autor excluindo arquivos enviados por avaliadores ($file->getData('fileStage') <> 5)
        if($args[0]->getData('revisedFileId') == "" && $args[0]->_reviewRound->_data["stageId"] == 3 && $args[0]->_reviewRound->_data["status"] = 15 && $file->getData('fileStage') <> 5){
            $file->setData('name', 'csp_' . str_replace('/', '_', $publication->getData('submissionIdCSP')) .'_V' . ($args[0]->_reviewRound->_data["round"]+1) . "." . $fileArray[1], $file->getData('locale'));
            $file->setData('name', 'csp_' . str_replace('/', '_', $publication->getData('submissionIdCSP')) .'_V' . ($args[0]->_reviewRound->_data["round"]+1) . "." . $fileArray[1], $context->getData('primaryLocale'));
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
        $context = Application::get()->getRequest()->getContext();
        if($args[0]["id"] == "titleAbstract"){
            $publicationId = explode('/',$args[0]["action"]);
            $publicationId = end($publicationId);
            $publication = Repo::publication()->get((int)$publicationId);
            $submission = Repo::submission()->get((int)$publication->_data["submissionId"]);
            $args[1]->addField(new FieldText('submissionIdCSP', [
                'label' => __('plugins.generic.cspWorkflow.submissionIdCSP'),
                'groupId' => 'default',
                'isRequired' => true,
                'size' => 'medium'
            ]));
        }
        if($args[0]["id"] == "issueEntry"){
            $publicationId = explode('/',$args[0]["action"]);
            $publicationId = end($publicationId);
            $publication = Repo::publication()->get((int)$publicationId);
            $submission = Repo::submission()->get((int)$publication->_data["submissionId"]);

            $dateFormatShort = PKPString::convertStrftimeFormat($context->getLocalizedDateFormatShort());
            $args[1]->addField(new \PKP\components\forms\FieldHTML('dateSubmitted', [
                'label' => __('plugins.themes.csp.dates.received'),
                'groupId' => 'default',
                'isRequired' => true,
                'size' => 'medium'
            ]));
            $timestampDateSubmitted = strtotime($submission->getData('dateSubmitted'));
            $config = [
                'name' => 'dateSubmitted',
                'label' => __('plugins.themes.csp.dates.received'),
                'description' => __('plugins.generic.cspWorkflow.dates.description'),
                'component' => 'field-text',
                'groupId' => 'default',
                'isRequired' => true,
                'value' => date('Y-m-d', $timestampDateSubmitted),
            ];
            $args[0]["fields"][] = $config;

            $args[1]->addField(new FieldText('dateAccepted', [
                'label' => __('plugins.themes.csp.dates.accepted'),
                'groupId' => 'default',
                'isRequired' => true,
                'size' => 'medium'
            ]));
            $decisionsAcceptedArray = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$publication->_data["submissionId"]])
            ->filterByDecisionTypes([Decision::ACCEPT])
            ->getMany()
            ->toArray();
            $decisionsAccepted = end($decisionsAcceptedArray);
            $config = [
                'name' => 'dateAccepted',
                'label' => __('plugins.themes.csp.dates.accepted'),
                'component' => 'field-text',
                'groupId' => 'default',
                'isRequired' => true,
                'value' => date('Y-m-d', $decisionsAccepted ? strtotime($decisionsAccepted->getData('dateDecided')) : null),
            ];
            $args[0]["fields"][] = $config;

            $config = [
                'name' => 'submissionIdCSP',
                'label' => __('plugins.generic.cspWorkflow.submissionIdCSP'),
                'component' => 'field-text',
                'groupId' => 'default',
                'isRequired' => true,
                'value' => $submission->getData('submissionIdCSP')
            ];
            $args[0]["fields"][] = $config;
        }

        if($args[0]["id"] == "selectRevisionDecision"){
            $revisionDecisionForm = $args[1];
            $config =& $args[0];
            $fieldDecision = $revisionDecisionForm->getField('decision');
            $config["fields"][0]["value"] = $fieldDecision->options[1]["value"];
        }
        if($args[1]->id == "metadata"){
            $publicationId = explode('/',$args[0]["action"]);
            $publicationId = end($publicationId);
            $publication = Repo::publication()->get((int)$publicationId);
            $submission = Repo::submission()->get((int)$publication->_data["submissionId"]);

            $section = Repo::section()->get((int) $publication->getData('sectionId'));
            $sectionAbbrev = $section->getAbbrev($context->getData('primaryLocale'));

            $args[1]->addField(new FieldTextarea('agradecimentos', [
                'label' => __('plugins.generic.CspSubmission.agradecimentos'),
                'groupId' => 'default',
                'isRequired' => false,
                'size' => 'medium'
            ]));
            $config = [
                'name' => 'agradecimentos',
                'label' => __('plugins.generic.CspSubmission.agradecimentos'),
                'component' => 'field-textarea',
                'groupId' => 'default',
                'isRequired' => false,
                'value' => $submission->getLocalizedData('agradecimentos')
            ];
            $args[0]["fields"][] = $config;

            if($sectionAbbrev == "ESP_TEMATICO") {
                $args[1]->addField(new FieldText('espacoTematico', [
                    'label' => __('plugins.generic.CspSubmission.espacoTematico'),
                    'groupId' => 'default',
                    'isRequired' => true,
                    'size' => 'medium',
                    'value' => $context->getData('espacoTematico'),
                ]));
                $config = [
                    'name' => 'espacoTematico',
                    'label' => __('plugins.generic.CspSubmission.espacoTematico'),
                    'component' => 'field-text',
                    'groupId' => 'default',
                    'isRequired' => true,
                    'value' => $submission->getLocalizedData('espacoTematico')
                ];
                $args[0]["fields"][] = $config;
            }

            if($sectionAbbrev == "COMENTARIOS") {
                $args[1]->addField(new FieldText('codigoArtigoRelacionado', [
                    'label' => __('plugins.generic.CspSubmission.codigoArtigoRelacionado'),
                    'groupId' => 'default',
                    'isRequired' => true,
                    'size' => 'small',
                    'value' => $context->getData('codigoArtigoRelacionado'),
                ]));
                $config = [
                    'name' => 'codigoArtigoRelacionado',
                    'label' => __('plugins.generic.CspSubmission.codigoArtigoRelacionado'),
                    'component' => 'field-text',
                    'groupId' => 'default',
                    'isRequired' => true,
                    'value' => $submission->getLocalizedData('codigoArtigoRelacionado')
                ];
                $args[0]["fields"][] = $config;
            }

            $args[1]->addField(new FieldText('codigoFasciculoTematico', [
                'label' => __('plugins.generic.CspSubmission.codigoFasciculoTematico'),
                'description' => __('plugins.generic.CspSubmission.codigoFasciculoTematico.description'),
                'groupId' => 'default',
                'isRequired' => false,
                'size' => 'medium',
                'value' => $context->getData('codigoFasciculoTematico'),
            ]));
            $config = [
                'name' => 'codigoFasciculoTematico',
                'label' => __('plugins.generic.CspSubmission.codigoFasciculoTematico'),
                'component' => 'field-text',
                'groupId' => 'default',
                'isRequired' => false,
                'value' => $submission->getLocalizedData('codigoFasciculoTematico')
            ];
            $args[0]["fields"][] = $config;

            $args[1]->addField(new FieldRadioInput('conflitoInteresse', [
                'label' => __('plugins.generic.CspSubmission.conflitoInteresse'),
                'groupId' => 'default',
                'isRequired' => true,
                'type' => 'radio',
                'size' => 'small',
                'options' => [
                    ['value' => 'S', 'label' => __('common.yes')],
                    ['value' => 'N', 'label' => __('common.no')],
                ],
                'value' => $context->getData('conflitoInteresse'),
            ]));
            $config = [
                'name' => 'conflitoInteresse',
                'label' => __('plugins.generic.CspSubmission.conflitoInteresse'),
                'component' => 'field-radio-input',
                'groupId' => 'default',
                'isRequired' => true,
                'options' => [
                    ['value' => 'S', 'label' => __('common.yes')],
                    ['value' => 'N', 'label' => __('common.no')],
                ],
                'value' => $submission->getLocalizedData('conflitoInteresse')
            ];
            $args[0]["fields"][] = $config;

            $args[1]->addField(new FieldRadioInput('consideracoesEticas', [
                'label' => __('plugins.generic.CspSubmission.consideracoesEticas'),
                'groupId' => 'default',
                'isRequired' => true,
                'type' => 'radio',
                'size' => 'small',
                'options' => [
                    ['value' => 'S', 'label' => __('plugins.generic.CspSubmission.consideracoesEticas.checkbox.yes')],
                    ['value' => 'N', 'label' => __('plugins.generic.CspSubmission.consideracoesEticas.checkbox.no')],
                ],
                'value' => $context->getData('consideracoesEticas'),
            ]));
            $config = [
                'name' => 'consideracoesEticas',
                'label' => __('plugins.generic.CspSubmission.consideracoesEticas'),
                'component' => 'field-radio-input',
                'groupId' => 'default',
                'isRequired' => true,
                'options' => [
                    ['value' => 'S', 'label' => __('plugins.generic.CspSubmission.consideracoesEticas.checkbox.yes')],
                    ['value' => 'N', 'label' => __('plugins.generic.CspSubmission.consideracoesEticas.checkbox.no')],
                ],
                'value' => $submission->getLocalizedData('consideracoesEticas')
            ];
            $args[0]["fields"][] = $config;
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

}

