// global variable for subwindow reference
const MESSAGE_LEVEL_NONE  = 0;
const MESSAGE_LEVEL_INFO  = 1;
const MESSAGE_LEVEL_WARN  = 2;
const MESSAGE_LEVEL_ERROR = 3;
const MESSAGE_LEVEL_CSRF  = 4;
const MESSAGE_LEVEL_MIXED = 5;

var sessionMessage      = null;
var sessionMessageOpen  = null;
var sessionMessageTimer = null;

var newWindow;
var selectedNode;
var selectedLink;
var graphTimer;
var graphClickTimer;
var graphOpen = false;
var editor_url = 'weathermap-cacti-plugin-editor.php';
var imageWidth  = null;
var imageHeight = null;
var local_graph_id = null;
var infoUrlTarget = 'graph_view.php?action=preview&reset=true&style=selective&graph_list=';
var wmDragState = null;
var wmSaveInFlight = false;
var wmSuppressClickUntil = 0;
var wmLastSavedMove = null;
var wmFailedMove = null;
var wmRestoreFocusNode = null;
var wmRestoreFocusLink = null;
var wmRestoreFocusElement = null;
var wmDialogReturnFocus = null;
var wmReloadRequired = false;
var wmMapElements = [];

function displayMessages() {
	var error   = false;
	var title   = '';
	var header  = '';

	if (typeof sessionMessageTimer == 'function' || sessionMessageTimer !== null) {
		clearInterval(sessionMessageTimer);
	}

	if (sessionMessage == null) {
		return;
	}

	if (typeof sessionMessage.level != 'undefined') {
		if (sessionMessage.level == MESSAGE_LEVEL_ERROR) {
			title = errorReasonTitle;
			header = errorOnPage;
			var sessionMessageButtons = {
				'Ok': {
					text: sessionMessageOk,
					id: 'btnSessionMessageOk',
					click: function() {
						$(this).dialog('close');
					}
				}
			};

			sessionMessageOpen = {};
		} else if (sessionMessage.level == MESSAGE_LEVEL_MIXED) {
			title  = mixedReasonTitle;
			header = mixedOnPage;
			var sessionMessageButtons = {
				'Ok': {
					text: sessionMessageOk,
					id: 'btnSessionMessageOk',
					click: function() {
						$(this).dialog('close');
					}
				}
			};

			sessionMessageOpen = {};
		} else if (sessionMessage.level == MESSAGE_LEVEL_CSRF) {
			var href = document.location.href;
			href = href + (href.indexOf('?') > 0 ? '&':'?') + 'csrf_timeout=true';
			document.location = href;
			return false;
		} else {
			title = sessionMessageTitle;
			header = sessionMessageSave;
			var sessionMessageButtons = {
				'Pause': {
					text: sessionMessagePause,
					id: 'btnSessionMessagePause',
					click: function() {
						if (sessionMessageTimer != null) {
							clearInterval(sessionMessageTimer);
							sessionMessageTimer = null;
						}
						$('#btnSessionMessagePause').remove();
						$('#btnSessionMessageOk').html('<span class="ui-button-text">' + sessionMessageOk + '</span>');
					}
				},
				'Ok': {
					text: sessionMessageOk,
					id: 'btnSessionMessageOk',
					click: function() {
						$(this).dialog('close');
						$('#messageContainer').remove();
						clearInterval(sessionMessageTimer);
					}
				}
			};

			sessionMessageOpen = function() {
				sessionMessageCountdown(5000);
			}
		}

		var returnStr = '<div id="messageContainer" style="display:none">' +
			'<h4>' + header + '</h4>' +
			'<p style="display:table-cell;overflow:auto"> ' + sessionMessage.message + '</p>' +
			'</div>';

		$('#messageContainer').remove();
		$('body').append(returnStr);

		var messageWidth = $(window).width();
		if (messageWidth > 600) {
			messageWidth = 600;
		} else {
			messageWidth -= 50;
		}

		$('#messageContainer').dialog({
			open: sessionMessageOpen,
			draggable: true,
			resizable: false,
			height: 'auto',
			minWidth: messageWidth,
			maxWidth: 800,
			maxHeight: 600,
			title: title,
			buttons: sessionMessageButtons
		});

		sessionMessage = null;
	}
}

function sessionMessageCountdown(time) {
	var sessionMessageTimeLeft = (time / 1000);

	$('#btnSessionMessageOk').html('<span class="ui-button-text">' + sessionMessageOk + ' (' + sessionMessageTimeLeft + ')</span>');

	sessionMessageTimer = setInterval(function() {
		sessionMessageTimeLeft--;

		$('#btnSessionMessageOk').html('<span class="ui-button-text">' + sessionMessageOk + ' (' + sessionMessageTimeLeft + ')</span>');

		if (sessionMessageTimeLeft <= 0) {
			clearInterval(sessionMessageTimer);
			$('#messageContainer').dialog('close');
			$('#messageContainer').remove();
		}
	}, 1000);
}

function graphPicker() {
	$('.selectmenu-ajax').each(function() {
		var id       = $(this).attr('id');
		var value    = $(this).val();
		var title    = 'Click to Search';
		var action   = $(this).attr('data-action');
		var mapname  = 'none';

		if ($('#'+id+'_wrap').length) {
			$('#'+id+'_wrap').remove();
			$('#'+id+'_add').remove();
			$('#'+id+'_rep').remove();
		}

		var dialogForm = "<span id='" + id + "_wrap' class='autodrop ui-selectmenu-button ui-selectmenu-button-closed ui-corner-all ui-button ui-widget'>";
		dialogForm    += "<span id='" + id + "_click' style='z-index:4' class='ui-selectmenu-icon ui-icon ui-icon-triangle-1-s'></span>";
		dialogForm    += "<span class='ui-select-text'>";
		dialogForm    += "<input type='text' class='ui-state-default ui-corner-all' id='" + id + "_input' value='" + title + "'>";
		dialogForm    += "</span>";
		dialogForm    += "</span>&nbsp;";
		dialogForm    += "<input id='" + id + "_add' type='button' class='ui-button ui-corner-all ui-widget' value='Add' />&nbsp;";
		dialogForm    += "<input id='" + id + "_rep' type='button' class='ui-button ui-corner-all ui-widget' value='Replace'/>";

		$(this).after(dialogForm);
		$(this).hide();

		$('#' + id + '_add').off('click').on('click', function() {
			var hover   = 'graph_image.php?local_graph_id=';;
			var infourl = infoUrlTarget;

			if (id == 'link_target_picker') {
				var target = $('#' + id).val();
				var existing = $('#link_target').val();

				$('#link_target').val(existing + (existing != '' ? ' ':'') + target);

				// Add the graph hovers if possible
				var ehover = $('#link_hover').val();
				var einfo  = $('#link_infourl').val();

				if (local_graph_id > 0) {
					if (ehover == '') {
						$('#link_hover').val(hover + local_graph_id);
					}

					if (einfo == '' || infoUrlStyle == 1) {
						$('#link_infourl').val(infourl + local_graph_id);
					}
				}
			} else {
				var hover   = 'graph_image.php?local_graph_id=';;
				var infourl = infoUrlTarget;

				if (id == 'link_picker') {
					var target = $('#' + id).val();
					var ehover = $('#link_hover').val();
					var einfo  = $('#link_infourl').val();

					if (einfo == '') {
						einfo = infourl;
					}

					$('#link_hover').val(ehover + (ehover != '' ? ' ':'') + hover + target);
					if (infoUrlStyle == 0) {
						$('#link_infourl').val(einfo + (einfo != '' ? ',':'') + target);
					} else {
						$('#link_infourl').val(infourl + target);
					}
				} else if (id == 'node_picker') {
					var target = $('#' + id).val();
					var ehover = $('#node_hover').val();
					var einfo  = $('#node_infourl').val();

					if (einfo == '') {
						einfo = infourl;
					}

					$('#node_hover').val(ehover + (ehover != '' ? ' ':'') + hover + target);

					if (infoUrlStyle == 0) {
						$('#node_infourl').val(einfo + (einfo != '' ? ',':'') + target);
					} else {
						$('#node_infourl').val(infourl + target);
					}
				}
			}
		});

		$('#' + id + '_rep').off('click').on('click', function() {
			if (id == 'link_picker') {
				$('#link_hover').val('graph_image.php?local_graph_id=' + $('#' + id).val());
				$('#link_infourl').val(infoUrlTarget + $('#' + id).val());
			} else if (id == 'node_picker') {
				$('#node_hover').val('graph_image.php?local_graph_id=' + $('#' + id).val());
				$('#node_infourl').val(infoUrlTarget + $('#' + id).val());
			} else if (id == 'link_target_picker') {
				$('#link_target').val($('#' + id).val());
			}
		});

		$('#' + id + '_input').autocomplete({
			source: function(request, response) {
				if (id == 'node_picker') {
					var template = $('#node_template').val();
				} else if (id == 'link_picker') {
					var template = $('#link_template').val();
				} else {
					var template = -1;
				}

				var url = 'weathermap-cacti-plugin-editor.php' +
					'?mapname=' + $('#mapname').val() +
					'&action=' + action +
					'&term=' + request.term +
					'&target=' + id +
					'&graph_template_id='+template;

				$.getJSON(url, function(data) {
					response(data);
				});
			},
			autoFocus: true,
			minLength: 0,
			select: function(event, ui) {
				$('#' + id + '_input').val(ui.item.label);

				if (ui.item.id) {
					$('#' + id).val(ui.item.id);
					local_graph_id = ui.item.local_graph_id;
				} else {
					$('#' + id).val(ui.item.value);
					local_graph_id = ui.item.local_graph_id;
				}
			},
			open: function(event, ui) {
				$('.ui-dialog').css('z-index', '20');
				$(this).css('z-index', '5000');
			}
		}).css('border', 'none').css('background-color', 'transparent');

		$('#' + id + '_wrap').on('dblclick', function() {
			graphOpen = false;
			clearTimeout(graphTimer);
			clearTimeout(graphClickTimer);
			$('#' + id + '_input').autocomplete('close').select();
		}).on('click', function() {
			if (graphOpen) {
				$('#'+'_input').autocomplete('close');
				clearTimeout(graphTimer);
				graphOpen = false;
			} else {
				graphClickTimer = setTimeout(function() {
					$('#' + id + '_input').autocomplete('search', '');
						clearTimeout(graphTimer);
						graphOpen = true;
					}, 200);
			}
			$('#' + id + '_input').select();
		}).on('mouseleave', function() {
			graphTimer = setTimeout(function() { $('#' + id + '_input').autocomplete('close'); }, 800);
		});

		var width = $('#' + id + '_input').textBoxWidth();
		if (width < 200) {
			width = 200;
		}

		$('#' + id + '_wrap').css('width', width+20);
		$('#' + id + '_input').css('width', width);
		$('#' + id + '_wrap').find('.ui-select-text').css('width', width);

		$('ul[id^="ui-id"]').on('mouseenter', function() {
			clearTimeout(graphTimer);
		}).on('mouseleave', function() {
			graphTimer = setTimeout(function() {
				$('#' + id + '_input').autocomplete('close');
			}, 800);
		});

		$('ul[id^="ui-id"] > li').on('mouseenter', function() {
			$(this).addClass('ui-state-hover');
		}).on('mouseleave', function() {
			$(this).removeClass('ui-state-hover');
		});

		$('#' + id + '_wrap').on('mouseenter', function() {
			$(this).addClass('ui-state-hover');
			$('input#' + id + '_input').addClass('ui-state-hover');
		}).on('mouseleave', function() {
			$(this).removeClass('ui-state-hover');
			$('input#' + id + '_input').removeClass('ui-state-hover');
		});
	});

}

