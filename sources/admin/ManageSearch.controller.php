<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.0 Alpha
 *
 * The admin screen to change the search settings.
 */

if (!defined('ELK'))
	die('No access...');

/**
 * ManageSearch controller admin class.
 */
class ManageSearch_Controller extends Action_Controller
{
	/**
	 * Search settings form
	 * @var Settings_Form
	 */
	protected $_searchSettings;

	/**
	 * Main entry point for the admin search settings screen.
	 * It checks permissions, and it forwards to the appropriate function based on
	 * the given sub-action.
	 * Defaults to sub-action 'settings'.
	 * Called by ?action=admin;area=managesearch.
	 * Requires the admin_forum permission.
	 *
	 * @uses ManageSearch template.
	 * @uses Search language file.
	 *
	 * @see Action_Controller::action_index()
	 */
	function action_index()
	{
		global $context, $txt;

		isAllowedTo('admin_forum');

		loadLanguage('Search');
		loadTemplate('ManageSearch');

		$subActions = array(
			'settings' => array($this, 'action_searchSettings_display'),
			'weights' => array($this, 'action_weight'),
			'method' => array($this, 'action_edit'),
			'createfulltext' => array($this, 'action_edit'),
			'removecustom' => array($this, 'action_edit'),
			'removefulltext' => array($this, 'action_edit'),
			'createmsgindex' => array($this, 'action_create'),
			'managesphinx' => array($this, 'action_managesphinx'),
		);

		call_integration_hook('integrate_manage_search', array(&$subActions));

		// Default the sub-action to 'edit search settings'.
		$subAction = isset($_REQUEST['sa']) && isset($subActions[$_REQUEST['sa']]) ? $_REQUEST['sa'] : 'weights';

		// Set up action/subaction stuff.
		$action = new Action();
		$action->initialize($subActions);

		$context['sub_action'] = $subAction;

		// Create the tabs for the template.
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['manage_search'],
			'help' => 'search',
			'description' => $txt['search_settings_desc'],
			'tabs' => array(
				'weights' => array(
					'description' => $txt['search_weights_desc'],
				),
				'method' => array(
					'description' => $txt['search_method_desc'],
				),
				'settings' => array(
					'description' => $txt['search_settings_desc'],
				),
			),
		);

