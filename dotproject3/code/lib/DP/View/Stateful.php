<?php
/**
 * View that will receive changes in state.
 * 
 * Views that inherit this class can interpret POST or GET variables
 * and change their internal representation accordingly. Saving the state
 * of the view is also supported through the DP_View_Stateful::saveState() method.
 * @todo The observer pattern may replace DP_View_Stateful
 * @package dotproject
 * @subpackage system
 * @version not.even.alpha
 */
class DP_View_Stateful extends DP_View {
	/**
	 * @var string $url_prefix Prefix to use with links generated by the view.
	 */
	protected $url_prefix;
	/**
	 * @var bool $persist_state Indicates whether this views state should be saved by the session.
	 */
	protected $persist_state;	
	
	function __construct($id) {
		parent::__construct($id);
		
		$this->persist_state = true;
	}
	
	/**
	 * Save the state of the view to a session variable.
	 * 
	 * The state of this object will be saved to a session variable with the same ID.
	 * 
	 * @param mixed $state The nominated state variable
	 */
	public function saveState($state) {
		if ($this->persist_state) {		
			$AppUI = DP_AppUI::getInstance();
			$AppUI->setState($this->id(), $state);
		}
	}
	
	/**
	 * Load the state of the view from a session variable
	 * 
	 * @param mixed $default The variable to use if nothing is registered with the session.
	 * @return mixed The state of this view
	 */
	public function loadState($default = null) {
		$AppUI = DP_AppUI::getInstance();
		$state = $AppUI->getState($this->id(), $default);
		return $state;
	}
	
	/**
	 * Set whether the state of this view is persistent i.e should be saved in the session
	 * 
	 * @param bool $persist_state Boolean, if the view's state should be saved in the session.
	 */
	public function setPersistent($persist_state = true) {
		$this->persist_state = $persist_state;
	}
	
	/**
	 * Update child views with server request object.
	 * 
	 * If the child objects are capable of handling request variables (Instances of DP_View_Stateful).
	 * The server request variables will be passed on.
	 * 
	 * @param mixed $request Server request object
	 */
	protected function updateChildrenFromServer($request) {
		foreach ($this->child_views as $child) {
			if ($child['view'] instanceof DP_View_Stateful) {
				$child['view']->updateStateFromServer($request); 
			}
		}
	}
	
	/**
	 * Get the URL up to the action but not including parameters.
	 * @todo FIX action name
	 */
	public function getActionUrl() {
		$fc = Zend_Controller_Front::getInstance();
		$req = $fc->getRequest();
		$action_url = $req->getBaseUrl().'/'.$req->getModuleName().'/'.$req->getActionName();
		return $action_url;
	}
	
	/**
	 * Get the URL prefix used when generating links.
	 * 
	 * @return string The full url prefix.
	 */
	public function getUrlPrefix() {
		return $this->url_prefix;
	}
	
	/**
	 * Set the URL prefix to use when generating links.
	 * 
	 * @param string $url_prefix The full url prefix.
	 */
	public function setUrlPrefix($url_prefix) {
		$this->url_prefix = $url_prefix;
	}
	
	/**
	 * Handle any POST or GET requests.
	 * 
	 * This method tries to access the object's variables in the server request object.
	 * If it finds relevant variables, it will update this object and the changes will be reflected
	 * when the view is rendered. The updates can be saved in the session by calling DP_View_Stateful::saveState()
	 * 
	 * @param mixed $request Server request object.
	 */
	public function updateStateFromServer($request) {
		$this->updateChildrenFromServer($request);
	}
}
?>