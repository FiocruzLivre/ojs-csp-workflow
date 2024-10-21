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
    public function publicationEdit($hookName, $args){
        $request = Application::get()->getRequest();
        $submission = Repo::submission()->get((int) $args[0]->getData('submissionId'));
        $params['agradecimentos'] = $request->getUserVar('agradecimentos');
        $params['conflitoInteresse'] = $request->getUserVar('conflitoInteresse');
        $params['consideracoesEticas'] = $request->getUserVar('consideracoesEticas');
        Repo::submission()->edit($submission, $params);
    }
    public function templateManagerDisplay($hookName, $args){
        if($args[1] == "decision/record.tpl"){
            $steps = $args[0]->getState('steps');
            $locale = Locale::getLocale();
            foreach ($steps as $stepKey => $step ) {
                if ($step->initialTemplateKey == "EDITOR_RECOMMENDATION") {
                    $variables = $step->variables[$locale];
                    foreach ($variables as $variableKey => $variable) {
                        if ($variable["key"] == "allReviewerComments") {
                            $reviewerComments = explode('</p><p>',$variable["value"]);
                            $reviewerRecomendation = str_replace('<p>','',$reviewerComments[0]);
                            $steps[$stepKey]->variables[$locale][$variableKey]["value"] = $reviewerRecomendation;
                        }
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
        if($args[1]->id == "metadata"){
            $context = Application::get()->getRequest()->getContext();
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
