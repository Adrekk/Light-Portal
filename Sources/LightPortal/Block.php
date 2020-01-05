<?php

namespace Bugo\LightPortal;

/**
 * Block.php
 *
 * @package Light Portal
 * @link https://dragomano.ru/mods/light-portal
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2019-2020 Bugo
 * @license https://opensource.org/licenses/BSD-3-Clause BSD
 *
 * @version 0.1
 */

if (!defined('SMF'))
	die('Hacking attempt...');

class Block
{
	/**
	 * Display blocks in their designated areas
	 * Отображаем блоки в предназначенных им областях
	 *
	 * @param string $area
	 * @return void
	 */
	public static function display($area = 'portal')
	{
		global $context;

		if (empty($context['template_layers']))
			return;

		$blocks = array_filter($context['lp_active_blocks'], function($block) use ($area) {
			$block['areas'] = array_flip($block['areas']);
			return isset($block['areas']['all']) || isset($block['areas'][$area]) || isset($block['areas']['page=' . filter_input(INPUT_GET, 'page', FILTER_SANITIZE_STRING)]);
		});

		if (empty($blocks))
			return;

		foreach ($blocks as $item => $data) {
			if ($data['can_show']) {
				if (empty($data['title'][$context['user']['language']]))
					$data['title_class'] = '';

				if (empty($data['content'])) {
					Subs::runAddons('prepareContent', array(&$data['content'], $data['type'], $data['id']));
				} else {
					Subs::parseContent($data['content'], $data['type']);
				}

				$context['lp_blocks'][$data['placement']][$item] = $data;
				$icon = self::getIcon($context['lp_blocks'][$data['placement']][$item]['icon']);
				$context['lp_blocks'][$data['placement']][$item]['title'] = $icon . $context['lp_blocks'][$data['placement']][$item]['title'][$context['user']['language']];
			}
		}

		loadTemplate('LightPortal/ViewBlock');

		// Zen for layers | Дзен для слоев
		$counter = 0;
		foreach ($context['template_layers'] as $position => $name) {
			$counter++;
			if ($name == 'body')
				break;
		}

		$context['template_layers'] = array_merge(
			array_slice($context['template_layers'], 0, $counter, true),
			array('portal'),
			array_slice($context['template_layers'], $counter, null, true)
		);
	}

	/**
	 * Manage blocks
	 * Управление блоками
	 *
	 * @return void
	 */
	public static function manage()
	{
		global $context, $txt;

		isAllowedTo('light_portal_manage');
		loadTemplate('LightPortal/ManageBlocks');

		$context['page_title'] = $txt['lp_portal'] . ' - ' . $txt['lp_blocks_manage'];

		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title'       => LP_NAME,
			'description' => $txt['lp_blocks_manage_tab_description']
		);

		self::getAll();
		self::postActions();

