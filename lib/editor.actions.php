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

/** editor.actions.php
 *
 * All the functions used by the that wrap in request variables
 * and pass control to lower level functions in the weathermap classes
 * and the editor.inc.php file.
 * @param mixed $mapfile
 */

function wmEditorMapIsEditable($mapfile) {
	clearstatcache(true, $mapfile);

	if (!is_file($mapfile) || is_link($mapfile) || !is_writable($mapfile) || !is_writable(dirname($mapfile))) {
		return false;
	}

	if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
		return true;
	}

	// Atomic replacement creates a new inode. Refuse editing up front when the web
	// process could not preserve the configured owner and group on that inode.
	$effective_uid = posix_geteuid();
	$target_uid    = fileowner($mapfile);
	$target_gid    = filegroup($mapfile);

	if ($target_uid === false || $target_gid === false || $target_uid !== $effective_uid) {
		return false;
	}

	$groups = function_exists('posix_getgroups') ? posix_getgroups() : [];

	if (function_exists('posix_getegid')) {
		$groups[] = posix_getegid();
	}

	if (in_array($target_gid, array_map('intval', $groups), true)) {
		return true;
	}

	$directory_metadata = @stat(dirname($mapfile));

	return $directory_metadata !== false &&
		(intval($directory_metadata['mode']) & 02000) !== 0 &&
		intval($directory_metadata['gid']) === $target_gid;
}

function wmEditorLockFile($mapfile) {
	$directory = dirname($mapfile);

	if (is_dir($directory) && is_writable($directory)) {
		return $mapfile . '.lock';
	}

	$identity = realpath($mapfile);

	if ($identity === false) {
		$identity = $mapfile;
	}

	return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
		'weathermap-editor-' . hash('sha256', $identity) . '.lock';
}

function newMap($mapfile, &$error = '') {
	$reservation = @fopen($mapfile, 'x');

	if ($reservation === false) {
		$error = 'A map with that filename already exists.';
		return false;
	}

	fclose($reservation);

	$map = new WeatherMap;

	$map->context = 'editor';

	if (!$map->WriteConfig($mapfile)) {
		@unlink($mapfile);
		$error = 'The new map could not be written.';
		return false;
	}

	return true;
}

function newMapCopy($mapfile, &$error = '') {
	global $mapdir;

	$map = new WeatherMap;

	$map->context = 'editor';
	$sourcemapname = '';

	if (isset_request_var('sourcemap')) {
		$sourcemapname = get_nfilter_request_var('sourcemap');
	}

	$sourcemapname = wm_editor_sanitize_conffile($sourcemapname);

	if ($sourcemapname == '') {
		$error = 'Choose a valid source map.';
		return false;
	}

	$sourcemap = $mapdir . '/' . $sourcemapname;

	if ($sourcemap == $mapfile) {
		$error = 'The source and destination map must be different.';
		return false;
	}

	$lock_specs = [
		$mapfile   => LOCK_EX,
		$sourcemap => LOCK_SH
	];
	$locks = [];
	ksort($lock_specs, SORT_STRING);

	foreach ($lock_specs as $locked_map => $lock_mode) {
		$handle = @fopen(wmEditorLockFile($locked_map), 'c');

		if ($handle === false || !flock($handle, $lock_mode)) {
			if ($handle !== false) {
				fclose($handle);
			}

			foreach (array_reverse($locks) as $held_lock) {
				flock($held_lock, LOCK_UN);
				fclose($held_lock);
			}

			$error = 'The source or destination map is busy.';
			return false;
		}

		$locks[] = $handle;
	}

	if (!is_file($sourcemap) || !is_readable($sourcemap) || !$map->ReadConfig($sourcemap)) {
		foreach (array_reverse($locks) as $held_lock) {
			flock($held_lock, LOCK_UN);
			fclose($held_lock);
		}

		$error = 'The source map could not be read.';
		return false;
	}

	$reservation = @fopen($mapfile, 'x');

	if ($reservation === false) {
		foreach (array_reverse($locks) as $held_lock) {
			flock($held_lock, LOCK_UN);
			fclose($held_lock);
		}

		$error = 'A map with that filename already exists.';
		return false;
	}

	fclose($reservation);

	if (!$map->WriteConfig($mapfile)) {
		@unlink($mapfile);

		foreach (array_reverse($locks) as $held_lock) {
			flock($held_lock, LOCK_UN);
			fclose($held_lock);
		}

		$error = 'The map copy could not be written.';
		return false;
	}

	foreach (array_reverse($locks) as $held_lock) {
		flock($held_lock, LOCK_UN);
		fclose($held_lock);
	}

	return true;
}

function getMapJavaScript($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	print $map->asJS();
	print "\t\t\twindow.mapRevision = " . js_escape(wmEditorConfigRevision($mapfile)) . ";\n";
	print "\t\t\twindow.mapEditable = " . (wmEditorMapIsEditable($mapfile) ? 'true' : 'false') . ";\n";
}

function getEditorState($mapfile, $selected, $use_overlay, $use_relative_overlay) {
	$map          = new WeatherMap;
	$map->context = 'editor';

	if (!$map->ReadConfig($mapfile)) {
		http_response_code(500);
		header('Content-Type: application/json; charset=utf-8');
		print json_encode(['ok' => false, 'message' => 'The map configuration could not be loaded.']);
		return;
	}

	if ($selected != '') {
		if (substr($selected, 0, 5) == 'NODE:') {
			$nodename = substr($selected, 5);

			if (isset($map->nodes[$nodename])) {
				$map->nodes[$nodename]->selected = 1;
			}
		}

		if (substr($selected, 0, 5) == 'LINK:') {
			$linkname = substr($selected, 5);

			if (isset($map->links[$linkname])) {
				$map->links[$linkname]->selected = 1;
			}
		}
	}

	$map->sizedebug = true;
	ob_start();
	$map->DrawMap('', '', 250, true, $use_overlay, $use_relative_overlay);
	$png = ob_get_clean();

	if ($png === false || $png == '') {
		http_response_code(500);
		header('Content-Type: application/json; charset=utf-8');
		print json_encode(['ok' => false, 'message' => 'The map image could not be rendered.']);
		return;
	}

	$map->htmlstyle = 'editor';
	$map->PreloadMapHTML();

	$revision = wmEditorConfigRevision($mapfile);
	$editable = wmEditorMapIsEditable($mapfile);
	$script   = $map->asJS();
	$script  .= "\t\t\twindow.mapRevision = " . js_escape($revision) . ";\n";
	$script  .= "\t\t\twindow.mapEditable = " . ($editable ? 'true' : 'false') . ";\n";
	wmEditorMapElements($map);

	header('Content-Type: application/json; charset=utf-8');
	print json_encode([
		'ok'       => true,
		'revision' => $revision,
		'editable' => $editable,
		'areas'    => $map->SortedImagemap('weathermap_imap'),
		'script'   => $script,
		'image'    => 'data:image/png;base64,' . base64_encode($png)
	]);
}