$(document).on('unload', cleanupJS);

$(function() {
	initJS();
});

function initJS() {
	// if the xycapture element is there, then we are in the main edit screen
	if ($('#xycapture').length) {
		attach_click_events();
		attach_help_events();
		//show_context_help('node_label', 'node_help');

		// set the mapmode, so we know where we stand.
		mapmode('existing');
		wmInitInteractionLayer();
	}

	$('#frmMain').off('submit').on('submit', function(event) {
		event.preventDefault();
		form_submit();
	});

	if ($('#node_template').length) {
		$('#node_template').selectmenu().selectmenu('menuWidget').addClass('overflow');
		$('#node_template-button').attr('aria-label', 'Graph Template');
	}

	wmApplyDialogLabels();

	initContextMenu();

	$('#wm_undo_move').off('click.wmUndo').on('click.wmUndo', wmUndoLastMove);
	$('#wm_retry_save').off('click.wmRetry').on('click.wmRetry', wmRetryLastMove);
	$('#existingdata').off('dragstart.wmEditor').on('dragstart.wmEditor', function(event) {
		event.preventDefault();
	});

	graphPicker();
	$('#node_picker_input').attr('aria-label', 'Graph Selector');
	$('#link_target_picker_input').attr('aria-label', 'Data Source Selector');
	$('#link_picker_input').attr('aria-label', 'Graph Selector');

	if (infoUrlStyle == 0) {
		infoUrlTarget = 'graph_view.php?action=preview&reset=true&style=selective&graph_list=';
	} else {
		infoUrlTarget = 'graph.php?rra_id=all&local_graph_id=';
	}
}

function wmApplyDialogLabels() {
	var labels = {
		link_bandwidth_in: 'Maximum bandwidth into first node',
		link_bandwidth_out_cb: 'Use the same outbound bandwidth',
		link_bandwidth_out: 'Maximum bandwidth out of first node',
		viastyle: 'Via Style',
		link_target: 'Data Sources',
		link_target_picker: 'Data Source Selector',
		link_width: 'Link Width',
		link_infourl: 'Info URLs',
		link_hover: 'Hover Graph URLs',
		link_template: 'Graph Template',
		link_picker: 'Graph Selector',
		link_commentin: 'Inbound Comment',
		link_commentposin: 'Inbound Comment Position',
		link_commentout: 'Outbound Comment',
		link_commentposout: 'Outbound Comment Position',
		map_title: 'Map Title',
		map_legend: 'Legend Text',
		map_bgfile: 'Background Image Filename',
		map_stamp: 'Timestamp Text',
		map_linkdefaultwidth: 'Default Link Width',
		map_linkdefaultbwin: 'Default Inbound Link Bandwidth',
		map_linkdefaultbwout: 'Default Outbound Link Bandwidth',
		map_width: 'Map Width',
		map_height: 'Map Height',
		mapstyle_htmlstyle: 'HTML Style',
		mapstyle_keystyle: 'Key Style',
		mapstyle_legendfont: 'Legend Font',
		mapstyle_nodefont: 'Node Font',
		mapstyle_nodewidth: 'Node Graph Width',
		mapstyle_nodeheight: 'Node Graph Height',
		mapstyle_linklabels: 'Link Labels',
		mapstyle_arrowstyle: 'Arrow Style',
		mapstyle_linkfont: 'Link Label Font',
		mapstyle_linkwidth: 'Link Graph Width',
		mapstyle_linkheight: 'Link Graph Height',
		item_configtext: 'Map Object Configuration',
		editorsettings_showvias: 'Show VIA Overlay',
		editorsettings_showrelative: 'Show Relative Positions Overlay',
		editorsettings_gridsnap: 'Snap To Grid'
	};

	Object.keys(labels).forEach(function(id) {
		var control = $('#' + id + ', [name="' + id + '"]');

		control.attr('aria-label', labels[id]);
		control.filter('select').each(function() {
			if ($(this).selectmenu('instance')) {
				var widget = $(this).selectmenu('widget');
				var label  = labels[id];

				widget.attr('aria-label', label).removeAttr('aria-labelledby');
				widget.off('.wmFieldLabel').on('focus.wmFieldLabel mousedown.wmFieldLabel click.wmFieldLabel', function() {
					var button = this;

					setTimeout(function() {
						$(button).attr('aria-label', label).removeAttr('aria-labelledby');
					}, 0);
				});
			}
		});
	});
}

/** textBoxWidth - This function will return the natural width of a string
 *  without any wrapping. */
$.fn.textBoxWidth = function() {
	var org = $(this);
	var html = $('<span style="display:none;white-space:nowrap;position:absolute;width:auto;left:-9999px">' + (org.val() || org.text()) + '</span>');
	html.css('font-family', org.css('font-family'));
	html.css('font-weight', org.css('font-weight'));
	html.css('font-size',   org.css('font-size'));
	html.css('padding',     org.css('padding'));
	html.css('margin',      org.css('margin'));
	$('body').append(html);
	var width = html.width();
	html.remove();
	return width;
};

function initContextMenu() {
	var nodeMenu = [
		{title: txtNodeActions, cmd: "cat1", isHeader: true},
		{title: txtClone, cmd: 'clone', uiIcon: 'ui-icon-copy'},
		{title: txtEdit, cmd: 'edit', uiIcon: 'ui-icon-pencil'},
		{title: txtDelete, cmd: 'delete', uiIcon: 'ui-icon-trash'},
		{title: "----"},
		{title: txtProperties, cmd: 'properties', uiIcon: 'ui-icon-gear'}
	];

	var linkMenu = [
		{title: txtLinkActions, cmd: "cat1", isHeader: true},
		{title: txtTidy, cmd: 'tidy', uiIcon: 'ui-icon-arrow-4'},
		{title: txtEdit, cmd: 'edit', uiIcon: 'ui-icon-pencil'},
		{title: txtDelete, cmd: 'delete', uiIcon: 'ui-icon-trash'},
		{title: "----"},
		{title: txtProperties, cmd: 'properties', uiIcon: 'ui-icon-gear'}
	];

	$('#wm_map_stage').off('contextmenu.wmEditor').on('contextmenu.wmEditor', function(event) {
		if ($(event.target).closest('.wm-node-handle').length) {
			return;
		}

		event.preventDefault();
		return false;
	});

	$('map').contextmenu({
		delegate: 'area',
		menu: linkMenu,
		preventSelect: true,
		select: function(event, ui) {
			contextAction(event, ui);
		},
		beforeOpen: function(event, ui) {
			if (wmSaveInFlight || wmReloadRequired) {
				return false;
			}

			var target = ui.target[0].id;

			if (target.startsWith('LINK')) {
				$(this).contextmenu('replaceMenu', linkMenu);
			} else if (target.startsWith('NODE')) {
				$(this).contextmenu('replaceMenu', nodeMenu);
			} else {
				return false;
			}
		}
	});
}

function contextAction(event, ui) {
	var alt, objectname, objecttype, objectid;

	alt        = ui.target[0].id;
	objecttype = alt.slice(0, 4);
	objectname = alt.slice(5, alt.length);
	objectid   = objectname.slice(0, objectname.length-2);

	if (ui.cmd == 'properties') {
		click_execute(event, alt);
	} else if (objecttype == 'NODE') {
		objectname = NodeIDs[objectid];

		if (prime_node_form(objectname)) {
			switch (ui.cmd) {
				case 'clone':
					clone_node();
					break;
				case 'edit':
					edit_node();
					break;
				case 'delete':
					delete_node();
					break;
			}
		}
	} else if (objecttype == 'LINK') {
		objectname = LinkIDs[objectid];

		if (prime_link_form(objectname)) {
			switch (ui.cmd) {
				case 'tidy':
					tidy_link();
					break;
				case 'edit':
					edit_link();
					break;
				case 'delete':
					delete_link();
					break;
			}
		}
	}
}

function cleanupJS() {
    // This should be cleaning up all the handlers we added in initJS, to avoid killing
    // IE/Win and Safari (at least) over a period of time with memory leaks.
}

