<?php

class LeadClass {
	private $DB;

	function __construct() {
		global $DB;
		$this->DB = $DB;
		require_once($_SERVER['DOCUMENT_ROOT'].'/local/components/bitrix/crm.timeline/class.php');
		$this->ent = new CCrmTimelineComponent();
		$this->leadWorked = function($isProcessed, $incoming, $isRepeat){
		    $leadRes;
		    if($isProcessed){
		        if($isRepeat){
    		        $leadRes = array(
					'LEAD_ID' => $incoming['ID'],
					'TITLE' => $incoming['TITLE'],
					'DATE_CREATE' => $incoming['DATE_CREATE'],
					'MANAGER' => $incoming['ASSIGNED_BY_NAME'] . ' ' . $incoming['ASSIGNED_BY_LAST_NAME'],
					'STATUS' => 'Повторный. Обработан'
    				);
		        }else{
		            $leadRes = array(
					'LEAD_ID' => $incoming['ID'],
					'TITLE' => $incoming['TITLE'],
					'DATE_CREATE' => $incoming['DATE_CREATE'],
					'MANAGER' => $incoming['ASSIGNED_BY_NAME'] . ' ' . $incoming['ASSIGNED_BY_LAST_NAME'],
					'STATUS' => 'Первичный. Обработан'
					);
		            
		        }
		    }else{
		        if($isRepeat){
    		        $leadRes = array(
					'LEAD_ID' => $incoming['ID'],
					'TITLE' => $incoming['TITLE'],
					'DATE_CREATE' => $incoming['DATE_CREATE'],
					'MANAGER' => $incoming['ASSIGNED_BY_NAME'] . ' ' . $incoming['ASSIGNED_BY_LAST_NAME'],
					'STATUS' => 'Повторный. Необработан'
    				);
		        }else{
		            $leadRes = array(
					'LEAD_ID' => $incoming['ID'],
					'TITLE' => $incoming['TITLE'],
					'DATE_CREATE' => $incoming['DATE_CREATE'],
					'MANAGER' => $incoming['ASSIGNED_BY_NAME'] . ' ' . $incoming['ASSIGNED_BY_LAST_NAME'],
					'STATUS' => 'Повторный. Обработан'
					);
		            
		        }
		    }
		    return $leadRes;
		};
		        
	}
	//Проводим поиск сущности в зависимости от типа
	public function getEntityData($entityType, $entityId){
		require_once($_SERVER['DOCUMENT_ROOT']. "/bitrix/modules/main/include/prolog_before.php");
		CModule::IncludeModule('crm');
		//Поиск по лидам, контактам
		if($entityType === 'LEAD'){
			$entityByApi = CCrmLead::GetByID($entityId, false);
			return $entityByApi;
		}else if($entityType === 'CONTACT'){
			$entityByApi = CCrmContact::GetByID($entityId, false);
			return $entityByApi;
		}else{
			return 'NO LEAD OR CONTACT';
		}
    }
	//Получаем тип сущности и информацию
	public function getEntity($entityId){
		$query = "SELECT * FROM `b_voximplant_statistic` WHERE `CALL_ID` = '".$entityId."'";
		$results = $this->DB->Query($query);
		$data;
		while ($result = $results -> fetch())
		{
			$data = $result;
		}
		return $data;
	}
	//Запись данных в БД
	public function insertNewIncoming($data){
		//Циклом формируем запрос чтобы каждый раз не редактировать при добавлении полей
		$query = 'INSERT INTO `psk_incoming_clients`';
		$keys = '';
		$values = '';
		foreach($data as $key => $value){
			$keys .= '`'.$key.'`, ';
			$values .= "'".$value."', ";
		}
		$keys = substr($keys, 0, -2);
		$values = substr($values, 0, -2);
		$query .= '('.$keys.') VALUES ('.$values.')';
		$results = $this->DB->Query($query);
	}
	//Проводим поиск дубликатов по id в таблице контроля дубликатов
	public function isDuplicate($id){
		$query = "SELECT * FROM `duplicate_leads` WHERE `UF_OLD_LEAD_ID`  LIKE '$id' OR `UF_NEW_LEAD_ID` LIKE '$id'";
		$data = [];
		$results = $this->DB->Query($query);
		while ($result = $results -> fetch()){
			$result['UF_LEAD_INFO'] = unserialize(gzuncompress(base64_decode($result['UF_LEAD_INFO'])));
			array_push($data, $result);
		}
		return $data;
	}
	public function isProcessedDirectly($lead){
		$leadProcess = $this->getLeadTimeline($lead['ID'], true);
		foreach($leadProcess as $dataArr){
			if(count($dataArr) > 0){
				foreach($dataArr as $entity){
					$entityDate = date('d.m.Y', strtotime($entity['CREATED_SERVER']));
					$leadDate = date('d.m.Y', strtotime($lead['DATE_CREATE']));
					if($entityDate == $leadDate){
						return ['processed' => true, 'data' => $leadProcess];
					}
				}
			}
			continue;
		}
		return ['processed' => false, 'data' => $leadProcess];
	}
	//Сортировка по ID
	public function sortArray($array) {
		$sortedArr = [];
		foreach ($array as $key => $arr){
			$sortedArr[$arr['ID']] = $arr;
		}
		array_multisort($sortedArr, SORT_ASC, $array);
		return $sortedArr;
	}
	//Получаем все звонки в лиде
	public function getCallsFromLead($leadId){
		$query = "SELECT * FROM `b_voximplant_statistic` WHERE `CRM_ENTITY_TYPE` = 'LEAD' AND `CRM_ENTITY_ID` = $leadId";
		$data = [];
		$results = $this->DB->Query($query);
		while ($result = $results -> fetch()){	
			$callData = $this->getEntity($result['CALL_ID']);
			array_push($data, $callData);
		}
		$dataSorted = $this->sortArray($data);
		return $dataSorted;
	}
	//Выделяем повторные звонки для исключения из общего отчета
	public function leadCallsFilter($callData){
		$callIdsArr = [];
		//Записываем все id звонков в массив
		foreach($callData['ENTITY_DATA']['LEAD_CALLS'] as $call){
			//Исключаем проверяемый звонок
			if($callData['CALL_ID'] === $call['CALL_ID']){
				continue;
			}else{
				array_push($callIdsArr, $call['CALL_ID']);
			}
		}
		//Если проверяемый звонок первый в лиде, то остальные записываем в массив и выкидываем из выгрузки в incomingCallsFilter
		if($callData['CALL_ID'] === $callData['ENTITY_DATA']['LEAD_CALLS'][0]['CALL_ID']){
			//Ищем наш звонок по id и удаляем из массива для удаления
			//$key = array_search($callIdsArr, $callData['CALL_ID']);
			//unset($callIdsArr[$key]);
			$separatedCalls = [
				'checked_call' => $callData['CALL_ID'],
				'toExcludeCalls' => $callIdsArr
			];
		}
		return $separatedCalls;
	}
	//Проверяет дольщик ли лид сейчас
	public function isHolder($id){
		$leadNow = $this->getEntityData('LEAD', $id);
		if($leadNow['STATUS_ID'] === '8'){
			return true;
		}else{
			return false;
		}
	}
	//Получаем и фильтруем данные из Живой ленты лида
	public function getLeadTimeline($id, $fullReport = false){
		$calls = $this->getCallsFromLead($id);
		$ent = $this->ent;
		$ent -> setEntityID($id);
		$ent -> setEntityTypeID(1);
		$data = $ent->loadHistoryItems(null, $nextOffsetTime = null, null, $nextOffsetID = null, array());
		$data = array_reverse($data);
		/*Далее разобрать массив на типы данных для анализа
		БП запускается через день
		Если не было через 24 часа звонков, смс, email от менеджера, то в повторную обработку*/
		
		$result = [
			'calls' => count($calls),
			'sms' => 0,
			'calls_timeline' => 0,
			'email' => 0,
			'lines' => 0
		];
		$resultFull = [
			'sms' => array(),
			'calls_timeline' => array(),
			'email' => array(),
			'lines' => array()
		];
		foreach($data as $entity){
			switch ($entity['ASSOCIATED_ENTITY_CLASS_NAME']) {
				case 'CRM_SMS':
					if($fullReport && $entity['ASSOCIATED_ENTITY']['DIRECTION'] == '2'){
						array_push($resultFull['sms'], $entity);
						break;
					}
					if($entity['ASSOCIATED_ENTITY']['DIRECTION'] == '2'){
						$result['sms']++;
					}
					break;
				case 'VOXIMPLANT_CALL':
					if($fullReport && $entity['ASSOCIATED_ENTITY']['CALL_INFO']['DURATION'] > 25){
						array_push($resultFull['calls_timeline'], $entity);
						break;
					}
					if($entity['ASSOCIATED_ENTITY']['CALL_INFO']['DURATION'] > 25){
						$result['calls_timeline']++;
					}
					break;					
				case 'CRM_EMAIL':
					if($fullReport && $entity['ASSOCIATED_ENTITY']['DIRECTION'] == '2'){
						array_push($resultFull['email'], $entity);
						break;
					}
					if($entity['ASSOCIATED_ENTITY']['DIRECTION'] == '2'){
						$result['email']++;
					}
					break;	
				case 'IMOPENLINES_SESSION':
					$messages = $entity['ASSOCIATED_ENTITY']['OPENLINE_INFO']['MESSAGES'];
					foreach($messages as $message){
						if($message['IS_EXTERNAL'] === true){
							$result['lines']++;
							if($fullReport){
								array_push($resultFull['lines'], $entity);
								break;
							}
						}
					}
					break;					
				default:
					break;
			}
		}
		$processed = false;
		foreach($result as $res_val){
			if($res_val > 0){
				$processed = true;
				break 1;
			}
		}
		$result['data'] = $data;
		if($fullReport){
			return $resultFull;
		}
		return [
			'processed' => $processed,
			'result' => $result
		];
	}
	
