<?php

// Pandora FMS - http://pandorafms.com
// ==================================================
// Copyright (c) 2005-2010 Artica Soluciones Tecnologicas
// Please see http://pandorafms.org for full contribution list

// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation for version 2.
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.

// Load global vars
global $config;

check_login ();

if (! check_acl ($config['id_user'], 0, "AW")) {
	db_pandora_audit("ACL Violation",
		"Trying to access massive plugin edition section");
	require ("general/noaccess.php");
	return;
}

$plugin_id = (int) get_parameter('plugin_id');
$agent_ids = get_parameter('agent_ids', array());
$module_ids = get_parameter('module_ids', array());
$module_names = get_parameter('module_names', array());

if (is_ajax()) {
	$get_plugin = (bool) get_parameter('get_plugin');
	$get_agents = (bool) get_parameter('get_agents');
	$get_modules = (bool) get_parameter('get_modules');
	$get_module_plugin_macros = (bool) get_parameter('get_module_plugin_macros');
	
	if ($get_plugin) {
		$plugin = db_get_row('tplugin', 'id', $plugin_id);
		
		if (empty($plugin)) {
			$plugin = array();
		}
		
		if (isset($plugin['description'])) {
			$plugin['description'] = io_safe_output($plugin['description']);
			$plugin['description'] = str_replace("\n", "<br>", $plugin['description']);
		}
		if (isset($plugin['macros'])) {
			$macros = json_decode($plugin['macros'], true);
			if (!empty($macros)) {
				$macros = array_values($macros);
				
				if (!empty($macros)) {
					$plugin['macros'] = $macros;
				}
			}
		}
		
		echo json_encode($plugin);
		return;
	}
	
	if ($get_agents) {
		$sql = "SELECT ta.id_agente, ta.nombre AS agent_name,
					tam.nombre AS module_name
				FROM tagente ta
				INNER JOIN tagente_modulo tam
					ON ta.id_agente = tam.id_agente
						AND tam.id_plugin = $plugin_id
				ORDER BY ta.nombre, tam.nombre";
		$result = db_get_all_rows_sql($sql);
		if (empty($result)) $result = array();
		
		$agents = array();
		
		$current_element = array();
		foreach ($result as $key => $value) {
			$id = (int) $value['id_agente'];
			$name = $value['agent_name'];
			$module_name = $value['module_name'];
			
			if (!empty($current_element) && $current_element['id'] !== $id) {
				$agents[] = $current_element;
				$current_element = array();
			}
			
			$current_element['id'] = $id;
			$current_element['name'] = $name;
			
			if (!isset($current_element['module_names']))
				$current_element['module_names'] = array();
			$current_element['module_names'][] = $module_name;
		}
		if (!empty($current_element)) {
			$agents[] = $current_element;
		}
		
		echo json_encode($agents);
		return;
	}
	
	if ($get_module_plugin_macros) {
		$fields = array('macros');
		$filter = array(
				'id_plugin' => $plugin_id,
				'id_agente' => $agent_ids,
				'nombre' => $module_names
			);
		$module_plugin_macros = db_get_all_rows_filter('tagente_modulo', $filter, $fields);
		if (empty($module_plugin_macros)) $module_plugin_macros = array();
		
		$module_plugin_macros = array_reduce($module_plugin_macros, function($carry, $item) {
			
			$macros = json_decode($item['macros'], true);
			if (!empty($macros)) {
				$macros = array_values($macros);
				if (!empty($macros)) {
					$carry[] = $macros;
				}
			}
			
			return $carry;
			
		}, array());
		
		echo json_encode($module_plugin_macros);
		return;
	}
	
	return;
}

$update = (bool) get_parameter('update');