		// Call the right function for this sub-action.
		$action->dispatch($subAction);
	}

	/**
	 * Edit some general settings related to the search function.
	 * Called by ?action=admin;area=managesearch;sa=settings.
	 * Requires the admin_forum permission.
	 *
	 * @uses ManageSearch template, 'modify_settings' sub-template.
	 */
	function action_searchSettings_display()
	{
		global $txt, $context, $scripturl, $modSettings;

		// initialize the form
		$this->_initSearchSettingsForm();

		$config_vars = $this->_searchSettings->settings();

		if (!isset($context['settings_post_javascript']))
			$context['settings_post_javascript'] = '';

		call_integration_hook('integrate_modify_search_settings', array(&$config_vars));

		// Perhaps the search method wants to add some settings?
		require_once(SUBSDIR . '/Search.subs.php');
		$searchAPI = findSearchAPI();
		if (is_callable(array($searchAPI, 'searchSettings')))
			call_user_func_array($searchAPI->searchSettings, array(&$config_vars));

		$context['page_title'] = $txt['search_settings_title'];
		$context['sub_template'] = 'show_settings';

		$context['search_engines'] = array();
		if (!empty($modSettings['additional_search_engines']))
			$context['search_engines'] = unserialize($modSettings['additional_search_engines']);

		for ($count = 0; $count < 3; $count++)
			$context['search_engines'][] = array(
				'name' => '',
				'url' => '',
				'separator' => '',
			);

		// A form was submitted.
		if (isset($_REQUEST['save']))
		{
			checkSession();

			call_integration_hook('integrate_save_search_settings');

			if (empty($_POST['search_results_per_page']))
				$_POST['search_results_per_page'] = !empty($modSettings['search_results_per_page']) ? $modSettings['search_results_per_page'] : $modSettings['defaultMaxMessages'];

			$new_engines = array();
			foreach ($_POST['engine_name'] as $id => $searchengine)
			{
				// If no url, forget it
				if (!empty($_POST['engine_url'][$id]))
				{
					$new_engines[] = array(
						'name' => trim(Util::htmlspecialchars($searchengine, ENT_COMPAT)),
						'url' => trim(Util::htmlspecialchars($_POST['engine_url'][$id], ENT_COMPAT)),
						'separator' => trim(Util::htmlspecialchars(!empty($_POST['engine_separator'][$id]) ? $_POST['engine_separator'][$id] : '+', ENT_COMPAT)),
					);
				}
			}
			updateSettings(array(
				'additional_search_engines' => !empty($new_engines) ? serialize($new_engines) : null
			));

			Settings_Form::save_db($config_vars);
			redirectexit('action=admin;area=managesearch;sa=settings;' . $context['session_var'] . '=' . $context['session_id']);
		}

		// Prep the template!
		$context['post_url'] = $scripturl . '?action=admin;area=managesearch;save;sa=settings';
		$context['settings_title'] = $txt['search_settings_title'];

		// We need this for the in-line permissions
		createToken('admin-mp');

		Settings_Form::prepare_db($config_vars);
	}

	/**
	 * Initialize admin searchSettings form with the existing forum settings
	 * for search.
	 *
	 * @return array
	 */
	function _initSearchSettingsForm()
	{
		global $txt;

		// This is really quite wanting.
		require_once(SUBSDIR . '/Settings.class.php');

		// Instantiate the form
		$this->_searchSettings = new Settings_Form();

		// What are we editing anyway?
		$config_vars = array(
				// Permission...
				array('permissions', 'search_posts'),
				// Some simple settings.
				array('check', 'simpleSearch'),
				array('check', 'search_dropdown'),
				array('int', 'search_results_per_page'),
				array('int', 'search_max_results', 'subtext' => $txt['search_max_results_disable']),
			'',
				// Some limitations.
				array('int', 'search_floodcontrol_time', 'subtext' => $txt['search_floodcontrol_time_desc'], 6, 'postinput' => $txt['seconds']),
				array('title', 'additional_search_engines'),
				array('callback', 'external_search_engines'),
		);

		addInlineJavascript('
		document.getElementById(\'add_more_link_div\').style.display = \'\';', true);

		return $this->_searchSettings->settings($config_vars);
	}

	/**
	 * Retrieve admin search settings
	 *
	 * @return array
	 */
	function settings()
	{
		global $txt;

		// What are we editing anyway?
		$config_vars = array(
				// Permission...
				array('permissions', 'search_posts'),
				// Some simple settings.
				array('check', 'simpleSearch'),
				array('check', 'search_dropdown'),
				array('int', 'search_results_per_page'),
				array('int', 'search_max_results', 'subtext' => $txt['search_max_results_disable']),
			'',
				// Some limitations.
				array('int', 'search_floodcontrol_time', 'subtext' => $txt['search_floodcontrol_time_desc'], 6, 'postinput' => $txt['seconds']),
				array('title', 'additional_search_engines'),
				array('callback', 'external_search_engines'),
		);

		return $config_vars;
	}

	/**
	 * Edit the relative weight of the search factors.
	 * Called by ?action=admin;area=managesearch;sa=weights.
	 * Requires the admin_forum permission.
	 *
	 * @uses ManageSearch template, 'modify_weights' sub-template.
	 */
	function action_weight()
	{
		global $txt, $context, $modSettings;

		$context['page_title'] = $txt['search_weights_title'];
		$context['sub_template'] = 'modify_weights';

		$factors = array(
			'search_weight_frequency',
			'search_weight_age',
			'search_weight_length',
			'search_weight_subject',
			'search_weight_first_message',
			'search_weight_sticky',
		);

		call_integration_hook('integrate_modify_search_weights', array(&$factors));

		// A form was submitted.
		if (isset($_POST['save']))
		{
			checkSession();
			validateToken('admin-msw');

			call_integration_hook('integrate_save_search_weights');

			$changes = array();
			foreach ($factors as $factor)
				$changes[$factor] = (int) $_POST[$factor];
			updateSettings($changes);
		}

		$context['relative_weights'] = array('total' => 0);
		foreach ($factors as $factor)
			$context['relative_weights']['total'] += isset($modSettings[$factor]) ? $modSettings[$factor] : 0;

		foreach ($factors as $factor)
			$context['relative_weights'][$factor] = round(100 * (isset($modSettings[$factor]) ? $modSettings[$factor] : 0) / $context['relative_weights']['total'], 1);

		createToken('admin-msw');
	}

	/**
	 * Edit the search method and search index used.
	 * Calculates the size of the current search indexes in use.
	 * Allows to create and delete a fulltext index on the messages table.
	 * Allows to delete a custom index (that action_create() created).
	 * Called by ?action=admin;area=managesearch;sa=method.
	 * Requires the admin_forum permission.
	 *
	 * @uses ManageSearch template, 'select_search_method' sub-template.
	 */
	function action_edit()
	{
		global $txt, $context, $modSettings, $db_prefix;

		// need to work with some db search stuffs
		$db_search = db_search();
		$db = database();
		require_once(SUBSDIR . '/ManageSearch.subs.php');

		$context[$context['admin_menu_name']]['current_subsection'] = 'method';
		$context['page_title'] = $txt['search_method_title'];
		$context['sub_template'] = 'select_search_method';
		$context['supports_fulltext'] = $db_search->search_support('fulltext');

		// Load any apis.
		$context['search_apis'] = loadSearchAPIs();

		// Detect whether a fulltext index is set.
		if ($context['supports_fulltext'])
			detectFulltextIndex();

		if (!empty($_REQUEST['sa']) && $_REQUEST['sa'] == 'createfulltext')
		{
			checkSession('get');
			validateToken('admin-msm', 'get');

			$context['fulltext_index'] = 'body';
			alterFullTextIndex('{db_prefix}messages', $context['fulltext_index'], true);
		}
		elseif (!empty($_REQUEST['sa']) && $_REQUEST['sa'] == 'removefulltext' && !empty($context['fulltext_index']))
		{
			checkSession('get');
			validateToken('admin-msm', 'get');

			alterFullTextIndex('{db_prefix}messages', $context['fulltext_index']);

			$context['fulltext_index'] = '';

			// Go back to the default search method.
			if (!empty($modSettings['search_index']) && $modSettings['search_index'] == 'fulltext')
				updateSettings(array(
					'search_index' => '',
				));
		}
		elseif (!empty($_REQUEST['sa']) && $_REQUEST['sa'] == 'removecustom')
		{
			checkSession('get');
			validateToken('admin-msm', 'get');

			$tables = $db->db_list_tables(false, $db_prefix . 'log_search_words');
			if (!empty($tables))
			{
				$db_search->search_query('drop_words_table', '
					DROP TABLE {db_prefix}log_search_words',
					array(
					)
				);
			}

			updateSettings(array(
				'search_custom_index_config' => '',
				'search_custom_index_resume' => '',
			));

			// Go back to the default search method.
			if (!empty($modSettings['search_index']) && $modSettings['search_index'] == 'custom')
				updateSettings(array(
					'search_index' => '',
				));
		}
		elseif (isset($_POST['save']))
		{
			checkSession();
			validateToken('admin-msmpost');

			updateSettings(array(
				'search_index' => empty($_POST['search_index']) || (!in_array($_POST['search_index'], array('fulltext', 'custom')) && !isset($context['search_apis'][$_POST['search_index']])) ? '' : $_POST['search_index'],
				'search_force_index' => isset($_POST['search_force_index']) ? '1' : '0',
				'search_match_words' => isset($_POST['search_match_words']) ? '1' : '0',
			));
		}

		$table_info_defaults = array(
			'data_length' => 0,
			'index_length' => 0,
			'fulltext_length' => 0,
			'custom_index_length' => 0,
		);

		// Get some info about the messages table, to show its size and index size.
		if (method_exists($db_search, 'membersTableInfo'))
			$context['table_info'] = array_merge($table_info_defaults, $db_search->membersTableInfo());
		else
			// Here may be wolves.
			$context['table_info'] = array(
				'data_length' => $txt['not_applicable'],
				'index_length' => $txt['not_applicable'],
				'fulltext_length' => $txt['not_applicable'],
				'custom_index_length' => $txt['not_applicable'],
			);

		// Format the data and index length in kilobytes.
		foreach ($context['table_info'] as $type => $size)
		{
			// If it's not numeric then just break.  This database engine doesn't support size.
			if (!is_numeric($size))
				break;

			$context['table_info'][$type] = comma_format($context['table_info'][$type] / 1024) . ' ' . $txt['search_method_kilobytes'];
		}

		$context['custom_index'] = !empty($modSettings['search_custom_index_config']);
		$context['partial_custom_index'] = !empty($modSettings['search_custom_index_resume']) && empty($modSettings['search_custom_index_config']);
		$context['double_index'] = !empty($context['fulltext_index']) && $context['custom_index'];

		createToken('admin-msmpost');
		createToken('admin-msm', 'get');
	}

	/**
	 * Create a custom search index for the messages table.
	 * Called by ?action=admin;area=managesearch;sa=createmsgindex.
	 * Linked from the action_edit screen.
	 * Requires the admin_forum permission.
	 * Depending on the size of the message table, the process is divided in steps.
	 *
	 * @uses ManageSearch template, 'create_index', 'create_index_progress', and 'create_index_done'
	 *  sub-templates.
	 */
	function action_create()
	{
		global $modSettings, $context, $db_prefix, $txt;

		// Get hang of db_search
		$db_search = db_search();
		$db = database();

		// Scotty, we need more time...
		@set_time_limit(600);
		if (function_exists('apache_reset_timeout'))
			@apache_reset_timeout();

		$context[$context['admin_menu_name']]['current_subsection'] = 'method';
		$context['page_title'] = $txt['search_index_custom'];

		$messages_per_batch = 50;

		$index_properties = array(
			2 => array(
				'column_definition' => 'small',
				'step_size' => 1000000,
			),
			4 => array(
				'column_definition' => 'medium',
				'step_size' => 1000000,
				'max_size' => 16777215,
			),
			5 => array(
				'column_definition' => 'large',
				'step_size' => 100000000,
				'max_size' => 2000000000,
			),
		);

		if (isset($_REQUEST['resume']) && !empty($modSettings['search_custom_index_resume']))
		{
			$context['index_settings'] = unserialize($modSettings['search_custom_index_resume']);
			$context['start'] = (int) $context['index_settings']['resume_at'];
			unset($context['index_settings']['resume_at']);
			$context['step'] = 1;
		}
		else
		{
			$context['index_settings'] = array(
				'bytes_per_word' => isset($_REQUEST['bytes_per_word']) && isset($index_properties[$_REQUEST['bytes_per_word']]) ? (int) $_REQUEST['bytes_per_word'] : 2,
			);
			$context['start'] = isset($_REQUEST['start']) ? (int) $_REQUEST['start'] : 0;
			$context['step'] = isset($_REQUEST['step']) ? (int) $_REQUEST['step'] : 0;

			// admin timeouts are painful when building these long indexes
			if ($_SESSION['admin_time'] + 3300 < time() && $context['step'] >= 1)
				$_SESSION['admin_time'] = time();
		}

		if ($context['step'] !== 0)
			checkSession('request');

		// Step 0: let the user determine how they like their index.
		if ($context['step'] === 0)
			$context['sub_template'] = 'create_index';

		// Step 1: insert all the words.
		if ($context['step'] === 1)
		{
			$context['sub_template'] = 'create_index_progress';

			if ($context['start'] === 0)
			{
				$tables = $db->db_list_tables(false, $db_prefix . 'log_search_words');
				if (!empty($tables))
				{
					$db_search->search_query('drop_words_table', '
						DROP TABLE {db_prefix}log_search_words',
						array(
						)
					);
				}

				$db_search->create_word_search($index_properties[$context['index_settings']['bytes_per_word']]['column_definition']);

				// Temporarily switch back to not using a search index.
				if (!empty($modSettings['search_index']) && $modSettings['search_index'] == 'custom')
					updateSettings(array('search_index' => ''));

				// Don't let simultanious processes be updating the search index.
				if (!empty($modSettings['search_custom_index_config']))
					updateSettings(array('search_custom_index_config' => ''));
			}

			$num_messages = array(
				'done' => 0,
				'todo' => 0,
			);

			$request = $db->query('', '
				SELECT id_msg >= {int:starting_id} AS todo, COUNT(*) AS num_messages
				FROM {db_prefix}messages
				GROUP BY todo',
				array(
					'starting_id' => $context['start'],
				)
			);
			while ($row = $db->fetch_assoc($request))
				$num_messages[empty($row['todo']) ? 'done' : 'todo'] = $row['num_messages'];

			if (empty($num_messages['todo']))
			{
				$context['step'] = 2;
				$context['percentage'] = 80;
				$context['start'] = 0;
			}
			else
			{
				// Number of seconds before the next step.
				$stop = time() + 3;
				while (time() < $stop)
				{
					$inserts = array();
					$request = $db->query('', '
						SELECT id_msg, body
						FROM {db_prefix}messages
						WHERE id_msg BETWEEN {int:starting_id} AND {int:ending_id}
						LIMIT {int:limit}',
						array(
							'starting_id' => $context['start'],
							'ending_id' => $context['start'] + $messages_per_batch - 1,
							'limit' => $messages_per_batch,
						)
					);
					$forced_break = false;
					$number_processed = 0;
					while ($row = $db->fetch_assoc($request))
					{
						// In theory it's possible for one of these to take friggin ages so add more timeout protection.
						if ($stop < time())
						{
							$forced_break = true;
							break;
						}

						$number_processed++;
						foreach (text2words($row['body'], $context['index_settings']['bytes_per_word'], true) as $id_word)
						{
							$inserts[] = array($id_word, $row['id_msg']);
						}
					}
					$num_messages['done'] += $number_processed;
					$num_messages['todo'] -= $number_processed;
					$db->free_result($request);

					$context['start'] += $forced_break ? $number_processed : $messages_per_batch;

					if (!empty($inserts))
						$db->insert('ignore',
							'{db_prefix}log_search_words',
							array('id_word' => 'int', 'id_msg' => 'int'),
							$inserts,
							array('id_word', 'id_msg')
						);

					if ($num_messages['todo'] === 0)
					{
						$context['step'] = 2;
						$context['start'] = 0;
						break;
					}
					else
						updateSettings(array('search_custom_index_resume' => serialize(array_merge($context['index_settings'], array('resume_at' => $context['start'])))));
				}

				// Since there are still two steps to go, 80% is the maximum here.
				$context['percentage'] = round($num_messages['done'] / ($num_messages['done'] + $num_messages['todo']), 3) * 80;
			}
		}
		// Step 2: removing the words that occur too often and are of no use.
		elseif ($context['step'] === 2)
		{
			if ($context['index_settings']['bytes_per_word'] < 4)
				$context['step'] = 3;
			else
			{
				$stop_words = $context['start'] === 0 || empty($modSettings['search_stopwords']) ? array() : explode(',', $modSettings['search_stopwords']);
				$stop = time() + 3;
				$context['sub_template'] = 'create_index_progress';
				$max_messages = ceil(60 * $modSettings['totalMessages'] / 100);

				while (time() < $stop)
				{
					$request = $db->query('', '
						SELECT id_word, COUNT(id_word) AS num_words
						FROM {db_prefix}log_search_words
						WHERE id_word BETWEEN {int:starting_id} AND {int:ending_id}
						GROUP BY id_word
						HAVING COUNT(id_word) > {int:minimum_messages}',
						array(
							'starting_id' => $context['start'],
							'ending_id' => $context['start'] + $index_properties[$context['index_settings']['bytes_per_word']]['step_size'] - 1,
							'minimum_messages' => $max_messages,
						)
					);
					while ($row = $db->fetch_assoc($request))
						$stop_words[] = $row['id_word'];
					$db->free_result($request);

					updateSettings(array('search_stopwords' => implode(',', $stop_words)));

					if (!empty($stop_words))
						$db->query('', '
							DELETE FROM {db_prefix}log_search_words
							WHERE id_word in ({array_int:stop_words})',
							array(
								'stop_words' => $stop_words,
							)
						);

					$context['start'] += $index_properties[$context['index_settings']['bytes_per_word']]['step_size'];
					if ($context['start'] > $index_properties[$context['index_settings']['bytes_per_word']]['max_size'])
					{
						$context['step'] = 3;
						break;
					}
				}

				$context['percentage'] = 80 + round($context['start'] / $index_properties[$context['index_settings']['bytes_per_word']]['max_size'], 3) * 20;
			}
		}

		// Step 3: remove words not distinctive enough.
		if ($context['step'] === 3)
		{
			$context['sub_template'] = 'create_index_done';

			updateSettings(array('search_index' => 'custom', 'search_custom_index_config' => serialize($context['index_settings'])));
			$db->query('', '
				DELETE FROM {db_prefix}settings
				WHERE variable = {string:search_custom_index_resume}',
				array(
					'search_custom_index_resume' => 'search_custom_index_resume',
				)
			);
		}
	}

	/**
	 * Edit settings related to the sphinx or sphinxQL search function.
	 * Called by ?action=admin;area=managesearch;sa=sphinx.
	 */
	function action_managesphinx()
	{
		global $txt, $context, $modSettings;

		// saving the settings
		if (isset($_POST['save']))
		{
			checkSession();
			validateToken('admin-mssphinx');

			updateSettings(array(
				'sphinx_data_path' => rtrim($_POST['sphinx_data_path'], '/'),
				'sphinx_log_path' => rtrim($_POST['sphinx_log_path'], '/'),
				'sphinx_stopword_path' => $_POST['sphinx_stopword_path'],
				'sphinx_indexer_mem' => (int) $_POST['sphinx_indexer_mem'],
				'sphinx_searchd_server' => $_POST['sphinx_searchd_server'],
				'sphinx_searchd_port' => (int) $_POST['sphinx_searchd_port'],
				'sphinxql_searchd_port' => (int) $_POST['sphinxql_searchd_port'],
				'sphinx_max_results' => (int) $_POST['sphinx_max_results'],
			));
		}
		// checking if we can connect?
		elseif (isset($_POST['checkconnect']))
		{
			checkSession();
			validateToken('admin-mssphinx');

			// If they have not picked sphinx yet, let them know, but we can still check connections
			if (empty($modSettings['search_index']) || ($modSettings['search_index'] !== 'sphinx' && $modSettings['search_index'] !== 'sphinxql'))
			{
				$context['settings_message'][] = $txt['sphinx_test_not_selected'];
				$context['error_type'] = 'notice';
			}

			// try to connect via Sphinx API?
			if ($modSettings['search_index'] === 'sphinx' || empty($modSettings['search_index']))
			{
				if (@file_exists(SOURCEDIR . '/sphinxapi.php'))
				{
					include_once(SOURCEDIR . '/sphinxapi.php');
					$mySphinx = new SphinxClient();
					$mySphinx->SetServer($modSettings['sphinx_searchd_server'], (int) $modSettings['sphinx_searchd_port']);
					$mySphinx->SetLimits(0, (int) $modSettings['sphinx_max_results']);
					$mySphinx->SetMatchMode(SPH_MATCH_BOOLEAN);
					$mySphinx->SetSortMode(SPH_SORT_ATTR_ASC, 'id_topic');

					$request = $mySphinx->Query('test', 'elkarte_index');
					if ($request === false)
					{
						$context['settings_message'][] = $txt['sphinx_test_connect_failed'];
						$context['error_type'] = 'serious';
					}
					else
						$context['settings_message'][] = $txt['sphinx_test_passed'];
				}
				else
				{
					$context['settings_message'][] = $txt['sphinx_test_api_missing'];
					$context['error_type'] = 'serious';
				}
			}

			// try to connect via SphinxQL
			if ($modSettings['search_index'] === 'sphinxql' || empty($modSettings['search_index']))
			{
				if (!empty($modSettings['sphinx_searchd_server']) && !empty($modSettings['sphinxql_searchd_port']))
				{
					$result = mysql_connect(($modSettings['sphinx_searchd_server'] === 'localhost' ? '127.0.0.1' : $modSettings['sphinx_searchd_server']) . ':' . (int) $modSettings['sphinxql_searchd_port']);
					if ($result === false)
					{
						$context['settings_message'][] = $txt['sphinxql_test_connect_failed'];
						$context['error_type'] = 'serious';
					}
					else
						$context['settings_message'][] = $txt['sphinxql_test_passed'];
				}
				else
				{
					$context['settings_message'][] = $txt['sphinxql_test_connect_failed'];
					$context['error_type'] = 'serious';
				}
			}
		}
		elseif (isset($_POST['createconfig']))
		{
			checkSession();
			validateToken('admin-mssphinx');
			require_once(SUBSDIR . '/ManageSearch.subs.php');

			createSphinxConfig();
		}

		// Setup for the template
		$context['page_title'] = $txt['search_sphinx'];
		$context['page_description'] = $txt['sphinx_description'];
		$context['sub_template'] = 'manage_sphinx';
		createToken('admin-mssphinx');
	}
}