	public function inReportLead($incoming, $dateStart, $dateEnd){
		//Исключаем отв. не определен
		if($incoming['ENTITY_DATA']['ASSIGNED_BY_ID'] === '3'){
			return false;
		}
		//Исключаем дольщиков и со статусом на время звонка Визиты в офис
		if($incoming['ENTITY_DATA']['STATUS_ID'] == '8' || $incoming['ENTITY_DATA']['STATUS_ID'] == '6'){
			return false;
		}
		//Проверяем дольщик ли лид сейчас? Возможно надо усложнить проверку, так как непонятен срок перехода. Можно также вернуть в добавок текущее состояние лида
		if($this->isHolder($incoming['ENTITY_ID'])){
			return false;
		}
		return true;
	}
	//Метод проверяет успешность обработки лида в заданный период 3 дня, 3 месяца и т.д.
	public function leadProcessedInRange($leadId, $leadDateCreate, $range, $entities){
		//Range должен быть получен в секундах
		$typeCatIds = ['2','4','6'];

		$leadDate = substr($leadDateCreate, 0, -9);
		foreach($entities as $entityArr){
			if(count($entityArr) > 0){
				foreach($entityArr as $entity){
					//Проверяем только исходящие звонки, sms, email на попадание в диапазон
					if($entity['TYPE_ID'] === '1' && in_array($entity['TYPE_CATEGORY_ID'], $typeCatIds)/* && $entity['ASSOCIATED_ENTITY']['DIRECTION']=='2'*/){
						$entityDate = substr($entity['CREATED_SERVER'], 0, -9);
						if((strtotime($entityDate) - strtotime($leadDate)) < $range){
							return true;
						}
					}
				}
			}else{
				continue;
			}
			
		}
		return false;
	}

