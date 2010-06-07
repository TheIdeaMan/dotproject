<?php /* PROJECTS $Id$ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

/**
 *	@package dotProject
 *	@subpackage modules
 *	@version $Revision$
 */
global $AppUI;

require_once( $AppUI->getSystemClass( 'dp' ) );
require_once( $AppUI->getModuleClass( 'tasks' ) );
require_once( $AppUI->getModuleClass( 'companies' ) );
require_once( $AppUI->getModuleClass( 'forums' ) );

/**
 * The Project Class
 */
class CProject extends CDpObject {
	var $project_id = null;
	var $project_company = null;
	var $project_department = 0;
	var $project_name = null;
	var $project_short_name = null;
	var $project_owner = null;
	var $project_url = null;
	var $project_demo_url = null;
	var $project_start_date = null;
	var $project_end_date = null;
	var $project_actual_end_date = null;
	var $project_status = null;
	var $project_percent_complete = 0;
	var $project_color_identifier = null;
	var $project_description = null;
	var $project_target_budget = null;
	var $project_actual_budget = null;
	var $project_creator = null;
	var $project_private = null;
	var $project_departments= null;
	var $project_contacts = null;
	var $project_priority = null;
	var $project_type = null;
	var $project_active = null;

	function CProject() {
		$this->CDpObject( 'projects', 'project_id' );
		$this->search_fields = array('project_name', 'project_short_name', 'project_description', 'project_url'
                                     , 'project_demo_url');
		$this->_parent = new CCompany;
		$this->_tbl_parent = 'project_company';
	}
    
	function check() {
		// ensure changes of state in checkboxes is captured
		$this->project_private = intval( $this->project_private );
		// Make sure project_short_name is the right size (issue for languages with encoded characters)
		if (strlen($this->project_short_name) > 10) {
			$this->project_short_name = substr($this->project_short_name, 0, 10);
		}

		return null; // object is ok
	}
    
	function load($oid=null , $strip = true) {
		$result = parent::load($oid, $strip);
		if ($result && $oid) {
			$working_hours = dPgetConfig('daily_working_hours', 8);
			
			$q = new DBQuery;
			$q->addTable('projects');
			$q->addQuery('SUM(t1.task_duration * t1.task_percent_complete * IF(t1.task_duration_type = 24, '.$working_hours
			             .', t1.task_duration_type)) / SUM(t1.task_duration * IF(t1.task_duration_type = 24, '.$working_hours
			             .', t1.task_duration_type)) AS project_percent_complete');
			$q->addJoin('tasks', 't1', 'projects.project_id = t1.task_project');
			$q->addWhere(" project_id = $oid");
			$this->project_percent_complete = $q->loadResult();
		}
		return $result;
	}
    
  /** 
   * overload canDelete
   */
	function canDelete( &$msg, $oid=null ) {
		// TODO: check if user permissions are considered when deleting a project
		global $AppUI;
		$perms = $AppUI->acl();
        
		return $perms->checkModuleItem('projects', 'delete', $oid);
	}

	function delete() {
		$this->load($this->project_id);
		$details['name'] = $this->project_name;
		$details['project'] = $this->project_id;
		addHistory('projects', $this->project_id, 'delete', $details);
		$q = new DBQuery;
		$q->addTable('tasks');
		$q->addQuery('task_id');
		$q->addWhere("task_project = $this->project_id");
		$sql = $q->prepare();
		$q->clear();
		$tasks_to_delete = db_loadColumn ( $sql );
        
		foreach ( $tasks_to_delete as $task_id ) {
			$task = new CTask();
			$task->task_id = $task_id;
			$task->delete();
		}

		// remove the project-contacts and project-departments map
		$q->setDelete('project_contacts');
		$q->addWhere('project_id ='.$this->project_id);
		$q->exec();
		$q->clear();
		$q->setDelete('project_departments');
		$q->addWhere('project_id ='.$this->project_id);
		$q->exec();
		$q->clear();
		$q->setDelete('projects');
		$q->addWhere('project_id ='.$this->project_id);
		
        $result = ((!$q->exec())?db_error():NULL);
		$q->clear();
		return $result;
	}

