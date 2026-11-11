<?php
/**
 * Registers and runs WordPress hooks.
 *
 * @package ShapeFormsLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader class.
 */
class ShapeForms_Lite_Loader {

	/**
	 * Registered actions.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $actions = array();

	/**
	 * Registered filters.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $filters = array();

	/**
	 * Adds a new action.
	 *
	 * @param string   $hook          Action hook name.
	 * @param object   $component     Instance containing the callback.
	 * @param string   $callback      Callback method name.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return void
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add(
			$this->actions,
			$hook,
			$component,
			$callback,
			$priority,
			$accepted_args
		);
	}

	/**
	 * Adds a new filter.
	 *
	 * @param string   $hook          Filter hook name.
	 * @param object   $component     Instance containing the callback.
	 * @param string   $callback      Callback method name.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return void
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters = $this->add(
			$this->filters,
			$hook,
			$component,
			$callback,
			$priority,
			$accepted_args
		);
	}

	/**
	 * Registers stored hooks with WordPress.
	 *
	 * @return void
	 */
	public function run() {
		foreach ( $this->filters as $hook ) {
			add_filter(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}

		foreach ( $this->actions as $hook ) {
			add_action(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}
	}

	/**
	 * Stores a hook definition.
	 *
	 * @param array<int, array<string, mixed>> $hooks         Existing hooks.
	 * @param string                           $hook          Hook name.
	 * @param object                           $component     Instance containing the callback.
	 * @param string                           $callback      Callback method name.
	 * @param int                              $priority      Hook priority.
	 * @param int                              $accepted_args Number of accepted arguments.
	 * @return array<int, array<string, mixed>>
	 */
	private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
		$hooks[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return $hooks;
	}
}