function attach_click_events() {
	var accessibleLinks = {};

	$("area[id^='LINK:']").attr('href', '#').each(function() {
		var linkId = String(this.id).split(':')[1];
		var name   = typeof LinkIDs != 'undefined' && typeof LinkIDs[linkId] != 'undefined' ? LinkIDs[linkId] : linkId;

		if (accessibleLinks[linkId]) {
			$(this).attr({'aria-hidden': 'true', 'tabindex': '-1'}).removeAttr('aria-label');
		} else {
			accessibleLinks[linkId] = true;
			$(this).attr({'aria-label': 'Edit link ' + name, 'tabindex': '0'}).removeAttr('aria-hidden');
		}
	}).off('click').on('click', click_handler);
	$("area[id^='NODE:']").attr({'href': '#', 'aria-hidden': 'true', 'tabindex': '-1'}).off('click').on('click', click_handler);
	$("area[id$='TIMESTAMP'], area[id^='LEGEND:']")
		.attr({'aria-hidden': 'true', 'tabindex': '-1'})
		.removeAttr('href aria-label')
		.off('click');

	$('#tb_newfile').html('Return to<br>Cacti').off('click').on('click', function() {
		window.location = 'weathermap-cacti-plugin-mgmt.php';
	});

	$('#tb_addnode').off('click').on('click', add_node);
	$('#tb_mapprops').off('click').on('click', map_properties);
	$('#tb_mapstyle').off('click').on('click', map_style);

	$('#tb_addlink').off('click').on('click', add_link);
	$('#tb_prefs').off('click').on('click', prefs);

	$('#node_delete').off('click').on('click', delete_node);
	$('#node_clone').off('click').on('click', clone_node);
	$('#node_edit').off('click').on('click', edit_node);

	$('#link_delete').off('click').on('click', delete_link);
	$('#link_edit').off('click').on('click', edit_link);

	$('#link_tidy').off('click').on('click', tidy_link);

	$('.wm_submit').off('click').on('click', form_submit);
	$('.wm_cancel').off('click').on('click', cancel_op);

	$('#xycapture').off('mouseover').mouseover(function(event) {
		coord_capture(event);
	});

	$('#xycapture').off('mousemove').mousemove(function(event) {
		coord_update(event);
	});

	$('#xycapture').off('mouseout').mouseout(function(event) {
		coord_release(event);
	});
}

// used by the cancel button on each of the properties dialogs
function cancel_op() {
	hide_all_dialogs();

	$('#action').val('');
}

function help_handler(event) {
	var objectid = $(this).attr('id');
	var section  = objectid.slice(0, objectid.indexOf('_'));
	var target   = section + '_help';
	var helptext = 'undefined';

	if (helptexts[objectid]) {
		helptext = helptexts[objectid];
	}

	if ((event.type == 'blur') || (event.type == 'mouseout')) {
        helptext = helptexts[section + '_default'];

		if (helptext == 'undefined') {
			alert('OID is: ' + objectid + ' and target is:' + target + ' and section is: ' + section);
		}
	}

	if (helptext != 'undefined') {
		$('#' + target).text(helptext);
	}
}

// Any clicks in the imagemap end up here.
function click_handler(event, target) {
	event.preventDefault();

	if (wmSaveInFlight || wmReloadRequired || Date.now() < wmSuppressClickUntil) {
		return false;
	}

	var alt = $(this).attr('id');

	if (alt == 'undefined') {
		alt = event.target.id;
	}

	click_execute(event, alt);
}

function click_execute(event, alt) {
	var objectname, objecttype, objectid;

	objecttype = alt.slice(0, 4);
	objectname = alt.slice(5, alt.length);
	objectid   = objectname.slice(0,objectname.length-2);

	// if we're not in a mode yet...
	if ($('#action').val() === '') {
		// if we're waiting for a node specifically (e.g. 'make link') then ignore links here
		if (objecttype == 'NODE') {
			// chop off the suffix
			objectname = NodeIDs[objectid];

			show_node(objectname);
		}

		if (objecttype == 'LINK') {
			// chop off the suffix
			objectname = LinkIDs[objectid];

			show_link(objectname);
		}
	} else {
		// we've got a command queued, so do the appropriate thing
		if (objecttype == 'NODE' && $('#action').val() == 'add_link') {
			$('#param').val(NodeIDs[objectid]);
			$('#action').val('add_link2');
			$('#tb_help').text('Click on the second node for the end of the link.');
		} else if (objecttype == 'NODE' && $('#action').val() == 'add_link2') {
			$('#param2').val(NodeIDs[objectid]);
			form_submit();
		} else {
			// Halfway through one operation, the user has done something unexpected.
			// reset back to standard state, and see if we can oblige them
			//		alert('A bit confused');
			$('#action').val('');
			hide_all_dialogs()
		}
	}
}

function show_context_help(itemid, targetid) {
	var helpbox, helpboxtext, message;

	message = "We'd show helptext for " + itemid + " in the'" + targetid + "' div";

	helpbox = $('#'+targetid);
	helpboxtext = helpbox.firstChild;
	helpboxtext.nodeValue = message;
}

function prefs() {
	hide_all_dialogs();

	$('#action').val('editor_settings');

	show_dialog('dlgEditorSettings');
}

function new_file() {
	self.location = '?action=newfile';
}

function mapmode(m) {
	if (m == 'xy') {
		$('#debug').val('xy');
		$('#xycapture').show();
		$('#existingdata').hide();

		setCanvasSize('xycapture');
		$('#wm_node_layer').addClass('is-disabled');
	} else if (m == 'existing') {
		$('#debug').val('existing');
		$('#xycapture').hide();
		$('#existingdata').show();

		setCanvasSize('existingdata');
		$('#wm_node_layer').removeClass('is-disabled');
	}
}

function setCanvasSize(element) {
	var image = $('#'+element)[0];

	imageWidth  = (image && image.naturalWidth ? image.naturalWidth : $('#'+element).attr('data-width'));
	imageHeight = (image && image.naturalHeight ? image.naturalHeight : $('#'+element).attr('data-height'));

	//console.log('Width:'+imageWidth+', Height:'+imageHeight);
}

function wmInitInteractionLayer() {
	var image = $('#existingdata')[0];
	var layer = $('#wm_node_layer');

	if (!image || !layer.length || typeof Nodes == 'undefined' || typeof NodeIDs == 'undefined') {
		return;
	}

	if (!image.complete || !image.naturalWidth) {
		$('#existingdata').off('load.wmLayer').one('load.wmLayer', wmInitInteractionLayer);
		return;
	}

	var width  = image.naturalWidth;
	var height = image.naturalHeight;

	$('#wm_map_stage').css({width: width, height: height});
	$('#wm_link_preview').attr({width: width, height: height, viewBox: '0 0 ' + width + ' ' + height});
	layer.empty();

	var groups = {};
	var order  = [];

	$('area.node').each(function(index) {
		var match = (this.id || '').match(/^NODE:(N\d+):(\d+)$/);

		if (!match || typeof NodeIDs[match[1]] == 'undefined') {
			return;
		}

		var name   = NodeIDs[match[1]];
		var bounds = wmAreaBounds(this);

		if (!bounds || typeof Nodes[name] == 'undefined' || Nodes[name].x == 'null') {
			return;
		}

		if (typeof groups[name] == 'undefined') {
			groups[name] = {
				bounds: bounds,
				areas: [{bounds: bounds, targetId: this.id}],
				id: match[1],
				order: index
			};
			order.push(name);
		} else {
			groups[name].bounds = wmUnionBounds(groups[name].bounds, bounds);
			groups[name].areas.push({bounds: bounds, targetId: this.id});
		}
	});

	order.forEach(function(name, index) {
		var node   = Nodes[name];
		var group  = groups[name];
		var label  = node.label || name;

		if (typeof mapEditable != 'undefined' && mapEditable === false) {
			node.editable = false;
		}

		group.areas.forEach(function(area, partIndex) {
			var bounds = area.bounds;
			var button = partIndex === 0 ? $('<button type="button" class="wm-node-handle"></button>') : $('<span class="wm-node-handle wm-node-handle-part" aria-hidden="true"></span>');

			button.attr({
				'aria-label': partIndex === 0 ? 'Move node ' + label + '. Position ' + Math.round(node.x) + ', ' + Math.round(node.y) + '.' : null,
				'aria-describedby': partIndex === 0 ? 'wm_drag_help' : null,
				'aria-hidden': partIndex === 0 ? null : 'true',
				'data-node-name': name,
				'data-node-id': group.id,
				'data-map-target': area.targetId,
				'tabindex': partIndex === 0 ? '0' : null,
				'title': (node.editable === false ? label + ' cannot be moved from this map' : 'Drag ' + label + ' to move it')
			}).css({
				left: bounds.minX,
				top: bounds.minY,
				width: Math.max(24, bounds.maxX - bounds.minX),
				height: Math.max(24, bounds.maxY - bounds.minY),
				zIndex: order.length - index
			}).data('wmBounds', group.bounds).data('wmPartBounds', bounds);

			if (node.editable === false) {
				if (partIndex === 0) {
					button.addClass('is-locked').attr('aria-disabled', 'true');
				}
			}

			button
				.on('click.wmNode', function(event) {
					event.preventDefault();

					if (wmDragState || wmSaveInFlight || wmReloadRequired || node.editable === false || Date.now() < wmSuppressClickUntil) {
						return;
					}

					if (this.focus) {
						this.focus({preventScroll: true});
					}

					wmDialogReturnFocus = this;
					click_execute(event, 'NODE:' + group.id + ':0');
				})
				.on('contextmenu.wmNode', function(event) {
					event.preventDefault();

					if (wmDragState || wmSaveInFlight || wmReloadRequired || node.editable === false) {
						return;
					}

					var target = document.getElementById($(this).attr('data-map-target'));

					if (target) {
						$(target).trigger($.Event('contextmenu', {
							pageX: event.pageX,
							pageY: event.pageY
						}));
					}
				})
				.on('pointerdown.wmNode', wmHandlePointerDown)
				.on('keydown.wmNode', partIndex === 0 ? wmHandleNodeKeyDown : null);

			layer.append(button);
		});
	});

	wmInitMapElementHandles(layer, width, height);

	$(document).off('keydown.wmDragCancel').on('keydown.wmDragCancel', function(event) {
		if (event.key == 'Escape') {
			if (wmDragState) {
				event.preventDefault();
				wmCancelDrag('Move cancelled.');
			} else if ($('#action').val() !== '' && !$('.ui-dialog:visible').length && !wmSaveInFlight) {
				event.preventDefault();
				$('#action, #param, #param2').val('');
				mapmode('existing');
				$('#tb_help').text('Drag nodes, legends, or timestamps to move them. Click or right-click nodes and links for properties.');
				wmSetSaveStatus('saved', 'Action cancelled.');
			}
		}
	});

	if (wmRestoreFocusNode) {
		var focusNode = wmRestoreFocusNode;
		wmRestoreFocusNode = null;
		setTimeout(function() {
			$('#wm_node_layer .wm-node-handle').filter(function() {
				return $(this).attr('data-node-name') == focusNode && $(this).attr('tabindex') == '0';
			}).first().trigger('focus');
		}, 0);
	}

	if (wmRestoreFocusLink) {
		var focusLink = wmRestoreFocusLink;
		wmRestoreFocusLink = null;
		setTimeout(function() {
			$("area[id^='LINK:'][tabindex='0']").filter(function() {
				var linkId = String(this.id).split(':')[1];

				return typeof LinkIDs != 'undefined' && LinkIDs[linkId] == focusLink;
			}).first().trigger('focus');
		}, 0);
	}

	if (wmRestoreFocusElement) {
		var focusElement = wmRestoreFocusElement;
		wmRestoreFocusElement = null;
		setTimeout(function() {
			$('#wm_node_layer .wm-map-element-handle').filter(function() {
				return $(this).attr('data-element-key') == focusElement;
			}).first().trigger('focus');
		}, 0);
	}

	if (typeof mapEditable != 'undefined' && mapEditable === false) {
		wmSetSaveStatus('saved', 'Read-only map');
	}
}

