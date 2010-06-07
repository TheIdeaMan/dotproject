<?php /* ADMIN $Id$ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

/**
 * User Class
 */
class CUser extends CDpObject {
	var $user_id = null;
	var $user_username = null;
	var $user_password = null;
	var $user_parent = null;
	var $user_type = null;
	var $user_contact = null;
	var $user_signature = null;

	/**
	 * Default constructor.
	 * Sets up table name, key fields and search fields.
	 */
	function CUser() {
		$this->CDpObject('users', 'user_id');
		$this->_tbl_name = 'user_username';
		$this->search_fields = array ('user_username', 'user_signature');
	}

	/**
	 * Prepare the object for saving.
	 * 
	 * @return mixed an error message or null if the checks were successful.
	 */
	function check() {
		if ($this->user_id === NULL) {
			return 'user id is NULL';
		}
		if ($this->user_password !== NULL) {
			$this->user_password = db_escape(trim($this->user_password));
		}
		// TODO: more
		
		return NULL; // object is ok
	}

	/**
	 * Save the object
	 * 
	 * @return mixed an error message or null if the checks were successful.
	 */
	function store() {
		$msg = $this->check();
		if( $msg ) {
			return get_class( $this )."::store-check failed";
		}
		$q  = new DBQuery;
		if( $this->user_id ) {
		// save the old password
			$perm_func = 'updateLogin';
			$q->addTable('users');
			$q->addQuery('user_password');
			$q->addWhere('user_id = ' . $this->user_id);
			$pwd = $q->loadResult();
			if ($pwd != $this->user_password) {
				$this->user_password = md5($this->user_password);
			} else {
				$this->user_password = null;
			}

			$ret = db_updateObject( 'users', $this, 'user_id', false );
		} else {
			$perm_func = 'addLogin';
			$this->user_password = md5($this->user_password);
			$ret = db_insertObject( 'users', $this, 'user_id' );
		}
		if( !$ret ) {
			return get_class( $this ).'::store failed <br />' . db_error();
		} else {
			$acl = $GLOBALS['AppUI']->acl();
			$acl->$perm_func($this->user_id, $this->user_username);
			//Insert Default Preferences
			//Lets check if the user has already default users preferences set, if not insert the default ones
			$q->addTable('user_preferences', 'upr');
			$q->addWhere('upr.pref_user = ' . $this->user_id);
			$uprefs = $q->loadList();
			$q->clear();
			if (!count($uprefs) && $this->user_id > 0) {
				//Lets get the default users preferences
				$q->addTable('user_preferences', 'dup');
				$q->addWhere('dup.pref_user = 0');
				$dprefs = $q->loadList();
				$q->clear();
				
				foreach ($dprefs as $dprefskey => $dprefsvalue) {
					$q->addTable('user_preferences', 'up');
					$q->addInsert('pref_user', $this->user_id);
					$q->addInsert('pref_name', $dprefsvalue['pref_name']);
					$q->addInsert('pref_value', $dprefsvalue['pref_value']);
					$q->exec();
					$q->clear();
				}
			}

			return NULL;
		}
	}

	/**
	 * Delete the object
	 * 
	 * @param integer $oid 	optional parameter with the object id to delete. 
	 * 											It uses the one from the object if it's missing.
	 * @return mixed null if the operation was successful or an error message otherwise.
	 */
	function delete($oid = null) {
		$id = $this->user_id;
		$result = parent::delete($oid);
		if (! $result) {
			$acl = $GLOBALS['AppUI']->acl();
			$acl->deleteLogin($id);
			$q  = new DBQuery;
			$q->setDelete('user_preferences');
			$q->addWhere('pref_user = '.$this->user_id);
			$q->exec();
			$q->clear();
		}
		
		return $result;
 	}
}
?>