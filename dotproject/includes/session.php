<?php /* $Id$ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

/**
 * Session Handling Functions
 * Please note that these functions assume that the database
 * is accessible and that a table called 'sessions' (with a prefix
 * if necessary) exists.  It also assumes MySQL date and time
 * functions, which may make it less than easy to port to
 * other databases.  You may need to use less efficient techniques
 * to make it more generic.
 *
 * NOTE: index.php MUST call dPsessionStart
 * instead of trying to set their own sessions.
 */

require_once DP_BASE_DIR . '/includes/main_functions.php';
require_once DP_BASE_DIR . '/includes/db_adodb.php';
require_once DP_BASE_DIR . '/includes/db_connect.php';
require_once DP_BASE_DIR . '/classes/query.class.php';
require_once DP_BASE_DIR . '/classes/ui.class.php';
require_once DP_BASE_DIR . '/classes/event_queue.class.php';

function dPsessionOpen($save_path, $session_name)
{
	return true;
}

function dPsessionClose()
{
	return true;
}

function dPsessionRead($id)
{
	$q  = new DBQuery;
	$q->addTable('sessions');
	$q->addQuery('session_data');
	$q->addQuery('session_created, session_updated');
	
	$q->addWhere("session_id = '$id'");
	$qid = $q->exec();
	if (! $qid || $qid->EOF ) {
		dprint(__FILE__, __LINE__, 11, "Failed to retrieve session $id");
		$data =  "";
	} else {
		$session_lifespan = time() - db_dateTime2unix($qid->fields['session_created']);
		$session_idle = time() - db_dateTime2unix($qid->fields['session_updated']);

		$max = dPsessionConvertTime('max_lifetime');
		$idle = dPsessionConvertTime('idle_time');
		dprint(__FILE__, __LINE__, 11, "Found session $id, max=$max/" . $session_lifespan
		. ", idle=$idle/" . $session_idle);
		// If the idle time or the max lifetime is exceeded, trash the
		// session.
		if ($max < $session_lifespan
		 || $idle < $session_idle) {
			dprint(__FILE__, __LINE__, 11, "session $id expired");
			dPsessionDestroy($id);
			$data = '';
		} else {
			$data = $qid->fields['session_data'];
		}
	}
	$q->clear();
	return $data;
}

function dPsessionWrite($id, $data)
{
	global $AppUI;
	$q = new DBQuery;
	$q->addQuery('count(*) as row_count');
	$q->addTable('sessions');
	$q->addWhere("session_id = '$id'");
	$qid = $q->loadResult();

	$q->addTable('sessions');
	if ( $qid > 0 ) {
		dprint(__FILE__, __LINE__, 11, 'Updating session ' . $id);
		$q->addUpdate('session_data', $data);
		if (isset($AppUI))
			$q->addUpdate('session_user', $AppUI->last_insert_id);
		$q->addWhere("session_id = '$id'");
	} else {
		dprint(__FILE__, __LINE__, 11, 'Creating new session ' . $id);
		$q->addInsert('session_id', $id);
		$q->addInsert('session_data', $data);
		$q->addInsert('session_created', date('Y-m-d H:i:s'));
	}
	$q->exec();
	$q->clear();
	return true;
}

function dPsessionDestroy($id, $user_access_log_id = 0)
{
	global $AppUI;

	if(!($user_access_log_id) && isset($AppUI->last_insert_id))
		$user_access_log_id = $AppUI->last_insert_id;
	
	
	dprint(__FILE__, __LINE__, 11, "Killing session $id");
	$q = new DBQuery;
	$q->setDelete('sessions');
	$q->addWhere("session_id = '$id'");
	$q->exec();
	$q->clear();

	if ($user_access_log_id)
	{
		$q->addTable('user_access_log');
		$q->addUpdate('date_time_out', date("Y-m-d H:i:s"));
		$q->addWhere('user_access_log_id = ' . $user_access_log_id);
		$q->exec();
		$q->clear();
	}
	
	return true;
}