function wmInitMapElementHandles(layer, width, height) {
	wmMapElements = [];

	$("area[data-wm-type][data-wm-name]").each(function() {
		var bounds = wmAreaBounds(this);

		if (!bounds) {
			return;
		}

		wmMapElements.push({
			id: this.id,
			type: $(this).attr('data-wm-type'),
			name: $(this).attr('data-wm-name'),
			x: Number($(this).attr('data-wm-x')),
			y: Number($(this).attr('data-wm-y')),
			minX: bounds.minX,
			minY: bounds.minY,
			maxX: bounds.maxX,
			maxY: bounds.maxY
		});
	});

	wmMapElements.forEach(function(element, index) {
		var bounds = {
			minX: Number(element.minX),
			minY: Number(element.minY),
			maxX: Number(element.maxX),
			maxY: Number(element.maxY)
		};
		var displayName = element.type == 'legend'
			? (element.name == 'DEFAULT' ? 'legend' : 'legend ' + element.name)
			: (element.name == 'CURRENT' ? 'timestamp' : String(element.name).toLowerCase() + ' timestamp');
		var handle = $('<button type="button" class="wm-node-handle wm-map-element-handle"></button>');

		if (Object.keys(bounds).some(function(key) { return !isFinite(bounds[key]); })) {
			return;
		}

		handle.attr({
			'aria-label': 'Move ' + displayName + '. Position ' + Math.round(element.x) + ', ' + Math.round(element.y) + '.',
			'aria-describedby': 'wm_drag_help',
			'data-element-index': index,
			'data-element-key': element.type + ':' + element.name,
			'data-drag-label': displayName,
			'title': 'Drag ' + displayName + ' to move it',
			'tabindex': '0'
		}).css({
			left: bounds.minX,
			top: bounds.minY,
			width: Math.max(24, bounds.maxX - bounds.minX),
			height: Math.max(24, bounds.maxY - bounds.minY),
			zIndex: 5000 + index
		}).data('wmBounds', bounds).data('wmPartBounds', bounds);

		if (typeof mapEditable != 'undefined' && mapEditable === false) {
			handle.addClass('is-locked').attr({
				'aria-disabled': 'true',
				'title': displayName + ' cannot be moved on this read-only map'
			});
		}

		handle
			.on('click.wmElement', function(event) {
				event.preventDefault();
			})
			.on('contextmenu.wmElement', function(event) {
				event.preventDefault();
			})
			.on('pointerdown.wmElement', wmHandleMapElementPointerDown)
			.on('keydown.wmElement', wmHandleMapElementKeyDown);

		layer.append(handle);
	});
}

function wmAreaBounds(area) {
	var coords = String($(area).attr('coords') || '').split(',').map(function(value) {
		return Number(value);
	});
	var shape = String($(area).attr('shape') || 'rect').toLowerCase();

	if (!coords.length || coords.some(function(value) { return !isFinite(value); })) {
		return null;
	}

	if (shape == 'circle' && coords.length >= 3) {
		var radius = coords.length == 3 ? coords[2] : Math.sqrt(Math.pow(coords[2] - coords[0], 2) + Math.pow(coords[3] - coords[1], 2));
		return {minX: coords[0] - radius, minY: coords[1] - radius, maxX: coords[0] + radius, maxY: coords[1] + radius};
	}

	var xs = [];
	var ys = [];

	for (var i = 0; i < coords.length - 1; i += 2) {
		xs.push(coords[i]);
		ys.push(coords[i + 1]);
	}

	if (!xs.length) {
		return null;
	}

	return {
		minX: Math.min.apply(Math, xs),
		minY: Math.min.apply(Math, ys),
		maxX: Math.max.apply(Math, xs),
		maxY: Math.max.apply(Math, ys)
	};
}

function wmUnionBounds(first, second) {
	return {
		minX: Math.min(first.minX, second.minX),
		minY: Math.min(first.minY, second.minY),
		maxX: Math.max(first.maxX, second.maxX),
		maxY: Math.max(first.maxY, second.maxY)
	};
}

function wmClientToMapPoint(clientX, clientY) {
	var image = $('#existingdata')[0];
	var rect  = image.getBoundingClientRect();
	var width = image.naturalWidth || Number($(image).attr('data-width'));
	var height = image.naturalHeight || Number($(image).attr('data-height'));

	return {
		x: Math.max(0, Math.min(width, (clientX - rect.left) * width / rect.width)),
		y: Math.max(0, Math.min(height, (clientY - rect.top) * height / rect.height)),
		width: width,
		height: height
	};
}

function wmDrawnMapSize() {
	var image = $('#existingdata')[0];

	return {
		width: image.naturalWidth || Number($(image).attr('data-width')),
		height: image.naturalHeight || Number($(image).attr('data-height'))
	};
}

function wmDragCoordinate(value, minimum, maximum) {
	var grid = Number($('#wm_map_stage').attr('data-grid-snap')) || 0;
	var next = Math.round(value);

	if (grid > 0) {
		next = Math.round(next / grid) * grid;
	}

	return Math.max(minimum, Math.min(maximum, next));
}

function wmHandlePointerDown(event) {
	var original = event.originalEvent || event;
	var name     = $(this).attr('data-node-name');

	if (wmDragState || original.button !== 0 || wmSaveInFlight || wmReloadRequired || $('#action').val() !== '' || !Nodes[name] || Nodes[name].editable === false) {
		return;
	}

	event.preventDefault();

	var point = wmClientToMapPoint(original.clientX, original.clientY);

	wmDragState = {
		kind: 'node',
		name: name,
		displayName: name,
		nodeId: $(this).attr('data-node-id'),
		handle: $('#wm_node_layer .wm-node-handle').filter(function() {
			return $(this).attr('data-node-name') == name;
		}),
		bounds: $(this).data('wmBounds'),
		pointerId: original.pointerId,
		startClientX: original.clientX,
		startClientY: original.clientY,
		startMapX: point.x,
		startMapY: point.y,
		originX: Number(Nodes[name].x),
		originY: Number(Nodes[name].y),
		x: Number(Nodes[name].x),
		y: Number(Nodes[name].y),
		minX: 0,
		minY: 0,
		maxX: point.width,
		maxY: point.height,
		width: point.width,
		height: point.height,
		started: false,
		keyboard: false,
		descendants: wmRelativeDescendants(name)
	};

	if (this.setPointerCapture && typeof original.pointerId != 'undefined') {
		try {
			this.setPointerCapture(original.pointerId);
		} catch (error) {
			// Window-level handlers below still keep the drag active.
		}
	}

	$(window)
		.off('.wmPointerDrag')
		.on('pointermove.wmPointerDrag', wmHandlePointerMove)
		.on('pointerup.wmPointerDrag', wmHandlePointerUp)
		.on('pointercancel.wmPointerDrag', function() { wmCancelDrag('Move cancelled.'); });
}

function wmHandleMapElementPointerDown(event) {
	var original = event.originalEvent || event;
	var index    = Number($(this).attr('data-element-index'));
	var element  = wmMapElements[index] || null;

	if (wmDragState || original.button !== 0 || wmSaveInFlight || wmReloadRequired ||
		$('#action').val() !== '' || !element || (typeof mapEditable != 'undefined' && mapEditable === false)) {
		return;
	}

	event.preventDefault();

	var point       = wmClientToMapPoint(original.clientX, original.clientY);
	var bounds      = $(this).data('wmBounds');
	var originX     = Number(element.x);
	var originY     = Number(element.y);
	var displayName = element.type == 'legend'
		? (element.name == 'DEFAULT' ? 'legend' : 'legend ' + element.name)
		: (element.name == 'CURRENT' ? 'timestamp' : String(element.name).toLowerCase() + ' timestamp');
	var offsetLeft   = bounds.minX - originX;
	var offsetTop    = bounds.minY - originY;
	var offsetRight  = bounds.maxX - originX;
	var offsetBottom = bounds.maxY - originY;

	wmDragState = {
		kind: 'element',
		name: displayName,
		displayName: displayName,
		elementType: element.type,
		elementName: element.name,
		elementKey: element.type + ':' + element.name,
		handle: $(this),
		bounds: bounds,
		pointerId: original.pointerId,
		startClientX: original.clientX,
		startClientY: original.clientY,
		startMapX: point.x,
		startMapY: point.y,
		originX: originX,
		originY: originY,
		x: originX,
		y: originY,
		minX: Math.max(0, -offsetLeft),
		minY: Math.max(0, -offsetTop),
		maxX: Math.min(point.width, point.width - offsetRight),
		maxY: Math.min(point.height, point.height - offsetBottom),
		width: point.width,
		height: point.height,
		started: false,
		keyboard: false,
		descendants: []
	};

	if (this.setPointerCapture && typeof original.pointerId != 'undefined') {
		try {
			this.setPointerCapture(original.pointerId);
		} catch (error) {
			// Window-level handlers below still keep the drag active.
		}
	}

	$(window)
		.off('.wmPointerDrag')
		.on('pointermove.wmPointerDrag', wmHandlePointerMove)
		.on('pointerup.wmPointerDrag', wmHandlePointerUp)
		.on('pointercancel.wmPointerDrag', function() { wmCancelDrag('Move cancelled.'); });
}

