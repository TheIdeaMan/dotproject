<?php /* TASKS $Id$ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

global $m, $a, $project_id, $f, $task_status, $min_view, $query_string, $durnTypes, $tpl;
global $task_sort_item1, $task_sort_type1, $task_sort_order1;
global $task_sort_item2, $task_sort_type2, $task_sort_order2;
global $user_id, $currentTabId, $currentTabName, $canEdit, $showEditCheckbox;
global $tasks_opened, $tasks_closed;
/*      tasks.php

        This file contains common task list rendering code used by
        modules/tasks/index.php and modules/projects/vw_tasks.php

        in

        External used variables:
        * $min_view: hide some elements when active (used in the vw_tasks.php)
        * $project_id
        * $f
        * $query_string
*/
if (empty($query_string))
	$query_string = '?m='.$m.'&amp;a='.$a;

// Number of columns (used to calculate how many columns to span things through)
$cols = 13;

/****
// Let's figure out which tasks are selected
*/

$tasks_closed = array();
$tasks_opened = $AppUI->getState('tasks_opened');
if(!$tasks_opened)
	$tasks_opened = array();

$task_id = intval( dPgetParam( $_GET, 'task_id', 0 ) );
$q = new DBQuery;
$pinned_only = intval( dPgetParam( $_GET, 'pinned', 0) );
if (isset($_GET['pin'])) {
    $pin = intval( dPgetParam( $_GET, 'pin', 0 ) );
    $msg = '';
    
    // load the record data
    if($pin) {
        $q->addTable('user_task_pin');
        $q->addInsert('user_id', $AppUI->user_id);
        $q->addInsert('task_id', $task_id);
    }  else {
        $q->setDelete('user_task_pin');
        $q->addWhere('user_id = ' . $AppUI->user_id);
        $q->addWhere('task_id = ' . $task_id);
    }
    
    if ( !$q->exec() ) {
        $AppUI->setMsg( 'ins/del err', UI_MSG_ERROR, true );
    } else {
        $q->clear();
    }
    
    $AppUI->redirect('', -1);
} else if($task_id > 0) {
    $tasks_opened[] = $task_id;
}

$AppUI->savePlace();

if( ($open_task_id = dPGetParam($_GET, 'open_task_id', 0)) > 0
    && !in_array($_GET['open_task_id'], $tasks_opened)) {
    $tasks_opened[] = $_GET['open_task_id'];
}

// Closing tasks needs also to be within tasks iteration in order to
// close down all child tasks
if(($close_task_id = dPGetParam($_GET, 'close_task_id', 0)) > 0) {
	closeOpenedTask($close_task_id);
}

// We need to save tasks_opened until the end because some tasks are closed within tasks iteration
/// End of tasks_opened routine

$durnTypes = dPgetSysVal( 'TaskDurationType' );
$taskPriority = dPgetSysVal( 'TaskPriority' );

$task_project = intval( dPgetParam( $_GET, 'task_project', null ) );
//$task_id = intval( dPgetParam( $_GET, 'task_id', null ) );

$task_sort_item1 = dPgetParam( $_GET, 'task_sort_item1', '' );
$task_sort_type1 = dPgetParam( $_GET, 'task_sort_type1', '' );
$task_sort_item2 = dPgetParam( $_GET, 'task_sort_item2', '' );
$task_sort_type2 = dPgetParam( $_GET, 'task_sort_type2', '' );
$task_sort_order1 = intval( dPgetParam( $_GET, 'task_sort_order1', 0 ) );
$task_sort_order2 = intval( dPgetParam( $_GET, 'task_sort_order2', 0 ) );
if (isset($_POST['show_task_options'])) {
	$AppUI->setState('TaskListShowIncomplete', dPgetParam($_POST, 'show_incomplete', 0));
}

$showIncomplete = $AppUI->getState('TaskListShowIncomplete', 0);

require_once $AppUI->getModuleClass('projects');
$project = new CProject;
$allowedProjects = $project->getAllowedSQL($AppUI->user_id);
$working_hours = dPgetConfig('daily_working_hours', 8);

$q->addQuery('project_id, project_color_identifier, project_name');
$q->addQuery('SUM(task_duration * task_percent_complete * IF(task_duration_type = 24, '.$working_hours
             .', task_duration_type)) / SUM(task_duration * IF(task_duration_type = 24, '.$working_hours
             .', task_duration_type)) AS project_percent_complete');
