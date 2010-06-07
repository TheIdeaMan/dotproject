<?php /* HISTORY $Id$ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

/** 
 * History module
 * J. Christopher Pereira (kripper@imatronix.cl)
 * IMATRONIX
 */

$AppUI->savePlace();
$titleBlock = new CTitleBlock( 'History', 'stock_book_blue_48.png', $m, "$m.$a" );
$titleBlock->show();

function show_history($history)
{
//        return $history;
	GLOBAL $AppUI;
        $id = $history['history_item'];
        $module = $history['history_table'];        
	$table_id = (substr($module, -1) == 's'?substr($module, 0, -1):$module);
	if (substr($table_id, -2) == 'ie')
		$table_id = substr($table_id, 0, -2) . 'y';
 	$table_id .= '_id';
	$item_name = substr($table_id, 0, -2) . 'name';
	if ($module == 'modules')
	{
		$table_id = 'mod_id';
		$table_name = 'mod_name';
	}
        
        if ($module == 'login')
               return 'User \'' . $history['history_description'] . '\' ' . $history['history_action'] . '.';
        
        if ($history['history_action'] == 'add')
                $msg = $AppUI->_('Added new').' ';
        else if ($history['history_action'] == 'update')
                $msg = $AppUI->_('Modified').' ';
        else if ($history['history_action'] == 'delete')
                return $AppUI->_('Deleted').' \'' . $history['history_description'] . '\' '.$AppUI->_('from').' ' . $AppUI->_($module) . $AppUI->_('module');

	$q  = new DBQuery;
	$q->addTable($module);
	$q->addQuery('*');
	$q->addWhere($table_id.' ='.$id);
	list($item) = $q->loadList();
	if ($item)
        switch ($module)
        {
        case 'history':
                $link = '&amp;a=addedit&amp;history_id='; break;
        case 'files':
                $link = '&amp;a=addedit&amp;file_id='; break;
        case 'tasks':
                $link = '&amp;a=view&amp;task_id='; break;
        case 'forums':
                $link = '&amp;a=viewer&amp;forum_id='; break;
        case 'projects':
                $link = '&amp;a=view&amp;project_id='; break;
        case 'companies':
                $link = '&amp;a=view&amp;company_id='; break;
        case 'contacts':
                $link = '&amp;a=view&amp;contact_id='; break;
        case 'task_log':
                $module = 'Tasks'; // TODO: task_id = 170?!? Why?
                $link = '&amp;a=view&amp;task_id=170&amp;tab=1&amp;task_log_id=';
                break;
        }

	if (!empty($link)) 
		$link = '<a href="?m='.$module.$link.$id.'">'.($item[$item_name]?$item[$item_name]:$history['history_description']).'</a>';
	else
		$link = ($item[$item_name]?$item[$item_name]:$history['history_description']);
		$msg .= $AppUI->_('item')." '$link' ".$AppUI->_('in').' '.$AppUI->_(ucfirst($module)).' '.$AppUI->_('module'); // . $history;
	
        return $msg;
}

$filter = array();
if (!empty($_REQUEST['filter']))
        $filter[] = 'history_table = \'' . $_REQUEST['filter'] . '\' ';
if (!empty($_REQUEST['project_id']))
{
	$project_id = $_REQUEST['project_id'];
	
$q  = new DBQuery;
$q->addTable('tasks');
$q->addQuery('task_id');
$q->addWhere('task_project = ' . $project_id);
$project_tasks = implode(',', $q->loadColumn());
if (!empty($project_tasks))
	$project_tasks = "OR (history_table = 'tasks' AND history_item IN ($project_tasks))";

$q->addTable('files');
$q->addQuery('file_id');
$q->addWhere('file_project = ' . $project_id);
$project_files = implode(',', $q->loadColumn());
if (!empty($project_files))
	$project_files = "OR (history_table = 'files' AND history_item IN ($project_files))";

	$filter[] = "(
	(history_table = 'projects' AND history_item = '$project_id')
	$project_tasks
	$project_files
	)";
}

$page = dPgetParam($_GET, 'page', 1);
$offset = ($page - 1) * dPgetConfig('page_size');

$q  = new DBQuery;
$q->addTable('history');
$q->addTable('users');
$q->addWhere('history_user = user_id');
$q->addWhere($filter);
$q->addOrder('history_date DESC');
$q->setLimit(dPgetConfig('page_size'), $offset);
$history = $q->loadList();


$q->addQuery('count(*)');
$q->addTable('history');
$q->addTable('users');
$q->addWhere('history_user = user_id');
$q->addWhere($filter);
$history_count = $q->loadResult();

foreach ($history as $key => $row)
{
	$module = $row['history_table'] == 'task_log'?'tasks':$row['history_table'];
	$row['history_table'] = $module;
	$history[$key]['history_display'] = show_history($row);

	$perms = $AppUI->acl();
  if ($module != 'login' && !$perms->checkModuleItem($module, "access", $row['history_item']))
  	unset($history[$key]);
}

global $tpl;
$tpl->displayList('history', $history, $history_count);
?>