function wmHandlePointerMove(event) {
	if (!wmDragState) {
		return;
	}

	var original = event.originalEvent || event;

	if (typeof wmDragState.pointerId != 'undefined' && original.pointerId != wmDragState.pointerId) {
		return;
	}

	var distance = Math.sqrt(
		Math.pow(original.clientX - wmDragState.startClientX, 2) +
		Math.pow(original.clientY - wmDragState.startClientY, 2)
	);

	if (!wmDragState.started && distance < 4) {
		return;
	}

	event.preventDefault();

	if (!wmDragState.started) {
		wmBeginDrag();
	}

	var point = wmClientToMapPoint(original.clientX, original.clientY);

	wmDragState.x = wmDragCoordinate(wmDragState.originX + point.x - wmDragState.startMapX, wmDragState.minX, wmDragState.maxX);
	wmDragState.y = wmDragCoordinate(wmDragState.originY + point.y - wmDragState.startMapY, wmDragState.minY, wmDragState.maxY);

	wmRenderDrag();
}

function wmHandlePointerUp(event) {
	if (!wmDragState) {
		return;
	}

	var original = event.originalEvent || event;

	if (typeof wmDragState.pointerId != 'undefined' && original.pointerId != wmDragState.pointerId) {
		return;
	}

	$(window).off('.wmPointerDrag');
	wmSuppressClickUntil = Date.now() + 500;

	if (!wmDragState.started) {
		if (wmDragState.kind == 'element') {
			var elementHandle = wmDragState.handle[0];
			var elementName   = wmDragState.displayName;

			if (elementHandle && elementHandle.focus) {
				elementHandle.focus({preventScroll: true});
			}

			wmDragState = null;
			wmSetSaveStatus('saved', 'Drag ' + elementName + ' to move it.');
			return;
		}

		var nodeId = wmDragState.nodeId;
		var opener = wmDragState.handle.filter('[tabindex="0"]').first()[0] || wmDragState.handle.first()[0];

		if (opener && opener.focus) {
			opener.focus({preventScroll: true});
			wmDialogReturnFocus = opener;
		}

		wmDragState = null;
		click_execute(event, 'NODE:' + nodeId + ':0');
		return;
	}

	wmFinishDrag();
}

function wmHandleNodeKeyDown(event) {
	var name = $(this).attr('data-node-name');
	var key  = event.key;

	if (!Nodes[name]) {
		return;
	}

	if (!wmDragState || !wmDragState.keyboard || wmDragState.name != name) {
		if ((key == ' ' || key == 'Spacebar') && !wmDragState && !wmSaveInFlight && Nodes[name].editable !== false && $('#action').val() === '') {
			event.preventDefault();
			wmStartKeyboardDrag($(this), name);
		}

		return;
	}

	if (key == 'Escape') {
		event.preventDefault();
		wmCancelDrag('Move cancelled.');
		return;
	}

	if (key == 'Tab') {
		wmCancelDrag('Move cancelled when focus left the node.');
		return;
	}

	if (key == ' ' || key == 'Spacebar' || key == 'Enter') {
		event.preventDefault();
		wmFinishDrag();
		return;
	}

	var dx   = 0;
	var dy   = 0;
	var grid = Number($('#wm_map_stage').attr('data-grid-snap')) || 1;
	var step = grid * (event.shiftKey ? 10 : 1);

	if (key == 'ArrowLeft')  { dx = -step; }
	if (key == 'ArrowRight') { dx = step; }
	if (key == 'ArrowUp')    { dy = -step; }
	if (key == 'ArrowDown')  { dy = step; }

	if (dx || dy) {
		event.preventDefault();
		wmDragState.x = wmDragCoordinate(wmDragState.x + dx, wmDragState.minX, wmDragState.maxX);
		wmDragState.y = wmDragCoordinate(wmDragState.y + dy, wmDragState.minY, wmDragState.maxY);
		wmRenderDrag();
		wmSetSaveStatus('editing', 'Position ' + wmDragState.x + ', ' + wmDragState.y + '. Press Space or Enter to save.');
	}
}

function wmHandleMapElementKeyDown(event) {
	var index   = Number($(this).attr('data-element-index'));
	var element = wmMapElements[index] || null;
	var key     = event.key;

	if (!element || (typeof mapEditable != 'undefined' && mapEditable === false)) {
		return;
	}

	if (!wmDragState || !wmDragState.keyboard || wmDragState.elementKey != element.type + ':' + element.name) {
		if ((key == ' ' || key == 'Spacebar') && !wmDragState && !wmSaveInFlight && !wmReloadRequired && $('#action').val() === '') {
			event.preventDefault();
			wmStartKeyboardMapElementDrag($(this), element);
		}

		return;
	}

	if (key == 'Escape') {
		event.preventDefault();
		wmCancelDrag('Move cancelled.');
		return;
	}

	if (key == 'Tab') {
		wmCancelDrag('Move cancelled when focus left the map element.');
		return;
	}

	if (key == ' ' || key == 'Spacebar' || key == 'Enter') {
		event.preventDefault();
		wmFinishDrag();
		return;
	}

	var dx   = 0;
	var dy   = 0;
	var grid = Number($('#wm_map_stage').attr('data-grid-snap')) || 1;
	var step = grid * (event.shiftKey ? 10 : 1);

	if (key == 'ArrowLeft')  { dx = -step; }
	if (key == 'ArrowRight') { dx = step; }
	if (key == 'ArrowUp')    { dy = -step; }
	if (key == 'ArrowDown')  { dy = step; }

	if (dx || dy) {
		event.preventDefault();
		wmDragState.x = wmDragCoordinate(wmDragState.x + dx, wmDragState.minX, wmDragState.maxX);
		wmDragState.y = wmDragCoordinate(wmDragState.y + dy, wmDragState.minY, wmDragState.maxY);
		wmRenderDrag();
		wmSetSaveStatus('editing', 'Position ' + wmDragState.x + ', ' + wmDragState.y + '. Press Space or Enter to save.');
	}
}

function wmStartKeyboardDrag(handle, name) {
	var size = wmDrawnMapSize();

	wmDragState = {
		kind: 'node',
		name: name,
		displayName: name,
		nodeId: handle.attr('data-node-id'),
		handle: $('#wm_node_layer .wm-node-handle').filter(function() {
			return $(this).attr('data-node-name') == name;
		}),
		bounds: handle.data('wmBounds'),
		originX: Number(Nodes[name].x),
		originY: Number(Nodes[name].y),
		x: Number(Nodes[name].x),
		y: Number(Nodes[name].y),
		width: size.width,
		height: size.height,
		minX: 0,
		minY: 0,
		maxX: size.width,
		maxY: size.height,
		started: true,
		keyboard: true,
		descendants: wmRelativeDescendants(name)
	};

	wmBeginDrag();
	wmRenderDrag();
	wmSetSaveStatus('editing', 'Picked up ' + name + '. Use the arrow keys to move it.');
}

function wmStartKeyboardMapElementDrag(handle, element) {
	var image       = $('#existingdata')[0];
	var bounds      = handle.data('wmBounds');
	var width       = image.naturalWidth || Number($(image).attr('data-width'));
	var height      = image.naturalHeight || Number($(image).attr('data-height'));
	var originX     = Number(element.x);
	var originY     = Number(element.y);
	var displayName = element.type == 'legend'
		? (element.name == 'DEFAULT' ? 'legend' : 'legend ' + element.name)
		: (element.name == 'CURRENT' ? 'timestamp' : String(element.name).toLowerCase() + ' timestamp');

	wmDragState = {
		kind: 'element',
		name: displayName,
		displayName: displayName,
		elementType: element.type,
		elementName: element.name,
		elementKey: element.type + ':' + element.name,
		handle: handle,
		bounds: bounds,
		originX: originX,
		originY: originY,
		x: originX,
		y: originY,
		width: width,
		height: height,
		minX: Math.max(0, -(bounds.minX - originX)),
		minY: Math.max(0, -(bounds.minY - originY)),
		maxX: Math.min(width, width - (bounds.maxX - originX)),
		maxY: Math.min(height, height - (bounds.maxY - originY)),
		started: true,
		keyboard: true,
		descendants: []
	};

	wmBeginDrag();
	wmRenderDrag();
	wmSetSaveStatus('editing', 'Picked up ' + displayName + '. Use the arrow keys to move it.');
}

function wmRelativeDescendants(name) {
	var descendants = [];
	var pending     = [name];

	while (pending.length) {
		var parent = pending.shift();

		Object.keys(Nodes).forEach(function(candidate) {
			if (candidate != name && descendants.indexOf(candidate) == -1 && Nodes[candidate].relative_to == parent) {
				descendants.push(candidate);
				pending.push(candidate);
			}
		});
	}

	return descendants;
}

function wmBeginDrag() {
	if (!wmDragState) {
		return;
	}

	wmDragState.started = true;
	$('#toolbar .tb_active').prop('disabled', true);

	var image  = $('#existingdata');
	var bounds = wmDragState.bounds;

	wmDragState.handle.addClass('is-dragging').each(function() {
		var partBounds = $(this).data('wmPartBounds') || bounds;

		$(this).css({
			backgroundImage: 'url("' + image.attr('src') + '")',
			backgroundPosition: '-' + partBounds.minX + 'px -' + partBounds.minY + 'px',
			backgroundSize: image[0].naturalWidth + 'px ' + image[0].naturalHeight + 'px'
		});
	});

	$('<div class="wm-drag-origin" aria-hidden="true"></div>').css({
		left: bounds.minX,
		top: bounds.minY,
		width: Math.max(18, bounds.maxX - bounds.minX),
		height: Math.max(18, bounds.maxY - bounds.minY)
	}).appendTo('#wm_map_stage');

	$('<div class="wm-drag-coordinate" aria-hidden="true"></div>').appendTo('#wm_map_stage');
	wmSetSaveStatus('editing', 'Moving ' + wmDragState.displayName + '...');
}