	public function leadProcessedInReport($entities, $dateStart, $dateEnd){
		foreach($entities as $entityArr){
			if(count($entityArr > 0)){
				//Проверяем куммуникации менеджеров на период отчетности
				foreach($entityArr as $entity){
					if(strtotime($entity['CREATED_SERVER']) >= strtotime($dateStart) && strtotime($entity['CREATED_SERVER']) <= strtotime($dateEnd)){
						$responce = array(
							'responce' => true,
							'managerId' => $entity['AUTHOR_ID'],
							'managerName' => $entity['AUTHOR']['FORMATTED_NAME']
						);
						return $responce;
					}else{
						$responce = array(
							'responce' => false
						);
						return $responce;
					}
				}
			}else{
				continue;
			}
		}
		$responce = array(
			'responce' => false
		);
		return $responce;
	}

	public function leadWorksThreeMonths($leadEntities, $dateStart){
		//Метод проверяет велась ли работа в течение 3 месяцев по лиду
		//Проверяем дату последней коммуникации до снятия отчета на попадание в диапазон 3 месяца
		foreach($entities as $entityArr){
			if(count($entityArr > 0)){
				//Проверяем куммуникации менеджеров до периода отчетности
				foreach($entityArr as $entity){
					$range = 7776000; //3 месяца в секундах
					if(strtotime($entity['CREATED_SERVER']) < strtotime($dateStart)){
						if((strtotime($dateStart) - strtotime($entity['CREATED_SERVER'])) < $range){
							return true;
						}else{
							continue;
						}
					}else{
						continue;
					}
				}
			}else{
				continue;
			}	
		}
		return false;
	}

