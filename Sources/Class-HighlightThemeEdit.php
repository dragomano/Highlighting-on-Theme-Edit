<?php

/**
 * Class-HighlightThemeEdit.php
 *
 * @package Highlight on Theme Edit
 * @link https://dragomano.ru/mods/highlighting-on-theme-edit
 * @author Bugo https://custom.simplemachines.org/mods/index.php?mod=2800
 * @copyright 2010-2019 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 *
 * @version 0.8
 */

if (!defined('SMF'))
	die('Hacking attempt...');

class HighlightThemeEdit
{
	public static function menuButtons()
	{
		global $context, $settings, $txt;

		if (!isset($_REQUEST['area']) || $_REQUEST['area'] != 'theme' || (!isset($_REQUEST['sa']) || $_REQUEST['sa'] != 'edit'))
			return;

		$style_list = glob($settings['default_theme_dir'] . "/css/codemirror/*.css");
		$context['hte_style_set'] = array();
		foreach ($style_list as $file) {
			$search = array($settings['default_theme_dir'] . "/css/codemirror/", '.css');
			$replace = array('', '');
			$file = str_replace($search, $replace, $file);
			$context['hte_style_set'][$file] = ucwords(str_replace('-', ' ', $file));
		}

		foreach ($context['hte_style_set'] as $file => $name)
			loadCSSFile('codemirror/' . $file . '.css');

		addInlineCss('.CodeMirror {max-height: 24em; font-size: 1.4em; border: 1px solid #C5C5C5} .CodeMirror-line {z-index: auto !important}');

		$context['insert_after_template'] .= '
		<script src="' . $settings['default_theme_url'] . '/scripts/codemirror/codemirror.js"></script>
		<script src="' . $settings['default_theme_url'] . '/scripts/codemirror/xml.js"></script>
		<script src="' . $settings['default_theme_url'] . '/scripts/codemirror/javascript.js"></script>
		<script src="' . $settings['default_theme_url'] . '/scripts/codemirror/css.js"></script>
		<script src="' . $settings['default_theme_url'] . '/scripts/codemirror/clike.js"></script>
		<script src="' . $settings['default_theme_url'] . '/scripts/codemirror/php.js"></script>';

		$context['insert_after_template'] .= '
		<script>
			jQuery(document).ready(function($) {
				$("textarea").parent(".centertext").css("text-align", "left");
				$("input[name=save]").before(\'<div class="floatleft">' . $txt['theme'] . ' <select id="hteThemeChanger" onchange="selectTheme(this.value)">';

		foreach ($context['hte_style_set'] as $file => $name)
			$context['insert_after_template'] .= '<option value="' . $file . '">' . $name . '</option>';

		$context['insert_after_template'] .= '</select></div>\');
				var data = localStorage.getItem("hteTheme");
				if (data !== null) {
					$("#hteThemeChanger").val(data);
					editor.setOption("theme", data);
				} else {
					$("#hteThemeChanger option[value=\'codemirror\']").attr("selected", "selected");
				}
			});';

		if (!empty($context['sub_template'])) {
			if ($context['sub_template'] == 'edit_file' || $context['sub_template'] == 'edit_style') {
				$filename = isset($_GET['filename']) ? explode('/', $_GET['filename'])[0] : 'css';

				$context['insert_after_template'] .= '
			var editor = CodeMirror.fromTextArea(document.getElementsByName("entire_file")[0], {
				lineNumbers: true,
				mode: "text/' . ($filename == 'css' ? 'css' : 'javascript') . '"
			});
			function selectTheme(theme) {
				editor.setOption("theme", theme);
				localStorage.setItem("hteTheme", theme);
			}';
			}

			if ($context['sub_template'] == 'edit_template') {
				$editors = '';

				foreach ($context['file_parts'] as $part) {
					$context['insert_after_template'] .= '
			var editor' . $part['line'] . ' = CodeMirror.fromTextArea(document.getElementById("on_line' . $part['line'] . '"), {
				lineNumbers: true,
				matchBrackets: true,
				mode: "text/x-php",
				firstLineNumber: ' . $part['line'] . ',
				lineWrapping: true
			});';

					$editors .= '
				editor' . $part['line'] . '.setOption("theme", theme);';
				}

				$context['insert_after_template'] .= '
			function selectTheme(theme) {' . $editors . '
				localStorage.setItem("hteTheme", theme);
			}';
			}
		}

		$context['insert_after_template'] .= '
		</script>';
	}
}