$q->addQuery('company_name');
$q->addTable('projects');
$q->leftJoin('tasks', 't1', 'projects.project_id = t1.task_project');
$q->leftJoin('companies', 'c', 'company_id = project_company');
$q->addWhere('t1.task_id = t1.task_parent');
if ( count($allowedProjects)) {
  $q->addWhere($allowedProjects);
}

$q->addGroup('project_id');
$q->addOrder('project_name');
$psql = $q->prepare();

$q->addQuery('project_id, COUNT(t1.task_id) as total_tasks');
$psql2 = $q->prepare();
$q->clear();

$projects = array();
$canViewTask = $perms->checkModule('tasks', 'view');
if ($canViewTask) {
    $prc = db_exec( $psql );
    echo db_error();
    while ($row = db_fetch_assoc( $prc )) {
        $projects[$row['project_id']] = $row;
    }
    
    $prc2 = db_exec( $psql2 );
    echo db_error();
    while ($row2 = db_fetch_assoc( $prc2 )) {
        $projects[$row2['project_id']] = ((!($projects[$row2['project_id']]))?array():$projects[$row2['project_id']]);
        array_push($projects[$row2['project_id']], $row2);
    }
}

$q->addQuery('tasks.task_id, task_parent, task_name');
$q->addQuery('task_start_date, task_end_date, task_dynamic');
$q->addQuery('count(tasks.task_parent) as children');
$q->addQuery('task_pinned, pin.user_id as pin_user');
$q->addQuery('task_priority, task_percent_complete');
$q->addQuery('task_duration, task_duration_type');
$q->addQuery('task_project');
$q->addQuery('task_description, task_owner, task_status');
$q->addQuery('usernames.user_username, usernames.user_id');
$q->addQuery('assignees.user_username as assignee_username');
$q->addQuery('count(distinct assignees.user_id) as assignee_count');
$q->addQuery('co.contact_first_name, co.contact_last_name');
$q->addQuery('task_milestone');
$q->addQuery('count(distinct f.file_task) as file_count');
$q->addQuery('tlog.task_log_problem');

$q->addTable('tasks');
$mods = $AppUI->getActiveModules();
if (!empty($mods['history']) && !getDenyRead('history')) {
    $q->addQuery('MAX(history_date) as last_update');
    $q->leftJoin('history', 'h', 'history_item = tasks.task_id AND history_table=\'tasks\'');
}
$q->leftJoin('projects', 'p', 'p.project_id = task_project');
$q->leftJoin('users', 'usernames', 'task_owner = usernames.user_id');
$q->leftJoin('user_tasks', 'ut', 'ut.task_id = tasks.task_id');
$q->leftJoin('users', 'assignees', 'assignees.user_id = ut.user_id');
$q->leftJoin('contacts', 'co', 'co.contact_id = usernames.user_contact');
$q->leftJoin('task_log', 'tlog', 'tlog.task_log_task = tasks.task_id AND tlog.task_log_problem > 0');
$q->leftJoin('files', 'f', 'tasks.task_id = f.file_task');
$q->leftJoin('user_task_pin', 'pin', 'tasks.task_id = pin.task_id AND pin.user_id = ' . $AppUI->user_id);