	/**	
	 * Import tasks from another project
	 *
	 *	@param	int		Project ID of the tasks come from.
	 * @param  date  The date to offset tasks with.
	 * @param  bool  To keep or not assignees.
	 * @param  bool  To keep or not files.
	 *	@return	bool	
	 **/
	function importTasks ($from_project_id, $import_date = '', $keepAssignees = true, $keepFiles = false) {
        
		// Load the original
		$origProject = new CProject();
		$origProject->load ($from_project_id);
		$q = new DBQuery;
		$q->addTable('tasks');
		$q->addQuery('task_id');
		$q->addWhere('task_project ='.$from_project_id);
		$sql = $q->prepare();
		$q->clear();
		$tasks = array_flip(db_loadColumn ($sql));
        
		$origDate = new CDate( $origProject->project_start_date );
		$destDate = new CDate(((!empty($import_date))?$import_date:$this->project_start_date));
		$offset = $origDate->compare($destDate);
		$timeOffset = $destDate->getTime() - $origDate->getTime();

		// Dependencies array
		$deps = array();
		
		// Copy each task into this project and get their deps
		foreach ($tasks as $orig => $void) {
			$objTask = new CTask();
			$objTask->load ($orig);
			$destTask = $objTask->copy($this->project_id);
			$tasks[$orig] = $destTask;
			$deps[$orig] = $objTask->getDependencies ();
		}
		$all_deps = explode(',', implode(',', $deps));
    //print_r($all_deps);
    
		// Fix record integrity 
		foreach ($tasks as $old_id => $newTask) {
			// Fix parent Task
			// This task had a parent task, adjust it to new parent task_id
			if ($newTask->task_id != $newTask->task_parent)
				$newTask->task_parent = $tasks[$newTask->task_parent]->task_id;
            
//      if ($reschedule)
//      {
//      	//if (empty($all_deps) || !in_array($old_id, $all_deps))
//      	//{
//      		$newTask->task_start_date = $import_date;
//      		$end_date = new CDate($import_date);
//      		$end_date->addDays($newTask->calcDays());
//      		$newTask->task_end_date = $end_date->format(FMT_DATETIME_MYSQL);
//      		//print_r($newTask);
//      	//}
//      }
//      else
//      {
				// Fix task start date from project start date offset
				if (!empty($newTask->task_start_date) && $newTask->task_start_date != '0000-00-00 00:00:00') {
					$origDate->setDate ($newTask->task_start_date);
					
					$destDate = $origDate;
					$destDate->addDays($offset);
//					$destDate = $destDate->next_working_day();
					$newTask->task_start_date = $destDate->format(FMT_DATETIME_MYSQL, true);
				}
				
				// Fix task end date from start date + work duration
				//$newTask->calc_task_end_date();
				if (!empty($newTask->task_end_date) && $newTask->task_end_date != '0000-00-00 00:00:00'){
					$origDate->setDate ($newTask->task_end_date);
					
					$destDate = $origDate;
					$destDate->addDays($offset);
//					$destDate = $destDate->next_working_day();
					//$destDate->setDate ($origDate->getTime() + $timeOffset , DATE_FORMAT_UNIXTIME );
					$newTask->task_end_date = $destDate->format(FMT_DATETIME_MYSQL, true);
				}
//      }
			
			// Dependencies
			if (!empty($deps[$old_id])) {
				$oldDeps = explode (',', $deps[$old_id]);
				// New dependencies array
				$newDeps = array();
				foreach ($oldDeps as $dep) {
					$newDeps[] = $tasks[$dep]->task_id;
                }
				// Update the new task dependencies
				$csList = implode (',', $newDeps);
				$newTask->updateDependencies ($csList);
			} // end of update dependencies 
            
			$newTask->store();
			
			if ($keepAssignees) {
				$q->addQuery('user_id, user_type, user_task_priority, perc_assignment');
				$q->addTable('user_tasks');
				$q->addWhere('task_id = ' . $old_id);
				$assignedUsers = $q->loadList();
				
				$q->setDelete('user_tasks');
				$q->addWhere('task_id = ' . $newTask->task_id);
				$q->exec();
				$q->clear();
				foreach ($assignedUsers as $user) {
					$q->addTable('user_tasks');
					foreach($user as $field => $value) {
						$q->addInsert($field, $value);
                    }
					$q->addInsert('task_id', $newTask->task_id);
					$q->exec();
					$q->clear();
				}
			}

			if ($keepFiles) {
				$q->addQuery('*');
				$q->addTable('files');
				$q->addWhere('file_task = ' . $old_id);
				$files = $q->loadList();
                
				foreach($files as $file) {
					$res = copy(DP_BASE_DIR.'/files/'.$file['file_project'].'/'.$file['file_real_filename'], 
                                DP_BASE_DIR.'/files/'.$obj->project_id.'/'.$file['file_real_filename']);
					$file['file_id'] = '';
					$file['file_task'] = $newTask->task_id;
					$file['file_project'] = $newTask->task_project;
					$q->addTable('files');
					$q->addInsert(array_keys($file), array_values($file), true);
					$q->exec();
					$q->clear();
				}
			}
		} // end Fix record integrity	
//		foreach($tasks as $task)
//			$task->store();
	} // end of importTasks

