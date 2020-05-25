<?
//require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

// Без этого с консоли требует авторизацию.
define("NOT_CHECK_PERMISSIONS",true);
//
require_once($_SERVER['DOCUMENT_ROOT']. "/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('crm');
require_once($_SERVER["DOCUMENT_ROOT"]."/local/php_interface/classes/LeadClass.php");

if($_GET['action'] === 'getReport'){
	$dateFrom = date('d.m.Y', strtotime($_GET['dateFrom']));
	$dateTo = date('d.m.Y', strtotime($_GET['dateTo']));
	$dateFrom = $dateFrom.' 00:00:00';
	$dateTo = $dateTo.' 23:59:59';
	$reportObj = new LeadClass();
	$calls = $reportObj->getCallReport($dateFrom, $dateTo);
	echo json_encode($calls);
}
if($_GET['action'] === 'getReportDaily'){
	$date = date('d.m.Y', time() - 86400);
	$dateFrom = $date.' 00:00:00';
	$dateTo = $date.' 23:59:59';
	$reportObj = new LeadClass();
	$calls = $reportObj->getCallReport($dateFrom, $dateTo);
	mail("*****@psk-info.ru", "Daily lead report", '<a href="'.$calls['filename'].'">'.$calls['filename'].'</a>'); 
}
if($_GET['action'] === 'getDates'){
	$day = 86400;
	$format = 'd.m.Y';
	$startTime = strtotime($_GET['dateFrom']);
	$endTime = strtotime($_GET['dateTo']);
	$numDays = round(($endTime - $startTime) / $day) + 1;
	
	$days = array();
	
	for ($i = 0; $i < $numDays; $i++) { 
		$days[] = date($format, ($startTime + ($i * $day)));
	}
	
	echo json_encode($days);
}
if($_GET['action'] === 'getReportParts'){
	$dateFrom = $_GET['dateFrom'].' 00:00:00';
	$dateTo = $_GET['dateTo'].' 23:59:59';
	$reportObj = new LeadClass();
	$calls = $reportObj->getCallReport($dateFrom, $dateTo);
	echo json_encode($calls);
}

if($_POST['data']['action'] === 'getReportFile'){
	$dateFrom = date('d.m.Y', strtotime($_POST['data']['dateFrom']));
	$dateTo = date('d.m.Y', strtotime($_POST['data']['dateTo']));
	$data = $_POST['data']['data'];
	$reportObj = new LeadClass();
	$calls = $reportObj->writePartsToExcelFile($data, $dateFrom, $dateTo);
	echo $calls;
}
?>