function wmEditorMapElements(&$map) {
	$elements = [];

	foreach ($map->imap->shapes as $shape) {
		$name = (string) $shape->name;
		$type = '';
		$key  = '';

		if (strpos($name, 'LEGEND:') === 0) {
			$type = 'legend';
			$key  = substr($name, 7);

			if ($key === '' || !isset($map->keyx[$key]) || !isset($map->keyy[$key])) {
				continue;
			}

			$x = intval($map->keyx[$key]);
			$y = intval($map->keyy[$key]);
		} elseif (in_array($name, ['TIMESTAMP', 'MINTIMESTAMP', 'MAXTIMESTAMP'], true)) {
			$type = 'timestamp';
			$key  = $name == 'TIMESTAMP' ? 'CURRENT' : substr($name, 0, -9);

			if ($name == 'MINTIMESTAMP') {
				$x = intval($map->mintimex);
				$y = intval($map->mintimey);
			} elseif ($name == 'MAXTIMESTAMP') {
				$x = intval($map->maxtimex);
				$y = intval($map->maxtimey);
			} else {
				$x = intval($map->timex);
				$y = intval($map->timey);
			}
		} else {
			continue;
		}

		if (!($shape instanceof HTML_ImageMap_Area_Rectangle)) {
			continue;
		}

		// Timestamp configuration stores the text baseline. A 0,0 TIMEPOS is a
		// request for the rendered top-right default, so use the drawn baseline.
		if ($type == 'timestamp' && ($x <= 0 || $y <= 0)) {
			$x = intval($shape->x1);
			$y = intval($shape->y2);
		}

		$element = [
			'id'       => $name,
			'type'     => $type,
			'name'     => $key,
			'x'        => $x,
			'y'        => $y,
			'minX'     => intval($shape->x1),
			'minY'     => intval($shape->y1),
			'maxX'     => intval($shape->x2),
			'maxY'     => intval($shape->y2)
		];
		$elements[] = $element;
		$shape->extrahtml .= sprintf(
			' data-wm-type="%s" data-wm-name="%s" data-wm-x="%d" data-wm-y="%d"',
			html_escape($type),
			html_escape($key),
			$x,
			$y
		);
	}

	return $elements;
}

function getMapAreaData($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	// we need to draw and throw away a map, to get the
	// dimensions for the imagemap.
	$map->DrawMap('null');

	$map->htmlstyle = 'editor';

	$map->PreloadMapHTML();
	wmEditorMapElements($map);

	print $map->SortedImagemap('weathermap_imap');
}

function drawMap($mapfile, $selected, $use_overlay, $use_relative_overlay) {
	header('Content-type: image/png');

	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	if ($selected != '') {
		if (substr($selected, 0, 5) == 'NODE:') {
			$nodename                        = substr($selected, 5);
			$map->nodes[$nodename]->selected = 1;
		}

		if (substr($selected, 0, 5) == 'LINK:') {
			$linkname                        = substr($selected, 5);
			$map->links[$linkname]->selected = 1;
		}
	}

	$map->sizedebug = true;
	$map->DrawMap('', '', 250, true, $use_overlay, $use_relative_overlay);
}

function showConfig($mapfile) {
	header('Content-type: text/plain');

	$fd = fopen($mapfile,'r');

	while (!feof($fd)) {
		$buffer = fgets($fd, 4096);
		print $buffer;
	}

	fclose($fd);
}

function fetchConfig($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	header('Content-type: text/plain');

	$item_name = get_nfilter_request_var('item_name');
	$item_type = get_nfilter_request_var('item_type');

	$ok = false;

	if ($item_type == 'node') {
		if (isset($map->nodes[$item_name])) {
			print $map->nodes[$item_name]->WriteConfig();
			$ok = true;
		}
	}

	if ($item_type == 'link') {
		if (isset($map->links[$item_name])) {
			print $map->links[$item_name]->WriteConfig();
			$ok = true;
		}
	}

	if (!$ok) {
		print "# the request item didn't exist. That's probably a bug.\n";
	}
}

function setNodeConfig($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$node_name   = get_nfilter_request_var('node_name');
	$node_config = get_nfilter_request_var('item_configtext');

	if (isset($map->nodes[$node_name])) {
		$map->nodes[$node_name]->config_override = $node_config;

		wmEditorCommitMap($map, $mapfile);

		// now clear and reload the map object, because the in-memory one is out of sync
		// - we don't know what changes the user made here, so we just have to reload.
		unset($map);

		$map = new WeatherMap;

		$map->context = 'editor';

		$map->ReadConfig($mapfile);
	}
}

function setLinkConfig($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$link_name   = get_nfilter_request_var('link_name');
	$link_config = get_nfilter_request_var('item_configtext');

	if (isset($map->links[$link_name])) {
		$map->links[$link_name]->config_override = $link_config;

		wmEditorCommitMap($map, $mapfile);

		// now clear and reload the map object, because the in-memory one is out of sync
		// - we don't know what changes the user made here, so we just have to reload.
		unset($map);

		$map = new WeatherMap;

		$map->context = 'editor';

		$map->ReadConfig($mapfile);
	}
}

function setNodeProperties($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$node_name     = wm_editor_sanitize_name(get_nfilter_request_var('node_name'));
	$new_node_name = trim((string) get_nfilter_request_var('node_new_name'));

	if ($new_node_name == '' || $new_node_name !== wm_editor_sanitize_name($new_node_name) ||
		!preg_match('/^[A-Za-z0-9_.:-]+$/', $new_node_name) || !isset($map->nodes[$node_name])) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(422);
		print json_encode(['ok' => false, 'message' => 'Choose a non-empty node name containing only letters, numbers, dots, colons, underscores, or hyphens.']);
		exit;
	}

	if ($node_name != $new_node_name && wmEditorIncludedObjectsReferenceNode($map, $node_name)) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(422);
		print json_encode(['ok' => false, 'message' => 'This node is referenced by an included configuration and cannot be renamed here.']);
		exit;
	}

	// first check if there's a rename...
	if ($node_name != $new_node_name && strpos($new_node_name, ' ') === false) {
		if (!isset($map->nodes[$new_node_name])) {
			// we need to rename the node first.
			$newnode                    = $map->nodes[$node_name];
			$newnode->name              = $new_node_name;
			$map->nodes[$new_node_name] = $newnode;

			unset($map->nodes[$node_name]);

			// find the references elsewhere to the old node name.
			// First, relatively-positioned NODEs
			foreach ($map->nodes as $node) {
				if ($node->relative_to == $node_name) {
					$map->nodes[$node->name]->relative_to = $new_node_name;
				}
			}

			// Next, LINKs that use this NODE as an end.
			foreach ($map->links as $link) {
				if (isset($link->a)) {
					if ($link->a->name == $node_name) {
						$map->links[$link->name]->a = $newnode;
					}

					if ($link->b->name == $node_name) {
						$map->links[$link->name]->b = $newnode;
					}

					// while we're here, VIAs can also be relative to a NODE,
					// so check if any of those need to change
					if ((count($link->vialist) > 0)) {
						$vv = 0;

						foreach ($link->vialist as $v) {
							if (isset($v[2]) && $v[2] == $node_name) {
								// die PHP4, die!
								$map->links[$link->name]->vialist[$vv][2] = $new_node_name;
							}

							$vv++;
						}
					}
				}
			}
		} else {
			// silently ignore attempts to rename a node to an existing name
			$new_node_name = $node_name;
		}
	}

	// by this point, and renaming has been done, and new_node_name will always be the right name
	$map->nodes[$new_node_name]->label       = wm_editor_sanitize_string(get_nfilter_request_var('node_label'));
	$map->nodes[$new_node_name]->infourl[IN] = wm_editor_sanitize_string(get_nfilter_request_var('node_infourl'));

	$urls = preg_split('/\s+/', trim(get_nfilter_request_var('node_hover')), -1, PREG_SPLIT_NO_EMPTY);

	$map->nodes[$new_node_name]->overliburl[IN]  = $urls;
	$map->nodes[$new_node_name]->overliburl[OUT] = $urls;

	if (get_nfilter_request_var('node_iconfilename') == '--NONE--') {
		$map->nodes[$new_node_name]->iconfile = '';
	} elseif (get_nfilter_request_var('node_iconfilename') == '--AICON--') {
		// $map->nodes[$new_node_name]->iconfile = '--AICON--';
	} else {
		$iconfile                             = stripslashes(get_nfilter_request_var('node_iconfilename'));
		$map->nodes[$new_node_name]->iconfile = $iconfile;
	}

	wmEditorCommitMap($map, $mapfile);
}

function wmEditorIncludedObjectsReferenceNode(&$map, $node_name) {
	foreach ($map->nodes as $candidate) {
		if ($candidate->defined_in != $map->configfile && $candidate->relative_to == $node_name) {
			return true;
		}
	}

	foreach ($map->links as $link) {
		if ($link->defined_in == $map->configfile) {
			continue;
		}

		if ((isset($link->a) && $link->a->name == $node_name) ||
			(isset($link->b) && $link->b->name == $node_name)) {
			return true;
		}

		foreach ($link->vialist as $via) {
			if (isset($via[2]) && $via[2] == $node_name) {
				return true;
			}
		}
	}

	return false;
}

function setLinkProperties($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$link_name = get_nfilter_request_var('link_name');

	if (strpos($link_name, ' ') === false) {
		$map->links[$link_name]->width        = floatval(get_nfilter_request_var('link_width'));
		$map->links[$link_name]->infourl[IN]  = wm_editor_sanitize_string(get_nfilter_request_var('link_infourl'));
		$map->links[$link_name]->infourl[OUT] = wm_editor_sanitize_string(get_nfilter_request_var('link_infourl'));

		$urls = preg_split('/\s+/', get_nfilter_request_var('link_hover'), -1, PREG_SPLIT_NO_EMPTY);

		$map->links[$link_name]->overliburl[IN]  = $urls;
		$map->links[$link_name]->overliburl[OUT] = $urls;

		$map->links[$link_name]->comments[IN]      = wm_editor_sanitize_string(get_nfilter_request_var('link_commentin'));
		$map->links[$link_name]->comments[OUT]     = wm_editor_sanitize_string(get_nfilter_request_var('link_commentout'));
		$map->links[$link_name]->commentoffset_in  = intval(get_nfilter_request_var('link_commentposin'));
		$map->links[$link_name]->commentoffset_out = intval(get_nfilter_request_var('link_commentposout'));

		$targets         = preg_split('/\s+/', trim(get_nfilter_request_var('link_target')), -1, PREG_SPLIT_NO_EMPTY);
		$new_target_list = [];

		foreach ($targets as $target) {
			// we store the original TARGET string, and line number, along with the breakdown, to make nicer error messages later
			$newtarget = [$target, 'traffic_in', 'traffic_out', 0, $target];

			// if it's an RRD file, then allow for the user to specify the
			// DSs to be used. The default is traffic_in, traffic_out, which is
			// OK for Cacti (most of the time), but if you have other RRDs...
			if (preg_match('/(.*\.rrd):([\-a-zA-Z0-9_]+):([\-a-zA-Z0-9_]+)$/i', $target, $matches)) {
				$newtarget[0] = trim($matches[1]);
				$newtarget[1] = trim($matches[2]);
				$newtarget[2] = trim($matches[3]);
			}

			// now we've (maybe) messed with it, we'll store the array of target specs
			$new_target_list[] = $newtarget;
		}

		$map->links[$link_name]->targets = $new_target_list;

		$bwin  = get_nfilter_request_var('link_bandwidth_in');
		$bwout = get_nfilter_request_var('link_bandwidth_out');

		if (isset_request_var('link_bandwidth_out_cb') && get_nfilter_request_var('link_bandwidth_out_cb') == 'symmetric') {
			$bwout = $bwin;
		}

		if (wm_editor_validate_bandwidth($bwin)) {
			$map->links[$link_name]->max_bandwidth_in_cfg = $bwin;
			$map->links[$link_name]->max_bandwidth_in     = unformat_number($bwin, $map->kilo);
		}

		if (wm_editor_validate_bandwidth($bwout)) {
			$map->links[$link_name]->max_bandwidth_out_cfg = $bwout;
			$map->links[$link_name]->max_bandwidth_out     = unformat_number($bwout, $map->kilo);
		}

		$viastyle = get_nfilter_request_var('viastyle');

		if (!wm_editor_validate_one_of($viastyle, array('curved', 'angled'))) {
			$viastyle = $map->links[$link_name]->viastyle;
		}

		$map->links[$link_name]->viastyle = $viastyle;

		// $map->links[$link_name]->SetBandwidth($bwin,$bwout);

		wmEditorCommitMap($map, $mapfile);
	}
}

function setMapProperties($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$map->title              = wm_editor_sanitize_string(get_nfilter_request_var('map_title'));
	$map->keytext['DEFAULT'] = wm_editor_sanitize_string(get_nfilter_request_var('map_legend'));
	$map->stamptext          = wm_editor_sanitize_string(get_nfilter_request_var('map_stamp'));

	$map->htmloutputfile  = wm_editor_sanitize_file(get_nfilter_request_var('map_htmlfile'), ['html']);
	$map->imageoutputfile = wm_editor_sanitize_file(get_nfilter_request_var('map_pngfile'), ['png', 'jpg', 'gif', 'jpeg']);

	$map->width  = intval(get_nfilter_request_var('map_width'));
	$map->height = intval(get_nfilter_request_var('map_height'));

	// XXX sanitise this a bit
	if (get_nfilter_request_var('map_bgfile') == '--NONE--') {
		$map->background = '';
	} else {
		$map->background = wm_editor_sanitize_file(stripslashes(get_nfilter_request_var('map_bgfile')), ['png', 'jpg', 'gif', 'jpeg']);
	}

	$inheritables = [
		['link', 'width', 'map_linkdefaultwidth', 'float']
	];

	handle_inheritance($map, $inheritables);

	$map->links['DEFAULT']->width = intval(get_nfilter_request_var('map_linkdefaultwidth'));

	$map->links['DEFAULT']->add_note('my_width', get_filter_request_var('map_linkdefaultwidth'));

	$bwin  = get_nfilter_request_var('map_linkdefaultbwin');
	$bwout = get_nfilter_request_var('map_linkdefaultbwout');

	$bwin_old  = $map->links['DEFAULT']->max_bandwidth_in_cfg;
	$bwout_old = $map->links['DEFAULT']->max_bandwidth_out_cfg;

	if (!wm_editor_validate_bandwidth($bwin)) {
		$bwin = $bwin_old;
	}

	if (! wm_editor_validate_bandwidth($bwout)) {
		$bwout = $bwout_old;
	}

	if (($bwin_old != $bwin) || ($bwout_old != $bwout)) {
		$map->links['DEFAULT']->max_bandwidth_in_cfg  = $bwin;
		$map->links['DEFAULT']->max_bandwidth_out_cfg = $bwout;
		$map->links['DEFAULT']->max_bandwidth_in      = unformat_number($bwin, $map->kilo);
		$map->links['DEFAULT']->max_bandwidth_out     = unformat_number($bwout, $map->kilo);

		// $map->defaultlink->SetBandwidth($bwin,$bwout);
		foreach ($map->links as $link) {
			if (($link->max_bandwidth_in_cfg == $bwin_old) || ($link->max_bandwidth_out_cfg == $bwout_old)) {
				// $link->SetBandwidth($bwin,$bwout);
				$link_name = $link->name;

				$map->links[$link_name]->max_bandwidth_in_cfg  = $bwin;
				$map->links[$link_name]->max_bandwidth_out_cfg = $bwout;
				$map->links[$link_name]->max_bandwidth_in      = unformat_number($bwin, $map->kilo);
				$map->links[$link_name]->max_bandwidth_out     = unformat_number($bwout, $map->kilo);
			}
		}
	}

	wmEditorCommitMap($map, $mapfile);

	db_execute_prepared('UPDATE weathermap_maps
		SET titlecache = ?
		WHERE configfile = ?',
		[$map->title, basename($mapfile)]);
}

