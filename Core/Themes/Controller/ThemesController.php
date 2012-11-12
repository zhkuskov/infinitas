<?php
/**
 * Themes controller for managing the themes in infinitas
 *
 * Copyright (c) 2009 Carl Sutton ( dogmatic69 )
 *
 *
 *
 * @filesource
 * @copyright Copyright (c) 2009 Carl Sutton ( dogmatic69 )
 * @link http://infinitas-cms.org
 * @package Core.Themes.Controller
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @since 0.5a
 */

class ThemesController extends ThemesAppController {
/**
 * list available themes
 *
 * @return void
 */
	public function admin_index() {
		$this->Theme->recursive = 1;
		$themes = $this->Paginator->paginate(null, $this->Filter->filter);

		$filterOptions = $this->Filter->filterOptions;
		$filterOptions['fields'] = array(
			'name' => $this->Theme->find('list'),
			'licence',
			'author',
			'core' => Configure::read('CORE.core_options'),
			'active' => Configure::read('CORE.active_options')
		);

		$this->set(compact('themes', 'filterOptions'));
	}

/**
 * add a new theme
 *
 * @return void
 */
	public function admin_add() {
		parent::admin_add();

		if(!$themes = $this->Theme->notInstalled()) {
			$this->notice(
				__d('themes', 'You do not have any themes to add'),
				array(
					'level' => 'warning',
					'redirect' => true
				)
			);
		}

		$this->set(compact('themes'));
	}

/**
 * edit an existing theme
 *
 * @param string $id the id of the theme to edit
 *
 * @return void
 */
	public function admin_edit($id) {
		parent::admin_edit($id);
		$themes = $this->Theme->notInstalled();
		$themes[$this->request->data['Theme']['name']] = $this->request->data['Theme']['name'];
		try{
			$defaultLayouts = InfinitasTheme::layouts($this->request->data['Theme']['id']);
		} catch(Exception $e) {
			$this->notice($e->getMessage(), array(
				'level' => 'warning'
			));
		}
		$this->set(compact('themes', 'defaultLayouts'));
	}

	public function frontend_css() {
		$this->layout = 'ajax';
		$this->response->type('css');
		$css = $this->Event->trigger('requireCssToLoad');
		$this->set('css', array_filter(array_values(Set::flatten($css))));
	}

	/**
	 * Mass toggle action.
	 *
	 * This overwrites the default toggle action so that other themes can
	 * be deactivated first as you should only have one active at a time.
	 *
	 * @var array $ids the id of the theme to toggle
	 */
	public function __massActionToggle($ids) {
		if (count($ids) > 1) {
			$this->notice(
				__d('themes', 'Please select only one theme to be active'),
				array(
					'level' => 'warning',
					'redirect' => true
				)
			);
		}

		if ($this->Theme->deactivateAll()) {
			return $this->MassAction->toggle($ids);
		}

		$this->notice(
			__d('themes', 'There was a problem deactivating the other theme'),
			array(
				'level' => 'error',
				'redirect' => true
			)
		);
	}

	/**
	 * redirect to the installer to add a new theme.
	 *
	 * @param null $ids not used
	 */
	public function __massActionInstall($ids) {
		$this->redirect(
			array(
				'plugin' => 'installer',
				'controller' => 'plugins',
				'action' => 'install'
			)
		);
	}
}