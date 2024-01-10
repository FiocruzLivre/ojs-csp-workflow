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
			$templateMgr->addStyleSheet('CspSubmission', $url, ['contexts' => 'backend']);

            Hook::add('TemplateManager::display', [$this, 'templateManagerDisplay']);
            Hook::add('TemplateResource::getFilename', [$this, '_overridePluginTemplates']);
            Hook::add('TemplateManager::fetch', [$this, 'templateManagerFetch']);
            Hook::add('submissionfilesmetadataform::initdata', [$this, 'submissionfilesmetadataformInitdata']);
            Hook::add('submissionfilesmetadataform::execute', [$this, 'submissionfilesmetadataformExecute']);
            Hook::add('Form::config::after', [$this, 'FormConfigAfter']);
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

    /**
     * Em lista (grid) de arquivos,
     * exibe comentário sobre o arquivo e nome de pessoa que incluiu o arquivo
     */
    public function templateManagerFetch($hookName, $args) {
        $templateVars = $args[0]->getTemplateVars();
        if($args[1] == "controllers/grid/gridRow.tpl"){
            if(substr($templateVars["grid"]->_id,0,10) == "grid-files"){
                $args[0]->tpl_vars["columns"]->value['notes'] = new GridColumn('notes', 'common.note');
                $noteDao = DAORegistry::getDAO('NoteDAO'); /** @var NoteDAO $noteDao */
                $notes = $noteDao->getByAssoc(Application::ASSOC_TYPE_SUBMISSION_FILE, $args[0]->tpl_vars["row"]->value->_id)->toArray();;
                foreach ($notes as $key => $value) {
                    $content[] = $value->getContents('contents');
                }
                $note = $content <> null ? implode('<hr>', $content) : "";
                $args[0]->tpl_vars["cells"]->value[] = "<span id='cell-".
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
        if($args[1] == "controllers/grid/grid.tpl"){
            if(in_array($templateVars["grid"]->_id,
                        ["grid-files-submission-editorsubmissiondetailsfilesgrid", 
                        "grid-files-review-editorreviewfilesgrid",
                        "grid-files-review-workflowreviewrevisionsgrid",
                        "grid-files-final-finaldraftfilesgrid", 
                        "grid-files-copyedit-copyeditfilesgrid",
                        "grid-files-productionready-productionreadyfilesgrid"])){
                $args[0]->tpl_vars["columns"]->value["name"]->_flags["width"] = 40;
                $args[0]->tpl_vars["columns"]->value["date"]->_flags["width"] = 20;

            }
            if($templateVars["grid"]->_id == "grid-files-final-managefinaldraftfilesgrid"){
                $args[0]->tpl_vars["columns"]->value["select"]->_flags["width"] = 13;
                $args[0]->tpl_vars["columns"]->value["name"]->_flags["width"] = 60;
                $args[0]->tpl_vars["columns"]->value["type"]->_flags["width"] = 25;
            }
        }
    }

    public function submissionfilesmetadataformInitdata($hookName, $args) {
        $submission = Repo::submission()->get((int) $args[0]->_submissionFile->getData('submissionId'));
        if($submission->_data["stageId"] == 1){
            $args[0]->_submissionFile->setData('name',str_replace('/','_',$submission->getData('submissionIdCSP')).'_PDF_para_avaliação',$args[0]->_submissionFile->getData('locale'));
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
}
