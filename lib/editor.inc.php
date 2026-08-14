<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2022-2026 The Cacti Group, Inc.                           |
 |                                                                         |
 | Based on the Original Plugin developed by Howard Jones                  |
 |                                                                         |
 | Copyright (C) 2005-2022 Howard Jones and contributors                   |
 |                                                                         |
 | Permission is hereby granted, free of charge, to any person obtaining   |
 | a copy of this software and associated documentation files              |
 | (the "Software"), to deal in the Software without restriction,          |
 | including without limitation the rights to use, copy, modify, merge,    |
 | publish, distribute, sublicense, and/or sell copies of the Software,    |
 | and to permit persons to whom the Software is furnished to do so,       |
 | subject to the following conditions:                                    |
 |                                                                         |
 | The above copyright notice and this permission notice shall be          |
 | included in all copies or substantial portions of the Software.         |
 |                                                                         |
 | THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,         |
 | EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES         |
 | OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND                |
 | NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS     |
 | BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN      |
 | ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN       |
 | CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE        |
 | SOFTWARE.                                                               |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | Extensions to Howard Jones' original work are designed, written, and    |
 | maintained by the Cacti Group.                                          |
 |                                                                         |
 | Howard Jones was the original author of Weathermap.  You can reach      |
 | him at: howie@thingy.com                                                |
 +-------------------------------------------------------------------------+
 | http://www.network-weathermap.com/                                      |
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/** editor.inc.php
 *
 * All the functions used by the editor.
 */

function display_graphs() {
	$sql_where  = '';
	$sql_params = [];

	if (get_nfilter_request_var('term') != '') {
		$sql_where .= 'WHERE title_cache LIKE ?';

		$sql_params[] = '%' . get_nfilter_request_var('term') . '%';
	} else {
		$sql_where .= '';
	}

	if (get_nfilter_request_var('graph_template_id') > 0) {
		$sql_where .= ($sql_where != '' ? ' AND ' : 'WHERE ') . 'gl.graph_template_id = ?';

		$sql_params[] = get_filter_request_var('graph_template_id');
	}

	if (get_nfilter_request_var('target') == 'link_target_picker') {
		$sql_where .= ($sql_where != '' ? ' AND ' : 'WHERE ') . 'gl.snmp_query_id = (SELECT id FROM snmp_query WHERE hash = "d75e406fdeca4fcef45b8be3a9a63cbc")';
	}

	$rows = read_config_option('autocomplete_rows');

	if (empty($rows) || $rows > 100 || $rows < 0) {
		$rows = 100;
	}

	$graphs = db_fetch_assoc_prepared("SELECT DISTINCT gtg.local_graph_id AS id, gtg.title_cache AS title
		FROM graph_templates_graph AS gtg
		INNER JOIN graph_local AS gl
		ON gtg.local_graph_id = gl.id
		$sql_where
		ORDER BY title_cache
		LIMIT $rows",
		$sql_params);

	$return = [];

	if (cacti_sizeof($graphs)) {
		foreach ($graphs as $index => $g) {
			if (!is_graph_allowed($g['id'])) {
				unset($graphs[$index]);
			} else {
				$return[] = ['label' => $g['title'], 'value' => $g['title'], 'id' => $g['id']];
			}
		}
	}

	print json_encode($return);
}

