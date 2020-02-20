jQuery(document).ready(function($) {
	$("#page_comments").on("click", "span.reply_button", function() {
		let parent_id = $(this).parents("li").attr("data-id"),
			counter = $(this).parents("li").attr("data-counter"),
			level = $(this).parents("li").attr("data-level");
		$("#comment_form").children("input[name=parent_id]").val(parent_id);
		$("#comment_form").children("input[name=counter]").val(counter);
		$("#comment_form").children("input[name=level]").val(level);
		$("textarea.content").focus();
	});
	let work = comment_redirect_url + "del_comment";
	$("#page_comments").on("click", "span.remove_button", function() {
		if (!confirm(smf_you_sure))
			return false;
		let item = $(this).parents("li").attr("data-id"),
			alias = $(this).parents("li").attr("data-alias");
		if (item && alias) {
			let items = [item];
			$($(this).parents("li")).find('li').each(function() {
				items.push($(this).attr("data-id"));
			});
			$.post(work, {items: items, alias: alias});
			$(this).closest("li").slideUp();
		}
	});
	$("#page_comments").on("click", ".popover_title .bold_text", function (e) {
		let commentTextarea = $("#message").val(),
			position = $("#message")[0].selectionStart,
			nickname = e.target.innerText + ", "
		$("#message").val(commentTextarea.substring(0, position) + nickname + commentTextarea.substring(position));
		$("#message").focus();
	});
	$("#comment_form").on("focus", "#message", function () {
		$("#message").css("height", "auto");
		$("button[name=comment]").css("display", "block");
	});
	$("#comment_form").on("keyup", function (e) {
		if ($(e.target).attr("name")) {
			if ($("#message").val() != "") {
				$("button[name=comment]").prop("disabled", false);
			} else {
				$("button[name=comment]").prop("disabled", true);
			}
		}
	});
	$("#page_comments").on("submit", "#comment_form", function (e) {
		$.ajax({
			type: $(this).attr("method"),
			url: $(this).attr("action"),
			data: $(this).serialize(),
			dataType: "json",
			success: function (data) {
				$("#comment_form")[0].reset();
				let comment = data.comment;
				if (data.parent != 0) {
					if ($("li[data-id=" + data.parent + "] ul").is(".comment_list")) {
						$("li[data-id=" + data.parent + "] ul.comment_list").append(comment).slideDown();
					} else {
						$("li[data-id=" + data.parent + "] .comment_wrapper").append(comment).slideDown();
					}
				} else {
					if ($("ul").is(".comment_list")) {
						$(".comment_list").first().append(comment).slideDown();
					} else {
						$("#page_comments").prepend("<ul class=\"comment_list row\"><\/ul>");
						$(".comment_list").first().append(comment).slideDown();
					}
				}
				$("#message").css("height", "30px");
				$("button[name=comment]").css("display", "none");
				$("input[name=parent_id]").val(0);
				//window.location.href = comment_redirect_url + "start=" + data.start + "#comment" + data.item;
				window.location.hash = "#comment" + data.item;
			}
		});
		e.preventDefault();
	});
});