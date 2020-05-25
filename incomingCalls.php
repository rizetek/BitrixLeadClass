<?
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
	require_once($_SERVER["DOCUMENT_ROOT"]."/local/php_interface/classes/LeadClass.php");
	CJSCore::Init(array("jquery"));
	global $APPLICATION;
	$APPLICATION->SetTitle('Отчет по входящим обращениям');
?>
<form id="report-form" class="form-group">
	<label for="exampleFormControlSelect1">Дата от:</label>
	<input id="dateFrom" type="text" onclick="BX.calendar({node: this, field: this, bTime: false});" name="dateFrom" value="">
	<label for="exampleFormControlSelect1">Дата до:</label>
	<input id="dateTo" type="text" onclick="BX.calendar({node: this, field: this, bTime: false});" name="dateTo" value="">
	<div id="textStatus">Выберите даты отчетности</div>
	<button type="submit">Скачать</button>
</form>
<script>
	$('#report-form').on('submit', function(e){
		e.preventDefault();
		var dateFrom = $(this).find('#dateFrom').val();
		var dateTo = $(this).find('#dateTo').val();
		BX.showWait();
		/*
		$.ajax({
			method: 'POST',
			dataType: 'json',
			url: 'getReport.php',
			data: {
				action: 'getReport',
				dateFrom: dateFrom,
				dateTo: dateTo
			}
		})
		.success(function(info) {  
			BX.closeWait();      
			console.log(info.data);
			console.log(info.filename);
			document.location = info.filename;
		});
		*/
		var dates;
		$.ajax({
			method: 'POST',
			dataType: 'json',
			url: 'getReport.php',
			data: {
				action: 'getDates',
				dateFrom: dateFrom,
				dateTo: dateTo
			}
		})
		.success(function(data) { 
			BX.closeWait();
			dates = data;
			var count = dates.length;
			var leads = [];
			function ajaxwithi(i) {
				$.ajax({
					method: 'POST',
					dataType: 'json',
					url: 'getReport.php',
					data: {
						action: 'getReportParts',
						dateFrom: dates[i],
						dateTo: dates[i]
					}
				})
				.complete(function() {
						if (i <= count - 1) {
							ajaxwithi(i + 1);
						}else{
							$.post('getReport.php', {
								dataType: 'json',
								data: {
									action: 'getReportFile',
									dateFrom: dateFrom,
									dateTo: dateTo,
									data: leads
								}
							}).success(function(result) {
								console.log(result);
								document.location = result;
							})
						}
				})
				.success(function(result) {
						var dataStr = result.data;
						//dataStr = dataStr.slice(1, -1);
						leads = leads.concat(dataStr);
						var count = Object.keys(leads).length;
						$('#textStatus').text('Обработано лидов ' + count);
						console.log(result.data);
				});
			}			
			ajaxwithi(0);