if ($f != 'children') {
	$q->addWhere('tasks.task_id = task_parent');
}
if ($project_id) {
	$q->addWhere('task_project = ' . $project_id);
}
if ($pinned_only) {
	$q->addWhere('task_pinned = 1');
}
switch ($f) {
 case 'all':
     break;
 case 'myfinished7days':
     $q->addWhere('ut.user_id = ' . $user_id);
 case 'allfinished7days':        // patch 2.12.04 tasks finished in the last 7 days
     //$q->addTable('user_tasks');
     $q->addTable('user_tasks');
     $q->addWhere('user_tasks.user_id = ' . $user_id);
     $q->addWhere('user_tasks.task_id = tasks.task_id');
     
     $q->addWhere('task_percent_complete = 100');
     //TODO: use date class to construct date.
     $q->addWhere('task_end_date >= \'' . date('Y-m-d 00:00:00', mktime(0, 0, 0, date('m'), date('d')-7, date('Y'))) . "'");
     break;
 case 'children':
     // patch 2.13.04 2, fixed ambigious task_id
     $q->addWhere('task_parent = ' . $task_id);
     $q->addWhere('tasks.task_id <> ' . $task_id);
     break;
 case 'myproj':
     $q->addWhere('project_owner = ' . $user_id);
     break;
 case 'mycomp':
     if(!$AppUI->user_company){
         $AppUI->user_company = 0;
     }
     $q->addWhere('project_company = ' . $AppUI->user_company);
     break;
 case 'myunfinished':
     $q->addTable('user_tasks');
     $q->addWhere('user_tasks.user_id = ' . $user_id);
     $q->addWhere('user_tasks.task_id = tasks.task_id');
     $q->addWhere('(task_percent_complete < 100 OR task_end_date = "")');
     $q->addWhere('p.project_status <> 7');
     $q->addWhere('p.project_status <> 4');
     $q->addWhere('p.project_status <> 5');
     break;
 case 'allunfinished':
     $q->addWhere('(task_percent_complete < 100 OR task_end_date = "")');
     $q->addWhere('p.project_status <> 7');
     $q->addWhere('p.project_status <> 4');
     $q->addWhere('p.project_status <> 5');
     break;
 case 'unassigned':
     $q->leftJoin('user_tasks', 'ut_empty', 'tasks.task_id = ut_empty.task_id');
     $q->addWhere('ut_empty.task_id IS NULL');
     break;
 case 'taskcreated':
     $q->addWhere('task_owner = ' . $user_id);
     break;
 default:
     $q->addTable('user_tasks');
     $q->addWhere('user_tasks.user_id = ' . $user_id);
     $q->addWhere('user_tasks.task_id = tasks.task_id');
     break;
}

if (($project_id  || $task_id) && $showIncomplete) {
	$q->addWhere('( task_percent_complete < 100 or task_percent_complete is null )');
}

// reverse lookup the status integer from Tab name
$status = dPgetSysVal( 'TaskStatus' );
$sutats = array_flip($status);
$ctn = substr($currentTabName, 7, -1);

// we are in a tabbed view
if ( isset($currentTabName) ) {
	$task_status = $sutats[$ctn];
}

if ($task_status) {
	if ($task_status <> -1) {
	  $q->addWhere('task_status = ' . $task_status);
  }
}
if ($task_type) {
	if ($task_type <> -1) {
    $q->addWhere('task_type = ' . $task_type);
  }
}
if ($task_owner) {
	if ($task_owner <> -1) {
    $q->addWhere('task_owner = ' . $task_owner);
  }
}    

// patch 2.12.04 text search
if ( $search_text = $AppUI->getState('searchtext') ) {
	$q->addWhere('( task_name LIKE ("%'.$search_text.'%") OR task_description LIKE ("%'.$search_text.'%") )');
}

// filter tasks considering task and project permissions
$projects_filter = '';
$tasks_filter = '';

// TODO: Enable tasks filtering
$allowedProjects = $project->getAllowedSQL($AppUI->user_id, 'task_project');
if (count($allowedProjects)) {
	$q->addWhere($allowedProjects);
}

$obj = new CTask;
$allowedTasks = $obj->getAllowedSQL($AppUI->user_id, 'tasks.task_id');
if ( count($allowedTasks)) {
	$q->addWhere($allowedTasks);
}

// Filter by company
if ( ! $min_view && $f2 != 'all' ) {
	$q->leftJoin('companies', 'c', 'c.company_id = p.project_company');
	$q->addWhere('company_id = ' . intval($f2) );
}

$q->addGroup('tasks.task_id');
$q->addOrder('project_id, task_start_date');

if ($canViewTask) {
  $tasks = $q->loadList();
}

// POST PROCESSING TASKS
foreach ($tasks as $row) {
	//add information about assigned users into the page output
	$q->clear();
	$q->addQuery('ut.user_id,	u.user_username');
	$q->addQuery('contact_email, ut.perc_assignment, SUM(ut.perc_assignment) AS assign_extent');
	$q->addQuery('contact_first_name, contact_last_name');
	$q->addTable('user_tasks', 'ut');
	$q->leftJoin('users', 'u', 'u.user_id = ut.user_id');
	$q->leftJoin('contacts', 'c', 'u.user_contact = c.contact_id');
	$q->addWhere('ut.task_id = ' . $row['task_id']);
	$q->addGroup('ut.user_id');
	$q->addOrder('perc_assignment desc, user_username');
	
	$assigned_users = array ();
	$row['task_assigned_users'] = $q->loadList();
	$q->addQuery('count(*) as children');
	$q->addTable('tasks');
	$q->addWhere('task_parent = ' . $row['task_id']);
	$q->addWhere('task_id <> task_parent');
	$row['children'] = $q->loadResult();
	$row['style'] = taskstyle($row);
	$row['canEdit'] = !getDenyEdit( 'tasks', $row['task_id'] );
	$row['canViewLog'] = $perms->checkModuleItem('task_log', 'view', $row['task_id']);
	$i = count($projects[$row['task_project']]['tasks']) + 1;
	$row['task_number'] = $i;
	$row['node_id'] = 'node_'.$i.'-' . $row['task_id'];


	if (strpos($row['task_duration'], '.') && $row['task_duration_type'] == 1) {
		$row['task_duration'] = floor($row['task_duration']) . ':' 
            . round(60 * ($row['task_duration'] - floor($row['task_duration'])));
    }
	//pull the final task row into array
	$projects[$row['task_project']]['tasks'][] = $row;
}