function setMapStyle($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	if (wm_editor_validate_one_of(get_nfilter_request_var('mapstyle_htmlstyle'), ['static', 'overlib'], false)) {
		$map->htmlstyle = strtolower(get_nfilter_request_var('mapstyle_htmlstyle'));
	}

	$map->keyfont             = get_filter_request_var('mapstyle_legendfont');
	$map->keystyle['DEFAULT'] = get_nfilter_request_var('mapstyle_keystyle');

	$inheritables = [
		['link', 'labelstyle',    'mapstyle_linklabels', ''],
		['link', 'bwfont',        'mapstyle_linkfont',   'int'],
		['link', 'overlibwidth',  'mapstyle_linkwidth',  'int'],
		['link', 'overlibheight', 'mapstyle_linkheight', 'int'],
		['link', 'arrowstyle',    'mapstyle_arrowstyle', ''],

		['node', 'labelfont',     'mapstyle_nodefont',   'int'],
		['node', 'overlibwidth',  'mapstyle_nodewidth',  'int'],
		['node', 'overlibheight', 'mapstyle_nodeheight', 'int'],
	];

	handle_inheritance($map, $inheritables);

	wmEditorCommitMap($map, $mapfile);
}

function addLink($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$a = get_nfilter_request_var('param');
	$b = get_nfilter_request_var('param2');

	$log = "[$a -> $b]";

	if ($a != $b && isset($map->nodes[$a]) && isset($map->nodes[$b])) {
		$newlink = new WeatherMapLink;

		$newlink->Reset($map);

		$newlink->a = $map->nodes[$a];
		$newlink->b = $map->nodes[$b];

		// $newlink->SetBandwidth($map->defaultlink->max_bandwidth_in_cfg, $map->defaultlink->max_bandwidth_out_cfg);

		$newlink->width = $map->links['DEFAULT']->width;

		// make sure the link name is unique. We can have multiple links between
		// the same nodes, these days
		$newlinkname = "$a-$b";

		while (array_key_exists($newlinkname,$map->links)) {
			$newlinkname .= 'a';
		}

		$newlink->name            = $newlinkname;
		$newlink->defined_in      = $map->configfile;
		$map->links[$newlinkname] = $newlink;
		array_push($map->seen_zlayers[$newlink->zorder], $newlink);

		wmEditorCommitMap($map, $mapfile);
	}
}

function wmEditorConfigRevision($mapfile) {
	if (!is_file($mapfile) || !is_readable($mapfile)) {
		return '';
	}

	$revision = hash_file('sha256', $mapfile);

	return ($revision === false ? '' : $revision);
}

function wmEditorMoveNodeOnMap(&$map, $node_name, $x, $y) {
	if (!isset($map->nodes[$node_name]) || is_null($map->nodes[$node_name]->x)) {
		return false;
	}

	$node = $map->nodes[$node_name];

	// Quantize relative coordinates first so link geometry follows the position that WriteConfig can represent.
	if ($node->relative_to != '' && isset($map->nodes[$node->relative_to])) {
		$anchor = $map->nodes[$node->relative_to];
		$dx     = $x - $anchor->x;
		$dy     = $y - $anchor->y;

		if ($node->polar) {
			$angle = intval(round(rad2deg(atan2($dx, -$dy))));

			if ($angle < 0) {
				$angle += 360;
			}

			$distance        = intval(round(sqrt($dx * $dx + $dy * $dy)));
			$node->original_x = $angle;
			$node->original_y = $distance;
			$x                = $anchor->x + $distance * sin(deg2rad($angle));
			$y                = $anchor->y - $distance * cos(deg2rad($angle));
		} else {
			$node->original_x = intval(round($dx));
			$node->original_y = intval(round($dy));
			$x                = $anchor->x + $node->original_x;
			$y                = $anchor->y + $node->original_y;
		}
	}

	$delta_x     = $x - $map->nodes[$node_name]->x;
	$delta_y     = $y - $map->nodes[$node_name]->y;
	$moved_nodes = [$node_name => true];
	$pending     = [$node_name];

	// Relative descendants follow their parent and are part of the same logical move.
	while (count($pending) > 0) {
		$parent = array_shift($pending);

		foreach ($map->nodes as $candidate_name => $candidate) {
			if (!isset($moved_nodes[$candidate_name]) && $candidate->relative_to == $parent) {
				$moved_nodes[$candidate_name] = true;
				$pending[]                    = $candidate_name;
			}
		}
	}

	// VIA points follow every moved endpoint. Links wholly inside the moved subtree translate.
	foreach ($map->links as $link) {
		if (!isset($link->a) || !isset($link->b) || count($link->vialist) == 0) {
			continue;
		}

		$a_moved = isset($moved_nodes[$link->a->name]);
		$b_moved = isset($moved_nodes[$link->b->name]);

		if (!$a_moved && !$b_moved) {
			continue;
		}

		if ($a_moved && $b_moved) {
			for ($i = 0; $i < count($link->vialist); $i++) {
				if (!isset($link->vialist[$i][2])) {
					$link->vialist[$i][0] += $delta_x;
					$link->vialist[$i][1] += $delta_y;
				}
			}

			continue;
		}

		$moved = ($a_moved ? $link->a : $link->b);
		$pivot = ($a_moved ? $link->b : $link->a);
		$pivx  = $pivot->x;
		$pivy  = $pivot->y;

		$dx_old = $pivx - $moved->x;
		$dy_old = $pivy - $moved->y;
		$dx_new = $pivx - ($moved->x + $delta_x);
		$dy_new = $pivy - ($moved->y + $delta_y);
		$l_old  = sqrt($dx_old * $dx_old + $dy_old * $dy_old);
		$l_new  = sqrt($dx_new * $dx_new + $dy_new * $dy_new);

		// Coincident endpoints do not provide an axis from which VIA geometry can be transformed.
		if ($l_old == 0) {
			continue;
		}

		$angle_old = rad2deg(atan2(-$dy_old, $dx_old));
		$angle_new = rad2deg(atan2(-$dy_new, $dx_new));
		$points    = [];

		foreach ($link->vialist as $via) {
			$points[] = $via[0];
			$points[] = $via[1];
		}

		$scalefactor = $l_new / $l_old;

		rotateAboutPoint($points, $pivx, $pivy, deg2rad($angle_old));

		for ($i = 0; $i < (count($points) / 2); $i++) {
			$points[$i * 2] = ($points[$i * 2] - $pivx) * $scalefactor + $pivx;
		}

		rotateAboutPoint($points, $pivx, $pivy, deg2rad(-$angle_new));

		$v = 0;
		$i = 0;

		foreach ($points as $point) {
			if (!isset($link->vialist[$v][2])) {
				$link->vialist[$v][$i] = $point;
			}

			$i++;

			if ($i == 2) {
				$i = 0;
				$v++;
			}
		}
	}

	foreach (array_keys($moved_nodes) as $moved_name) {
		$map->nodes[$moved_name]->x += $delta_x;
		$map->nodes[$moved_name]->y += $delta_y;
	}

	$node->x = $x;
	$node->y = $y;

	return true;
}

