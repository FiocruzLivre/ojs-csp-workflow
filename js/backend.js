$(function() {
	// Marca o campo "Este participante pode somente recomendar uma decisão editorial" 
	// em formulário de designar participante
	$(document).ajaxComplete(function() {
		$("#addParticipantForm #recommendOnly").prop("checked", true);
	});
});