	/**
	 *	Overload of the dpObject::getAllowedRecords 
	 *	to ensure that the allowed projects are owned by allowed companies.
	 *
	 *	@author	handco <handco@sourceforge.net>
	 *	@see	dpObject::getAllowedRecords
	 */
	function getAllowedRecords( $uid, $fields='*', $orderby='', $index=null, $extra=null ){
		$oCpy = new CCompany ();
		
		$aCpies = $oCpy->getAllowedRecords ($uid, "company_id, company_name");
		if (count($aCpies)) {
            $buffer = '(project_company IN ('.implode(',' , array_keys($aCpies)).'))'; 
            $extra['where'] .= (($extra['where'] != "")?' AND ':'')
                .'(project_company IN ('.implode(',' , array_keys($aCpies)).'))';
		} 
        else {
            // There are no allowed companies, so don't allow projects.
            $extra['where'] .= (($extra['where'] != "")?' AND ':'').'1 = 0';
		}
        
		return parent::getAllowedRecords ($uid, $fields, $orderby, $index, $extra);		
	}
	
	function getAllowedSQL($uid, $index = null) {
		$oCpy = new CCompany ();
		
		$where = $oCpy->getAllowedSQL ($uid, "project_company");
		$project_where = parent::getAllowedSQL($uid, $index);
		return array_merge($where, $project_where);
	}
    
	function setAllowedSQL($uid, &$query, $index = null) {
		$oCpy = new CCompany;
		parent::setAllowedSQL($uid, $query, $index);
		$oCpy->setAllowedSQL($uid, $query, "project_company");
	}
	
	/**
	 *	Overload of the dpObject::getDeniedRecords 
	 *	to ensure that the projects owned by denied companies are denied.
	 *
	 *	@author	handco <handco@sourceforge.net>
	 *	@see	dpObject::getAllowedRecords
	 */
	function getDeniedRecords( $uid ) {
		$aBuf1 = parent::getDeniedRecords ($uid);
		
		$oCpy = new CCompany ();
		// Retrieve which projects are allowed due to the company rules 
		$aCpiesAllowed = $oCpy->getAllowedRecords ($uid, "company_id,company_name");
		
		$q = new DBQuery;
		$q->addTable('projects');
		$q->addQuery('project_id');
		If (count($aCpiesAllowed)) {
			$q->addWhere("NOT (project_company IN (" . implode (',', array_keys($aCpiesAllowed)) . '))');
        }
		$sql = $q->prepare();
		$q->clear();
		$aBuf2 = db_loadColumn ($sql);
		
		return array_merge ($aBuf1, $aBuf2); 
	}
	function getAllowedProjectsInRows($userId) {
		$q = new DBQuery;
		$q->addTable('projects');
		$q->addQuery('project_id, project_status, project_name, project_description, project_short_name');                     
		$q->addGroup('project_id');
		$q->addOrder('project_short_name');
		$this->setAllowedSQL($userId, $q);
		$allowedProjectRows = $q->exec();
		
		return $allowedProjectRows;
	}