function dPsessionGC($maxlifetime)
{
	global $AppUI;

	dprint(__FILE__, __LINE__, 11, "Session Garbage collection running");
	$now = time();
	$max = dPsessionConvertTime('max_lifetime');
	$idle = dPsessionConvertTime('idle_time');
	// Find all the session
	$q = new DBQuery;
	$q->addQuery('session_id, session_user');
	$q->addTable('sessions');
	$q->addWhere("UNIX_TIMESTAMP() - UNIX_TIMESTAMP(session_updated) > $idle OR UNIX_TIMESTAMP() - UNIX_TIMESTAMP(session_created) > $max");
	$sessions = $q->loadList();
	$q->clear();

	$session_ids = '';
	$users = '';
	if (is_array($sessions))
	{
		foreach($sessions as $session)
		{
			$session_ids .= $session['session_id'] . ',';
			$users .= $session['session_user'] . ',';
		}
	}

	if (!empty($users))
	{
		$users = substr($users, 0, -1);
	
		$q->clear();
		$q->addTable('user_access_log');
		$q->addUpdate('date_time_out', date("Y-m-d H:i:s"));
		$q->addWhere('user_access_log_id IN (' . $users . ')');
		$q->exec();
		$q->clear();
	}

	if (!empty($session_ids))
	{
		$session_ids = substr($session_ids, 0, -1);
		$q->setDelete('sessions');
		$q->addWhere('session_id in (\'' . $session_ids . '\')');
		$q->exec();
		$q->clear();
	}
	if (dPgetConfig('session_gc_scan_queue')) {
		// We need to scan the event queue.  If $AppUI isn't created yet
		// And it isn't likely that it will be, we create it and run the
		// queue scanner.
		if (! isset($AppUI)) {
			$AppUI = new CAppUI;
			$queue = new EventQueue;
			$queue->scan();
		}
	}
	return true;
}

function dPsessionConvertTime($key)
{
	$key = 'session_' . $key;

	// If the value isn't set, then default to 1 day.
	if (! dPgetConfig($key) )
		return 86400;

	$numpart = (int) dPgetConfig($key);
	$modifier = substr(dPgetConfig($key), -1);
	if (! is_numeric($modifier)) {
		switch ($modifier) {
			case 'h':
				$numpart *= 3600;
				break;
			case 'd':
				$numpart *= 86400;
				break;
			case 'm':
				$numpart *= (86400 * 30);
				break;
			case 'y':
				$numpart *= (86400 * 365);
				break;
		}
	}
	return $numpart;
}

function dpSessionStart($start_vars = 'AppUI')
{
	session_name(dPgetConfig('session_name', 'dotproject'));
	
	if (ini_get('session.auto_start') > 0) {
		session_write_close();
	}
	if (strtolower(dPgetConfig('session_handling')) == 'app') 
	{
		ini_set('session.save_handler', 'user');
	
		// PHP 5.2 workaround
		if (version_compare(phpversion(), '5.2.0', '>=')) {
			register_shutdown_function('session_write_close');
		}
	
		session_set_save_handler(
			'dPsessionOpen', 
			'dPsessionClose', 
			'dPsessionRead', 
			'dPsessionWrite', 
			'dPsessionDestroy', 
			'dPsessionGC');
		$max_time = dPsessionConvertTime('max_lifetime');
	} else {
		$max_time = 0; // Browser session only.
	}
	// Try and get the correct path to the base URL.
	preg_match('_^(https?://)([^/]+)(:0-9]+)?(/.*)?$_i', DP_BASE_URL, $url_parts);
	$cookie_dir = $url_parts[4];
	if (substr($cookie_dir, 0, 1) != '/') {
		$cookie_dir = '/' . $cookie_dir;
	}
	if (substr($cookie_dir, -1) != '/') {
		$cookie_dir .= '/';
	}
	session_set_cookie_params($max_time, $cookie_dir);
	
	if (is_array($start_vars)) {
		foreach ($start_vars as $var) {
			$_SESSION[$var] =  $GLOBALS[$var];
		}
	} else if (!(empty($start_vars))) {
		$_SESSION[$start_vars] =  $GLOBALS[$start_vars];
	}
	
	session_start();
}

// vi:ai sw=2 ts=2:
// vim600:ai sw=2 ts=2 fdm=marker: