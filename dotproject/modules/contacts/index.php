<?php /* $Id$ */
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

$AppUI->savePlace();

if (! $canAccess) {
	$AppUI->redirect('m=public&a=access_denied');
}

$perms = $AppUI->acl();

// To configure an aditional filter to use in the search string
$additional_filter = "";
// retrieve any state parameters
if (isset( $_GET['where'] )) {
	$AppUI->setState( 'ContIdxWhere', $_GET['where'] );
}
if (isset( $_GET["search_string"] )){
	$AppUI->setState ('ContIdxWhere', "%".$_GET['search_string']);
				// Added the first % in order to find instrings also
	$additional_filter = "OR contact_first_name like '%{$_GET['search_string']}%'
	                      OR contact_last_name  like '%{$_GET['search_string']}%'
						  OR company_name       like '%{$_GET['search_string']}%'
						  OR contact_notes      like '%{$_GET['search_string']}%'
						  OR contact_email      like '%{$_GET['search_string']}%'";
}

$company_id = dPgetParam($_POST, 'company_filter', 'all');

$where = $AppUI->getState( 'ContIdxWhere' ) ? $AppUI->getState( 'ContIdxWhere' ) : '%';

$orderby = 'contact_order_by';

// Pull First Letters
$let = ':';
$search_map = array($orderby, 'contact_first_name', 'contact_last_name');
foreach ($search_map as $search_name)
{
	$q  = new DBQuery;
	$q->addTable('contacts');
	$q->addQuery("DISTINCT UPPER(SUBSTRING($search_name,1,1)) as L");
	$q->addWhere("contact_private=0 OR (contact_private=1 AND contact_owner=$AppUI->user_id)
								OR contact_owner IS NULL OR contact_owner = 0");
	$arr = $q->loadList();
	foreach( $arr as $L )
		$let .= $L['L'];
}


// optional fields shown in the list (could be modified to allow breif and verbose, etc)
$showfields = array(
	'contact_company' => 'contact_company',
	'company_name' 		=> 'company_name',
	'contact_phone' 	=> 'contact_phone',
	'contact_email' 	=> 'contact_email'
);

require_once $AppUI->getModuleClass('companies');
$company = new CCompany;
$allowedCompanies = $company->getAllowedSQL($AppUI->user_id);

// assemble the sql statement
$q = new DBQuery;
$q->addQuery('contact_id, contact_order_by');
$q->addQuery($showfields);
$q->addQuery('contact_first_name, contact_last_name, contact_phone');
$q->addTable('contacts', 'a');
$q->leftJoin('companies', 'b', 'a.contact_company = b.company_id');
$where_filter = '';
foreach($search_map as $search_name)
        $where_filter .=" OR $search_name LIKE '$where%'";
$where_filter = substr($where_filter, 4);
$q->addWhere("($where_filter $additional_filter)");
$q->addWhere("
	(contact_private=0
		OR (contact_private=1 AND contact_owner=$AppUI->user_id)
		OR contact_owner IS NULL OR contact_owner = 0
	)");
if ($company_id != 'all')
	$q->addWhere('contact_company = ' . $company_id);
else if (count($allowedCompanies)) {
	$comp_where = implode(' AND ', $allowedCompanies);
	$q->addWhere( '( (' . $comp_where . ') OR contact_company = 0 )' );
}
$q->addOrder('contact_order_by');

$carr[] = array();
$carrWidth = 4;
$carrHeight = 4;

$sql = $q->prepare();
$q->clear();
$res = db_exec( $sql );
if ($res)
	$rn = db_num_rows( $res );
else {
	echo db_error();
	$rn = 0;
}

$t = floor( $rn / $carrWidth );
$r = ($rn % $carrWidth);

if ($rn < ($carrWidth * $carrHeight)) {
	for ($y=0; $y < $carrWidth; $y++) {
		$x = 0;
		//if($y<$r)	$x = -1;
		while (($x<$carrHeight) && ($row = db_fetch_assoc( $res ))){
			$carr[$y][] = $row;
			$x++;
		}
	}
} else {
	for ($y=0; $y < $carrWidth; $y++) {
		$x = 0;
		if($y<$r)	$x = -1;
		while(($x<$t) && ($row = db_fetch_assoc( $res ))){
			$carr[$y][] = $row;
			$x++;
		}
	}
}

$tdw = floor( 100 / $carrWidth );

/**
* Contact search form
*/

// get CCompany() to filter tasks by company
require_once( $AppUI->getModuleClass( 'companies' ) );
$obj = new CCompany();
$companies = $obj->getAllowedRecords( $AppUI->user_id, 'company_id,company_name', 'company_name' );
$filters2 = arrayMerge(  array( 'all' => $AppUI->_('All', UI_OUTPUT_RAW) ), $companies );


 // Let's remove the first '%' that we previously added to ContIdxWhere
$default_search_string = dPformSafe(substr($AppUI->getState( 'ContIdxWhere' ), 1, strlen($AppUI->getState( 'ContIdxWhere' ))), true);

$form = '
<form action="./index.php" method="get">
'.$AppUI->_('Search for').'
	<input type="text" name="search_string" value="'.$default_search_string.'" />
	<input type="hidden" name="m" value="contacts" />
	<input type="submit" value=">" />
	<a href="./index.php?m=contacts&amp;search_string=">
		'.$AppUI->_('Reset search').'
	</a>
</form>';
// En of contact search form

$a2z = '
<table cellpadding="2" cellspacing="1" border="0">
<tr>
	<td width="100%" align="right">' . $AppUI->_('Show'). ': </td>
	<td><a href="./index.php?m=contacts&amp;where=0">' . $AppUI->_('All') . '</a></td>';
for ($c=65; $c < 91; $c++) {
	$cu = chr( $c );
	$cell = strpos($let, "$cu") > 0 ?
		"<a href=\"?m=contacts&amp;where=$cu\">$cu</a>" :
		"<font color=\"#999999\">$cu</font>";
	$a2z .= "\n\t<td>$cell</td>";
}
$a2z .= '
</tr>
<tr>
	<td colspan="28">'.$form.'</td>
</tr>
</table>';


// setup the title block
$titleBlock = new CTitleBlock( 'Contacts', 'monkeychat-48.png', $m, "$m.$a" );
$titleBlock->addCell( $a2z );
$titleBlock->addCell(
'<form action="?m=contacts" method="post" name="companyFilter">' .
  arraySelect( $filters2, 'company_filter', 'size=1 class=text onChange="document.companyFilter.submit();"', $company_id, false ) . 
'</form>', '', '', '');

if ($canEdit) {
	$titleBlock->addCell(
		'
<form action="?m=contacts&amp;a=addedit" method="post">
	<input type="submit" class="button" value="'.$AppUI->_('new contact').'" />
</form>', '', '', '');
	$titleBlock->addCrumbRight(
		'<a href="./index.php?m=contacts&amp;a=csvexport&amp;suppressHeaders=true">' . $AppUI->_('CSV Download') . "</a> | " .
		'<a href="./index.php?m=contacts&amp;a=vcardimport&amp;dialog=0">' . $AppUI->_('Import vCard') . '</a>'
	);
}

// Bread Crumbs
$titleBlock->addCrumb('?m=contacts&amp;a=list', 'list');

$titleBlock->show();

// TODO: Check to see that the Edit function is separated.

$tpl->displayFile('index');


for ($z=0; $z < $carrWidth; $z++) {
	echo '<td valign="top" align="left" bgcolor="#f4efe3" width="'.$tdw.'%">';


	for ($x=0; $x < @count($carr[$z]); $x++) {
		$tpl_contact = new CTemplate();

        	$contactid = $carr[$z][$x]['contact_id']; //added for simplification
		$tpl_contact->assign('contactid', $contactid);
		$tpl_contact->assign('contact', $carr[$z][$x]);
		$tpl_contact->assign('keyword', $_GET['search_string']);

		$q  = new DBQuery;
		$q->addTable('projects');
		$q->addQuery('count(*)');
		$q->addWhere("project_contacts like \"" .$contactid
			.",%\" or project_contacts like \"%," .$contactid 
			.",%\" or project_contacts like \"%," .$contactid
			."\" or project_contacts like \"" .$contactid."\"");
	
 		$res = $q->exec();
 		$projects_contact = db_fetch_row($res);
 		$q->clear();

		$contact_has_projects = ($projects_contact[0] > 0) ? true : false;
		$tpl_contact->assign('contact_has_projects', $contact_has_projects);

		$contact_fields = '';

		reset( $showfields );
		while (list( $key, $val ) = each( $showfields )) {
			if (strlen( $carr[$z][$x][$key] ) > 0) {
				if($val == "contact_email") {
					$contact_fields .= "<a href='mailto:{$carr[$z][$x][$key]}' class='mailto'>{$carr[$z][$x][$key]}</a>\n";
                } elseif($val == "contact_company" && is_numeric($carr[$z][$x][$key])) {
				} else {
					$contact_fields .= $carr[$z][$x][$key]. "<br />\n";
				}
			}
		}

		$tpl_contact->assign('contact_fields', $contact_fields);
		$tpl_contact_html = $tpl_contact->fetchFile('list.row');
		unset($tpl_contact);

		echo $tpl_contact_html;
	}

	echo "</td>";
}
echo '
</tr>
</table>';
?>