function wmRenderDrag() {
	if (!wmDragState) {
		return;
	}

	var dx = wmDragState.x - wmDragState.originX;
	var dy = wmDragState.y - wmDragState.originY;

	wmDragState.handle.css('transform', 'translate(' + dx + 'px, ' + dy + 'px)');

	if (wmDragState.kind == 'node') {
		wmDragState.descendants.forEach(function(name) {
			$('#wm_node_layer .wm-node-handle').filter(function() {
				return $(this).attr('data-node-name') == name;
			}).addClass('is-dependent').css('transform', 'translate(' + dx + 'px, ' + dy + 'px)');
		});
	}

	$('.wm-drag-coordinate').text(wmDragState.x + ', ' + wmDragState.y).css({
		left: Math.min(wmDragState.width - 90, Math.max(8, wmDragState.x + 14)),
		top: Math.min(wmDragState.height - 34, Math.max(8, wmDragState.y + 14))
	});

	if (wmDragState.kind == 'node') {
		wmRenderLinkPreview(wmDragState.name, wmDragState.x, wmDragState.y, wmDragState.descendants);
	} else {
		$('#wm_link_preview').empty();
	}
	$('#tb_coords').html(txtPosition + '<br />' + wmDragState.x + ', ' + wmDragState.y);

	if (wmDragState.keyboard) {
		wmKeepKeyboardDragVisible();
	}
}

function wmKeepKeyboardDragVisible() {
	var viewport = $('#wm_map_scroll')[0];

	if (!viewport || !wmDragState) {
		return;
	}

	var padding = 32;
	var left    = wmDragState.x - viewport.scrollLeft;
	var top     = wmDragState.y - viewport.scrollTop;

	if (left < padding) {
		viewport.scrollLeft = Math.max(0, wmDragState.x - padding);
	} else if (left > viewport.clientWidth - padding) {
		viewport.scrollLeft = Math.max(0, wmDragState.x - viewport.clientWidth + padding);
	}

	if (top < padding) {
		viewport.scrollTop = Math.max(0, wmDragState.y - padding);
	} else if (top > viewport.clientHeight - padding) {
		viewport.scrollTop = Math.max(0, wmDragState.y - viewport.clientHeight + padding);
	}
}

function wmRenderLinkPreview(nodeName, x, y, descendants) {
	var svg = $('#wm_link_preview').empty()[0];
	var dx  = x - Number(Nodes[nodeName].x);
	var dy  = y - Number(Nodes[nodeName].y);

	Object.keys(Links).forEach(function(linkName) {
		var link = Links[linkName];

		if (!link.a || !link.b) {
			return;
		}

		var involved = link.a == nodeName || link.b == nodeName || descendants.indexOf(link.a) != -1 || descendants.indexOf(link.b) != -1;

		if (!involved || !Nodes[link.a] || !Nodes[link.b]) {
			return;
		}

		var ax = Number(Nodes[link.a].x);
		var ay = Number(Nodes[link.a].y);
		var bx = Number(Nodes[link.b].x);
		var by = Number(Nodes[link.b].y);

		if (link.a == nodeName) { ax = x; ay = y; }
		if (link.b == nodeName) { bx = x; by = y; }
		if (descendants.indexOf(link.a) != -1) { ax += dx; ay += dy; }
		if (descendants.indexOf(link.b) != -1) { bx += dx; by += dy; }

		var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
		line.setAttribute('x1', ax);
		line.setAttribute('y1', ay);
		line.setAttribute('x2', bx);
		line.setAttribute('y2', by);
		line.setAttribute('class', 'wm-preview-link');
		svg.appendChild(line);
	});
}

function wmFinishDrag() {
	if (!wmDragState) {
		return;
	}

	$(window).off('.wmPointerDrag');

	var move = {
		kind: wmDragState.kind,
		name: wmDragState.name,
		displayName: wmDragState.displayName,
		elementType: wmDragState.elementType,
		elementName: wmDragState.elementName,
		elementKey: wmDragState.elementKey,
		fromX: wmDragState.originX,
		fromY: wmDragState.originY,
		x: wmDragState.x,
		y: wmDragState.y,
		handle: wmDragState.handle,
		descendants: wmDragState.descendants
	};

	wmDragState = null;

	if (move.x == move.fromX && move.y == move.fromY) {
		wmCleanupMoveVisuals(move);
		wmSetSaveStatus('saved', 'Position unchanged.');
		return;
	}

	if (move.kind == 'element') {
		wmSaveMapElementPosition(move);
	} else {
		wmSaveNodePosition(move, false);
	}
}

function wmCancelDrag(message) {
	if (!wmDragState) {
		return;
	}

	$(window).off('.wmPointerDrag');

	var move = {
		handle: wmDragState.handle,
		descendants: wmDragState.descendants
	};

	wmDragState = null;
	wmCleanupMoveVisuals(move);
	wmSetSaveStatus('saved', message || 'Move cancelled.');
}

function wmCleanupMoveVisuals(move) {
	if (move && move.handle) {
		move.handle.removeClass('is-dragging').css({
			transform: '',
			backgroundImage: '',
			backgroundPosition: '',
			backgroundSize: ''
		});
	}

	$('#wm_node_layer .wm-node-handle').removeClass('is-dependent').css('transform', '');
	$('.wm-drag-origin, .wm-drag-coordinate').remove();
	$('#wm_link_preview').empty();
	$('#tb_coords').html(txtPosition + '<br />---, ---');

	if (!wmSaveInFlight) {
		$('#toolbar .tb_active').prop('disabled', false);
	}
}

function wmSaveNodePosition(move, isUndo) {
	if (wmSaveInFlight) {
		return;
	}

	wmSaveInFlight = true;
	wmSetMutationBusy(true);
	wmFailedMove   = null;
	$('#wm_retry_save').prop('hidden', true);
	$('#wm_undo_move').prop('hidden', true);
	wmSetSaveStatus('saving', 'Saving ' + move.name + '...');

	var request = {
		action: 'save_node_position',
		mapname: $('#mapname').val(),
		node_name: move.name,
		x: move.x,
		y: move.y,
		revision: (typeof mapRevision == 'undefined' ? '' : mapRevision)
	};

	if (typeof csrfMagicName != 'undefined' && typeof csrfMagicToken != 'undefined') {
		request[csrfMagicName] = csrfMagicToken;
	}

	$.ajax({
		type: 'POST',
		url: editor_url,
		data: request,
		dataType: 'json'
	}).done(function(response) {
		if (!response || response.ok !== true) {
			wmHandleMoveSaveError(move, response && response.message ? response.message : 'The node position could not be saved.', false);
			return;
		}

		mapRevision       = response.revision;
		wmRestoreFocusNode = move.name;

		wmRefreshEditorMap().done(function() {
			wmSaveInFlight = false;
			wmSetMutationBusy(false);
			wmCleanupMoveVisuals(move);

			if (isUndo) {
				wmLastSavedMove = null;
				$('#wm_undo_move').prop('hidden', true);
				wmSetSaveStatus('saved', 'Move undone.');
			} else {
				var canUndoExactly = response.undoable === true && Nodes[move.name] && Nodes[move.name].polar !== true &&
					Number.isInteger(move.fromX) && Number.isInteger(move.fromY) &&
					Number.isInteger(Number(response.x)) && Number.isInteger(Number(response.y));

				if (canUndoExactly) {
					wmLastSavedMove = {
						name: move.name,
						fromX: move.fromX,
						fromY: move.fromY,
						x: Number(response.x),
						y: Number(response.y),
						revision: response.revision
					};
					$('#wm_undo_move').prop('hidden', false);
				} else {
					wmLastSavedMove = null;
					$('#wm_undo_move').prop('hidden', true);
				}

				wmSetSaveStatus('saved', response.message || 'All changes saved.');
			}
		}).fail(function() {
			wmCleanupMoveVisuals(move);
			wmRequireReload('The position was saved, but the map preview could not be refreshed. Reload the editor.');
		});
	}).fail(function(xhr) {
		var status  = xhr && typeof xhr.status != 'undefined' ? xhr.status : 0;
		var message = wmAjaxErrorMessage(xhr, 'The node position could not be saved.');

		if (wmIsSessionExpiredResponse(xhr)) {
			wmFailedMove = null;
			wmCleanupMoveVisuals(move);
			wmRequireReload(message);
		} else if (status === 409) {
			wmReconcileRejectedMove(move, message);
		} else if (status === 0 || (status >= 500 && status !== 503)) {
			wmReconcileUncertainMove(move, message);
		} else {
			wmHandleMoveSaveError(move, message, status === 429 || status === 503);
		}
	});
}

function wmSaveMapElementPosition(move) {
	if (wmSaveInFlight) {
		return;
	}

	wmInvalidateMoveHistory();
	wmSaveInFlight = true;
	wmSetMutationBusy(true);
	wmFailedMove = null;
	wmSetSaveStatus('saving', 'Saving ' + move.displayName + '...');

	var request = {
		action: 'save_map_element_position',
		mapname: $('#mapname').val(),
		element_type: move.elementType,
		element_name: move.elementName,
		x: move.x,
		y: move.y,
		revision: (typeof mapRevision == 'undefined' ? '' : mapRevision)
	};

	if (typeof csrfMagicName != 'undefined' && typeof csrfMagicToken != 'undefined') {
		request[csrfMagicName] = csrfMagicToken;
	}

	$.ajax({
		type: 'POST',
		url: editor_url,
		data: request,
		dataType: 'json'
	}).done(function(response) {
		if (!response || response.ok !== true) {
			wmHandleMoveSaveError(move, response && response.message ? response.message : 'The map element position could not be saved.', false);
			return;
		}

		mapRevision = response.revision;
		wmRestoreFocusElement = move.elementKey;

		wmRefreshEditorMap().done(function() {
			wmSaveInFlight = false;
			wmSetMutationBusy(false);
			wmCleanupMoveVisuals(move);
			wmSetSaveStatus('saved', response.message || 'All changes saved.');
		}).fail(function() {
			wmCleanupMoveVisuals(move);
			wmRequireReload('The position was saved, but the map preview could not be refreshed. Reload the editor.');
		});
	}).fail(function(xhr) {
		var status  = xhr && typeof xhr.status != 'undefined' ? xhr.status : 0;
		var message = wmAjaxErrorMessage(xhr, 'The map element position could not be saved.');

		if (wmIsSessionExpiredResponse(xhr)) {
			wmFailedMove = null;
			wmCleanupMoveVisuals(move);
			wmRequireReload(message);
		} else if (status === 409) {
			wmReconcileRejectedMove(move, message);
		} else if (status === 0 || (status >= 500 && status !== 503)) {
			wmReconcileUncertainMove(move, message);
		} else {
			wmHandleMoveSaveError(move, message, status === 429 || status === 503);
		}
	});
}