	public function skippedProcessed($leadCalls, $dateStart){
		if(count($leadCalls > 0)){
			$skippedCallInRangeDate = null;
			$range = 259200;//3 дня в секундах
			$responce = [
				'hasSkipped' => false,
				'processed' => false,
				'managerName' => ''
			];
			foreach($leadCalls as $call){
				if(strtotime($entity['CREATED_SERVER']) >= strtotime($dateStart) && $entity['ASSOCIATED_ENTITY']['CALL_INFO']['STATUS_CODE'] == '304'){
					$skippedCallInRangeDate = $entity['CREATED_SERVER'];
					$responce['hasSkipped'] = true;
				}else{
					if($skippedCallInRangeDate != null){
						if(strtotime(($entity['CREATED_SERVER']) - strtotime($skippedCallInRangeDate)) > $range){
							$responce['processed'] = false;
							$responce['managerName'] = $entity['AUTHOR']['FORMATTED_NAME'];
							return $responce;
						}else{
							$responce['processed'] = true;
							$responce['managerName'] = $entity['AUTHOR']['FORMATTED_NAME'];
							return $responce;
						}
					}else{
						continue;
					}
				}			
			}
		}
		return $responce;
	}

	public function incomingCallsFilter($data, $dateStart, $dateEnd){
		$excelArr = [];
		$dataArr = [];
		$exclArr = [];
		
		foreach($data as $key => $incoming){
				//Лид новый
				//Пропускаем лиды со статусом Дольщик
				if($incoming['STATUS_ID'] == '8'){
					continue;
				}
				//пропускаем лиды по фитнесу и агентские
				if($incoming['SOURCE_ID'] == '24' || $incoming['SOURCE_ID'] == '16'){
					continue;
				}
				//Лиды с источником Визит в офис автоматом идут Ответственному
				if($incoming['SOURCE_ID'] == '4'){
					$newLead = $this->leadWorked(true, $incoming, false);
					array_push($excelArr, $newLead);
					array_push($dataArr, $incoming);
					continue;
				}
				if(strtotime($incoming['DATE_CREATE']) >= strtotime($dateStart)){
					//Обработан сразу в этот день.
					$leadProcessed = $this->isProcessedDirectly($incoming);
					if($leadProcessed['processed'] === true){
					    $newLead = $this->leadWorked(true, $incoming, false);											
						array_push($excelArr, $newLead);
						array_push($dataArr, $incoming);
						continue;
					}else{//Проверяем обработку на следующие сутки
						//Обработан на следующие сутки
						if($this->leadProcessedInRange($incoming['ID'], $incoming['DATE_CREATE'], 172800, $leadProcessed['data'])){
					        $newLead = $this->leadWorked(true, $incoming, false);				
							array_push($excelArr, $newLead);
							array_push($dataArr, $incoming);
							continue;
						}else{//Не обработан за сутки
						    $newLead = $this->leadWorked(false, $incoming, false);	
							array_push($excelArr, $newLead);
							array_push($dataArr, $incoming);
							continue;
						}
					}
				}
				//Лид повторный
				else{
					//На этом этапе нужно отфильтровать лиды, которые поали сюда по причине обновления по обращению
					//Нужно проверить обращения на предмет соответствия дате обращения к дате отчетности
					//Если совпадение есть, то дальше в обработку, но перед этим 
					//попадает ли он в отчет leadProcessedInReport
					$leadProcessed = $this->isProcessedDirectly($incoming);
					$leadEntities = $leadProcessed['data'];
					$inReport = $this->leadProcessedInReport($leadEntities, $dateStart, $dateEnd);
					//В $leadProcessed есть результат первичной обработки этого повторного лида
					if($inReport['responce']){
						if($leadProcessed['processed'] === true){
							//Сначала проверяем совпадение ответственного по лиду с ответственным по коммуникации
							//Если совпадает оставляем ответственному
							if($incoming['ASSIGNED_BY_ID'] == $inReport['managerId']){
								$newLead = $this->leadWorked(true, $incoming, true);	
							}else{
								//Далее тут проверяем велась ли работа последние 3 месяца
								//Если да то не учитывается, это обычная работа менеджера с клиентом 
								if($this->leadWorksThreeMonths($leadEntities, $dateStart)){
									continue;						
								}else{
									//Добавляем проверку на обработку в 3 дня
									//Берем первый звонок на период отчетности
									//Если первый звонок пропущенный, то проверяем обработку пропущенного до 3 дней
									//То есть после пропущенного должен быть успешный исходящий звонок/смс/email
									$hasSkipped = $this->skippedProcessed($leadEntities['calls_timeline'], $dateStart);
									if($hasSkipped['hasSkipped']){
										if($hasSkipped['processed']){
											$newLead = $this->leadWorked(true, $incoming, true);
										}else{
											$newLead = $this->leadWorked(false, $incoming, true);
										}
									}else{
										if($incoming['ASSIGNED_BY_ID'] != '3'){
											$newLead = $this->leadWorked(true, $incoming, true);
										}else{
											$newLead = $this->leadWorked(false, $incoming, true);
										}
									}					
								}
							}			
							array_push($excelArr, $newLead);
							array_push($dataArr, $incoming);
						}else{
							//Первичный лид необработан
							//Добавляем проверку на обработку в 3 дня
							//Берем первый звонок на период отчетности
							//Если первый звонок пропущенный, то проверяем обработку пропущенного до 3 дней
							//То есть после пропущенного должен быть успешный исходящий звонок/смс/email
							$hasSkipped = $this->skippedProcessed($leadEntities['calls_timeline'], $dateStart);
							if($hasSkipped['hasSkipped']){
								if($hasSkipped['processed']){
									$newLead = $this->leadWorked(true, $incoming, true);
								}else{
									$newLead = $this->leadWorked(false, $incoming, true);
								}
							}else{
								$newLead = $this->leadWorked(true, $incoming, true);
							}							
							array_push($excelArr, $newLead);
							array_push($dataArr, $incoming);
						}
					}else{
						continue;
					}
				}
		}
		$managers = array(
			'Итого' => count($excelArr) - 1,
			'Первичный. Не обработан' => 0,
			'Первичный. Обработан' => 0
		);
		foreach($excelArr as $lead){
			if(!isset($lead['LEAD_ID'])){
				continue;
			}
			$managers[$lead['STATUS']]++;
			if($lead['STATUS'] == 'Первичный. Не обработан'){
				continue;
			}
			if(!isset($managers[$lead['MANAGER']])){
				$managers[$lead['MANAGER']] = 1;
			}else{
				$managers[$lead['MANAGER']]++;
			}
		}
		
		$totalArr = array();
		foreach($managers as $key => $value){
			$totalLeads = array(
				'MANAGER' => $key,
				'LEADS' => $value
			);
			array_push($totalArr, $totalLeads);
		}
		$filtered = array(
			'excelArr' => $excelArr,
			'dataArr' => $dataArr,
			'totalArr' => $totalArr,
			'statuses' => $statuses
		);
		return $filtered;
	}
	public function getCallReport($dateStart, $dateEnd){		
		require_once($_SERVER['DOCUMENT_ROOT']. "/bitrix/modules/main/include/prolog_before.php");
		define("NOT_CHECK_PERMISSIONS",true);
		CModule::IncludeModule("crm");
	
		$arFilterMod = array(
			'>=DATE_MODIFY' => $dateStart,
			'<=DATE_MODIFY' => $dateEnd,
			'CHECK_PERMISSIONS' => 'N'
		);
		$resMod = CCrmLead::GetList(array(), $arFilterMod, array());
		$data = array();
		
		while($lead = $resMod->Fetch()){
			$data[$lead['ID']] = $lead;
		}
		//Собираем отдельно лиды созданные в период и перезаписываем если уже есть
		$arFilterNew = array(
			'>=DATE_CREATE' => $dateStart,
			'<=DATE_CREATE' => $dateEnd,
			'CHECK_PERMISSIONS' => 'N'
		);
		$resNew = CCrmLead::GetList(array(), $arFilterNew, array());
		while($lead = $resNew->Fetch()){
			$data[$lead['ID']] = $lead;
		} 
		//Разбираем результат выборки
		$filteredArr = $this->incomingCallsFilter($data, $dateStart, $dateEnd);
		array_merge($excelArr, $filteredArr['excelArr']); 
		//Пишем результат в Excel
		$filename = $this->writeToExcelFile($filteredArr['excelArr'], $dateStart, $dateEnd, $filteredArr['totalArr']);
		$resultData = [
			'data' => $filteredArr['excelArr'],
			'filename' => $filename
		];
		return $resultData;
	}