$showEditCheckbox = isset($canEdit) && $canEdit && dPgetConfig('direct_edit_assignment');
?>

<script type="text/javascript" src="modules/tasks/list.js.php"></script>
<script type="text/javascript" language="javascript" src="modules/tasks/tree.js?<?php echo time(); ?>"></script>

<?php
$AppUI->setState('tasks_opened', $tasks_opened);

foreach($projects as $k => $p) {
	global $done;
	$done = array();
	if ( $task_sort_item1 != '' ) {
	  if ( $task_sort_item2 != '' && $task_sort_item1 != $task_sort_item2 ) {
			$p['tasks'] = array_csort($p['tasks'], 
                                      $task_sort_item1, $task_sort_order1, $task_sort_type1,
                                      $task_sort_item2, $task_sort_order2, $task_sort_type2 );
      } else {
	    $p['tasks'] = array_csort($p['tasks'], $task_sort_item1, $task_sort_order1, $task_sort_type1 );
      }
	} else {
		/* we have to calculate the end_date via start_date+duration for 
		** end='0000-00-00 00:00:00' if array_csort function is not used
		** as it is normally done in array_csort function in order to economise
		** cpu time as we have to go through the array there anyway
		*/
		for ($j=0; $j < count($p['tasks']); $j++) {
			if ( $p['tasks'][$j]['task_end_date'] == '0000-00-00 00:00:00' || $p['tasks'][$j]['task_end_date'] == NULL) {
				 $p['tasks'][$j]['task_end_date'] = calcEndByStartAndDuration($p['tasks'][$j]);
			}
		}
	}

	$p['tasks_count'] = count($p['tasks']);
	$projects[$k] = $p;
}

$durnTypes = dPgetSysVal( 'TaskDurationType' );
$tpl->assign('durnTypes', $durnTypes);

$tpl->assign('project_id', $project_id);
$tpl->assign('task_id', $task_id);
$tpl->assign('user_id', $user_id);

$tpl->assign('cols', $cols);

$tpl->assign('canEdit', $canEdit);
$tpl->assign('canViewLog', $canViewLog);
$tpl->assign('enable_gantt_charts', dPgetConfig('enable_gantt_charts'));
$tpl->assign('direct_edit_assignment', dPgetConfig('direct_edit_assignment'));

$tpl->assign('min_view', $min_view);

$tpl->assign('sort1', $task_sort_item1);
$tpl->assign('sort2', $task_sort_item2);

$tpl->assign('sort_order1', $task_sort_order1);
$tpl->assign('sort_order2', $task_sort_order2);
$tpl->assign('sort_type1', $task_sort_type1);
$tpl->assign('sort_type2', $task_sort_type2);

$tpl->assign('query_string', $query_string);
$tpl->assign('showIncomplete', $showIncomplete);
$tpl->assign('showEditCheckbox', $showEditCheckbox);

$tpl->assign('show_cols', (dPgetConfig('direct_edit_assignment')?($cols-4):($cols-1)));

$assignment_options = '';
for ($i = 0; $i <= 100; $i+=5) {
	$assignment_options .= '<option '.(($i==30)?'selected="true"':'').' value="'.$i.'">'.$i.'%</option>';
}
$tpl->assign('assignment_options', $assignment_options);

$tempoTask = new CTask();
$userAlloc = $tempoTask->getAllocation('user_id');
$tpl->assign('userAlloc', $userAlloc);

$tpl->assign('historyModule', !empty($mods['history']) && !getDenyRead('history'));

$tpl->assign('rows', $projects);

$tpl->displayFile('list.projects', 'tasks');
?>