/**
 * Get the installed Search API implementations.
 * This function checks for patterns in comments on top of the Search-API files!
 * In addition to filenames pattern.
 * It loads the search API classes if identified.
 * This function is used by action_edit to list all installed API implementations.
 */
function loadSearchAPIs()
{
	global $txt;

	$apis = array();
	if ($dh = opendir(SUBSDIR))
	{
		while (($file = readdir($dh)) !== false)
		{
			if (is_file(SUBSDIR . '/' . $file) && preg_match('~^SearchAPI-([A-Za-z\d_]+)\.class\.php$~', $file, $matches))
			{
				// Check that this is definitely a valid API!
				$fp = fopen(SUBSDIR . '/' . $file, 'rb');
				$header = fread($fp, 4096);
				fclose($fp);

				if (strpos($header, '* SearchAPI-' . $matches[1] . '.class.php') !== false)
				{
					require_once(SUBSDIR . '/' . $file);

					$index_name = strtolower($matches[1]);
					$search_class_name = $index_name . '_search';
					$searchAPI = new $search_class_name();

					// No Support?  NEXT!
					if (!$searchAPI->is_supported)
						continue;

					$apis[$index_name] = array(
						'filename' => $file,
						'setting_index' => $index_name,
						'has_template' => in_array($index_name, array('custom', 'fulltext', 'standard')),
						'label' => $index_name && isset($txt['search_index_' . $index_name]) ? $txt['search_index_' . $index_name] : '',
						'desc' => $index_name && isset($txt['search_index_' . $index_name . '_desc']) ? $txt['search_index_' . $index_name . '_desc'] : '',
					);
				}
			}
		}
	}
	closedir($dh);

	return $apis;
}