	public function writeToExcelFile($dataArray, $dateStart, $dateEnd, $totalArray){
		require_once($_SERVER['DOCUMENT_ROOT']. '/local/libs/phpexcel/Classes/PHPExcel.php');
		$dateStart = date("d.m.yy H:i:s", strtotime($dateStart));
		$dateEnd = date("d.m.yy H:i:s", strtotime($dateEnd));
		$filename = 'lead_reports/lead_report_from_'.$dateStart.'_to_'.$dateEnd.'_'.time().'.xlsx';
		$title = array('Ид лида', 'Название лида', 'Дата создания лида', 'Менеджер', 'Статус');
		$doc = new PHPExcel();
		// выбираем страницу 
		$doc->setActiveSheetIndex(0);
		// пишем таблицу
		$doc->getActiveSheet()->fromArray($dataArray);
		// выбираем страницу 
		$doc->createSheet();
		$doc->setActiveSheetIndex(1);
		// пишем таблицу
		$doc->getActiveSheet()->fromArray($totalArray);
		// clean data
		ob_end_clean();
		//OLD EXCEL $objWriter = PHPExcel_IOFactory::createWriter($doc, 'Excel5');
		//NEW EXCEL 
		$objWriter = PHPExcel_IOFactory::createWriter($doc, 'Excel2007');
		$objWriter->save($filename);
		return 'https://bitrix.psk-info.ru/reports_new/'.$filename;
	}

