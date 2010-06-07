<?php /* TASKS $Id$ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

/**
 * Tasks :: Add/Edit Form
 */
global $l10n, $projTasks, $all_tasks, $parents, $task_parent_options, $task_parent;
global $task_id;
$task_id = intval( dPgetParam( $_REQUEST, 'task_id', 0 ) );
$perms = $AppUI->acl();

// load the record data
global $obj;
$obj = new CTask();

// check if we are in a subform
if ($task_id > 0 && !$obj->load( $task_id )) {
	$AppUI->setMsg( 'Task' );
	$AppUI->setMsg( 'invalidID', UI_MSG_ERROR, true );
	$AppUI->redirect();
}

$task_parent = isset($_REQUEST['task_parent'])? $_REQUEST['task_parent'] : $obj->task_parent;

// check for a valid project parent
global $task_project;
$task_project = intval( $obj->task_project );
if (!$task_project) {
	$task_project = dPgetParam( $_REQUEST, 'task_project', 0 );
	if (!$task_project) {
		$AppUI->setMsg( 'badTaskProject', UI_MSG_ERROR );
		$AppUI->redirect();
	}
}

// check permissions
if ( $task_id ) {
	// we are editing an existing task
	$canEdit = $perms->checkModuleItem( $m, 'edit', $task_id );
} else {
	// do we have write access on this project?
	$canEdit = $perms->checkModuleItem( 'projects', 'view', $task_project );
	// And do we have add permission to tasks?
	if ($canEdit)
	  $canEdit = $perms->checkModule('tasks', 'add');
}

if (!$canEdit) {
	$AppUI->redirect( 'm=public&a=access_denied&err=noedit' );
}

//check permissions for the associated project
$canReadProject = $perms->checkModuleItem( 'projects', 'view', $obj->task_project);

global $durnTypes;
$durnTypes = dPgetSysVal( 'TaskDurationType' );
$taskPriority = dPgetSysVal( 'TaskPriority' );

// check the document access (public, participant, private)
if (!$obj->canAccess( $AppUI->user_id )) {
	$AppUI->redirect( 'm=public&a=access_denied&err=noaccess' );
}

// pull the related project
$project = new CProject();
$project->load( $task_project );

//Pull all users
global $users;
$users = dPgetUsersHash();

function getSpaces($amount){
	if($amount == 0) return '';
	return str_repeat('&nbsp;', $amount);
}

function constructTaskTree($task_data, $depth = 0){
	global $l10n, $projTasks, $all_tasks, $parents, $task_parent_options, $task_parent, $task_id;

	$projTasks[$task_data['task_id']] = $task_data['task_name'];

	$selected = $task_data['task_id'] == $task_parent ? 'selected="selected"' : '';
	$task_data['task_name'] = $l10n->truncate($task_data['task_name'], 45, '...');

	$task_parent_options .= '<option value="'.$task_data['task_id']. '" ' .$selected. '>'.getSpaces($depth*3).dPFormSafe($task_data['task_name']).'</option>'."\n";

	if (isset($parents[$task_data['task_id']])) {
		foreach ($parents[$task_data['task_id']] as $child_task) {
			if ($child_task != $task_id)
				constructTaskTree($all_tasks[$child_task], ($depth+1));
		}
	}
}

function build_date_list(&$date_array, $row) {
	global $tracked_dynamics, $project;
	// if this task_dynamic is not tracked, set end date to proj start date
	if ( !in_array($row['task_dynamic'], $tracked_dynamics) )
		$date = new CDate( $project->project_start_date );
	elseif ($row['task_milestone'] == 0) {
		$date = new CDate($row['task_end_date']);
	} else {
		$date = new CDate($row['task_start_date']);
	}
	$sdate = $date->format('%d/%m/%Y');
	$shour = $date->format('%H');
	$smin = $date->format('%M');

	$date_array[$row['task_id']] = array($row['task_name'], $sdate, $shour, $smin);
}

// let's get root tasks
$q  = new DBQuery;
$q->addTable('tasks', 't');
$q->addQuery('task_id, task_name, task_end_date, task_start_date, task_milestone, task_parent, task_dynamic');
$q->addOrder('task_start_date');
$q->addWhere('task_id  = task_parent');
$q->addWhere('task_project = '.$task_project);

$root_tasks = $q->loadHashList('task_id');
$q->clear();

$projTasks           = array();
$task_parent_options = '';

// Now lets get non-root tasks, grouped by the task parent
$q  = new DBQuery;
$q->addTable('tasks', 't');
$q->addQuery('task_id, task_name, task_end_date, task_start_date, task_milestone, task_parent, task_dynamic');
$q->addOrder('task_start_date');
$q->addWhere('task_id != task_parent');
$q->addWhere('task_project = '.$task_project);
$parents = array();
$projTasksWithEndDates = array( $obj->task_id => $AppUI->_('None') );//arrays contains task end date info for setting new task start date as maximum end date of dependenced tasks
$all_tasks = array();
$all_tasks = $q->loadHashList('task_id');
foreach($all_tasks as $sub_task) {
	$parents[$sub_task['task_parent']][] = $sub_task['task_id'];
	build_date_list($projTasksWithEndDates, $sub_task);
}