	/** 
	 * Retrieve tasks with latest task_end_dates within given project
	 * @param int Project_id
	 * @param int SQL-limit to limit the number of returned tasks
	 * @return array List of criticalTasks
	 */
	function getCriticalTasks($project_id = NULL, $limit = 1) {
		$project_id = !empty($project_id) ? $project_id : $this->project_id;
		$q = new DBQuery;
		$q->addTable('tasks');
		$q->addWhere("task_project = $project_id AND !isnull( task_end_date ) AND task_end_date !=  '0000-00-00 00:00:00'");
		$q->addOrder('task_end_date DESC');
		$q->setLimit($limit);

		return $q->loadList();
	}

	function getActualStartDate() {
		$q = new DBQuery();
		$q->addQuery('min(task_log_date)');
		$q->addTable('task_log');
		$q->addTable('tasks', 't', 'task_id = task_log_task');
		$q->addTable('projects', 'p', 'p.project_id = t.task_project');
		//$q->addWhere();
		return $q->loadResult();
	}
	
	/** 
	 * Return the maximum duration for a project (ignoring dates) - Critical path duration.
	 * 
	 * @return the duration in hours
	 */
	function calcMinDuration() {
		$max_duration = 0;
	
		$q = new DBQuery();
		$q->addQuery('task_id, task_duration, task_duration_type');
		$q->addTable('tasks');
		$q->addWhere('task_project = ' . $this->project_id);
		$tasks = $q->loadHashList();
		
		$tobj = new CTask();
		foreach ($tasks as $task)
		{
			$tobj->load($task['task_id']);
		
			if ($deps = $tobj->getDependencies())
				$duration = $tobj->getCriticalDuration();
			else
				$duration = $tobj->getDuration();
			if ($duration > $max_duration)
				$max_duration = $duration;
		}
		
		return $max_duration;
	}
	
	function calcDuration()
	{
		$start_date = new CDate($this->project_start_date);
		$end_date = new CDate($this->project_end_date);
		
		return $start_date->compare($end_date);
	}
	
	function getActualEndDate() {
		$q = new DBQuery();
		$q->addQuery('max(task_log_date)');
		$q->addTable('task_log');
		$q->addTable('tasks', 't', 'task_id = task_log_task');
		$q->addTable('projects', 'p', 'p.project_id = t.task_project');
		//$q->addWhere();
		$date = $q->loadResult();
		
		if (empty($date))
			$date = $this->project_end_date;
		
		dPgetSysVal('ProjectStatus');
		$completed_status = array_search('Complete');
		if ($date > $this->project_end_date)
			;
		else if ($this->project_status == $completed_status)
			;
		else
			$date = $this->project_end_date;
			
		return $date;
	}
	
	function getStartEndDate() {
		$q = new DBQuery();
		$q->addQuery('min(task_log_date)');
		$q->addTable('task_log');
		$q->addTable('tasks', 't', 'task_id = task_log_task');
		$q->addTable('projects', 'p', 'p.project_id = t.task_project');
		//$q->addWhere();
		$date = $q->loadResult();
		
		if (empty($date))
			$date = $this->project_start_date;
		
		return $date;
	}
	
	function calcMaxStartDate($end_date = null) 
	{
		if ($end_date == null)
			$end_date = $this->project_end_date;
		$end_date = new CDate($end_date);
		
		$min_end_date = $this->calcMinEndDate();
		
		$project_end_date = new CDate($this->project_start_date);
		$duration = $min_end_date->compare($project_end_date);
			
		$offset = $project_end_date->compare($end_date);
		$end_date->addDays($offset - $duration);

		return $end_date;
	}
	
	function calcMinEndDate($start_date = null)
	{
		$diff = 0;
	
		if ($start_date != null)
			$diff = $start_date->compare(new CDate($this->project_start_date));
						
		$latest_date = new CDate($this->project_start_date);
		$q = new DBQuery();
		$q->addQuery('task_id');
		$q->addQuery('task_start_date, task_end_date');
		$q->addTable('tasks');
		$q->addWhere('task_project = ' . $this->project_id);
		$tasks = $q->loadList();
		
		$t = new CTask();
		
		foreach ($tasks as $task)
		{
			$t->load($task['task_id']);
			$date = $t->get_deps_max_end_date($t);
			if (empty($date))
				$date = $t->task_end_date;
			$date = new CDate($date);
			
			if ($date->compare($latest_date) < 0)
				$latest_date = $date;
		}
		$latest_date->addDays($diff);
		
		return $latest_date;	
	}