	public function writePartsToExcelFile($dataArray, $dateStart, $dateEnd){
		require_once($_SERVER['DOCUMENT_ROOT']. '/local/libs/phpexcel/Classes/PHPExcel.php');
		$dateStart = date("d.m.yy H:i:s", strtotime($dateStart));
		$dateEnd = date("d.m.yy H:i:s", strtotime($dateEnd));
		$filename = 'lead_reports/lead_report_from_'.$dateStart.'_to_'.$dateEnd.'_'.time().'.xlsx';
		$title = array('Ид лида', 'Название лида', 'Дата создания лида', 'Менеджер', 'Статус');
		$excelArr = array();
		array_unshift($dataArray, $title);
		array_merge($excelArr, $dataArray);
		$managers = array(
			'Итого' => count($dataArray) - 1,
			'Первичный. Не обработан' => 0,
			'Первичный. Обработан' => 0
		);
		foreach($dataArray as $lead){
			if(!isset($lead['LEAD_ID'])){
				continue;
			}
			$managers[$lead['STATUS']]++;
			if($lead['STATUS'] == 'Первичный. Не обработан'){
				continue;
			}
			if(!isset($managers[$lead['MANAGER']])){
				$managers[$lead['MANAGER']] = 1;
			}else{
				$managers[$lead['MANAGER']]++;
			}
		}
		
		$totalArr = array();
		foreach($managers as $key => $value){
			$totalLeads = array(
				'MANAGER' => $key,
				'LEADS' => $value
			);
			array_push($totalArr, $totalLeads);
		}
		$doc = new PHPExcel();
		// выбираем страницу 
		$doc->setActiveSheetIndex(0);
		// пишем таблицу
		$doc->getActiveSheet()->fromArray($dataArray);
		$doc->createSheet();
		$doc->setActiveSheetIndex(1);
		$doc->getActiveSheet()->fromArray($totalArr);
		ob_end_clean();
		$objWriter = PHPExcel_IOFactory::createWriter($doc, 'Excel2007');
		$objWriter->save($filename);
		$resultData = [
			'filename' => 'https://bitrix.psk-info.ru/reports_new/'.$filename
		];
		return 'https://bitrix.psk-info.ru/reports_new/'.$filename;
	}
}