function wmHandleMoveSaveError(move, message, retryable) {
	wmSaveInFlight = false;
	wmSetMutationBusy(false);
	wmFailedMove   = retryable === false ? null : move;
	wmCleanupMoveVisuals(move);
	$('#wm_retry_save').prop('hidden', retryable === false);
	wmSetSaveStatus('error', message + ' No change was saved.');
}

function wmReconcileUncertainMove(move, message) {
	wmFailedMove = null;
	$('#wm_retry_save').prop('hidden', true);

	wmRefreshEditorMap().done(function() {
		wmSaveInFlight = false;
		wmSetMutationBusy(false);
		wmCleanupMoveVisuals(move);
		wmSetSaveStatus('error', message + ' The current map was reloaded to confirm its saved state.');
	}).fail(function() {
		wmCleanupMoveVisuals(move);
		wmRequireReload('The save result could not be confirmed. Reload the editor before making another change.');
	});
}

function wmReconcileRejectedMove(move, message) {
	wmFailedMove = null;
	wmInvalidateMoveHistory();

	if (move.kind == 'element') {
		wmRestoreFocusElement = move.elementKey;
	} else {
		wmRestoreFocusNode = move.name;
	}

	wmRefreshEditorMap().done(function() {
		wmSaveInFlight = false;
		wmSetMutationBusy(false);
		wmCleanupMoveVisuals(move);
		wmSetSaveStatus('error', message + ' The latest map has been loaded.');
	}).fail(function() {
		wmCleanupMoveVisuals(move);
		wmRequireReload(message + ' Reload the editor before making another change.');
	});
}

function wmUndoLastMove() {
	if (!wmLastSavedMove || wmSaveInFlight) {
		return;
	}

	if (wmLastSavedMove.revision && typeof mapRevision != 'undefined' && wmLastSavedMove.revision != mapRevision) {
		wmInvalidateMoveHistory();
		wmSetSaveStatus('error', 'The map changed after that move, so it can no longer be undone safely.');
		return;
	}

	var previous = wmLastSavedMove;

	wmLastSavedMove = null;
	wmSaveNodePosition({
		name: previous.name,
		fromX: previous.x,
		fromY: previous.y,
		x: previous.fromX,
		y: previous.fromY,
		handle: $('#wm_node_layer .wm-node-handle').filter(function() {
			return $(this).attr('data-node-name') == previous.name;
		}),
		descendants: wmRelativeDescendants(previous.name)
	}, true);
}

function wmRetryLastMove() {
	if (!wmFailedMove || wmSaveInFlight) {
		return;
	}

	var move = wmFailedMove;

	wmFailedMove = null;

	if (move.kind == 'element') {
		wmSaveMapElementPosition(move);
	} else {
		wmSaveNodePosition(move, false);
	}
}

function wmRefreshEditorMap() {
	var deferred = $.Deferred();
	var mapname  = $('#mapname').val();

	$('#wm_node_layer').addClass('is-disabled');

	$.ajax({
		url: editor_url,
		data: {mapname: mapname, action: 'load_editor_state'},
		dataType: 'json',
		cache: false
	}).done(function(response) {
		if (!response || response.ok !== true || !response.image || !response.areas || !response.script) {
			$('#wm_node_layer').removeClass('is-disabled');
			deferred.reject();
			return;
		}

		var nextImage = new Image();

		nextImage.onload = function() {
			try {
				$.globalEval(response.script);
			} catch (error) {
				$('#wm_node_layer').removeClass('is-disabled');
				deferred.reject(error);
				return;
			}

			$('.mapData').empty().html(response.areas);
			$('#existingdata').attr('src', response.image);
			$('#xycapture').attr('src', response.image);
			$('#map_revision').val(response.revision);
			$('#action, #param, #param2').val('');
			attach_click_events();
			initContextMenu();
			mapmode('existing');
			wmInitInteractionLayer();
			deferred.resolve();
		};
		nextImage.onerror = function() {
			$('#wm_node_layer').removeClass('is-disabled');
			deferred.reject();
		};
		nextImage.src = response.image;
	}).fail(function() {
		$('#wm_node_layer').removeClass('is-disabled');
		deferred.reject();
	});

	return deferred.promise();
}

function wmSetSaveStatus(state, message) {
	$('#wm_save_status').attr('data-state', state);
	$('#wm_save_status_text').text(message);
}

function wmAjaxErrorMessage(xhr, fallback) {
	if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
		return xhr.responseJSON.message;
	}

	if (wmIsSessionExpiredResponse(xhr)) {
		return 'Your editor session expired. Reload the page before saving again.';
	}

	return fallback;
}

function wmIsSessionExpiredResponse(xhr) {
	return !!(xhr && (xhr.status == 401 || xhr.status == 403 ||
		(xhr.status == 200 && /<(?:!doctype|html|form)\b/i.test(xhr.responseText || ''))));
}

function wmInvalidateMoveHistory() {
	wmLastSavedMove = null;
	wmFailedMove    = null;
	$('#wm_retry_save, #wm_undo_move').prop('hidden', true);
}

function wmSetMutationBusy(busy) {
	$('#toolbar .tb_active').prop('disabled', busy);
	$('.wm_submit').prop('disabled', busy);
	$('#wm_node_layer').toggleClass('is-busy', busy);
	$('.mapData').attr('aria-busy', busy ? 'true' : 'false');
}

function wmRequireReload(message) {
	wmReloadRequired = true;
	wmSaveInFlight   = true;
	wmSetMutationBusy(true);
	wmSetSaveStatus('error', message);
}

function add_node() {
	$('#tb_help').text(addNodeHelp);
	$('#action').val('add_node');

	mapmode('xy');
}

function delete_node() {
	if ($('.dlgConfirm').length == 0) {
		$('body').append('<div class="dlgConfirm"></div>');
	}

	$('.dlgConfirm').text(delNodeWarning);

	$('.dlgConfirm').dialog({
		resizable: false,
		title: delNodeTitle,
		height: 'auto',
		width: 400,
		modal: true,
		buttons: [
			{
				text: txtCancel,
				click: function() {
					$(this).dialog('close');
				}
			},
			{
				text: txtDelNode,
				click: function() {
					$(this).dialog('close');
					hide_all_dialogs();
					$('#action').val('delete_node');
					form_submit();
				}
			}
		]
	});
}

function clone_node() {
	$('#action').val('clone_node');

	form_submit();
}

function edit_node() {
	$('#action').val('edit_node');

	show_itemtext('node', $('#node_name').val());
}

function edit_link() {
	$('#action').val('edit_link');

	show_itemtext('link', $('#link_name').val());
}

function add_link() {
	$('#tb_help').text(addLinkHelp);
	$('#action').val('add_link');

	mapmode('existing');
}

function delete_link() {
	if ($('.dlgConfirm').length == 0) {
		$('body').append('<div class="dlgConfirm"></div>');
	}

	$('.dlgConfirm').text(delLinkWarning);

	$('.dlgConfirm').dialog({
		resizable: false,
		title: delLinkTitle,
		height: 'auto',
		width: 400,
		modal: true,
		buttons: [
			{
				text: txtCancel,
				click: function() {
					$(this).dialog('close');
				}
			},
			{
				text: txtDelLink,
				click: function() {
					$(this).dialog('close');
					hide_all_dialogs();
					$('#action').val('delete_link');
					form_submit();
				}
			}
		]
	});
}

function form_submit() {
	if (wmSaveInFlight || wmDragState) {
		return;
	}

	var data         = $('input, select, textarea').serialize();
	var currentAction = $('#action').val();
	var focusNode    = currentAction == 'set_node_properties' || currentAction == 'set_node_config' ? $('#node_name').val() : null;
	var focusLink    = currentAction == 'set_link_properties' || currentAction == 'set_link_config' ? $('#link_name').val() : null;

	wmInvalidateMoveHistory();
	wmSaveInFlight = true;
	wmSetMutationBusy(true);
	wmSetSaveStatus('saving', 'Saving changes...');

	$.ajax({
		type: 'POST',
		url: editor_url,
		data: data,
		dataType: 'json',
			success: function(response) {
			if (!response || response.ok !== true) {
				wmSaveInFlight = false;
				wmSetMutationBusy(false);
				wmSetSaveStatus('error', response && response.message ? response.message : 'The changes could not be saved.');
				return;
			}

				mapRevision       = response.revision;
				wmRestoreFocusNode = focusNode;
				wmRestoreFocusLink = focusLink;
			hide_all_dialogs();

				wmRefreshEditorMap().done(function() {
					wmSaveInFlight = false;
					wmSetMutationBusy(false);
					$('#action').val('');
					wmSetSaveStatus('saved', response.message || (response.changed === false ? 'No map changes were needed' : 'All changes saved'));
			}).fail(function() {
				wmRequireReload('The map saved, but the editor preview could not be refreshed. Reload the editor.');
			});
		},
		error: function(xhr) {
			var message = wmAjaxErrorMessage(xhr, 'The changes could not be saved.');

			if (wmIsSessionExpiredResponse(xhr)) {
				hide_all_dialogs();
				wmRequireReload(message);
			} else if (xhr && xhr.status == 409) {
				hide_all_dialogs();
				wmRefreshEditorMap().done(function() {
					wmSaveInFlight = false;
					wmSetMutationBusy(false);
					wmSetSaveStatus('error', message + ' The latest map has been loaded.');
				}).fail(function() {
					wmRequireReload(message + ' Reload the editor before making another change.');
				});
			} else if (!xhr || xhr.status === 0 || xhr.status >= 500) {
				wmRefreshEditorMap().done(function() {
					wmSaveInFlight = false;
					wmSetMutationBusy(false);
					wmSetSaveStatus('error', message + ' The current map was reloaded to confirm its saved state.');
				}).fail(function() {
					wmRequireReload('The save result could not be confirmed. Reload the editor before making another change.');
				});
			} else {
				wmSaveInFlight = false;
				wmSetMutationBusy(false);
				wmSetSaveStatus('error', message);
			}
		}
	});
}

