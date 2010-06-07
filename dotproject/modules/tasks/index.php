<?php /* TASKS $Id$ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

$AppUI->savePlace();
$perms = $AppUI->acl();
// retrieve any state parameters
global $user_id;
$user_id = $AppUI->user_id;
if($perms->checkModule('admin', 'view')){ // Only sysadmins are able to change users
	if(dPgetParam($_POST, 'user_id', 0) != 0){ // this means that 
		$user_id = dPgetParam($_POST, 'user_id', 0);
		$AppUI->setState('user_id', $_POST['user_id']);
	} else if ($AppUI->getState('user_id')){
		$user_id = $AppUI->getState('user_id');
	} else {
		$AppUI->setState('user_id', $user_id);
	}
}

if (isset( $_POST['f'] )) {
	$AppUI->setState( 'TaskIdxFilter', $_POST['f'] );
}
$f = $AppUI->getState( 'TaskIdxFilter' ) ? $AppUI->getState( 'TaskIdxFilter' ) : 'myunfinished';

if (isset( $_POST['f2'] )) {
	$AppUI->setState( 'CompanyIdxFilter', $_POST['f2'] );
}
$f2 = $AppUI->getState( 'CompanyIdxFilter' ) ? $AppUI->getState( 'CompanyIdxFilter' ) : 'all';

if (isset( $_GET['project_id'] )) {
	$AppUI->setState( 'TaskIdxProject', $_GET['project_id'] );
}
$project_id = $AppUI->getState( 'TaskIdxProject' ) ? $AppUI->getState( 'TaskIdxProject' ) : 0;

if (isset( $_POST['task_status'] )) {
	$AppUI->setState( 'TaskStatusIdxFilter', $_POST['task_status'] );
}
$task_status = $AppUI->getState( 'TaskStatusIdxFilter' ) ? $AppUI->getState( 'TaskStatusIdxFilter' ) : 0;

// get CCompany() to filter tasks by company
require_once( $AppUI->getModuleClass( 'companies' ) );
$obj = new CCompany();
$companies = $obj->getAllowedRecords( $AppUI->user_id, 'company_id,company_name', 'company_name' );
$filters2 = arrayMerge(  array( 'all' => $AppUI->_('All', UI_OUTPUT_RAW) ), $companies );

// setup the title block
$titleBlock = new CTitleBlock( 'Tasks', 'applet-48.png', $m, $m.$a );

// patch 2.12.04 text to search entry box
if (isset( $_POST['searchtext'] )) {
	$AppUI->setState( 'searchtext', $_POST['searchtext']);
}

$search_text = $AppUI->getState('searchtext') ? $AppUI->getState('searchtext'):'';
$search_text = dPformSafe($search_text, true);

$titleBlock->addCell( '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $AppUI->_('Search') . ':' );
$titleBlock->addCell(
	'
<form action="?m=tasks" method="post" id="searchfilter">
	<input type="text" class="text" SIZE="20" name="searchtext" onChange="document.searchfilter.submit();" value=' . "'$search_text'" .
	'title="'. $AppUI->_('Search in name and description fields') . '"/>
       	<!--<input type="submit" class="button" value=">" title="'. $AppUI->_('Search in name and description fields') . '"/>-->
</form>', '',	'', '');


// Let's see if this user has admin privileges
if(!getDenyRead('admin')){
	$titleBlock->addCell();
	$titleBlock->addCell( $AppUI->_('User') . ':' );
	
	$user_list = $perms->getPermittedUsers('tasks');
	$titleBlock->addCell(
'<form action="?m=tasks" method="post" name="userIdForm">'.
		arraySelect($user_list, 'user_id', 'size="1" class="text" onChange="document.userIdForm.submit();"', $user_id, false) . 
'</form>', '','','');
}

$titleBlock->addCell();
$titleBlock->addCell( $AppUI->_('Company') . ':' );
$titleBlock->addCell(
'<form action="?m=tasks" method="post" name="companyFilter">'.
	arraySelect( $filters2, 'f2', 'size=1 class=text onChange="document.companyFilter.submit();"', $f2, false ) . 
'</form>', '', '', '');


$titleBlock->addCell();
if ($canEdit && $project_id) {
	$titleBlock->addCell(
		'
<form action="?m=tasks&amp;a=addedit&amp;task_project=' . $project_id . '" method="post">
	<input type="submit" class="button" value="'.$AppUI->_('new task').'">
</form>', '', '', '');
}

$titleBlock->show();

// use a new title block (a new row) to prevent from oversized sites
$titleBlock = new CTitleBlock('', 'shim.gif');
$titleBlock->showhelp = false;
$titleBlock->addCell( '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $AppUI->_('Task Filter') . ':' );
$titleBlock->addCell(
'<form action="?m=tasks" method="post" name="taskFilter">'.
	arraySelect( $filters, 'f', 'size=1 class=text onChange="document.taskFilter.submit();"', $f, true ) .
'</form>', '', '', '');
$titleBlock->addCell( '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $AppUI->_('Task Status') . ':' );
$titleBlock->addCell(
'<form action="?m=tasks" method="post" name="taskStatus">' .
	arraySelect( $status, 'task_status', 'size=1 class=text onChange="document.taskStatus.submit();"', $task_status, true ) . 
'</form>', '', '', '');
$titleBlock->addCell();

$titleBlock->addCrumb( '?m=tasks&amp;a=todo&amp;user_id='.$user_id, 'my todo' );
if (dPgetParam($_GET, 'pinned') == 1)
        $titleBlock->addCrumb( '?m=tasks', 'all tasks' );
else
        $titleBlock->addCrumb( '?m=tasks&amp;pinned=1', 'my pinned tasks' );
$titleBlock->addCrumb( '?m=tasks&amp;a=tasksperuser', 'tasks per user' );

$titleBlock->show();

// include the re-usable sub view
$min_view = false;
include(DP_BASE_DIR.'/modules/tasks/tasks.php');
?>