if ($update) {
	try {
		$plugin = db_get_row('tplugin', 'id', $plugin_id);
		// Macros retrieved from the plugin definition
		$plugin_macros = array();
		if (isset($plugin['macros'])) {
			$plugin_macros = json_decode($plugin['macros'], true);
			if (!empty($plugin_macros)) {
				$plugin_macros = array_values($plugin_macros);
			}
		}
		
		// Error
		if (empty($plugin_macros))
			throw new Exception(__('Error retrieving the plugin macros'));
		
		// Macros returned by the form
		$macros = get_parameter('macros', array());
		
		// Error
		if (empty($macros))
			throw new Exception(__('Error retrieving the modified macros'));
		
		$fields = array('id_agente_modulo', 'macros');
		$filter = array(
				'id_plugin' => $plugin_id,
				'id_agente' => $agent_ids,
				'nombre' => $module_names
			);
		$module_plugin_macros = db_get_all_rows_filter('tagente_modulo', $filter, $fields);
		if (empty($module_plugin_macros)) $module_plugin_macros = array();
		
		// Error
		if (empty($module_plugin_macros))
			throw new Exception(__('Error retrieving the module plugin macros'));
		
		// Begin transaction
		// db_process_sql_begin();
		$errors = 0;
		$count = 0;
		
		foreach ($module_plugin_macros as $item) {
			
			$module_id = $item['id_agente_modulo'];
			$module_macros_str = $item['macros'];
			// Macros retrieved from the agent module
			$module_macros = json_decode($module_macros_str, true);
			
			// Error
			if (empty($module_macros))
				throw new Exception(__('Error retrieving the module plugin macros data'));
			
			// Get the new module plugin macros
			$result_macros = array_map(function ($item) use ($macros, $module_macros) {
				
				$result = array(
						'macro' => $item['macro'],
						'desc' => $item['desc'],
						'help' => $item['help'],
						'hide' => $item['hide']
					);
				
				// Get the default value os the module plugin macro
				$default = array_reduce($module_macros, function ($carry, $module_macro) use ($result) {
					
					if (isset($module_macro['macro']) && $module_macro['macro'] == $result['macro']) {
						$carry = $module_macro['value'];
					}
					
					return $carry;
					
				}, '');
				
				set_if_defined($result['value'], $macros[$item['macro']]);
				set_unless_defined($result['value'], $default);
				
				return $result;
				
			}, $plugin_macros);
			
			// Error
			if (empty($result_macros))
				throw new Exception(__('Error building the new macros'));
			
			$module_macros = json_encode($result_macros);
			if (empty($module_macros)) {
				$module_macros = $module_macros_str;
			}
			
			$values = array('macros' => $module_macros);
			$where = array('id_agente_modulo' => $module_id);
			// $result = db_process_sql_update('tagente_modulo', $values, $where, 'AND', false);
			$result = db_process_sql_update('tagente_modulo', $values, $where);
			
			if (!$result)
				$errors++;
			else
				$count += $result;
			
		}
		
		// if (!$errors) {
		// 	db_process_sql_commit();
		// }
		// else {
		// 	db_process_sql_rollback();
		// }
		
		// Result message
		ui_print_info_message(sprintf(__('%d modules updated'), $count));
	}
	catch (Exception $e) {
		ui_print_error_message($e->getMessage());
	}
}

$table = new StdClass();
$table->id = 'massive_plugin_edition';
$table->width = '100%';
$table->rowstyle = array();
$table->data = array();

// Plugins
$filter = array('order' => 'name');
$fields = array('id', 'name');
$plugins = db_get_all_rows_filter('tplugin', $filter, $fields);

if (empty($plugins)) {
	ui_print_empty_data(__('There are not registered plugins'));
	return;
}

$plugins_aux = array();
foreach ($plugins as $plugin) {
	$plugins_aux[$plugin['id']] = $plugin['name'];
}
$plugins = $plugins_aux;
unset($plugins_aux);

$plugins_select = html_print_select ($plugins, 'plugin_id',
	$plugin_id, '', __('None'), 0, true, false, false);

$row = array();
$row[] = '<b>' . __('Plugin') . '</b>';
$row[] = $plugins_select;

$table->data['plugin-ids-row'] = $row;

// Agents & modules
$row = array();

// Agents
$agents_select = html_print_select ($agent_ids, 'agent_ids[]',
	false, '', '', 0, true, true, false);

$row[] = '<b>' . __('Agents') . '</b>';
$row[] = $agents_select;

// Modules
// $modules_select = html_print_select ($module_ids, 'module_ids',
// 	false, '', '', 0, true, true, false);
$modules_select = html_print_select ($module_names, 'module_names[]',
	false, '', '', 0, true, true, false);

$row[] = '<b>' . _('Modules') . '</b>';
$row[] = $modules_select;

$table->rowstyle['agents-modules-row'] = 'vertical-align: top; display: none';
$table->data['agents-modules-row'] = $row;

echo '<form method="POST" id="form-massive_plugin_edition"
	action="index.php?sec=gmassive&sec2=godmode/massive/massive_operations&tab=massive_plugins&option=edit_plugins">';

html_print_table($table);

echo "<div style='text-align: right; width: " . $table->width . "'>";
html_print_input_hidden('update', 1);
html_print_submit_button (__('Update'), 'upd-btn', false, 'class="sub upd"');
echo "</div>";

echo '</form>';

ui_require_javascript_file("underscore-min");

?>

<script type="text/javascript">
	
	var $table = $('table#massive_plugin_edition'),
		$form = $('form#form-massive_plugin_edition'),
		$submitButton = $('input#submit-upd-btn'),
		$agentModulesRow = $('tr#massive_plugin_edition-agents-modules-row'),
		$pluginsSelect = $('select#plugin_id'),
		$agentsSelect = $('select#agent_ids'),
		$modulesSelect = $('select#module_names');
	
	var agents = [],
		ajaxPage = "<?php echo $config['homeurl'] . '/'; ?>ajax.php",
		canSubmit = false,
		pluginXHR,
		agentsXHR,
		modulesXHR,
		modulePluginMacrosXHR;
	
	var allowSubmit = function (val) {
		if (typeof val === 'undefined')
			val = true;
		
		canSubmit = val;
		$submitButton.prop('disabled', !val);
	}

	var showSpinner = function () {
		var $loadingSpinner = $pluginsSelect.siblings('img#loading_spinner');
		
		if ($loadingSpinner.length > 0) {
			// Display inline instead using the show function
			// cause its absolute positioning.
			$loadingSpinner.css('display', 'inline');
			return;
		}
		
		$loadingSpinner = $('<img />');
			
		$loadingSpinner
			.prop('id', 'loading_spinner')
			.css('padding-left', '5px')
			.css('position', 'absolute')
			.css('top', $pluginsSelect.position().top + 'px')
			.prop('src', "<?php echo $config['homeurl'] . '/'; ?>images/spinner.gif");
		
		$pluginsSelect.parent().append($loadingSpinner);
	}
	
	var hideSpinner = function () {
		var $loadingSpinner = $pluginsSelect.siblings('img#loading_spinner');
		
		if ($loadingSpinner.length > 0)
			$loadingSpinner.hide();
	}
	
	var clearModulePluginMacrosValues = function () {
		$('input.plugin-macro')
			.val('')
			.prop('disabled', true)
			.data('multiple_values', false)
			.prop('placeholder', '')
			.css('width', '99%')
			.siblings('button')
				.remove();
	}
	
	var hidePluginData = function () {
		$('table#massive_plugin_edition tr.plugin-data-row').hide();
	}
	
	var clearPluginData = function () {
		hidePluginData();
		clearModulePluginMacrosValues();
		$('table#massive_plugin_edition tr.plugin-data-row').remove();
	}
	
	var clearAgentsData = function () {
		$agentsSelect.empty();
	}
	
	var clearModulesData = function () {
		$modulesSelect.empty();
	}
	
	// Creates the plugin info and macros columns
	var fillPlugin = function (plugin) {
		clearPluginData();
		
		if (typeof plugin === 'undefined'
				|| typeof plugin.execute === 'undefined'
				|| typeof plugin.parameters === 'undefined'
				|| typeof plugin.description === 'undefined'
				|| typeof plugin.macros === 'undefined')
			throw new Error('<?php echo __("Invalid plugin data"); ?>');
		
		if (_.isString(plugin.macros)) {
			plugin.macros = JSON.parse(plugin.macros);
		}
		
		var $commandRow = $('<tr></tr>'),
			$commandCellTitle = $('<td></td>'),
			$commandCellData = $('<td></td>'),
			$descriptionRow = $('<tr></tr>'),
			$descriptionCellTitle = $('<td></td>'),
			$descriptionCellData = $('<td></td>');
		
		$commandCellTitle
			.addClass('plugin-data-cell')
			.css('font-weight', 'bold')
			.html('<?php echo __("Command"); ?>');
		$commandCellData
			.addClass('plugin-data-cell')
			.prop('colspan', 3)
			.css('font-style', 'italic')
			.html(plugin.execute + " " + plugin.parameters);
		$commandRow
			.addClass('plugin-data-row')
			.css('vertical-align', 'top')
			.append($commandCellTitle, $commandCellData);
		
		$descriptionCellTitle
			.addClass('plugin-data-cell')
			.css('font-weight', 'bold')
			.html('<?php echo __("Description"); ?>');
		$descriptionCellData
			.addClass('plugin-data-cell')
			.prop('colspan', 3)
			.html(plugin.description);
		$descriptionRow
			.addClass('plugin-data-row')
			.css('vertical-align', 'top')
			.append($descriptionCellTitle, $descriptionCellData);
		
		$table.append($commandRow, $descriptionRow);
		
		_.each(plugin.macros, function (macro, index) {
			var $macroRow = $('<tr></tr>'),
				$macroCellTitle = $('<td></td>'),
				$macroCellData = $('<td></td>'),
				$macroInput = $('<input>'),
				$macroIdentifier = $('<span></span>');
			
			$macroInput
				.prop('id', macro.macro)
				.prop('name', 'macros[' + macro.macro + ']')
				.addClass('plugin-macro')
				.addClass('plugin-data-input')
				.prop('type', function () {
					if (Number(macro.hide))
						return 'password';
					else
						return 'text';
				})
				.css('width', '99%')
				.prop('disabled', true)
				.autocomplete({
					source: [],
					minLength: 0,
					disabled: true
				})
				.bind('focus', function() {
					$(this).autocomplete("search");
				});
			
			$macroIdentifier
				.css('font-weight', 'normal')
				.css('padding-left', '5px')
				.append('(' + macro.macro + ')');
			
			$macroCellTitle
				.addClass('plugin-data-cell')
				.css('font-weight', 'bold')
				.html(macro.desc)
				.append($macroIdentifier);
			$macroCellData
				.addClass('plugin-data-cell')
				.prop('colspan', 3)
				.html($macroInput);
			$macroRow
				.addClass('plugin-data-row')
				.append($macroCellTitle, $macroCellData);
			
			$table.append($macroRow);
		});
	}
	
	var removeMultipleElementsButton = function (element) {
		element
			.css('width', '99%')
			.siblings('button')
				.remove();
	}
	
	// This button removes the special properties of the multiple values macro input
	var addMultipleElementsButton = function (element) {
		$button = $('<button>');
		
		$button
			.css('display', 'inline')
			.css('margin-left', '3px')
			.text("<?php echo __('Clear'); ?>")
			.click(function (e) {
				e.stopImmediatePropagation();
				e.preventDefault();
				
				if (!confirm("<?php echo __('Are you sure?'); ?>"))
					return false;
				
				removeMultipleElementsButton(element);
				
				element
					.val('')
					.data('multiple_values', false)
					.prop('placeholder', '');
			});
		
		element
			.css('width', '90%')
			.css('display', 'inline')
			.parent()
				.append($button);
	}
	
	// Fills the module plugin macros values
	var fillPluginMacros = function (moduleMacros) {
		clearModulePluginMacrosValues();
		
		if (!(moduleMacros instanceof Array))
			throw new Error('<?php echo __("Invalid macros array"); ?>');
		
		$("input.plugin-macro").each(function(index, el) {
			var id = $(el).prop('id');
			
			var values = _.chain(moduleMacros)
				.flatten()
				.where({ macro: id })
				.pluck('value')
				.uniq()
				.value();
			
			$(el).prop('disabled', false);
			
			// Remove the [""] element
			if (values.length == 1 && _.first(values) === '') {
				values = [];
			}
			
			if (values.length == 1) {
				$(el).val(_.first(values));
			}
			else if (values.length > 1) {
				$(el).val('')
					.data('multiple_values', true)
					.prop('placeholder', "<?php echo __('Multiple values'); ?>");
				addMultipleElementsButton($(el));
			}
			else {
				$(el).val('');
			}
			
			if ($(el).prop('type') !== 'password' && values.length > 0) {
				
				$(el).autocomplete("option", {
						disabled: false,
						source: values
					});
			}
			else {
				$(el).autocomplete("option", { disabled: true });
			}
		});
		
		$(".ui-autocomplete")
			.css('max-height', '100px')
			.css('overflow-y', 'auto')
			.css('overflow-x', 'hidden')
			.css('padding-right', '20px')
			.css('text-align', 'left');
	}
	
	// Fills the agents select
	var fillAgents = function (agents, selected) {
		clearAgentsData();
		
		if (!(agents instanceof Array))
			throw new Error('<?php echo __("Invalid agents array"); ?>');
		
		_.each(agents, function (agent, index) {
			if (typeof agent.id !== 'undefined' && typeof agent.name !== 'undefined') {
				$('<option>')
					.val(agent.id)
					.text(agent.name)
					.prop('selected', function () {
						if (typeof selected !== 'undefined')
							return false;
						
						return _.contains(selected, agent.id.toString());
					})
					.appendTo($agentsSelect);
			}
			else {
				throw new Error('<?php echo __("Invalid agent element"); ?>');
				return false;
			}
		});
	}
	
	// Fills the modules select
	var fillModules = function (modules, selected) {
		clearModulesData();
		
		if (!(modules instanceof Array))
			throw new Error('<?php echo __("Invalid modules array"); ?>');
		
		_.each(modules, function (module, index) {
			if (_.isString(module)) {
				$('<option>')
					.val(module)
					.text(module)
					.prop('selected', function () {
						if (typeof selected === 'undefined')
							return false;
						
						return _.contains(selected, module);
					})
					.appendTo($modulesSelect);
			}
			else if (typeof module.id !== 'undefined' && typeof module.name !== 'undefined') {
				$('<option>')
					.val(module.name)
					.text(module.name)
					.prop('selected', function () {
						if (typeof selected === 'undefined')
							return false;
						
						return _.contains(selected, module.name);
					})
					.appendTo($modulesSelect);
			}
			else {
				throw new Error('<?php echo __("Invalid module element"); ?>');
				return false;
			}
		});
		
		$modulesSelect.change();
	}
	
	var processGet = function (params, callback) {
		return jQuery.get(ajaxPage, params, 'json')
			.done(function (data, textStatus, jqXHR) {
				try {
					data = JSON.parse(data);
					callback(null, data);
				}
				catch (err) {
					callback(err);
				}
			})
			.fail(function (jqXHR, textStatus, errorThrown) {
				if (textStatus !== 'abort')
					callback(errorThrown);
			})
			.always(function (jqXHR, textStatus) {
				
			});
	}
	
	var getPlugin = function (pluginID, callback) {
		var params = {
			page: 'godmode/massive/massive_edit_plugins',
			get_plugin: 1,
			plugin_id: pluginID
		};
		
		pluginXHR = processGet(params, function (error, data) {
			callback(error, data);
		});
	}
	
	var getAgents = function (pluginID, callback) {
		var params = {
			page: 'godmode/massive/massive_edit_plugins',
			get_agents: 1,
			plugin_id: pluginID
		};
		
		agentsXHR = processGet(params, function (error, data) {
			callback(error, data);
		});
	}
	
	var getModules = function (pluginID, agentIDs, callback) {
		var params = {
			page: 'godmode/massive/massive_edit_plugins',
			get_modules: 1,
			plugin_id: pluginID,
			agent_ids: agentIDs
		};
		
		modulesXHR = processGet(params, function (error, data) {
			callback(error, data);
		});
	}
	
	var getModulePluginMacros = function (pluginID, agentIDs, moduleNames, callback) {
		var params = {
			page: 'godmode/massive/massive_edit_plugins',
			get_module_plugin_macros: 1,
			plugin_id: pluginID,
			agent_ids: agentIDs,
			module_names: moduleNames
		};
		
		modulePluginMacrosXHR = processGet(params, function (error, data) {
			callback(error, data);
		});
	}
	
	// Extract the a module names array from the agents
	var moduleNamesFromAgents = function (agents) {
		if (!(agents instanceof Array))
			throw new Error('<?php echo __("Invalid agents array"); ?>');
		
		var moduleNames = _.map(agents, function (agent) {
				return agent['module_names'];
			});
		moduleNames = _.intersection.apply(_, moduleNames);
		moduleNames = _.chain(moduleNames)
			.flatten()
			.uniq()
			.value();
		
		return moduleNames;
	}
	
	var agentsFilteredWithAgents = function (agents, agentIDs) {
		if (!(agents instanceof Array))
			throw new Error('<?php echo __("Invalid agents array"); ?>');
		
		var agentsFiltered = _.filter(agents, function (agent) {
			return _.contains(agentIDs, agent.id.toString());
		});
		
		// Hack. Is possible that find returns an object instead of an array
		// when the only array item is an object. Probably an Underscore.js bug
		if (typeof agentsFiltered !== 'undefined'
				&& !(agentsFiltered instanceof Array)
				&& (agentsFiltered instanceof Object))
			agentsFiltered = [agentsFiltered];
		
		return agentsFiltered;
	}
	
	var resetController = function () {
		if (typeof pluginXHR !== 'undefined') {
			pluginXHR.abort();
			pluginXHR = undefined;
		}
		if (typeof agentsXHR !== 'undefined') {
			agentsXHR.abort();
			agentsXHR = undefined;
		}
		if (typeof modulesXHR !== 'undefined') {
			modulesXHR.abort();
			modulesXHR = undefined;
		}
		if (typeof modulePluginMacrosXHR !== 'undefined') {
			modulePluginMacrosXHR.abort();
			modulePluginMacrosXHR = undefined;
		}
		
		allowSubmit(false);
		
		agents = [];
		
		hideSpinner();
		clearPluginData();
		
		$agentModulesRow.hide();
		clearAgentsData();
		clearModulesData();
	}
	
	var errorHandler = function (error) {
		console.log("<?php echo __('Error'); ?>: " + error.message);
		// alert("<?php echo __('Error'); ?>: " + err.message);
		
		// Init the plugin id select
		$pluginsSelect.val(0).change();
	}
	
	$pluginsSelect.change(function (e) {
		allowSubmit(false);
		
		// Plugin id
		var currentVal = $(this).val();
		
		resetController();
		
		if (currentVal == 0)
			return;
		
		try {
			showSpinner();
			
			// This asyc functions are executed at the same time
			getPlugin(currentVal, function (error, data) {
				if (error) {
					errorHandler(error);
					return;
				}
				
				plugin = data;
				
				try {
					fillPlugin(plugin);
					
					// Hide spinner only if the another call has finished
					if (typeof agentsXHR === 'undefined'
							|| agentsXHR.state() === 'resolved'
							|| agentsXHR.state() === 'rejected') {
						hideSpinner();
					}
				}
				catch (err) {
					errorHandler(err);
					return;
				}
			});
			
			// This asyc functions are executed at the same time
			getAgents(currentVal, function (error, data) {
				if (error) {
					errorHandler(error);
					return;
				}
				
				// This agent variable is global to this script scope
				agents = data;
				
				try {
					if (agents.length > 0) {
						fillAgents(agents);
						
						$agentModulesRow.show();
					}
					else {
						alert("<?php echo __('There are no modules using this plugin'); ?>");
						
						// Abort the another call
						if (typeof pluginXHR !== 'undefined') {
							pluginXHR.abort();
							pluginXHR = undefined;
						}
					}
					
					// Hide spinner only if the another call has finished
					if (typeof pluginXHR === 'undefined'
							|| pluginXHR.state() === 'resolved'
							|| pluginXHR.state() === 'rejected') {
						hideSpinner();
					}
				}
				catch (err) {
					errorHandler(err);
					return;
				}
			});
		}
		catch (err) {
			errorHandler(err);
			return;
		}
		
	}).change(); // Trigger the change
	
	$agentsSelect.change(function (e) {
		allowSubmit(false);
		
		var ids = $(this).val();
		var modulesSelected = $modulesSelect.val();
		
		try {
			var agentsFiltered = agentsFilteredWithAgents(agents, ids);
			var modules = moduleNamesFromAgents(agentsFiltered);
			
			fillModules(modules, modulesSelected);
		}
		catch (err) {
			errorHandler(err);
			return;
		}
	});
	
	$modulesSelect.change(function (e) {
		allowSubmit(false);
		
		var pluginID = $pluginsSelect.val();
		var moduleNames = $(this).val();
		var agentIDs = $agentsSelect.val();
		
		if (_.isNull(moduleNames) || _.isUndefined(moduleNames)) {
			e.preventDefault();
			return false;
		}
		
		try {
			showSpinner();
			
			clearModulePluginMacrosValues();
			
			getModulePluginMacros(pluginID, agentIDs, moduleNames, function (error, data) {
				if (error) {
					errorHandler(error);
					return;
				}
				
				try {
					var modulePluginMacros = data;
					
					if (_.isArray(modulePluginMacros) && modulePluginMacros.length > 0) {
						fillPluginMacros(modulePluginMacros);
						
						allowSubmit(true);
					}
					else {
						throw new Error('<?php echo __("There was a problem loading the module plugin macros data"); ?>');
					}
					
					hideSpinner();
				}
				catch (err) {
					errorHandler(err);
					return;
				}
			});
		}
		catch (err) {
			errorHandler(err);
			return;
		}
	});
	
	$form.submit(function(e) {
		if (!canSubmit) {
			e.stopImmediatePropagation();
			e.preventDefault();
		}
		else {
			$form.find('input.plugin-macro')
				.filter(function() {
					var val = $(this).val();
					
					if ($(this).data("multiple_values") == true
							&& (typeof val === 'undefined'
								|| val.length == 0))
						return true;
					else
						return false;
					
				}).prop('disabled', true);
		}
	});
	
	$(document).ready (function () {
		
	});
	
</script>