function wmEditorNodeMoveIsUndoable(&$map, $node_name) {
	if (!isset($map->nodes[$node_name]) || $map->nodes[$node_name]->polar) {
		return false;
	}

	$moved_nodes = [$node_name => true];
	$pending     = [$node_name];

	while (count($pending) > 0) {
		$parent = array_shift($pending);

		foreach ($map->nodes as $candidate_name => $candidate) {
			if (!isset($moved_nodes[$candidate_name]) && $candidate->relative_to == $parent) {
				$moved_nodes[$candidate_name] = true;
				$pending[]                    = $candidate_name;
			}
		}
	}

	foreach ($map->links as $link) {
		if (count($link->vialist) > 0 && isset($link->a) && isset($link->b) &&
			(isset($moved_nodes[$link->a->name]) || isset($moved_nodes[$link->b->name]))) {
			return false;
		}
	}

	return true;
}

function wmEditorReadConfigForValidation($filename, &$map, &$warnings) {
	$had_collector         = array_key_exists('weathermap_warning_collector', $GLOBALS);
	$had_collect_only      = array_key_exists('weathermap_warning_collect_only', $GLOBALS);
	$had_error_suppress    = array_key_exists('weathermap_error_suppress', $GLOBALS);
	$previous_collector    = $had_collector ? $GLOBALS['weathermap_warning_collector'] : null;
	$previous_collect_only = $had_collect_only ? $GLOBALS['weathermap_warning_collect_only'] : null;
	$previous_suppress     = $had_error_suppress ? $GLOBALS['weathermap_error_suppress'] : null;
	$GLOBALS['weathermap_warning_collector']  = [];
	$GLOBALS['weathermap_warning_collect_only'] = true;

	try {
		$map          = new WeatherMap;
		$map->context = 'editor';
		$result       = $map->ReadConfig($filename);
		$warnings     = $GLOBALS['weathermap_warning_collector'];
	} finally {
		if ($had_collector) {
			$GLOBALS['weathermap_warning_collector'] = $previous_collector;
		} else {
			unset($GLOBALS['weathermap_warning_collector']);
		}

		if ($had_collect_only) {
			$GLOBALS['weathermap_warning_collect_only'] = $previous_collect_only;
		} else {
			unset($GLOBALS['weathermap_warning_collect_only']);
		}

		if ($had_error_suppress) {
			$GLOBALS['weathermap_error_suppress'] = $previous_suppress;
		} else {
			unset($GLOBALS['weathermap_error_suppress']);
		}
	}

	return $result;
}

function wmEditorValidationFingerprint($message) {
	$message = preg_replace('/\bline\s+\(?\d+\)?/i', 'line #', trim($message));

	return preg_replace('/\s+/', ' ', $message);
}

function wmEditorValidationHasNewItems($candidate, $baseline, $normalise = false) {
	$available = [];

	foreach ($baseline as $item) {
		$key = $normalise ? wmEditorValidationFingerprint($item) : $item;
		$available[$key] = isset($available[$key]) ? $available[$key] + 1 : 1;
	}

	foreach ($candidate as $item) {
		$key = $normalise ? wmEditorValidationFingerprint($item) : $item;

		if (!isset($available[$key]) || $available[$key] == 0) {
			return true;
		}

		$available[$key]--;
	}

	return false;
}

function wmEditorLocalInventory(&$map) {
	$inventory = [
		'nodes' => ['count' => 0, 'required' => []],
		'links' => ['count' => 0, 'required' => []]
	];

	foreach (['nodes', 'links'] as $type) {
		foreach ($map->$type as $name => $item) {
			if (strpos($name, ':: ') === 0 || ($name != 'DEFAULT' && $item->defined_in != $map->configfile)) {
				continue;
			}

			$inventory[$type]['count']++;
			$is_normal = $type == 'nodes'
				? $item->x !== null
				: isset($item->a) && is_object($item->a) && isset($item->b) && is_object($item->b);

			// A raw object edit may deliberately rename its object. Other objects must retain their names.
			if ((string) $item->config_override === '') {
				$inventory[$type]['required'][$name] = $is_normal;
			}
		}
	}

	return $inventory;
}

function wmEditorReferenceIssues(&$map) {
	$issues = [];

	foreach ($map->nodes as $name => $node) {
		if ($node->relative_to != '' && !isset($map->nodes[$node->relative_to])) {
			$issues[] = "node-relative:$name:$node->relative_to";
		}

		if ($node->template != '' && $node->template != 'DEFAULT' && $node->template != ':: DEFAULT ::' &&
			!isset($map->nodes[$node->template])) {
			$issues[] = "node-template:$name:$node->template";
		}
	}

	foreach ($map->links as $name => $link) {
		$has_a = isset($link->a) && is_object($link->a);
		$has_b = isset($link->b) && is_object($link->b);

		if ($has_a != $has_b) {
			$issues[] = "link-endpoints:$name";
		}

		foreach (['a', 'b'] as $endpoint) {
			if (isset($link->$endpoint) && is_object($link->$endpoint) && !isset($map->nodes[$link->$endpoint->name])) {
				$issues[] = "link-$endpoint:$name:" . $link->$endpoint->name;
			}
		}

		if ($link->template != '' && $link->template != 'DEFAULT' && $link->template != ':: DEFAULT ::' &&
			!isset($map->links[$link->template])) {
			$issues[] = "link-template:$name:$link->template";
		}

		foreach ($link->vialist as $via) {
			if (isset($via[2]) && !isset($map->nodes[$via[2]])) {
				$issues[] = "link-via:$name:$via[2]";
			}
		}
	}

	return $issues;
}

function wmEditorSerializedMapIsValid(&$source_map, $mapfile, $candidate_file, &$parsed_map) {
	$candidate_warnings = [];

	if (!wmEditorReadConfigForValidation($candidate_file, $parsed_map, $candidate_warnings)) {
		return false;
	}

	$expected = wmEditorLocalInventory($source_map);
	$actual   = wmEditorLocalInventory($parsed_map);

	foreach (['nodes', 'links'] as $type) {
		if ($actual[$type]['count'] != $expected[$type]['count']) {
			return false;
		}

		foreach ($expected[$type]['required'] as $name => $is_normal) {
			if (!array_key_exists($name, $actual[$type]['required']) ||
				$actual[$type]['required'][$name] !== $is_normal) {
				return false;
			}
		}
	}

	$candidate_issues = wmEditorReferenceIssues($parsed_map);

	if (count($candidate_warnings) > 0 || count($candidate_issues) > 0) {
		$baseline          = null;
		$baseline_warnings = [];

		if (!wmEditorReadConfigForValidation($mapfile, $baseline, $baseline_warnings) ||
			wmEditorValidationHasNewItems($candidate_warnings, $baseline_warnings, true) ||
			wmEditorValidationHasNewItems($candidate_issues, wmEditorReferenceIssues($baseline))) {
			return false;
		}
	}

	return true;
}

function wmEditorMapElementPositionMatches(&$map, $expected_position) {
	if (!is_array($expected_position) || !isset($expected_position['type'], $expected_position['name'],
		$expected_position['x'], $expected_position['y'])) {
		return true;
	}

	$name = $expected_position['name'];
	$x    = intval($expected_position['x']);
	$y    = intval($expected_position['y']);

	if ($expected_position['type'] == 'legend') {
		return array_key_exists($name, $map->keyx) && array_key_exists($name, $map->keyy) &&
			intval($map->keyx[$name]) === $x && intval($map->keyy[$name]) === $y;
	}

	if ($expected_position['type'] != 'timestamp') {
		return false;
	}

	if ($name == 'MIN') {
		return intval($map->mintimex) === $x && intval($map->mintimey) === $y;
	}

	if ($name == 'MAX') {
		return intval($map->maxtimex) === $x && intval($map->maxtimey) === $y;
	}

	return $name == 'CURRENT' && intval($map->timex) === $x && intval($map->timey) === $y;
}