	function store() {
		$this->dPTrimAll();
        
		$msg = $this->check();
		if( $msg ) {
			return get_class( $this )."::store-check failed - $msg";
		}

		$details['name'] = $this->project_name;
		$details['project'] = $this->project_id;
		if( $this->project_id ) {
			$ret = db_updateObject( 'projects', $this, 'project_id', true );
			$details['changes'] = $ret;
			addHistory('projects', $this->project_id, 'update', $details);
		} 
		else {
			$ret = db_insertObject( 'projects', $this, 'project_id' );
			addHistory('projects', $this->project_id, 'add', $details);
		}
		
		//split out related departments and store them seperatly.
		$q = new DBQuery;
		$q->setDelete('project_departments');
		$q->addWhere('project_id='.$this->project_id);
		$q->exec();
		$q->clear();
        if ($this->project_departments) {
            $departments = explode(',',$this->project_departments);
            foreach ($departments as $department) {
				$q->addTable('project_departments');
				$q->addInsert('project_id', $this->project_id);
				$q->addInsert('department_id', $department);
				$q->exec();
				$q->clear();
            }
        }
		
		//split out related contacts and store them seperatly.
		$q->setDelete('project_contacts');
		$q->addWhere('project_id='.$this->project_id);
		$q->exec();
		$q->clear();
        if ($this->project_contacts) {
            $contacts = explode(',',$this->project_contacts);
            foreach ($contacts as $contact) {
                if ($contact) {
                    $q->addTable('project_contacts');
                    $q->addInsert('project_id', $this->project_id);
                    $q->addInsert('contact_id', $contact);
                    $q->exec();
                    $q->clear();
                }
            }
        }

		if( !$ret ) {
			return get_class( $this )."::store failed <br />" . db_error();
		} 
        else {
			$forum = new CForum();
			$forum->forum_id = 0;
			$forum->forum_project 	= $this->project_id;
			$forum->forum_name 		= $this->project_name;
			$forum->forum_owner 	= $this->project_owner;
			$forum->forum_moderated = $this->project_owner;
			$ret = $forum->store();
            
			return NULL;
		}
	}
}

/** 
 * The next lines of code have resided in projects/index.php before
 * and have been moved into this 'encapsulated' function
 * for reusability of that central code.
 *
 * @date 20060225
 * @responsible gregorerhardt
 *
 * E.g. this code is used as well in a tab for the admin/viewuser site
 *
 * @mixed user_id 	userId as filter for tasks/projects that are shown, if nothing is specified,
 *  	current viewing user $AppUI->user_id is used.
 */
