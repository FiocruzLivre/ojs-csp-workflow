{**
 * templates/workflow/submissionIdentification.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Show submission identification component
 *}

<span class="pkpWorkflow__identificationId">
	{{ currentPublication.submissionIdCSP }}
</span>

<span v-if="currentPublication.sectioTitle" class="pkpWorkflow__identificationSection">
	<span class="pkpWorkflow__identificationDivider"> - </span>
	<span class="pkpWorkflow__identificationSection">
		{{ currentPublication.sectioTitle }}
	</span>
</span>

<span v-if="currentPublication.codigoFasciculoTematico" class="pkpWorkflow__identificationSection">
	<span class="pkpWorkflow__identificationDivider"> - </span>
		{translate key="plugins.generic.CspSubmission.codigoFasciculoTematico"}:
		{{ currentPublication.codigoFasciculoTematico }}
</span>

<span class="pkpWorkflow__identificationTitle">
	<br>
	{{ localizeSubmission(currentPublication.fullTitle, currentPublication.locale) }}
</span>

<span v-if="currentPublication.authorsStringShort" class="pkpWorkflow__identificationAuthor">
	<br>
	{{ currentPublication.authorsString }}
</span>


