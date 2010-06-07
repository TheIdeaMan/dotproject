<?php /* CALENDAR $Id$ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

$AppUI->savePlace();

dPsetMicroTime();

require_once( $AppUI->getModuleClass( 'companies' ) );
require_once( $AppUI->getModuleClass( 'projects' ) );
require_once( $AppUI->getModuleClass( 'tasks' ) );

$companies = new CCompany();
$projects = new CProject();

$perms = $AppUI->acl();
$tasks_filters_selection = array(
'task_owner' => $perms->getPermittedUsers('calendar'),
'task_project' => arrayMerge( array( '-1'=>$AppUI->_('Personal Calendar'), '0'=>$AppUI->_('Unspecified Calendar') ) , $projects->getAllowedRecords($AppUI->user_id, 'project_id, project_name', 'project_name')),
'task_company' => $companies->getAllowedRecords($AppUI->user_id, 'company_id, company_name', 'company_name'));

// retrieve any state parameters
if (isset( $_REQUEST['task_company'] )) {
	$AppUI->setState( 'CalIdxCompany', intval( $_REQUEST['task_company'] ) );
}
$company_id = $AppUI->getState( 'CalIdxCompany', 0);
$proj = new CProject();

$r  = new DBQuery;
$r->addTable('projects');
$r->addQuery('project_id, CONCAT(c.company_name,"::", project_short_name) AS project_short_name');
$r->addJoin('companies', 'c', 'project_company = c.company_id'); 
if ($company_id > 0){
	$r->addWhere('project_company='.$company_id);
}
if ($calendar_filter > 0){
	$r->addWhere('project_id='.$calendar_filter);
}
$proj->setAllowedSQL($AppUI->user_id, $r);
$projects = $r->loadHashList();
$r->clear();

// retrieve any state parameters
if (isset( $_REQUEST['calendar_filter'] )) {
	$AppUI->setState( 'CalIdxCalFilter', intval( $_REQUEST['calendar_filter'] ) );
}
$calendar_filter = $AppUI->getState( 'CalIdxCalFilter', '0');

// Using simplified set/get semantics. Doesn't need as much code in the module.
$event_filter = $AppUI->checkPrefState('CalIdxFilter', @$_REQUEST['event_filter'], 'EVENTFILTER', 'my');

// get the passed timestamp (today if none)
$date = dPgetParam( $_GET, 'date', null );

// get the list of visible companies
$company = new CCompany();
$companies = $company->getAllowedRecords( $AppUI->user_id, 'company_id,company_name', 'company_name' );
$companies = arrayMerge( array( '0'=>$AppUI->_('All') ), $companies );

// setup the title block
$titleBlock = new CTitleBlock( 'Monthly Calendar', 'myevo-appointments.png', $m, "$m.$a" );
$titleBlock->addCrumb( '?m=calendar&amp;a=calmgt', 'calendar management' );
$titleBlock->addCrumb( '?m=calendar&amp;a=eventimport&amp;dialog=0', 'import icalendar' );
if ($canAuthor)
	$titleBlock->addCrumb( '?m=calendar&a=addedit', 'add event' );

if (isset($_POST['show_form']))
{
	if (isset($_POST['show_events'])) {
		$AppUI->setState('CalIdxShowEvents', true);
  } else if ($AppUI->getState('CalIdxShowEvents', '') === '') {
		$AppUI->setState('CalIdxShowEvents', true);
  } else {
		$AppUI->setState('CalIdxShowEvents', false);
  }
  
	if (isset($_POST['show_tasks'])) {
		$AppUI->setState('CalIdxShowTasks', true);
  } else {
		$AppUI->setState('CalIdxShowTasks', false);
  }
}
$AppUI->setState('CalIdxShowTasks', true); // for landstaff

$show_events = $AppUI->getState('CalIdxShowEvents', true);
$show_tasks = $AppUI->getState('CalIdxShowTasks', false);

$titleBlock->addCell('
<form action="' . $_SERVER['REQUEST_URI'] . '" method="post" name="displayForm">
	<input type="hidden" name="show_form" value="1" />
	<input type="checkbox" name="show_events" value="1" ' . ($show_events?'checked="checked" ':'') . 'onclick="document.displayForm.submit()" /> show events
	<input type="checkbox" name="show_tasks" value="1" ' . ($show_tasks?'checked="checked" ':'') . 'onclick="document.displayForm.submit()" /> show tasks
</form>', '', '', '');

if ($show_tasks) {
	$filters = $titleBlock->addFiltersCell($tasks_filters_selection);
}
$titleBlock->show();
?>

<script type="text/javascript" language="javascript">
<!--
function clickDay( uts, fdate ) {
	window.location = './index.php?m=calendar&a=day_view&date='+uts+'&tab=0';
}
function clickWeek( uts, fdate ) {
	window.location = './index.php?m=calendar&a=week_view&date='+uts;
}
-->
</script>


<?php
// establish the focus 'date'
$date = new CDate( $date );

// prepare time period for 'events'
$first_time = new CDate( $date );
$first_time->setDay( 1 );
$first_time->setTime( 0, 0, 0 );
$first_time->addSeconds( -1 );
$last_time = new CDate( $date );
$ts = $date->getTime();
$daysInMonth = date('t', $ts);
$last_time->setDay($daysInMonth);
$last_time->setTime( 23, 59, 59 );

$links = array();

// assemble the links for the tasks
if ($show_tasks) {
	require_once( DP_BASE_DIR.'/modules/calendar/links_tasks.php' );
	getTaskLinks( $first_time, $last_time, $links, 20, $filters );
}

// assemble the links for the events
if ($show_events) {
	require_once( DP_BASE_DIR.'/modules/calendar/links_events.php' );
	getEventLinks( $first_time, $last_time, $links, 20 );
	getExternalWebcalEventLinks( $first_time, $last_time, $links, 20 );
}

// create the main calendar
$cal = new CMonthCalendar( $date  );
$cal->setStyles( 'motitle', 'mocal' );
$cal->setLinkFunctions( 'clickDay', 'clickWeek' );
$cal->setEvents( $links );

$tpl->assign('cal', $cal->show());
//echo '<pre>';print_r($cal);echo '</pre>';

// create the mini previous and next month calendars under
$minical = new CMonthCalendar( $cal->prev_month );
$minical->setStyles( 'minititle', 'minical' );
$minical->showArrows = false;
$minical->showWeek = false;
$minical->clickMonth = true;
$minical->setLinkFunctions( 'clickDay' );

$tpl->assign('cal_prev', $minical->show());
$minical->setDate( $cal->next_month );
$tpl->assign('cal_next', $minical->show());

$tpl->displayFile('view.month', 'calendar');
?>