function projects_list_data($user_id = false) {
	global $AppUI, $buffer, $company, $company_id, $company_prefix, $deny, $department, $dept_ids, 
        $filters, $orderby, $orderdir, $projects, $search_string, $tasks_critical, $tasks_problems, $tasks_sum, 
        $tasks_summy, $tasks_total;

	// Let's delete temproary tables
	$q  = new DBQuery;
	$q->dropTemp('tasks_sum, tasks_total, tasks_summy, tasks_critical, tasks_problems, tasks_users');
	$q->exec();
	$q->clear();

	// Task sum table
	// by Pablo Roca (pabloroca@mvps.org)
	// 16 August 2003

	$working_hours = dPgetConfig('daily_working_hours', 8);

	// GJB: Note that we have to special case duration type 24 and this refers to the hours in a day, NOT 24 hours
	$q->createTemp('tasks_sum');
	$q->addTable('tasks');
	$q->addQuery('task_project, SUM(task_duration * task_percent_complete * IF(task_duration_type = 24, '.$working_hours
                 .', task_duration_type)) / SUM(task_duration * IF(task_duration_type = 24, '.$working_hours
                 .', task_duration_type)) AS project_percent_complete, SUM(task_duration * IF(task_duration_type = 24, '
                 .$working_hours.', task_duration_type)) AS project_duration');
	if ($user_id) {
		$q->addJoin('user_tasks', 'ut', 'ut.task_id = tasks.task_id');
		$q->addWhere('ut.user_id = '.$user_id);
	}
  $q->addWhere("tasks.task_id = tasks.task_parent");
	$q->addGroup('task_project');
	$tasks_sum = $q->exec();
	$q->clear();
    
    // Task total table
  $q->createTemp('tasks_total');
	$q->addTable('tasks');
	$q->addQuery("task_project, COUNT(distinct tasks.task_id) AS total_tasks");
	if ($user_id) {
		$q->addJoin('user_tasks', 'ut', 'ut.task_id = tasks.task_id');
		$q->addWhere('ut.user_id = '.$user_id);
	}
	$q->addGroup('task_project');
	$tasks_total = $q->exec();
	$q->clear();
    
	// temporary My Tasks
	// by Pablo Roca (pabloroca@mvps.org)
	// 16 August 2003
	$q->createTemp('tasks_summy');
	$q->addTable('tasks');
	$q->addQuery('task_project, COUNT(distinct task_id) AS my_tasks');
	$q->addWhere("task_owner = $AppUI->user_id");
	if ($user_id) {
		$q->addWhere('task_owner = '.$user_id);
	} else {
		$q->addWhere('task_owner = '.$AppUI->user_id);
	}
	$q->addGroup('task_project');
	$tasks_summy = $q->exec();
	$q->clear();

	// temporary critical tasks
	$q->createTemp('tasks_critical');
	$q->addTable('tasks');
	$q->addQuery('task_project, task_id AS critical_task, MAX(task_end_date) AS project_actual_end_date');
	$q->addJoin('projects', 'p', 'p.project_id = task_project');
	$q->addOrder("task_end_date DESC");
	$q->addGroup('task_project');
	$tasks_critical = $q->exec();
	$q->clear();

	// temporary task problem logs
	$q->createTemp('tasks_problems');
	$q->addTable('tasks');
	$q->addQuery('task_project, task_log_problem');
	$q->addJoin('task_log', 'tl', 'tl.task_log_task = task_id');
	$q->addWhere("task_log_problem > '0'");
	$q->addGroup('task_project');
	$tasks_problems = $q->exec();
	$q->clear();

	// temporary users tasks
	$q->createTemp('tasks_users');
	$q->addTable('tasks');
	$q->addQuery('task_project');
	$q->addQuery('ut.user_id');
	$q->addJoin('user_tasks', 'ut', 'ut.task_id = tasks.task_id');
	if ($user_id) {
		$q->addWhere('ut.user_id = '.$user_id);
	}
	$q->addOrder("task_end_date DESC");
	$q->addGroup('task_project');
	$tasks_users = $q->exec();
	$q->clear();

	if($AppUI->isActiveModule('departments') && isset($department)) {
		//If a department is specified, we want to display projects from the department, and all departments under that, so we need to build that list of departments
		$dept_ids = array();
		$q->addTable('departments');
		$q->addQuery('dept_id, dept_parent');
		$q->addOrder('dept_parent,dept_name');
		$rows = $q->loadList();
		addDeptId($rows, $department);
		$dept_ids[] = $department;
	}
	$q->clear();

	// retrieve list of records
	// modified for speed
	// by Pablo Roca (pabloroca@mvps.org)
	// 16 August 2003
	// get the list of permitted companies
	$obj = new CCompany();
	$companies = $obj->getAllowedRecords( $AppUI->user_id, 'company_id,company_name', 'company_name' );
	if(count($companies) == 0) $companies = array(0);

	global $projects;

	$q->addTable('projects');
	$q->addQuery('projects.project_id, project_name, project_description, project_start_date, project_end_date, '.
                 'project_color_identifier, project_company, project_duration, project_status, project_priority');
	$q->addQuery('company_name');
	$q->addQuery('tc.critical_task, tc.project_actual_end_date');
	$q->addQuery('tp.task_log_problem');
	$q->addQuery('tt.total_tasks');
	$q->addQuery('tsy.my_tasks');
	$q->addQuery('ts.project_percent_complete');
	$q->addQuery('contact_first_name, contact_last_name');
	$q->addJoin('companies', 'co', 'projects.project_company = company_id');
	$q->addJoin('users', 'u', 'projects.project_owner = u.user_id');
	$q->addJoin('contacts', 'c', 'user_contact = contact_id');
	$q->addJoin('tasks_critical', 'tc', 'projects.project_id = tc.task_project');
	$q->addJoin('tasks_problems', 'tp', 'projects.project_id = tp.task_project');
	$q->addJoin('tasks_sum', 'ts', 'projects.project_id = ts.task_project');
	$q->addJoin('tasks_total', 'tt', 'projects.project_id = tt.task_project');
	$q->addJoin('tasks_summy', 'tsy', 'projects.project_id = tsy.task_project');
	$q->addJoin('tasks_users', 'tu', 'projects.project_id = tu.task_project');
	// DO we have to include the above DENY WHERE restriction, too?
	//$q->addJoin('', '', '');
	if (isset($department)) {
		$q->addJoin('project_departments', 'pd', 'pd.project_id = projects.project_id');
		$q->addWhere("pd.department_id in ( ".implode(',',$dept_ids)." )");
	}
	if ($search_string != "") {
		$q->addWhere("project_name LIKE '%$search_string%'");
    }

	if (is_array($filters))
	    foreach($filters as $field => $filter) {
	        if ($filter > 0) {
	            // Special conditions:
	            if ($field == 'project_owner') {
	                $q->addWhere('(tu.user_id = '.$filter.' OR projects.project_owner = '.$filter.' )');
	            }
	            else if ($field == 'project_company_type') {
	                $q->addWhere('co.company_type = ' . $filter);
	            }
	            else {
	                $q->addWhere("projects.$field = $filter ");
	            }
	        }
	    }
	    
	$q->addGroup('projects.project_id');
	$q->addOrder("$orderby $orderdir");
	$obj->setAllowedSQL($AppUI->user_id, $q);
	$projects = $q->loadList();

	// get the list of permitted companies
	$companies = arrayMerge( array( '0'=>$AppUI->_('All') ), $companies );

	//get list of all departments, filtered by the list of permitted companies.
	$q->clear();
	$q->addTable('companies', 'co');
	$q->addQuery('company_id, company_name, dep.*');
	$q->addJoin('departments', 'dep', 'co.company_id = dep.dept_company');
	$q->addOrder('company_name,dept_parent,dept_name');
	$obj->setAllowedSQL($AppUI->user_id, $q);
	$rows = $q->loadList();

	//display the select list
	$buffer = '<select name="department" onChange="document.pickCompany.submit()" class="text">';
	$buffer .= '<option value="company_0" style="font-weight:bold;">'.$AppUI->_('All').'</option>'."\n";
	$company = '';
	foreach ($rows as $row) {
		if ($row["dept_parent"] == 0) {
			if($company!=$row['company_id']){
				$buffer .= '<option value="'.$company_prefix.$row['company_id'].'" style="font-weight:bold;"'
                    .($company_id==$row['company_id']?'selected="selected"':'').'>'.$row['company_name'].'</option>'."\n";
				$company=$row['company_id'];
			}
			if($row["dept_parent"]!=null){
				showchilddept( $row );
				findchilddept( $rows, $row["dept_id"] );
			}
		}
	}
	$buffer .= '</select>';

	return $projects;
}

