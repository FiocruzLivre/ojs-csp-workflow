$(function() {
	// Marca o campo "Este participante pode somente recomendar uma decisão editorial"
	// em formulário de designar participante
	$(document).ajaxComplete(function() {
		$("#addParticipantForm #recommendOnly").prop("checked", true);
	});

	// Substitui o ID da submissão pelo ID do CSP + seção (submissionIdCSP - título da seção) no cabeçalho do painel de fluxo editorial (Dashboard)
	if (window.MutationObserver && window.cspWorkflowSubmissionsApiUrl) {
		var cspIdCache = {};
		var pendingIds = {};
		var sectionTitlesById = null;
		var sectionsFetch = null;

		var getSectionTitles = function() {
			if (sectionTitlesById) {
				return $.Deferred().resolve(sectionTitlesById).promise();
			}
			if (!sectionsFetch) {
				sectionsFetch = $.get(window.cspWorkflowSectionsApiUrl).then(function(data) {
					var locale = ($.pkp && $.pkp.app && $.pkp.app.currentLocale) || "en";
					var titlesById = {};
					$.each((data && data.items) || [], function(i, section) {
						titlesById[section.id] = (section.title && (section.title[locale] || section.title.en)) || "";
					});
					sectionTitlesById = titlesById;
					return titlesById;
				});
			}
			return sectionsFetch;
		};

		var swappedTitleIds = {};

		// Troca o nome do autor pelo título da submissão e vice-versa, no cabeçalho do painel de fluxo.
		var swapAuthorAndTitle = function() {
			var $titleEl = $('[id^="reka-dialog-title-"]').first();
			var $descEl = $('[id^="reka-dialog-description-"]').first();
			if (!$titleEl.length || !$descEl.length) {
				return;
			}
			var titleId = $titleEl.attr("id");
			if (swappedTitleIds[titleId]) {
				return;
			}
			var $titleSpan = $titleEl.children("span").first();
			var $descSpan = $descEl.children("span").first();
			if (!$titleSpan.length || !$descSpan.length) {
				return;
			}
			var authorText = $titleSpan.text();
			var submissionTitleText = $descSpan.text();
			$titleSpan.text(submissionTitleText);
			$descSpan.text(authorText);
			swappedTitleIds[titleId] = true;
		};

		var replaceSubmissionIdHeaders = function() {
			$(".text-xl-medium").each(function() {
				var textNode = $(this).contents().filter(function() {
					return this.nodeType === 3 && $.trim(this.nodeValue) !== "";
				}).first();
				if (!textNode.length) {
					return;
				}
				var raw = $.trim(textNode.text());
				if (!/^\d+$/.test(raw)) {
					return;
				}
				if (cspIdCache[raw]) {
					textNode[0].nodeValue = cspIdCache[raw] + " ";
					return;
				}
				if (pendingIds[raw]) {
					return;
				}
				pendingIds[raw] = true;
				$.get(window.cspWorkflowSubmissionsApiUrl + raw)
					.done(function(data) {
						var publication = data && data.publications && $.grep(data.publications, function(p) {
							return p.id === data.currentPublicationId;
						})[0];
						if (publication && publication.submissionIdCSP) {
							getSectionTitles().done(function(titlesById) {
								var sectionTitle = titlesById[publication.sectionId];
								var label = sectionTitle
									? publication.submissionIdCSP + " - " + sectionTitle
									: publication.submissionIdCSP;
								cspIdCache[raw] = label;
								textNode[0].nodeValue = label + " ";
							});
						}
					})
					.always(function() {
						delete pendingIds[raw];
					});
			});
		};

		var onWorkflowPanelMutation = function() {
			replaceSubmissionIdHeaders();
			swapAuthorAndTitle();
		};

		new MutationObserver(onWorkflowPanelMutation).observe(document.body, {
			childList: true,
			subtree: true,
			characterData: true
		});
		onWorkflowPanelMutation();
	}
});