function wmEditorWriteConfigAtomically(&$map, $mapfile, $node_name, &$saved_map, &$error, $expected_position = null) {
	if (is_link($mapfile)) {
		$error = 'Symbolic-link map configurations cannot be replaced by the editor.';
		return false;
	}

	$metadata = @stat($mapfile);

	if ($metadata === false) {
		$error = 'Unable to inspect the map configuration metadata.';
		return false;
	}

	$tempfile = tempnam(dirname($mapfile), '.weathermap-save-');

	if ($tempfile === false) {
		$error = 'Unable to create a temporary configuration file.';
		return false;
	}

	if (!$map->WriteConfig($tempfile)) {
		@unlink($tempfile);
		$error = 'Unable to write the updated configuration.';
		return false;
	}

	$verify = null;

	if (!wmEditorSerializedMapIsValid($map, $mapfile, $tempfile, $verify) ||
		($node_name != '' && !isset($verify->nodes[$node_name]))) {
		@unlink($tempfile);
		$error = 'The updated configuration did not pass validation.';
		return false;
	}

	// INCLUDE directives can appear after locally-written KEYPOS/TIMEPOS lines and
	// silently restore an older value. Confirm the serialized candidate resolves
	// to the requested position before it can replace the active configuration.
	if (!wmEditorMapElementPositionMatches($verify, $expected_position)) {
		@unlink($tempfile);
		$error = 'The requested position is overridden by an included configuration.';
		return false;
	}

	$target_uid  = intval($metadata['uid']);
	$target_gid  = intval($metadata['gid']);
	$target_mode = intval($metadata['mode']) & 0777;
	$temp_uid    = fileowner($tempfile);
	$temp_gid    = filegroup($tempfile);

	if ($temp_uid !== $target_uid && (!function_exists('chown') || !@chown($tempfile, $target_uid))) {
		@unlink($tempfile);
		$error = 'Unable to preserve the map configuration owner.';
		return false;
	}

	if ($temp_gid !== $target_gid && (!function_exists('chgrp') || !@chgrp($tempfile, $target_gid))) {
		@unlink($tempfile);
		$error = 'Unable to preserve the map configuration group.';
		return false;
	}

	if (!@chmod($tempfile, $target_mode)) {
		@unlink($tempfile);
		$error = 'Unable to preserve the map configuration permissions.';
		return false;
	}

	clearstatcache(true, $tempfile);

	if (fileowner($tempfile) !== $target_uid || filegroup($tempfile) !== $target_gid ||
		(fileperms($tempfile) & 0777) !== $target_mode) {
		@unlink($tempfile);
		$error = 'The map configuration metadata could not be preserved.';
		return false;
	}

	if (!rename($tempfile, $mapfile)) {
		@unlink($tempfile);
		$error = 'Unable to replace the active configuration.';
		return false;
	}

	clearstatcache(true, $mapfile);

	$saved_warnings = [];

	if (!wmEditorReadConfigForValidation($mapfile, $saved_map, $saved_warnings) ||
		($node_name != '' && !isset($saved_map->nodes[$node_name])) ||
		!wmEditorMapElementPositionMatches($saved_map, $expected_position)) {
		$error = 'The saved configuration could not be reloaded.';
		return false;
	}

	return true;
}

function wmEditorSaveMapAtomically(&$map, $mapfile, &$error, $expected_position = null) {
	$saved_map = null;

	return wmEditorWriteConfigAtomically($map, $mapfile, '', $saved_map, $error, $expected_position);
}

function wmEditorCommitMap(&$map, $mapfile) {
	$error = '';

	if (!wmEditorSaveMapAtomically($map, $mapfile, $error)) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code($error == 'The updated configuration did not pass validation.' ? 422 : 500);
		print json_encode([
			'ok'      => false,
			'message' => ($error == '' ? 'The map configuration could not be saved.' : $error)
		]);
		exit;
	}

	return true;
}

function saveNodePosition($mapfile, $grid_snap_value) {
	if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') {
		return ['ok' => false, 'status' => 405, 'message' => 'Node positions can only be saved with POST.'];
	}

	$node_name         = wm_editor_sanitize_name(get_nfilter_request_var('node_name'));
	$expected_revision = strtolower(trim(get_nfilter_request_var('revision')));

	if ($node_name == '') {
		return ['ok' => false, 'status' => 400, 'message' => 'A valid node name is required.'];
	}

	if (!preg_match('/^[a-f0-9]{64}$/', $expected_revision)) {
		return ['ok' => false, 'status' => 400, 'message' => 'Reload the editor before moving this node.'];
	}

	if (!isset_request_var('x') || !isset_request_var('y')) {
		return ['ok' => false, 'status' => 400, 'message' => 'Both node coordinates are required.'];
	}

	$x_input = trim((string) get_nfilter_request_var('x'));
	$y_input = trim((string) get_nfilter_request_var('y'));

	if (!preg_match('/^-?\d+$/', $x_input) || !preg_match('/^-?\d+$/', $y_input)) {
		return ['ok' => false, 'status' => 400, 'message' => 'Node coordinates must be whole numbers.'];
	}

	$lock = @fopen(wmEditorLockFile($mapfile), 'c');

	if ($lock === false || !flock($lock, LOCK_EX)) {
		if ($lock !== false) {
			fclose($lock);
		}

		return ['ok' => false, 'status' => 503, 'message' => 'The map is busy and could not be locked.'];
	}

	clearstatcache(true, $mapfile);

	if (!wmEditorMapIsEditable($mapfile)) {
		flock($lock, LOCK_UN);
		fclose($lock);

		return ['ok' => false, 'status' => 403, 'message' => 'This map configuration is read-only and cannot be moved.'];
	}

	$current_revision = wmEditorConfigRevision($mapfile);

	if (!hash_equals($current_revision, $expected_revision)) {
		flock($lock, LOCK_UN);
		fclose($lock);

		return [
			'ok'       => false,
			'status'   => 409,
			'conflict' => true,
			'revision' => $current_revision,
			'message'  => 'The map changed in another editor. Reload it before moving this node.'
		];
	}

	$map          = new WeatherMap;
	$map->context = 'editor';

	if (!$map->ReadConfig($mapfile)) {
		flock($lock, LOCK_UN);
		fclose($lock);

		return ['ok' => false, 'status' => 500, 'message' => 'The map configuration could not be loaded.'];
	}

	if (!isset($map->nodes[$node_name]) || is_null($map->nodes[$node_name]->x)) {
		flock($lock, LOCK_UN);
		fclose($lock);

		return ['ok' => false, 'status' => 404, 'message' => 'The selected node no longer exists.'];
	}

	if ($map->nodes[$node_name]->defined_in != $map->configfile) {
		flock($lock, LOCK_UN);
		fclose($lock);

		return ['ok' => false, 'status' => 422, 'message' => 'This node is defined in an included configuration and cannot be moved here.'];
	}

	$maximum_width  = intval($map->width);
	$maximum_height = intval($map->height);

	// DrawMap adopts the background's native dimensions. Match that boundary before rendering.
	if ($map->background != '' && is_readable($map->background)) {
		$dimensions = @getimagesize($map->background);

		if ($dimensions !== false) {
			$maximum_width  = intval($dimensions[0]);
			$maximum_height = intval($dimensions[1]);
		}
	}

	$x = snap(intval($x_input), $grid_snap_value);
	$y = snap(intval($y_input), $grid_snap_value);
	$x = max(0, min($maximum_width, $x));
	$y = max(0, min($maximum_height, $y));

	$undoable = wmEditorNodeMoveIsUndoable($map, $node_name);

	if (!wmEditorMoveNodeOnMap($map, $node_name, $x, $y)) {
		flock($lock, LOCK_UN);
		fclose($lock);

		return ['ok' => false, 'status' => 422, 'message' => 'The selected node could not be moved.'];
	}

	$saved_map = null;
	$error     = '';

	if (!wmEditorWriteConfigAtomically($map, $mapfile, $node_name, $saved_map, $error)) {
		flock($lock, LOCK_UN);
		fclose($lock);

		return ['ok' => false, 'status' => 500, 'message' => $error];
	}

	$revision = wmEditorConfigRevision($mapfile);
	$node     = $saved_map->nodes[$node_name];

	flock($lock, LOCK_UN);
	fclose($lock);

	return [
		'ok'       => true,
		'status'   => 200,
		'node'     => $node_name,
		'x'        => $node->x + 0,
		'y'        => $node->y + 0,
		'undoable' => $undoable,
		'revision' => $revision,
		'message'  => sprintf('Saved %s at %d, %d.', $node_name, $node->x, $node->y)
	];
}