/** 
 * writes out a single <option> element for display of departments
 */
function showchilddept( &$a, $level=1 ) {
	global $buffer, $department;
	$s = '<option value="'.$a["dept_id"].'"'.(isset($department)&&$department==$a["dept_id"]?'selected="selected"':'').'>';
    
	for ($y=0; $y < $level; $y++) {
        $s .= (($y+1 == $level)?'':'&nbsp;&nbsp;');
	}
    
	$s .= '&nbsp;&nbsp;'.$a["dept_name"]."</option>\n";
	$buffer .= $s;
//	echo $s;
}

/**
 * recursive function to display children departments.
 */
function findchilddept( &$tarr, $parent, $level=1 ){
	$level = $level+1;
	$n = count( $tarr );
	for ($x=0; $x < $n; $x++) {
		if($tarr[$x]["dept_parent"] == $parent && $tarr[$x]["dept_parent"] != $tarr[$x]["dept_id"]){
			showchilddept( $tarr[$x], $level );
			findchilddept( $tarr, $tarr[$x]["dept_id"], $level);
		}
	}
}

function addDeptId($dataset, $parent){
	global $dept_ids;
	foreach ($dataset as $data){
		if($data['dept_parent']==$parent){
			$dept_ids[] = $data['dept_id'];
			addDeptId($dataset, $data['dept_id']);
		}
	}
}
?>