function map_properties() {
	mapmode('existing');

	hide_all_dialogs();

	$('#action').val('set_map_properties');

	show_dialog('dlgMapProperties');

	$('#map_title').focus();
}

function map_style() {
	mapmode('existing');

	hide_all_dialogs();

	$('#action').val('set_map_style');

	show_dialog('dlgMapStyle');

	$('#mapstyle_linklabels').focus();
}

function show_itemtext(itemtype,name) {
	mapmode('existing');

	hide_all_dialogs();

	$('textarea#item_configtext').val('');

	if (itemtype === 'node') {
		$('#action').val('set_node_config');
	}

	if (itemtype === 'link') {
		$('#action').val('set_link_config');
	}

	show_dialog('dlgTextEdit');

	$.ajax({
		type: 'GET',
		url: editor_url,
		data: {
			action: 'fetch_config',
			item_type: itemtype,
			item_name: name,
			mapname: $('#mapname').val()
		},
		success: function(text) {
			$('#item_configtext').val(text);
			$('#item_configtext').focus();
		}
	});
}

function prime_node_form(name) {
	var mynode = Nodes[name];

	if (mynode) {
		$('#node_name').val(name);
		$('#node_new_name').val(name);

		$('#node_position').text(Math.round(mynode.x) + ', ' + Math.round(mynode.y));

		$('#node_name').val(mynode.name);
		$('#node_new_name').val(mynode.name);
		$('#node_label').val(mynode.label);
		$('#node_infourl').val(mynode.infourl);
		$('#node_hover').val(mynode.overliburl);

		if (mynode.iconfile != '') {
			//console.log(mynode.iconfile.substring(0,2));
			//console.log(mynode.iconfile);
			if (mynode.iconfile.substring(0, 2) == '::') {
				$('#node_iconfilename').val('--AICON--');
				selectedNode = '--AICON--';
			} else {
				$('#node_iconfilename').val(mynode.iconfile);
				selectedNode = mynode.iconfile;
			}
		} else {
			$('#node_iconfilename').val('--NONE--');
			selectedNode = '--NONE--';
		}

		// Save the selected node for delete and clone actions.
		$('#param').val(mynode.name);

		return true;
	}

	return false;
}

function show_node(name) {
	mapmode('existing');

	hide_all_dialogs();

	var success = prime_node_form(name);

	if (success) {
		$('#action').val('set_node_properties');

		if ($('#node_iconfilename.dd-container').length) {
			$('#node_iconfilename').ddslick('destroy');
		}

		$('#node_iconfilename').val(selectedNode).ddslick({
			height:240,
			defaultSelectedIndex:selectedNode
		});
		$('#node_iconfilename').closest('td').find('.dd-selected').attr('aria-label', 'Icon Filename');

		$('.dd-container').on('click', function() {
			$('.ui-dialog').css('z-index', '100');
			$('.dd-options, .dd-container').css('z-index', '500');
		});

		show_dialog('dlgNodeProperties');

		$('#node_new_name').focus();
	} else {
		console.log('Unable to find node');
	}
}

function prime_link_form(name) {
	var mylink = Links[name];

	if (mylink) {
		$('#link_name').val(mylink.name);
		$('#link_target').val(mylink.target);
		$('#link_width').val(mylink.width);

		$('#link_bandwidth_in').val(mylink.bw_in);

		if (mylink.bw_in == mylink.bw_out) {
			$('#link_bandwidth_out').val('');
			$('#link_bandwidth_out_cb').prop('checked', true);
		} else {
			$('#link_bandwidth_out_cb').prop('checked', false);
			$('#link_bandwidth_out').val(mylink.bw_out);
		}

		$('#link_infourl').val(mylink.infourl);
		$('#link_hover').val(mylink.overliburl);
		$('#viastyle').val(mylink.viastyle);

		$('#link_commentin').val(mylink.commentin);
		$('#link_commentout').val(mylink.commentout);
		$('#link_commentposin').val(mylink.commentposin);
		$('#link_commentposout').val(mylink.commentposout);

		// if that didn't 'stick', then we need to add the special value
		if ($('#link_commentposout').val() != mylink.commentposout) {
			$('#link_commentposout').prepend($('<option>', { selected: true, value: mylink.commentposout, text: mylink.commentposout + '%' }));
		}

		if ($('#link_commentposin').val() != mylink.commentposin) {
			$('#link_commentposin').prepend($('<option>', { selected: true, value: mylink.commentposin, text: mylink.commentposin + '%' }));
		}

		document.getElementById('link_nodename1').firstChild.nodeValue  = mylink.a;
		document.getElementById('link_nodename1a').firstChild.nodeValue = mylink.a;
		document.getElementById('link_nodename1b').firstChild.nodeValue = mylink.a;
		document.getElementById('link_nodename2').firstChild.nodeValue  = mylink.b;

		$('#param').val(mylink.name);

		return true;
	}

	return false;
}

function show_link(name) {
	mapmode('existing');

	hide_all_dialogs();

	if (prime_link_form(name)) {
		$('#action').val('set_link_properties');

		show_dialog('dlgLinkProperties');

		$('#link_bandwidth_in').focus();
	}
}

function show_dialog(dlg) {
	var dialogWidth = Math.min(600, Math.max(280, $(window).width() - 24));

	if (!wmDialogReturnFocus || !document.documentElement.contains(wmDialogReturnFocus)) {
		wmDialogReturnFocus = document.activeElement;
	}

	if (dlg == 'dlgMapProperties') {
		var selectedNode = $('#map_bgfile').val();

		if ($('#map_bgfile.dd-container').length) {
			$('#map_bgfile').ddslick('destroy');
		}

		$('#map_bgfile').ddslick({
			height:240,
			defaultSelectedIndex:selectedNode
		});
		$('#map_bgfile').closest('td').find('.dd-selected').attr('aria-label', 'Background Image Filename');

		$('.dd-container').on('click', function() {
			$('.ui-dialog').css('z-index', '100');
			$('.dd-options, .dd-container').css('z-index', '500');
		});
	}

	$('#'+dlg).dialog({
		autoOpen: true,
		width: dialogWidth,
		height: 'auto',
		maxHeight: Math.max(320, $(window).height() - 24),
		modal: false,
		resizable: false,
		draggable: true,
			open: function() {
				$('select').not('#node_iconfilename, #map_bgfile').selectmenu({
				open: function() {
					$('.ui-dialog').css('z-index', '20');
					setTimeout(wmApplyDialogLabels, 0);
					}
				});
				wmApplyDialogLabels();
			},
			close: function() {
				$('#action').val('');
				mapmode('existing');

				if (wmDialogReturnFocus && document.documentElement.contains(wmDialogReturnFocus)) {
					$(wmDialogReturnFocus).trigger('focus');
				}

				wmDialogReturnFocus = null;
			}
		});

	$(window).off('resize.wmDialog').on('resize.wmDialog', function() {
		$('.dlgProperties').each(function() {
			if ($(this).dialog('instance') && $(this).dialog('isOpen')) {
				$(this).dialog('option', {
					width: Math.min(600, Math.max(280, $(window).width() - 24)),
					maxHeight: Math.max(320, $(window).height() - 24),
					position: {my: 'center', at: 'center', of: window}
				});
			}
		});
	});
}

function hide_dialog(dlg) {
	if ($('#'+dlg).dialog('instance')) {
		$('#'+dlg).dialog('close');
	}

	$('#action').val('');
}

function hide_all_dialogs() {
	hide_dialog('dlgMapProperties');
	hide_dialog('dlgMapStyle');
	hide_dialog('dlgLinkProperties');
	hide_dialog('dlgTextEdit');
	hide_dialog('dlgNodeProperties');
	hide_dialog('dlgEditorSettings');
}

function coord_capture(event) {
	// $('#tb_coords').html('+++');
}

function coord_update(event) {
	/**
	 * Get the absolution location on the page of the
	 * cursor on the page
	 */
	var windowX = event.pageX.toFixed(0);
	var windowY = event.pageY.toFixed(0);

	/**
	 * Get the upper left hand corner of the image on page
	 * Which helps us perform the relative calculation
	 */
	if ($('#xycapture').is(':visible')) {
		var imageTopLeft = $('#xycapture').offset();
	} else {
		var imageTopLeft = $('#existingdata').offset();
	}
	//console.log('ImageTop:'+imageTopLeft.top+', ImageLeft:'+imageTopLeft.left);

	/**
	 * get the relative location on the image
	 * by subtracting the imageTop from the cursor
	 * position.
	 */
	windowX -= imageTopLeft.left;
	windowY -= imageTopLeft.top;
	windowX  = windowX.toFixed(0);
	windowY  = windowY.toFixed(0);

	$('#x').val(windowX);
	$('#y').val(windowY);

	// Log the coordinates
	//console.log('X Value:'+$('#x').val());
	//console.log('Y Value:'+$('#y').val());

	$('#tb_coords').html(txtPosition+'<br />'+ windowX + ', ' + windowY);
}

function coord_release(event) {
	$('#tb_coords').html(txtPosition+'<br />---, ---');
}

function tidy_link() {
	$('#action').val('link_tidy');
	form_submit();
}

function attach_help_events() {
	// add an onblur/onfocus handler to all the visible <input> items
	$('input').focus(help_handler).blur(help_handler);
}