		$context['lp_current_blocks'] = array_merge(array_flip(array_keys($txt['lp_block_placement_set'])), $context['lp_current_blocks']);
		$context['sub_template'] = 'manage_blocks';
	}

	/**
	 * Get a list of all blocks sorted by placement
	 * Получаем список всех блоков с разбивкой по размещению
	 *
	 * @return void
	 */
	public static function getAll()
	{
		global $smcFunc, $context, $user_info;

		$request = $smcFunc['db_query']('', '
			SELECT b.block_id, b.icon, b.type, b.placement, b.priority, b.permissions, b.status, b.areas, bt.lang, bt.title
			FROM {db_prefix}lp_blocks AS b
				LEFT JOIN {db_prefix}lp_block_titles AS bt ON (bt.block_id = b.block_id)
			ORDER BY b.placement DESC, b.priority',
			array()
		);

		$context['lp_current_blocks'] = [];
		while ($row = $smcFunc['db_fetch_assoc']($request)) {
			if (!isset($context['lp_current_blocks'][$row['placement']][$row['block_id']]))
				$context['lp_current_blocks'][$row['placement']][$row['block_id']] = array(
					'icon'        => self::getIcon($row['icon']),
					'type'        => $row['type'],
					'priority'    => $row['priority'],
					'permissions' => $row['permissions'],
					'status'      => $row['status'],
					'areas'       => $row['areas']
				);

			$context['lp_current_blocks'][$row['placement']][$row['block_id']]['title'][$row['lang']] = $row['title'];
		}

		$smcFunc['db_free_result']($request);
	}

	/**
	 * Possible actions with blocks
	 * Возможные действия с блоками
	 *
	 * @return void
	 */
	private static function postActions()
	{
		if (!isset($_REQUEST['actions']))
			return;

		self::remove();

		if (!empty($_POST['toggle_status']) && !empty($_POST['item'])) {
			$item   = (int) $_POST['item'];
			$status = str_replace('toggle_status ', '', $_POST['toggle_status']);

			self::toggleStatus($item, $status == 'off' ? 1 : 0);
		}

		self::updatePriority();

		clean_cache();
	}

	/**
	 * Deleting a block
	 * Удаление блока
	 *
	 * @return void
	 */
	private static function remove()
	{
		global $db_type, $smcFunc;

		$item = filter_input(INPUT_POST, 'del_block', FILTER_VALIDATE_INT);

		if (empty($item))
			return;

		if ($db_type == 'postgresql') {
			$smcFunc['db_query']('', '
				DELETE FROM {db_prefix}lp_blocks
				WHERE {db_prefix}lp_blocks.block_id = {int:id}',
				array(
					'id' => $item
				)
			);
			$smcFunc['db_query']('', '
				DELETE FROM {db_prefix}lp_block_titles
				WHERE {db_prefix}lp_block_titles.block_id = {db_prefix}lp_blocks.block_id',
				array(
					'id' => $item
				)
			);
			$smcFunc['db_query']('', '
				DELETE FROM {db_prefix}lp_block_params
				WHERE {db_prefix}lp_block_params.block_id = {db_prefix}lp_blocks.block_id',
				array(
					'id' => $item
				)
			);
		} else {
			$smcFunc['db_query']('', '
				DELETE FROM {db_prefix}lp_blocks, {db_prefix}lp_block_titles, {db_prefix}lp_block_params
				USING {db_prefix}lp_blocks
					LEFT JOIN {db_prefix}lp_block_titles ON ({db_prefix}lp_block_titles.block_id = {db_prefix}lp_blocks.block_id)
					LEFT JOIN {db_prefix}lp_block_params ON ({db_prefix}lp_block_params.block_id = {db_prefix}lp_blocks.block_id)
				WHERE {db_prefix}lp_blocks.block_id = {int:id}',
				array(
					'id' => $item
				)
			);
		}
	}

	/**
	 * Changing the block status
	 * Смена статуса блока
	 *
	 * @param int $item
	 * @param int $status
	 * @return void
	 */
	public static function toggleStatus($item, $status)
	{
		global $smcFunc;

		if (empty($item) || !isset($status))
			return;

		$smcFunc['db_query']('', '
			UPDATE {db_prefix}lp_blocks
			SET status = {int:status}
			WHERE block_id = {int:id}',
			array(
				'status' => $status,
				'id'     => $item
			)
		);
	}

	/**
	 * Update priority
	 * Обновление приоритета
	 *
	 * @return void
	 */
	private static function updatePriority()
	{
		global $smcFunc;

		if (!isset($_POST['update_priority']))
			return;

		$blocks = $_POST['update_priority'];

		$conditions = '';
		foreach ($blocks as $priority => $item)
			$conditions .= ' WHEN block_id = ' . $item . ' THEN ' . $priority;

		if (empty($conditions))
			return;

		if (!empty($blocks) && is_array($blocks)) {
			$smcFunc['db_query']('', '
				UPDATE {db_prefix}lp_blocks
				SET priority = CASE ' . $conditions . '
					ELSE priority
					END
				WHERE block_id IN ({array_int:blocks})',
				array(
					'blocks' => $blocks
				)
			);

			if (!empty($_POST['update_placement'])) {
				$placement = (string) $_POST['update_placement'];

				$smcFunc['db_query']('', '
					UPDATE {db_prefix}lp_blocks
					SET placement = {string:placement}
					WHERE block_id IN ({array_int:blocks})',
					array(
						'placement' => $placement,
						'blocks'    => $blocks
					)
				);
			}
		}
	}

	/**
	 * Adding a block
	 * Добавление блока
	 *
	 * @return void
	 */
	public static function add()
	{
		global $context, $txt, $scripturl;

		isAllowedTo('light_portal_manage');
		loadTemplate('LightPortal/ManageBlocks');

		$context['page_title'] = $txt['lp_portal'] . ' - ' . $txt['lp_blocks_add_title'];
		$context['page_area_title'] = $txt['lp_blocks_add_title'];
		$context['canonical_url'] = $scripturl . '?action=admin;area=lp_blocks;sa=add';

		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title'       => LP_NAME,
			'description' => $txt['lp_blocks_add_tab_description']
		);

		$context['sub_template'] = 'block_add';

		if (!isset($_POST['add_block']))
			return;

		$type = (string) $_POST['add_block'];
		$context['current_block']['type'] = $type;

		Subs::getForumLanguages();

		$context['sub_template'] = 'post_block';

		self::validateData();
		self::prepareFormFields();
		self::prepareEditor();
		self::showPreview();
		self::setData();
	}

	/**
	 * Editing a block
	 * Редактирование блока
	 *
	 * @return void
	 */
	public static function edit()
	{
		global $context, $txt, $user_info, $scripturl;

		isAllowedTo('light_portal_manage');

		$item = !empty($_REQUEST['block_id']) ? (int) $_REQUEST['block_id'] : null;
		$item = $item ?: (!empty($_REQUEST['id']) ? (int) $_REQUEST['id'] : null);

		if (empty($item)) {
			header('HTTP/1.1 404 Not Found');
			fatal_lang_error('lp_block_not_found', false);
		}

		loadTemplate('LightPortal/ManageBlocks');

		$context['page_title'] = $txt['lp_portal'] . ' - ' . $txt['lp_blocks_edit_title'];

		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title'       => LP_NAME,
			'description' => $txt['lp_blocks_edit_tab_description']
		);

		Subs::getForumLanguages();

		$context['sub_template'] = 'post_block';
		$context['current_block'] = self::getData($item);

		self::validateData();

		$block_title = $context['lp_block']['title'][$user_info['language']] ?? '';
		$context['page_area_title'] = $txt['lp_blocks_edit_title'] . (!empty($block_title) ? ' - ' . $block_title : '');
		$context['canonical_url'] = $scripturl . '?action=admin;area=lp_blocks;sa=edit;id=' . $context['lp_block']['id'];

		self::prepareFormFields();
		self::prepareEditor();
		self::showPreview();
		self::setData($context['lp_block']['id']);
	}

	/**
	 * Get the parameters of all blocks
	 * Получаем параметры всех блоков
	 *
	 * @return array
	 */
	private static function getOptions()
	{
		$options = [
			'bbc' => [
				'content' => 'sceditor'
			],
			'html' => [
				'content' => 'textarea'
			],
			'php' => [
				'content' => 'textarea'
			]
		];

		Subs::runAddons('blockOptions', array(&$options));

		return $options;
	}

	/**
	 * Validating the sent data
	 * Валидируем отправляемые данные
	 *
	 * @return void
	 */
	private static function validateData()
	{
		global $context, $txt;

		if (isset($_POST['save']) || isset($_POST['preview'])) {
			$args = array(
				'block_id'      => FILTER_VALIDATE_INT,
				'icon'          => FILTER_SANITIZE_STRING,
				'type'          => FILTER_SANITIZE_STRING,
				'content'       => FILTER_UNSAFE_RAW,
				'placement'     => FILTER_SANITIZE_STRING,
				'priority'      => FILTER_VALIDATE_INT,
				'permissions'   => FILTER_VALIDATE_INT,
				'areas'         => FILTER_SANITIZE_STRING,
				'title_class'   => FILTER_SANITIZE_STRING,
				'title_style'   => FILTER_SANITIZE_STRING,
				'content_class' => FILTER_SANITIZE_STRING,
				'content_style' => FILTER_SANITIZE_STRING
			);

			Subs::runAddons('validateBlockData', array(&$args));

			foreach ($context['languages'] as $lang)
				$args['title_' . $lang['filename']] = FILTER_SANITIZE_STRING;

			$post_data = filter_input_array(INPUT_POST, $args);

			self::findErrors($post_data);
		}

		$options = self::getOptions();
		$block_options = $context['current_block']['options'] ?? $options;

		$context['lp_block'] = array(
			'id'            => $post_data['block_id'] ?? $context['current_block']['id'] ?? 0,
			'title'         => $context['current_block']['title'] ?? [],
			'icon'          => trim($post_data['icon'] ?? $context['current_block']['icon'] ?? ''),
			'type'          => $post_data['type'] ?? $context['current_block']['type'] ?? '',
			'content'       => $post_data['content'] ?? $context['current_block']['content'] ?? '',
			'placement'     => $post_data['placement'] ?? $context['current_block']['placement'] ?? '',
			'priority'      => $post_data['priority'] ?? $context['current_block']['priority'] ?? 0,
			'permissions'   => $post_data['permissions'] ?? $context['current_block']['permissions'] ?? 0,
			'areas'         => $post_data['areas'] ?? $context['current_block']['areas'] ?? 'all',
			'title_class'   => $post_data['title_class'] ?? $context['current_block']['title_class'] ?? '',
			'title_style'   => $post_data['title_style'] ?? $context['current_block']['title_style'] ?? '',
			'content_class' => $post_data['content_class'] ?? $context['current_block']['content_class'] ?? '',
			'content_style' => $post_data['content_style'] ?? $context['current_block']['content_style'] ?? '',
			'options'       => $options[$context['current_block']['type']]
		);

		if (!empty($context['lp_block']['options']['parameters'])) {
			foreach ($context['lp_block']['options']['parameters'] as $option => $value)
				$context['lp_block']['options']['parameters'][$option] = $post_data[$option] ?? $block_options['parameters'][$option] ?? $value;
		}

		foreach ($context['languages'] as $lang)
			$context['lp_block']['title'][$lang['filename']] = $post_data['title_' . $lang['filename']] ?? $context['lp_block']['title'][$lang['filename']] ?? '';

		$context['lp_block']['title'] = Subs::cleanBbcode($context['lp_block']['title']);
	}

	/**
	 * Check that the fields are filled in correctly
	 * Проверям правильность заполнения полей
	 *
	 * @param array $data
	 * @return void
	 */
	private static function findErrors($data)
	{
		global $context, $txt;

		$post_errors = [];

		if (empty($data['areas']))
			$post_errors[] = 'no_areas';

		if (!empty($post_errors)) {
			$_POST['preview'] = true;
			$context['post_errors'] = [];

			foreach ($post_errors as $error)
				$context['post_errors'][] = $txt['lp_post_error_' . $error];
		}
	}

	/**
	 * Adding special fields to the form
	 * Добавляем свои поля для формы
	 *
	 * @return void
	 */
	private static function prepareFormFields()
	{
		global $context, $txt;

		checkSubmitOnce('register');

		$context['posting_fields']['subject'] = ['no'];

		foreach ($context['languages'] as $lang) {
			$context['posting_fields']['title_' . $lang['filename']]['label']['text'] = $txt['lp_title'] . ' [<strong>' . $lang['filename'] . '</strong>]';
			$context['posting_fields']['title_' . $lang['filename']]['input'] = array(
				'type' => 'text',
				'attributes' => array(
					'size'      => '100%',
					'maxlength' => 255,
					'value'     => $context['lp_block']['title'][$lang['filename']] ?? ''
				)
			);
		}

		$context['posting_fields']['icon']['label']['text'] = $txt['current_icon'];
		$context['posting_fields']['icon']['label']['after'] = $txt['lp_block_icon_cheatsheet'];
		$context['posting_fields']['icon']['input'] = array(
			'type' => 'text',
			'after' => '<span id="block_icon">' . self::getIcon() . '</span>',
			'attributes' => array(
				'size'      => '100%',
				'maxlength' => 30,
				'value'     => $context['lp_block']['icon']
			)
		);

		$context['posting_fields']['placement']['label']['text'] = $txt['lp_block_placement'];
		$context['posting_fields']['placement']['input'] = array(
			'type' => 'select',
			'attributes' => array(
				'id' => 'placement'
			),
			'options' => array()
		);

		foreach ($txt['lp_block_placement_set'] as $level => $title) {
			$context['posting_fields']['placement']['input']['options'][$title] = array(
				'value'    => $level,
				'selected' => $level == $context['lp_block']['placement']
			);
		}

		$context['posting_fields']['permissions']['label']['text'] = $txt['edit_permissions'];
		$context['posting_fields']['permissions']['input'] = array(
			'type' => 'select',
			'attributes' => array(
				'id' => 'permissions'
			),
			'options' => array()
		);

		foreach ($txt['lp_permissions'] as $level => $title) {
			$context['posting_fields']['permissions']['input']['options'][$title] = array(
				'value'    => $level,
				'selected' => $level == $context['lp_block']['permissions']
			);
		}

		$context['posting_fields']['areas']['label']['text'] = $txt['lp_block_areas'];
		$context['posting_fields']['areas']['input'] = array(
			'type' => 'text',
			'after' => $txt['lp_block_areas_subtext'],
			'attributes' => array(
				'size'      => '100%',
				'maxlength' => 255,
				'value'     => $context['lp_block']['areas'],
				'required'  => true
			)
		);

		$context['posting_fields']['title_class']['label']['text'] = $txt['lp_block_title_class'];
		$context['posting_fields']['title_class']['input'] = array(
			'type' => 'select',
			'attributes' => array(
				'id' => 'title_class'
			),
			'options' => array()
		);

		foreach ($context['lp_all_title_classes'] as $key => $data) {
			$context['posting_fields']['title_class']['input']['options'][$key] = array(
				'value'    => $key,
				'selected' => $key == $context['lp_block']['title_class']
			);
		}

		$context['posting_fields']['title_style']['label']['text'] = $txt['lp_block_title_style'];
		$context['posting_fields']['title_style']['input'] = array(
			'type' => 'text',
			'attributes' => array(
				'size'      => '100%',
				'maxlength' => 255,
				'value'     => $context['lp_block']['title_style']
			)
		);

		if (empty($context['lp_block']['options']['no_content_class'])) {
			$context['posting_fields']['content_class']['label']['text'] = $txt['lp_block_content_class'];
			$context['posting_fields']['content_class']['input'] = array(
				'type' => 'select',
				'attributes' => array(
					'id' => 'content_class'
				),
				'options' => array()
			);

			foreach ($context['lp_all_content_classes'] as $key => $data) {
				$context['posting_fields']['content_class']['input']['options'][$key] = array(
					'value'    => $key,
					'selected' => $key == $context['lp_block']['content_class']
				);
			}

			$context['posting_fields']['content_style']['label']['text'] = $txt['lp_block_content_style'];
			$context['posting_fields']['content_style']['input'] = array(
				'type' => 'text',
				'attributes' => array(
					'size'      => '100%',
					'maxlength' => 255,
					'value'     => $context['lp_block']['content_style']
				)
			);
		}

		if (!empty($context['lp_block']['options']['content']) && $context['lp_block']['options']['content'] === 'textarea') {
			$context['posting_fields']['content']['label']['text'] = $txt['lp_block_content'];
			$context['posting_fields']['content']['input'] = array(
				'type' => 'textarea',
				'attributes' => array(
					'maxlength' => Subs::getMaxMessageLength(),
					'value'     => $context['lp_block']['content']
				)
			);
		}

		Subs::runAddons('prepareBlockFields');

		loadTemplate('Post');
	}

	/**
	 * Run the desired editor
	 * Подключаем нужный редактор
	 *
	 * @return void
	 */
	private static function prepareEditor()
	{
		global $context;

		if (!empty($context['lp_block']['options']['content']) && $context['lp_block']['options']['content'] === 'sceditor')
			Subs::createBbcEditor($context['lp_block']['content']);
	}

	/**
	 * Preview
	 * Предварительный просмотр
	 *
	 * @return void
	 */
	private static function showPreview()
	{
		global $context, $user_info, $smcFunc, $txt;

		if (!isset($_POST['preview']))
			return;

		checkSubmitOnce('free');

		// Hide active blocks during preview | Во время превью скроем активные блоки
		$context['lp_active_blocks'] = [];

		$context['preview_title']   = Subs::cleanBbcode($context['lp_block']['title'][$user_info['language']]);
		$context['preview_content'] = $smcFunc['htmlspecialchars']($context['lp_block']['content'], ENT_QUOTES);

		censorText($context['preview_title']);
		censorText($context['preview_content']);

		if (empty($context['lp_block']['content']))
			Subs::runAddons('prepareContent', array(&$context['preview_content'], $context['lp_block']['type'], $context['lp_block']['id']));
		else
			Subs::parseContent($context['preview_content'], $context['lp_block']['type']);

		$context['page_title']    = $txt['preview'] . ' - ' . $context['preview_title'];
		$context['preview_title'] = self::getIcon() . $context['preview_title'] . '<span class="floatright">' . $txt['preview'] . '</span>';
	}

	/**
	 * Creating or updating a block
	 * Создаем или обновляем блок
	 *
	 * @param int $item
	 * @return void
	 */
	public static function setData($item = null)
	{
		global $context, $smcFunc;

		if (!empty($context['post_errors']) || !isset($_POST['save']))
			return;

		checkSubmitOnce('check');

		if (empty($item)) {
			$max_length = Subs::getMaxMessageLength();

			$item = $smcFunc['db_insert']('',
				'{db_prefix}lp_blocks',
				array(
					'icon'          => 'string-30',
					'type'          => 'string',
					'content'       => 'string-' . $max_length,
					'placement'     => 'string-10',
					'priority'      => 'int',
					'permissions'   => 'int',
					'areas'         => 'string',
					'title_class'   => 'string',
					'title_style'   => 'string',
					'content_class' => 'string',
					'content_style' => 'string'
				),
				array(
					$context['lp_block']['icon'],
					$context['lp_block']['type'],
					$context['lp_block']['content'],
					$context['lp_block']['placement'],
					$context['lp_block']['priority'],
					$context['lp_block']['permissions'],
					$context['lp_block']['areas'],
					$context['lp_block']['title_class'],
					$context['lp_block']['title_style'],
					$context['lp_block']['content_class'],
					$context['lp_block']['content_style']
				),
				array('block_id'),
				1
			);

			if (!empty($context['lp_block']['title'])) {
				$titles = [];
				foreach ($context['lp_block']['title'] as $lang => $title) {
					$titles[] = array(
						'block_id' => $item,
						'lang'     => $lang,
						'title'    => $title
					);
				}

				$smcFunc['db_insert']('',
					'{db_prefix}lp_block_titles',
					array(
						'block_id' => 'int',
						'lang'     => 'string',
						'title'    => 'string'
					),
					$titles,
					array('block_id', 'lang')
				);
			}

			if (!empty($context['lp_block']['options']['parameters'])) {
				$parameters = [];
				foreach ($context['lp_block']['options']['parameters'] as $param_name => $value) {
					$parameters[] = array(
						'block_id' => $item,
						'name'     => $param_name,
						'value'    => $value
					);
				}

				$smcFunc['db_insert']('',
					'{db_prefix}lp_block_params',
					array(
						'block_id' => 'int',
						'name'     => 'string',
						'value'    => 'string'
					),
					$parameters,
					array('block_id', 'name')
				);
			}
		} else {
			$smcFunc['db_query']('', '
				UPDATE {db_prefix}lp_blocks
				SET icon = {string:icon}, type = {string:type}, content = {string:content}, placement = {string:placement}, priority = {int:priority}, permissions = {int:permissions}, areas = {string:areas}, title_class = {string:title_class}, title_style = {string:title_style}, content_class = {string:content_class}, content_style = {string:content_style}
				WHERE block_id = {int:block_id}',
				array(
					'block_id'      => $item,
					'icon'          => $context['lp_block']['icon'],
					'type'          => $context['lp_block']['type'],
					'content'       => $context['lp_block']['content'],
					'placement'     => $context['lp_block']['placement'],
					'priority'      => $context['lp_block']['priority'],
					'permissions'   => $context['lp_block']['permissions'],
					'areas'         => $context['lp_block']['areas'],
					'title_class'   => $context['lp_block']['title_class'],
					'title_style'   => $context['lp_block']['title_style'],
					'content_class' => $context['lp_block']['content_class'],
					'content_style' => $context['lp_block']['content_style']
				)
			);

			if (!empty($context['lp_block']['title'])) {
				$titles = [];
				foreach ($context['lp_block']['title'] as $lang => $title) {
					$titles[] = array(
						'block_id' => $item,
						'lang'     => $lang,
						'title'    => $title
					);
				}

				$smcFunc['db_insert']('replace',
					'{db_prefix}lp_block_titles',
					array(
						'block_id' => 'int',
						'lang'     => 'string',
						'title'    => 'string'
					),
					$titles,
					array('block_id', 'lang')
				);
			}

			if (!empty($context['lp_block']['options']['parameters'])) {
				$parameters = [];
				foreach ($context['lp_block']['options']['parameters'] as $param_name => $value) {
					$parameters[] = array(
						'block_id' => $item,
						'name'     => $param_name,
						'value'    => $value
					);
				}

				$smcFunc['db_insert']('replace',
					'{db_prefix}lp_block_params',
					array(
						'block_id' => 'int',
						'name'     => 'string',
						'value'    => 'string'
					),
					$parameters,
					array('block_id', 'name')
				);
			}
		}

		clean_cache();
		redirectexit('action=admin;area=lp_blocks;sa=main');
	}

	/**
	 * Get the block fields
	 * Получаем поля блока
	 *
	 * @param mixed $item
	 * @return array
	 */
	public static function getData($item)
	{
		global $smcFunc;

		if (empty($item))
			return;

		$request = $smcFunc['db_query']('', '
			SELECT
				b.block_id, b.icon, b.type, b.content, b.placement, b.priority, b.permissions, b.status, b.areas, b.title_class, b.title_style, b.content_class, b.content_style,
				bt.lang, bt.title, bp.name, bp.value
			FROM {db_prefix}lp_blocks AS b
				LEFT JOIN {db_prefix}lp_block_titles AS bt ON (bt.block_id = b.block_id)
				LEFT JOIN {db_prefix}lp_block_params AS bp ON (bp.block_id = b.block_id)
			WHERE b.block_id = {int:item}',
			array(
				'item' => $item
			)
		);

		if ($smcFunc['db_num_rows']($request) == 0)	{
			header('HTTP/1.1 404 Not Found');
			fatal_lang_error('lp_block_not_found', false);
		}

		while ($row = $smcFunc['db_fetch_assoc']($request)) {
			censorText($row['content']);

			if (!isset($data))
				$data = array(
					'id'            => $row['block_id'],
					'icon'          => $row['icon'],
					'type'          => $row['type'],
					'content'       => $row['content'],
					'placement'     => $row['placement'],
					'priority'      => $row['priority'],
					'permissions'   => $row['permissions'],
					'status'        => $row['status'],
					'areas'         => $row['areas'],
					'title_class'   => $row['title_class'],
					'title_style'   => $row['title_style'],
					'content_class' => $row['content_class'],
					'content_style' => $row['content_style'],
					'can_show'      => Subs::canShowItem($row['permissions'])
				);

			$data['title'][$row['lang']] = $row['title'];

			if (!empty($row['name']))
				$data['options']['parameters'][$row['name']] = $row['value'];
		}

		$smcFunc['db_free_result']($request);

		return $data;
	}

	/**
	 * Get the block icon
	 * Получаем иконку блока
	 *
	 * @param string $icon
	 * @return string
	 */
	public static function getIcon($icon = null)
	{
		global $context;

		$icon = $icon ?? ($context['lp_block']['icon'] ?? '');

		if (!empty($icon))
			return '<i class="fas fa-' . $icon . '"></i> ';
		else
			return '';
	}
}