function saveMapElementPosition($mapfile, $grid_snap_value) {
	if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') {
		return ['ok' => false, 'status' => 405, 'message' => 'Map elements can only be moved with POST.'];
	}

	$type              = strtolower(trim((string) get_nfilter_request_var('element_type')));
	$name              = wm_editor_sanitize_name(get_nfilter_request_var('element_name'));
	$expected_revision = strtolower(trim((string) get_nfilter_request_var('revision')));

	if (!in_array($type, ['legend', 'timestamp'], true) || $name == '') {
		return ['ok' => false, 'status' => 400, 'message' => 'A valid map element is required.'];
	}

	if (!preg_match('/^[a-f0-9]{64}$/', $expected_revision)) {
		return ['ok' => false, 'status' => 400, 'message' => 'Reload the editor before moving this map element.'];
	}

	if (!isset_request_var('x') || !isset_request_var('y')) {
		return ['ok' => false, 'status' => 400, 'message' => 'Both map element coordinates are required.'];
	}

	$x_input = trim((string) get_nfilter_request_var('x'));
	$y_input = trim((string) get_nfilter_request_var('y'));

	if (!preg_match('/^-?\d+$/', $x_input) || !preg_match('/^-?\d+$/', $y_input)) {
		return ['ok' => false, 'status' => 400, 'message' => 'Map element coordinates must be whole numbers.'];
	}

	$lock = @fopen(wmEditorLockFile($mapfile), 'c');

	if ($lock === false || !flock($lock, LOCK_EX)) {
		if ($lock !== false) {
			fclose($lock);
		}

		return ['ok' => false, 'status' => 503, 'message' => 'The map is busy and could not be locked.'];
	}

	clearstatcache(true, $mapfile);

	if (!wmEditorMapIsEditable($mapfile)) {
		flock($lock, LOCK_UN);
		fclose($lock);
		return ['ok' => false, 'status' => 403, 'message' => 'This map configuration is read-only and cannot be moved.'];
	}

	$current_revision = wmEditorConfigRevision($mapfile);

	if (!hash_equals($current_revision, $expected_revision)) {
		flock($lock, LOCK_UN);
		fclose($lock);
		return [
			'ok'       => false,
			'status'   => 409,
			'conflict' => true,
			'revision' => $current_revision,
			'message'  => 'The map changed in another editor. Reload it before moving this element.'
		];
	}

	$map          = new WeatherMap;
	$map->context = 'editor';

	if (!$map->ReadConfig($mapfile)) {
		flock($lock, LOCK_UN);
		fclose($lock);
		return ['ok' => false, 'status' => 500, 'message' => 'The map configuration could not be loaded.'];
	}

	$render_map          = new WeatherMap;
	$render_map->context = 'editor';

	if (!$render_map->ReadConfig($mapfile)) {
		flock($lock, LOCK_UN);
		fclose($lock);
		return ['ok' => false, 'status' => 500, 'message' => 'The map configuration could not be rendered.'];
	}

	$render_map->DrawMap('null');
	$render_map->PreloadMapHTML();
	$rendered_element = null;

	foreach (wmEditorMapElements($render_map) as $element) {
		if ($element['type'] == $type && $element['name'] == $name) {
			$rendered_element = $element;
			break;
		}
	}

	if ($rendered_element === null) {
		flock($lock, LOCK_UN);
		fclose($lock);
		return ['ok' => false, 'status' => 404, 'message' => 'The selected map element is not currently rendered.'];
	}

	$minimum_x = max(0, -($rendered_element['minX'] - $rendered_element['x']));
	$minimum_y = max(0, -($rendered_element['minY'] - $rendered_element['y']));
	$maximum_x = min(intval($render_map->width), intval($render_map->width) - ($rendered_element['maxX'] - $rendered_element['x']));
	$maximum_y = min(intval($render_map->height), intval($render_map->height) - ($rendered_element['maxY'] - $rendered_element['y']));
	$x = snap(intval($x_input), $grid_snap_value);
	$y = snap(intval($y_input), $grid_snap_value);
	$x = max($minimum_x, min($maximum_x, $x));
	$y = max($minimum_y, min($maximum_y, $y));

	if ($type == 'legend') {
		if (!array_key_exists($name, $map->keyx) || !array_key_exists($name, $map->keyy)) {
			flock($lock, LOCK_UN);
			fclose($lock);
			return ['ok' => false, 'status' => 404, 'message' => 'The selected legend no longer exists.'];
		}

		$map->keyx[$name] = $x;
		$map->keyy[$name] = $y;
		$label            = $name == 'DEFAULT' ? 'legend' : 'legend ' . $name;
	} else {
		if (!in_array($name, ['CURRENT', 'MIN', 'MAX'], true)) {
			flock($lock, LOCK_UN);
			fclose($lock);
			return ['ok' => false, 'status' => 404, 'message' => 'The selected timestamp no longer exists.'];
		}

		// A zero in either TIMEPOS coordinate means automatic placement. Once the
		// user drags it, keep it explicitly positioned inside the map.
		$x = max(1, $x);
		$y = max(1, $y);

		if ($name == 'MIN') {
			$map->mintimex = $x;
			$map->mintimey = $y;
		} elseif ($name == 'MAX') {
			$map->maxtimex = $x;
			$map->maxtimey = $y;
		} else {
			$map->timex = $x;
			$map->timey = $y;
		}

		$label = $name == 'CURRENT' ? 'timestamp' : strtolower($name) . ' timestamp';
	}

	$error = '';

	$expected_position = [
		'type' => $type,
		'name' => $name,
		'x'    => $x,
		'y'    => $y
	];

	if (!wmEditorSaveMapAtomically($map, $mapfile, $error, $expected_position)) {
		flock($lock, LOCK_UN);
		fclose($lock);
		$status = $error == 'The requested position is overridden by an included configuration.' ? 422 : 500;
		return ['ok' => false, 'status' => $status, 'message' => $error];
	}

	$revision = wmEditorConfigRevision($mapfile);
	flock($lock, LOCK_UN);
	fclose($lock);

	return [
		'ok'       => true,
		'status'   => 200,
		'type'     => $type,
		'name'     => $name,
		'x'        => $x,
		'y'        => $y,
		'revision' => $revision,
		'message'  => sprintf('Saved %s at %d, %d.', $label, $x, $y)
	];
}

function linkTidy($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$target = wm_editor_sanitize_name(get_nfilter_request_var('param'));

	if (isset($map->links[$target])) {
		// draw a map and throw it away, to calculate all the bounding boxes
		$map->DrawMap('null');

		tidy_link($map, $target);

		wmEditorCommitMap($map, $mapfile);
	}
}