// let's iterate root tasks
foreach ($root_tasks as $root_task) {
	build_date_list($projTasksWithEndDates, $root_task);
	if ($root_task['task_id'] != $task_id)
		constructTaskTree($root_task);
}

// setup the title block
$ttl = $task_id > 0 ? 'Edit Task' : 'Add Task';
$titleBlock = new CTitleBlock( $ttl, 'applet-48.png', $m, $m.$a );
$titleBlock->addCrumb( '?m=tasks', 'tasks list' );
if ( $canReadProject ) {
	$titleBlock->addCrumb( '?m=projects&amp;a=view&amp;project_id='.$task_project, 'view this project' );
}
if ($task_id > 0)
  $titleBlock->addCrumb( '?m=tasks&amp;a=view&amp;task_id='.$obj->task_id, 'view this task' );
$titleBlock->show();

// Let's gather all the necessary information from the department table
// collect all the departments in the company
$depts = array( 0 => '' );

// ALTER TABLE `tasks` ADD `task_departments` CHAR( 100 ) ;
$company_id                = $project->project_company;
$selected_departments      = $obj->task_departments != '' ? explode(',', $obj->task_departments) : array();
$departments_count         = 0;
$department_selection_list = getDepartmentSelectionList($company_id, $selected_departments);
if ($department_selection_list!='') {
  $department_selection_list = ('<select name="dept_ids[]" class="text">'."\n"
								.'<option value="0"></option>'."\n"
								.$department_selection_list."\n"
								.'</select>');
}



function getDepartmentSelectionList($company_id, $checked_array = array(), $dept_parent=0, $spaces = 0){
	global $departments_count, $l10n;
	$parsed = '';

	if($departments_count < 10) $departments_count++;
	$q  = new DBQuery;
	$q->addTable('departments', 'dept');
	$q->addQuery('dept_id, dept_name');
	$q->addWhere('dept_parent = '.$dept_parent);
	$q->addWhere('dept_company = '.$company_id);
	$q->addOrder('dept_name');
	
	$depts_list = $q->loadHashList('dept_id');
	$q->clear();
	
	foreach($depts_list as $dept_id => $dept_info){
		$selected = in_array($dept_id, $checked_array) ? ' selected="selected"' : '';

		$dept_info['dept_name'] = $l10n->truncate($dept_info['dept_name'], 28, '...');

		$parsed .= '<option value="'.$dept_id.'"'.$selected.'>'.str_repeat('&nbsp;', $spaces).$dept_info['dept_name'].'</option>';
		$parsed .= getDepartmentSelectionList($company_id, $checked_array, $dept_id, $spaces+5);
	}

	return $parsed;
}

//Dynamic tasks are by default now off because of dangerous behavior if incorrectly used
if ( is_null($obj->task_dynamic) ) $obj->task_dynamic = 0 ;

global $can_edit_time_information;
$can_edit_time_information = $obj->canUserEditTimeInformation();
//get list of projects, for task move drop down list.
//require_once $AppUI->getModuleClass('projects');
//$project = new CProject;
$pq = new DBQuery;
$pq->addQuery('project_id, project_name');
$pq->addTable('projects');
$pq->addWhere('project_company = '.$company_id);
$pq->addWhere('( project_status != 7 or project_id = \''. $task_project . '\')');
$pq->addOrder('project_name');
$project->setAllowedSQL($AppUI->user_id, $pq);
global $projects;
$projects = $pq->loadHashList();

if (dPgetConfig('check_task_dates'))
	$check_task_dates_set = true;	  
else
	$check_task_dates_set = false;	  
$tpl->assign('check_task_dates_set', $check_task_dates_set);
$tpl->assign('can_edit_time_information', $can_edit_time_information);
$tpl->assign('task_project', $task_project);
$tpl->assign('task_id', $task_id);
$tpl->assign('project', $project); 
$tpl->assign('status', $status);
$tpl->assign('taskPriority', $taskPriority);
$tpl->assign('percent', $percent); 
$tpl->assign('ui_getplace', str_replace('&', '&amp;', $AppUI->getPlace()));
$tpl->displayAddEdit($obj);

if (isset($_GET['tab'])) {
	$AppUI->setState('TaskAeTabIdx', dPgetParam($_GET, 'tab', 0));
}

$tab = $AppUI->getState('TaskAeTabIdx', 0);
$tabBox = new CTabBox('?m=tasks&amp;a=addedit&amp;task_id='.$task_id, '', $tab, '');
$tabBox->add(DP_BASE_DIR.'/modules/tasks/ae_desc', 'Details');
$tabBox->add(DP_BASE_DIR.'/modules/tasks/ae_dates', 'Dates');
$tabBox->add(DP_BASE_DIR.'/modules/tasks/ae_depend', 'Dependencies');
$tabBox->add(DP_BASE_DIR.'/modules/tasks/ae_resource', 'Human Resources');
$tabBox->loadExtras('tasks', 'addedit');
$tabBox->show('', true);
?>