function display_datasources() {
	$sql_where  = '';
	$sql_params = [];

	if (get_nfilter_request_var('term') != '') {
		$sql_where .= 'WHERE (name_cache LIKE ? OR dl.snmp_index LIKE ?) AND dtd.data_source_path != ""';
		$sql_params[] = '%' . get_nfilter_request_var('term') . '%';
		$sql_params[] = '%' . get_nfilter_request_var('term') . '%';
	} else {
		$sql_where .= 'WHERE dtd.data_source_path != ""';
	}

	$sql_where .= ($sql_where != '' ? ' AND ' : 'WHERE ') . 'dl.snmp_query_id = (SELECT id FROM snmp_query WHERE hash = "d75e406fdeca4fcef45b8be3a9a63cbc")';

	$rows = read_config_option('autocomplete_rows');

	if (empty($rows) || $rows > 100 || $rows < 0) {
		$rows = 100;
	}

	$graphs = db_fetch_assoc_prepared("SELECT gti.local_graph_id AS id, dtd.name_cache AS title, dtd.data_source_path AS path, COUNT(*) AS items
		FROM data_template_data AS dtd
		INNER JOIN data_local AS dl
		ON dl.id = dtd.local_data_id
		INNER JOIN data_template_rrd AS dtr
		ON dtd.local_data_id = dtr.local_data_id
		INNER JOIN graph_templates_item AS gti
		ON gti.task_item_id = dtr.id
		$sql_where
		GROUP BY gti.local_graph_id
		ORDER BY name_cache
		LIMIT $rows", $sql_params);

	$return = [];

	if (cacti_sizeof($graphs)) {
		foreach ($graphs as $index => $g) {
			if (!is_graph_allowed($g['id'])) {
				unset($graphs[$index]);
			} else {
				$return[] = ['label' => $g['title'], 'value' => $g['title'], 'id' => trim(str_replace('<path_rra>', '', $g['path']), '/'), 'local_graph_id' => $g['id']];
			}
		}
	}

	print json_encode($return, true);
}

/**
 * Clean up URI (function taken from Cacti) to protect against XSS
 * @param mixed $str
 */
function wm_editor_sanitize_uri($str) {
	static $drop_char_match   =   [' ', '^', '$', '<', '>', '`', '\'', '"', '|', '+', '[', ']', '{', '}', ';', '!', '%'];
	static $drop_char_replace = ['', '', '',  '',  '',  '',  '',   '',  '',  '',  '',  '',  '',  '',  '',  '', ''];

	return str_replace($drop_char_match, $drop_char_replace, urldecode($str));
}

// much looser sanitise for general strings that shouldn't have HTML in them
function wm_editor_sanitize_string($str) {
	static $drop_char_match   = ['<', '>' ];
	static $drop_char_replace = ['', ''];

	return str_replace($drop_char_match, $drop_char_replace, html_escape($str));
}

function wm_editor_validate_bandwidth($bw) {
	if (preg_match('/^(\d+\.?\d*[KMGT]?)$/', $bw)) {
		return true;
	}

	return false;
}

function wm_editor_validate_one_of($input,$valid = [],$case_sensitive = false) {
	if (!$case_sensitive) {
		$input = strtolower($input);
	}

	foreach ($valid as $v) {
		if (!$case_sensitive) {
			$v = strtolower($v);
		}

		if ($v == $input) {
			return true;
		}
	}

	return false;
}

function wm_editor_sanitize_action($action, $valid = []) {
	if ($action === '') {
		return '';
	}

	if (!wm_editor_validate_one_of($action, $valid, true)) {
		return '';
	}

	return $action;
}

// Labels for Nodes, Links and Scales shouldn't have spaces in
function wm_editor_sanitize_name($str) {
	return str_replace([' '], '', $str);
}

function wm_editor_sanitize_selected($str) {
	$res = urldecode($str);

	if (! preg_match('/^(LINK|NODE):/',$res)) {
		return '';
	}

	return wm_editor_sanitize_name($res);
}

function wm_editor_sanitize_file($filename,$allowed_exts = []) {
	$filename = wm_editor_sanitize_uri($filename);

	if ($filename == '') {
		return '';
	}

	$ok = false;

	foreach ($allowed_exts as $ext) {
		$match = '.' . $ext;

		if (substr($filename, -strlen($match),strlen($match)) == $match) {
			$ok = true;
		}
	}

	if (!$ok) {
		return '';
	}

	return $filename;
}

function wm_editor_sanitize_conffile($filename) {
	$filename = wm_editor_sanitize_uri($filename);

	// If we've been fed something other than a .conf filename, just pretend it didn't happen
	if (substr($filename,-5,5) != '.conf') {
		$filename = '';
	}

	// on top of the url stuff, we don't ever need to see a / in a config filename (CVE-2013-3739)
	if (strstr($filename,'/') !== false) {
		$filename = '';
	}

	// Defense-in-depth: reject Windows path separators to prevent traversal on Windows hosts.
	if (strstr($filename, '\\') !== false) {
		$filename = '';
	}

	return $filename;
}

function show_editor_startpage() {
	global $mapdir, $config, $configerror;

	$matches            = 0;
	$weathermap_version = plugin_weathermap_numeric_version();
	$selected_theme     = get_selected_theme();

	$titles = [];
	$notes  = [];

	$errorstring = '';

	if (is_dir($mapdir)) {
		$n  = 0;
		$dh = opendir($mapdir);

		if ($dh) {
			while (false !== ($file = readdir($dh))) {
				$realfile = $mapdir . '/' . $file;
				$note     = '';

				// skip directories, unreadable files, .files and anything that doesn't come through the sanitiser unchanged
				if ((is_file($realfile)) && (is_readable($realfile)) && (!preg_match("/^\./",$file)) && (wm_editor_sanitize_conffile($file) == $file)) {
					if (!wmEditorMapIsEditable($realfile)) {
						$note .= '(read-only)';
					}

					$title = '(no title)';
					$fd    = fopen($realfile, 'r');

					if ($fd) {
						while (!feof($fd)) {
							$buffer = fgets($fd, 4096);

							if (preg_match('/^\s*TITLE\s+(.*)/i', $buffer, $matches)) {
								$title = wm_editor_sanitize_string($matches[1]);
							}
						}

						fclose($fd);

						$titles[$file] = $title;
						$notes[$file]  = $note;

						$n++;
					}
				}
			}

			closedir($dh);
		} else {
			$errorstring = "Can't open mapdir to read.";
		}

		ksort($titles);

		if ($n == 0) {
			$errorstring = 'No files in mapdir';
		}
	} else {
		$errorstring = "NO DIRECTORY named $mapdir";
	}

	?>
	<!DOCTYPE html>
	<html lang='en'>
	<head>
		<meta charset='utf-8'>
		<meta name='viewport' content='width=device-width, initial-scale=1'>
		<link href='<?php print $config['url_path'] . 'include/themes/' . $selected_theme . '/images/favicon.ico'; ?>' rel='shortcut icon'>
		<link rel='stylesheet' type='text/css' media='screen' href='<?php print $config['url_path'] . 'include/themes/' . $selected_theme . '/jquery-ui.css'; ?>'>
		<link rel='stylesheet' type='text/css' media='screen' href='<?php print $config['url_path'] . 'include/themes/' . $selected_theme . '/main.css'; ?>'>
		<link rel='stylesheet' type='text/css' media='screen' href='css/editor.css?v=<?php print intval(filemtime(dirname(__DIR__) . '/css/editor.css')); ?>'>
		<title><?php print __('Weathermap Editor %s', $weathermap_version, 'weathermap'); ?></title>
	</head>
	<body class='wm-start-page'>
		<header class='wm-start-header'>
			<div>
				<span class='wm-eyebrow'><?php print __('Cacti Network Visualisation', 'weathermap'); ?></span>
				<h1><?php print __('Weathermap Editor', 'weathermap'); ?></h1>
				<p><?php print __('Build, open, and arrange network maps from one workspace.', 'weathermap'); ?></p>
			</div>
			<span class='wm-version-badge'>v<?php print html_escape($weathermap_version); ?></span>
		</header>

		<main class='wm-start-shell'>
			<?php if ($configerror != '') { ?>
				<div class='wm-start-alert' role='alert'><?php print html_escape($configerror); ?></div>
			<?php } ?>

			<section class='wm-start-intro'>
				<strong><?php print __('Visual editing with config-safe output', 'weathermap'); ?></strong>
				<span><?php print __('The editor handles common layout and styling changes while preserving advanced directives maintained in the map configuration.', 'weathermap'); ?></span>
			</section>

			<div class='wm-start-grid'>
				<section class='wm-start-card'>
					<span class='wm-card-icon' aria-hidden='true'>＋</span>
					<h2><?php print __('Create a map', 'weathermap'); ?></h2>
					<p><?php print __('Start with a clean canvas and add nodes and links as you go.', 'weathermap'); ?></p>
					<form method='post' class='wm-start-form'>
						<label for='new_map_name'><?php print __('Configuration filename', 'weathermap'); ?></label>
						<div class='wm-field-row'>
							<input id='new_map_name' type='text' name='mapname' placeholder='network.conf' pattern='[^\\/\\s]+\.conf' required>
							<input name='action' type='hidden' value='newmap'>
							<button type='submit' class='wm-primary-button'><?php print __('Create', 'weathermap'); ?></button>
						</div>
						<small><?php print __('Use a filename without spaces ending in .conf.', 'weathermap'); ?></small>
					</form>
				</section>

				<section class='wm-start-card'>
					<span class='wm-card-icon' aria-hidden='true'>⧉</span>
					<h2><?php print __('Create from a map', 'weathermap'); ?></h2>
					<p><?php print __('Duplicate an existing layout, then adapt it without changing the original.', 'weathermap'); ?></p>
					<form method='post' class='wm-start-form'>
						<label for='copy_map_name'><?php print __('New configuration filename', 'weathermap'); ?></label>
						<input id='copy_map_name' type='text' name='mapname' placeholder='network-copy.conf' pattern='[^\\/\\s]+\.conf' required>
						<label for='source_map'><?php print __('Source map', 'weathermap'); ?></label>
						<div class='wm-field-row'>
							<select id='source_map' name='sourcemap' <?php print($errorstring == '' ? '' : 'disabled'); ?>>
								<?php
								if ($errorstring == '') {
									foreach ($titles as $file => $title) {
										print '<option value="' . html_escape($file) . '">' . html_escape($file) . '</option>';
									}
								} else {
									print '<option value="">' . html_escape($errorstring) . '</option>';
								}
								?>
							</select>
							<input name='action' type='hidden' value='newmapcopy'>
							<button type='submit' class='wm-primary-button' <?php print($errorstring == '' ? '' : 'disabled'); ?>><?php print __('Create copy', 'weathermap'); ?></button>
						</div>
					</form>
				</section>
			</div>

			<section class='wm-map-library'>
				<div class='wm-library-heading'>
					<div>
						<span class='wm-eyebrow'><?php print __('Map library', 'weathermap'); ?></span>
						<h2><?php print __('Open an existing map', 'weathermap'); ?></h2>
					</div>
					<span><?php print __('%d maps', count($titles), 'weathermap'); ?></span>
				</div>

				<div class='wm-map-list'>
					<?php if ($errorstring == '') { ?>
						<?php foreach ($titles as $file => $title) { ?>
							<a class='wm-map-row' href='?mapname=<?php print urlencode($file); ?>'>
								<span class='wm-map-file'><?php print html_escape($file); ?></span>
								<span class='wm-map-title'><?php print html_escape($title); ?></span>
								<?php if ($notes[$file] != '') { ?><span class='wm-readonly-badge'><?php print __('Read only', 'weathermap'); ?></span><?php } ?>
								<span class='wm-map-arrow' aria-hidden='true'>→</span>
							</a>
						<?php } ?>
					<?php } else { ?>
						<div class='wm-empty-state'><?php print html_escape($errorstring); ?></div>
					<?php } ?>
				</div>
			</section>
		</main>

		<footer class='wm-start-footer'>
			<span>PHP Weathermap <?php print html_escape($weathermap_version); ?></span>
			<a href='docs/' target='_blank' rel='noopener'><?php print __('Documentation', 'weathermap'); ?></a>
		</footer>
	</body>
	</html>
	<?php
}

function snap($coord, $gridsnap = 0) {
	if ($gridsnap == 0) {
		return ($coord);
	} else {
		$rest = $coord % $gridsnap;

		return intval(($coord - $rest + round($rest / $gridsnap) * $gridsnap));
	}
}

function extract_with_validation($array, $paramarray, $prefix = '') {
	$all_present = true;
	$candidates  = [];

	foreach ($paramarray as $var) {
		$varname = $var[0];
		$vartype = $var[1];
		$varreqd = $var[2];

		if ($varreqd == 'req' && !array_key_exists($varname, $array)) {
			$all_present = false;
		}

		if (array_key_exists($varname, $array)) {
			$varvalue = $array[$varname];

			$waspresent = $all_present;

			switch ($vartype) {
				case 'int':
					if (!preg_match('/^\-*\d+$/', $varvalue)) {
						$all_present = false;
					}

					break;
				case 'float':
					if (!preg_match('/^\d+\.\d+$/', $varvalue)) {
						$all_present = false;
					}

					break;
				case 'yesno':
					if (!preg_match('/^(y|n|yes|no)$/i', $varvalue)) {
						$all_present = false;
					}

					break;
				case 'sqldate':
					if (!preg_match('/^\d\d\d\d\-\d\d\-\d\d$/i', $varvalue)) {
						$all_present = false;
					}

					break;
				case 'any':
					// we don't care at all

					break;
				case 'ip':
					if (!preg_match('/^((\d|[1-9]\d|2[0-4]\d|25[0-5]|1\d\d)(?:\.(\d|[1-9]\d|2[0-4]\d|25[0-5]|1\d\d)){3})$/', $varvalue)) {
						$all_present = false;
					}

					break;
				case 'alpha':
					if (!preg_match('/^[A-Za-z]+$/', $varvalue)) {
						$all_present = false;
					}

					break;
				case 'alphanum':
					if (!preg_match('/^[A-Za-z0-9]+$/', $varvalue)) {
						$all_present = false;
					}

					break;
				case 'bandwidth':
					if (!preg_match('/^\d+\.?\d*[KMGT]*$/i', $varvalue)) {
						$all_present = false;
					}

					break;
				default:
					// an unknown type counts as an error, really
					$all_present = false;

					break;
			}

			if ($all_present) {
				$candidates["{$prefix}{$varname}"] = $varvalue;
			}
		}
	}

	if ($all_present) {
		foreach ($candidates as $key => $value) {
			$GLOBALS[$key] = $value;
		}
	}

	return [$all_present, $candidates];
}

function get_imagelist($imagedir) {
	global $config;

	$imagelist = [];

	$imdir = $config['base_path'] . '/plugins/weathermap/images/' . $imagedir;

	if (is_dir($imdir)) {
		$n  = 0;
		$dh = opendir($imdir);

		if ($dh) {
			while ($file = readdir($dh)) {
				$realfile = $imdir . '/' . $file;
				$uri      = "images/$imagedir/$file";

				if (is_readable($realfile) && !preg_match('/^\./', $file) && (preg_match('/\.(gif|jpg|png)$/i', $file))) {
					$imagelist[] = $uri;
					$n++;
				}
			}

			closedir($dh);
		}
	}

	return ($imagelist);
}

function handle_inheritance(&$map, &$inheritables) {
	foreach ($inheritables as $inheritable) {
		$fieldname  = $inheritable[1];
		$formname   = $inheritable[2];
		$validation = $inheritable[3];

		$new = get_nfilter_request_var($formname);

		if ($validation != '') {
			switch($validation) {
				case 'int':
					$new = intval($new);

					break;
				case 'float':
					$new = floatval($new);

					break;
			}
		}

		$old = ($inheritable[0] == 'node' ? $map->nodes['DEFAULT']->$fieldname : $map->links['DEFAULT']->$fieldname);

		if ($old != $new) {
			if ($inheritable[0] == 'node') {
				$map->nodes['DEFAULT']->$fieldname = $new;

				foreach ($map->nodes as $node_name => $node) {
					if ($node->name != ':: DEFAULT ::' && $old == $node->$fieldname) {
						$map->nodes[$node->name]->$fieldname = $new;
					}
				}
			} elseif ($inheritable[0] == 'link') {
				$map->links['DEFAULT']->$fieldname = $new;

				foreach ($map->links as $link_name => $link) {
					if ($link->name != ':: DEFAULT ::' && $old == $link->$fieldname) {
						$map->links[$link->name]->$fieldname = $new;
					}
				}
			}
		}
	}
}

function get_fontlist(&$map,$name,$current) {
	$output = '<select class="fontcombo" name="' . html_escape($name) . '">';

	ksort($map->fonts);

	foreach ($map->fonts as $fontnumber => $font) {
		$output .= '<option ';

		if ($current == $fontnumber) {
			$output .= 'selected';
		}

		$output .= ' value="' . $fontnumber . '">' . $fontnumber . ' (' . $font->type . ')</option>';
	}

	$output .= '</select>';

	return ($output);
}

function range_overlaps($a_min, $a_max, $b_min, $b_max) {
	if ($a_min > $b_max) {
		return false;
	}

	if ($b_min > $a_max) {
		return false;
	}

	return true;
}

function common_range($a_min,$a_max, $b_min, $b_max) {
	$min_overlap = max($a_min, $b_min);
	$max_overlap = min($a_max, $b_max);

	return [$min_overlap, $max_overlap];
}

/**
 * distance - find the distance between two points
 *
 * @param mixed $ax
 * @param mixed $ay
 * @param mixed $bx
 * @param mixed $by
 */
function distance($ax, $ay, $bx, $by) {
	$dx = $bx - $ax;
	$dy = $by - $ay;

	return sqrt($dx * $dx + $dy * $dy);
}

function tidy_links(&$map, $targets, $ignore_tidied = false) {
	// not very efficient, but it saves looking for special cases (a->b & b->a together)
	$ntargets = count($targets);
	$i        = 1;

	foreach ($targets as $target) {
		tidy_link($map, $target, $i, $ntargets, $ignore_tidied);
		$i++;
	}
}

/**
 * tidy_link - change link offsets so that link is horizontal or vertical, if possible.
 *             if not possible, change offsets to the closest facing compass points
 * @param mixed $map
 * @param mixed $target
 * @param mixed $linknumber
 * @param mixed $linktotal
 * @param mixed $ignore_tidied
 */
function tidy_link(&$map,$target, $linknumber = 1, $linktotal = 1, $ignore_tidied = false) {
	// print "\n-----------------------------------\nTidying $target...\n";
	if (isset($map->links[$target]) && isset($map->links[$target]->a)) {
		$node_a = $map->links[$target]->a;
		$node_b = $map->links[$target]->b;

		$new_a_offset = '0:0';
		$new_b_offset = '0:0';

		// Update TODO: if the nodes are already directly left/right or up/down, then use compass-points, not pixel offsets
		// (e.g. N90) so if the label changes, they won't need to be re-tidied

		// First bounding box in the node's boundingbox array is the icon, if there is one, or the label if not.
		$bb_a = $node_a->boundingboxes[0];
		$bb_b = $node_b->boundingboxes[0];

		// figure out if they share any x or y coordinates
		$x_overlap = range_overlaps($bb_a[0], $bb_a[2], $bb_b[0], $bb_b[2]);
		$y_overlap = range_overlaps($bb_a[1], $bb_a[3], $bb_b[1], $bb_b[3]);

		$a_x_offset = 0;
		$a_y_offset = 0;
		$b_x_offset = 0;
		$b_y_offset = 0;

		// if they are side by side, and there's some common y coords, make link horizontal
		if (!$x_overlap && $y_overlap) {
			// print "SIDE BY SIDE\n";

			// snap the X coord to the appropriate edge of the node
			if ($bb_a[2] < $bb_b[0]) {
				$a_x_offset = $bb_a[2] - $node_a->x;
				$b_x_offset = $bb_b[0] - $node_b->x;
			}

			if ($bb_b[2] < $bb_a[0]) {
				$a_x_offset = $bb_a[0] - $node_a->x;
				$b_x_offset = $bb_b[2] - $node_b->x;
			}

			// this should be true whichever way around they are
			[$min_overlap,$max_overlap] = common_range($bb_a[1],$bb_a[3],$bb_b[1],$bb_b[3]);

			$overlap = $max_overlap - $min_overlap;
			$n       = $overlap / ($linktotal + 1);

			$a_y_offset = $min_overlap + ($linknumber * $n) - $node_a->y;
			$b_y_offset = $min_overlap + ($linknumber * $n) - $node_b->y;

			$new_a_offset = sprintf('%d:%d', $a_x_offset,$a_y_offset);
			$new_b_offset = sprintf('%d:%d', $b_x_offset,$b_y_offset);
		}

		// if they are above and below, and there's some common x coords, make link vertical
		if (!$y_overlap && $x_overlap) {
			// print "ABOVE/BELOW\n";

			// snap the Y coord to the appropriate edge of the node
			if ($bb_a[3] < $bb_b[1]) {
				$a_y_offset = $bb_a[3] - $node_a->y;
				$b_y_offset = $bb_b[1] - $node_b->y;
			}

			if ($bb_b[3] < $bb_a[1]) {
				$a_y_offset = $bb_a[1] - $node_a->y;
				$b_y_offset = $bb_b[3] - $node_b->y;
			}

			[$min_overlap,$max_overlap] = common_range($bb_a[0],$bb_a[2],$bb_b[0],$bb_b[2]);

			$overlap = $max_overlap - $min_overlap;
			$n       = $overlap / ($linktotal + 1);

			// move the X coord to the centre of the overlapping area
			$a_x_offset = $min_overlap + ($linknumber * $n) - $node_a->x;
			$b_x_offset = $min_overlap + ($linknumber * $n) - $node_b->x;

			$new_a_offset = sprintf('%d:%d', $a_x_offset,$a_y_offset);
			$new_b_offset = sprintf('%d:%d', $b_x_offset,$b_y_offset);
		}

		// if no common coordinates, figure out the best diagonal...
		if (!$y_overlap && !$x_overlap) {
			$pt_a = new WMPoint($node_a->x, $node_a->y);
			$pt_b = new WMPoint($node_b->x, $node_b->y);

			$line = new WMLineSegment($pt_a, $pt_b);

			$tangent = $line->vector;
			$tangent->normalise();

			$normal = $tangent->getNormal();

			$pt_a->AddVector($normal, 15 * ($linknumber - 1));
			$pt_b->AddVector($normal, 15 * ($linknumber - 1));

			$a_x_offset = $pt_a->x - $node_a->x;
			$a_y_offset = $pt_a->y - $node_a->y;

			$b_x_offset = $pt_b->x - $node_b->x;
			$b_y_offset = $pt_b->y - $node_b->y;

			$new_a_offset = sprintf('%d:%d', $a_x_offset,$a_y_offset);
			$new_b_offset = sprintf('%d:%d', $b_x_offset,$b_y_offset);
		}

		// if no common coordinates, figure out the best diagonal...
		// currently - brute force search the compass points for the shortest distance
		// potentially - intersect link line with rectangles to get exact crossing point
		if (1 == 0 && !$y_overlap && !$x_overlap) {
			// print "DIAGONAL\n";

			$corners = ['NE', 'E', 'SE', 'S', 'SW', 'W', 'NW', 'N'];

			// start with what we have now
			$best_distance = distance($node_a->x, $node_a->y, $node_b->x, $node_b->y);
			$best_offset_a = 'C';
			$best_offset_b = 'C';

			foreach ($corners as $corner1) {
				[$ax,$ay] = calc_offset($corner1, $bb_a[2] - $bb_a[0], $bb_a[3] - $bb_a[1]);

				$axx = $node_a->x + $ax;
				$ayy = $node_a->y + $ay;

				foreach ($corners as $corner2) {
					[$bx,$by] = calc_offset($corner2, $bb_b[2] - $bb_b[0], $bb_b[3] - $bb_b[1]);

					$bxx = $node_b->x + $bx;
					$byy = $node_b->y + $by;

					$d = distance($axx,$ayy, $bxx, $byy);

					if ($d < $best_distance) {
						// print "from $corner1 ($axx, $ayy) to $corner2 ($bxx, $byy): ";
						// print "NEW BEST $d\n";
						$best_distance = $d;
						$best_offset_a = $corner1;
						$best_offset_b = $corner2;
					}
				}
			}

			// Step back a bit from the edge, to hide the corners of the link
			$new_a_offset = $best_offset_a . '85';
			$new_b_offset = $best_offset_b . '85';
		}

		// unwritten/implied - if both overlap, you're doing something weird and you're on your own
		// finally, update the offsets
		$map->links[$target]->a_offset = $new_a_offset;
		$map->links[$target]->b_offset = $new_b_offset;

		// and also add a note that this link was tidied, and is eligible for automatic tidying
		$map->links[$target]->add_hint('_tidied', 1);
	}
}

function untidy_links(&$map) {
	foreach ($map->links as $link) {
		$link->a_offset = 'C';
		$link->b_offset = 'C';
	}
}

function retidy_links(&$map, $ignore_tidied = false) {
	$routes = [];
	$done   = [];

	foreach ($map->links as $link) {
		if (isset($link->a)) {
			$route = $link->a->name . ' ' . $link->b->name;

			if (strcmp($link->a->name, $link->b->name) > 0) {
				$route = $link->b->name . ' ' . $link->a->name;
			}

			$routes[$route][] = $link->name;
		}
	}

	foreach ($map->links as $link) {
		if (isset($link->a)) {
			$route = $link->a->name . ' ' . $link->b->name;

			if (strcmp($link->a->name, $link->b->name) > 0) {
				$route = $link->b->name . ' ' . $link->a->name;
			}

			if (($ignore_tidied || $link->get_hint('_tidied') == 1) && !isset($done[$route]) && isset($routes[$route])) {
				if (sizeof($routes[$route]) == 1) {
					tidy_link($map, $link->name);

					$done[$route] = 1;
				} else {
					// handle multi-links specially...
					tidy_links($map, $routes[$route]);

					// mark it so we don't do it again when the other links come by
					$done[$route] = 1;
				}
			}
		}
	}
}

function editor_log($str) {
	// $f = fopen('editor.log','a');
	// fputs($f, $str);
	// fclose($f);
}

function getEditorJs() {
	?>
	<script type='text/javascript'>
	var sessionMessageOk    = '<?php print __esc('Ok', 'weathermap'); ?>';
	var sessionMessageTitle = '<?php print __esc('Operation successful', 'weathermap'); ?>';
	var sessionMessageSave  = '<?php print __esc('The Operation was successful.  Details are below.', 'weathermap'); ?>';
	var sessionMessagePause = '<?php print __esc('Pause', 'weathermap'); ?>';

	var addLinkHelp   = '<?php print __esc('Click on the first node for the start of the link.', 'weathermap'); ?>';
	var addNodeHelp   = '<?php print __esc('Click on the map where you would like to add a new node.', 'weathermap'); ?>';

	var delNodeWarning  = '<?php print __esc('WARNING: Pressing \'Delete Node\' will delete this Node.', 'weathermap'); ?>';
	var delNodeTitle    = '<?php print __esc('Delete Node Confirmation', 'weathermap'); ?>';
	var delLinkWarning  = '<?php print __esc('WARNING: Pressing \'Delete Link\' will delete this Link.', 'weathermap'); ?>';
	var delLinkTitle    = '<?php print __esc('Delete Link Confirmation', 'weathermap'); ?>';
	var txtCancel       = '<?php print __esc('Cancel', 'weathermap'); ?>';
	var txtDelLink      = '<?php print __esc('Delete Link', 'weathermap'); ?>';
	var txtDelNode      = '<?php print __esc('Delete Node', 'weathermap'); ?>';
	var txtPosition     = '<?php print __esc('Position', 'weathermap'); ?>';

	var txtNodeActions = '<?php print __esc('Node Actions', 'weathermap'); ?>';
	var txtLinkActions = '<?php print __esc('Link Actions', 'weathermap'); ?>';
	var txtClone       = '<?php print __esc('Clone', 'weathermap'); ?>';
	var txtEdit        = '<?php print __esc('Edit', 'weathermap'); ?>';
	var txtDelete      = '<?php print __esc('Delete', 'weathermap'); ?>';
	var txtTidy        = '<?php print __esc('Tidy', 'weathermap'); ?>';
	var txtProperties  = '<?php print __esc('Properties', 'weathermap'); ?>';

	// seed the help text. Done in a big lump here, so we could make a foreign language version someday.

	var helptexts = {
		'link_target':        '<?php print __esc('Where should Weathermap get data for this link? This can either be an RRD file, or an HTML with special comments in it (normally from MRTG).', 'weathermap'); ?>',
		'link_width':         '<?php print __esc('How wide the link arrow will be drawn, in pixels.', 'weathermap'); ?>',
		'link_infourl':       '<?php print __esc('If you are using the \'overlib\' HTML style then this is the URL that will be opened when you click on the link', 'weathermap'); ?>',
		'link_hover':         '<?php print __esc('If you are using the \'overlib\' HTML style then this is the URL of the image that will be shown when you hover over the link', 'weathermap'); ?>',
		'link_bandwidth_in':  '<?php print __esc('The bandwidth from the first node to the second node', 'weathermap'); ?>',
		'link_bandwidth_out': '<?php print __esc('The bandwidth from the second node to the first node (if that is different)', 'weathermap'); ?>',
		'link_commentin':     '<?php print __esc('The text that will appear alongside the link', 'weathermap'); ?>',
		'link_commentout':    '<?php print __esc('The text that will appear alongside the link', 'weathermap'); ?>',
		'node_infourl':       '<?php print __esc('If you are using the \'overlib\' HTML style then this is the URL that will be opened when you click on the node', 'weathermap'); ?>',
		'node_hover':         '<?php print __esc('If you are using the \'overlib\' HTML style then this is the URL of the image that will be shown when you hover over the node', 'weathermap'); ?>',
		'node_label':         '<?php print __esc('The text that appears on the node', 'weathermap'); ?>',
		'node_new_name':      '<?php print __esc('The name used for this node when defining links', 'weathermap'); ?>',
		'tb_newfile':         '<?php print __esc('Change to a different file, or start creating a new one.', 'weathermap'); ?>',
		'tb_addnode':         '<?php print __esc('Add a new node to the map', 'weathermap'); ?>',
		'tb_addlink':         '<?php print __esc('Add a new link to the map, by joining two nodes together.', 'weathermap'); ?>',
		'hover_tb_newfile':   '<?php print __esc('Select a different map to edit, or start a new one.', 'weathermap'); ?>',

		// These are the default text - what appears when nothing more interesting
		// is happening. One for each dialog/location.
		'link_default':       '<?php print __esc('This is where help appears for links', 'weathermap'); ?>',
		'map_default':        '<?php print __esc('This is where help appears for maps', 'weathermap'); ?>',
		'node_default':       '<?php print __esc('This is where help appears for nodes', 'weathermap'); ?>',
		'tb_default':         '<?php print __esc('or click a Node or Link to edit it\'s properties', 'weathermap'); ?>'
	};
	</script>
	<?php
}