function reTidy($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	// draw a map and throw it away, to calculate all the bounding boxes
	$map->DrawMap('null');
	retidy_links($map);

	wmEditorCommitMap($map, $mapfile);
}

function reTidyAll($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	// draw a map and throw it away, to calculate all the bounding boxes
	$map->DrawMap('null');
	retidy_links($map,true);

	wmEditorCommitMap($map, $mapfile);
}

function unTidy($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	// draw a map and throw it away, to calculate all the bounding boxes
	$map->DrawMap('null');
	untidy_links($map);

	wmEditorCommitMap($map, $mapfile);
}

function deleteLink($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$target = wm_editor_sanitize_name(get_nfilter_request_var('param'));
	$log    = 'delete link ' . $target;

	if (isset($map->links[$target])) {
		unset($map->links[$target]);

		wmEditorCommitMap($map, $mapfile);
	}
}

function addNode($mapfile, $grid_snap_value) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$x = snap(intval(get_nfilter_request_var('x')), $grid_snap_value);
	$y = snap(intval(get_nfilter_request_var('y')), $grid_snap_value);

	$map->ReadConfig($mapfile);

	$newnodename = sprintf('node%05d', time() % 10000);

	while (array_key_exists($newnodename,$map->nodes)) {
		$newnodename .= 'a';
	}

	$node = new WeatherMapNode;

	$node->name     = $newnodename;
	$node->template = 'DEFAULT';
	$node->Reset($map);

	$node->x          = $x;
	$node->y          = $y;
	$node->defined_in = $map->configfile;

	array_push($map->seen_zlayers[$node->zorder], $node);

	// only insert a label if there's no LABEL in the DEFAULT node.
	// otherwise, respect the template.
	if ($map->nodes['DEFAULT']->label == $map->nodes[':: DEFAULT ::']->label) {
		$node->label = 'Node';
	}

	$map->nodes[$node->name] = $node;
	$log                     = "added a node called $newnodename at $x,$y to $mapfile";

	wmEditorCommitMap($map, $mapfile);
}

function editorSettings($mapfile) {
	global $use_overlay, $use_relative_overlay, $grid_snap_value;

	$map = new WeatherMap;

	$map->context = 'editor';

	// have to do this, otherwise the editor will be unresponsive afterwards - not actually going to change anything!
	$map->ReadConfig($mapfile);

	$use_overlay          = (isset_request_var('editorsettings_showvias') ? intval(get_nfilter_request_var('editorsettings_showvias')) : false);
	$use_relative_overlay = (isset_request_var('editorsettings_showrelative') ? intval(get_nfilter_request_var('editorsettings_showrelative')) : false);
	$grid_snap_value      = (isset_request_var('editorsettings_gridsnap') ? intval(get_nfilter_request_var('editorsettings_gridsnap')) : 0);
}

function deleteNode($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$target = wm_editor_sanitize_name(get_nfilter_request_var('param'));

	if (wmEditorIncludedObjectsReferenceNode($map, $target)) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(422);
		print json_encode(['ok' => false, 'message' => 'This node is referenced by an included configuration and cannot be deleted here.']);
		exit;
	}

	if (isset($map->nodes[$target])) {
		$log = 'delete node ' . $target;

		foreach ($map->links as $link) {
			if (isset($link->a)) {
				if (($target == $link->a->name) || ($target == $link->b->name)) {
					unset($map->links[$link->name]);
				}
			}
		}

		unset($map->nodes[$target]);

		wmEditorCommitMap($map, $mapfile);
	}
}

function cloneNode($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	$target = wm_editor_sanitize_name(get_nfilter_request_var('param'));

	if (isset($map->nodes[$target])) {
		$log = 'clone node ' . $target;

		$newnodename = $target;

		do {
			$newnodename = $newnodename . '_copy';
		} while (isset($map->nodes[$newnodename]));

		$node = new WeatherMapNode;

		$node->Reset($map);
		$node->CopyFrom($map->nodes[$target]);

		// CopyFrom skips this one, because it's also the function used by template inheritance
		// - but for Clone, we DO want to copy the template too
		$node->template = $map->nodes[$target]->template;

		$node->name = $newnodename;
		$node->x += 30;
		$node->y += 30;
		$node->defined_in = $mapfile;

		$map->nodes[$newnodename] = $node;

		array_push($map->seen_zlayers[$node->zorder], $node);

		wmEditorCommitMap($map, $mapfile);
	}
}

function displayFontSamples($mapfile) {
	$map = new WeatherMap;

	$map->context = 'editor';

	$map->ReadConfig($mapfile);

	ksort($map->fonts);
	header('Content-type: image/png');

	$keyfont   = 2;
	$keyheight = imagefontheight($keyfont) + 2;

	$sampleheight = 32;
	// $im = imagecreate(250,imagefontheight(5)+5);
	$im    = imagecreate(2000,$sampleheight);
	$imkey = imagecreate(2000,$keyheight);

	$white    = imagecolorallocate($im,255,255,255);
	$black    = imagecolorallocate($im,0,0,0);
	$whitekey = imagecolorallocate($imkey,255,255,255);
	$blackkey = imagecolorallocate($imkey,0,0,0);

	$x = 3;

	foreach ($map->fonts as $fontnumber => $font) {
		$string    = 'Abc123%';
		$keystring = "Font $fontnumber";

		[$width,$height]   = $map->myimagestringsize($fontnumber, $string);
		[$kwidth,$kheight] = $map->myimagestringsize($keyfont, $keystring);

		if ($kwidth > $width) {
			$width = $kwidth;
		}

		$y = ($sampleheight / 2) + ($height / 2);

		$map->myimagestring($im, $fontnumber, $x, $y, $string, $black);
		$map->myimagestring($imkey, $keyfont, $x, $keyheight, "Font $fontnumber", $blackkey);

		$x = $x + $width + 6;
	}

	$im2 = imagecreate($x,$sampleheight + $keyheight);

	imagecopy($im2, $im, 0, 0, 0, 0, $x, $sampleheight);
	imagecopy($im2,$imkey, 0, $sampleheight, 0, 0, $x, $keyheight);

	imagedestroy($im);

	imagepng($im2);

	imagedestroy($im2);
}

function fixMapBackgroundAndImages(&$map) {
	global $config;

	// build up the editor's list of used images
	if ($map->background != '') {
		// Update the location of the backgrounds
		if (!file_exists($config['base_path'] . '/plugins/weathermap/' . $map->background)) {
			if (file_exists($config['base_path'] . '/plugins/weathermap/images/backgrounds/' . basename($map->background))) {
				$map->background = 'images/backgrounds/' . basename($map->background);
			}
		}
	}

	foreach ($map->nodes as $n) {
		if ($n->iconfile != '' && ! preg_match('/^(none|nink|inpie|outpie|box|rbox|gauge|round)$/', $n->iconfile)) {
			// Update the location of the objects
			if (!file_exists($config['base_path'] . '/plugins/weathermap/' . $n->iconfile)) {
				if (file_exists($config['base_path'] . '/plugins/weathermap/images/objects/' . basename($n->iconfile))) {
					$map->used_images[] = 'images/objects/' . basename($n->iconfile);
				}
			}

			$map->used_images[] = $n->iconfile;
		}
	}
}

function getImageURL($mapname, $selected) {
	// now we'll just draw the full editor page, with our new knowledge
	$imageurl = 'weathermap-cacti-plugin-editor.php?mapname=' . $mapname . '&action=draw';

	if ($selected != '') {
		$imageurl .= '&selected=' . wm_editor_sanitize_selected($selected);
	}

	$imageurl .= '&unique=' . time();

	return $imageurl;
}
