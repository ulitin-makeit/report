<?php

	namespace Brs\Report\Agent;

	use Bitrix\Main\Application;
	use Bitrix\Main\UserTable;
	use Bitrix\Main\Config\Option;
	use Bitrix\Crm\StatusTable; 
	use Bitrix\Crm\CompanyTable;
	use Bitrix\Crm\Category\Entity\DealCategoryTable;
	use Brs\Currency\Models\CurrencyRateTable;

    use Brs\Entities\Deal;
    use Brs\Report\Model\Orm\UniversalTable; // ОРМ таблицы отчёта
	use Brs\FinancialCard\Models\FinancialCardTable;
	use Brs\FinancialCard\Models\FinancialCardPriceTable;
	use Brs\FinancialCard\Models\IndividualFieldsTable;
	use Brs\FinancialCard\Models\RefundCardTable;
	use Brs\FinancialCard\Models\FinancialCardHotelTable;
	use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;
	use Brs\IncomingPaymentEcomm\Models\PaymentDeferredTable;
	use Brs\Main\Model\Orm\Crm\Deal\DealPropertyTable;
	use Brs\Main\Model\Orm\Crm\Contact\ContactPropertyTable;
	use Brs\CardData\Model\Orm\CardTypeTable;
	use Brs\Main\Crm\Deal\Course;
	use Brs\Listcontrol\Model\Orm\ManagerTable;

	use Brs\Models\OfferTable;
	use Brs\Models\CardTable;
	use Brs\Models\CountryTable;
	use Brs\Models\CityTable;
	use Brs\Models\CurrencyUidTable;
	use Brs\Models\GuestTable;
	use Brs\Models\AirportTable;
	use Brs\Helpers\Currency;

	/**
	 * Агент отчёта, перезаписывает данные в таблицу по нему (чтобы можно было фильтровать и список использовать на странице отчёта).
	 */
	class Universal {

		// Схема работы фин карты 
		static array $financialCardSchemeWork = [
			FinancialCardTable::SCHEME_BUYER_AGENT => 'Агент покупателя',
			FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT => 'Агент Поставщика&nbsp;SR',
			FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT => 'Агент Поставщика&nbsp;LR',
			FinancialCardTable::SCHEME_PROVISION_SERVICES => 'Оказание услуг',
			FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE => 'Сервисный сбор РС&nbsp;ТЛС'
		];

		static array $nds = array(
			'VAT_10' => 'CalculatedVat10110', // налог на добавленную стоимость (НДС) 10%;
			'VAT_20' => 'CalculatedVat20120', // НДС 20%
			'VAT_22' => 'CalculatedVat22122', // НДС 22%
			'VAT_0' => 0, // НДС 0%;
			'VAT_NO' => 0, // НДС не облагается;
			'VAT_10_110' => 'CalculatedVat10110', // вычисленный НДС 10% от 110% суммы;
			'VAT_18_118' => 'CalculatedVat18118', // вычисленный НДС 18% от 118% суммы;
			'VAT_20_120' => 'CalculatedVat20120',
			'VAT_22_122' => 'CalculatedVat22122',
		);

		/** Коэффициент «сумма с НДС» (1 + ставка НДС) для расчёта сумм без НДС */
		private const VAT_COEFFICIENT = 1.22;

		static array $headerCodes; // содержит массив соответствий

		/*
		 * Метод инициализирует перезапись отчёта в таблице.
		 * 
		 * @param string $typeRefresh
		 * @return string
		 */
		static function init(string $typeRefresh = 'all') : string {
			
			\ini_set('memory_limit', -1);
			\set_time_limit(0);

			// подключаем модули
			\CModule::IncludeModule('crm');
			\CModule::IncludeModule('brs.currency');
			\CModule::IncludeModule('brs.report');
			\CModule::IncludeModule('brs.financialcard');
			\CModule::IncludeModule('brs.incomingpaymentecomm');

			// генерируем сам отчёт
			$document = self::generateDocumentReport($typeRefresh);

			if($typeRefresh == 'all'){ // заполняем таблицу (стираем все предыдущие данные и заполняем полностью)
				self::fillReportTable($document);
			} else { // изменяем в таблице только те сделки, которые были изменены или добавлены
				self::updateReportTable($document);
			}

			self::updatePropertyDeal($document['dealIdList']); // устанавливаем значение "Да" всем сделкам со свойством "Отчёт обновлён"

			Option::set('brs.report', 'BRS_REPORT_UNIVERSAL_DATE_REFRESH', (new \DateTime())->format('d.m.Y H:i:s'), SITE_ID); // сохраняем дату последнего обновления отчёта

			return '\\Brs\\Report\\Agent\\Universal::init('.($typeRefresh != 'all') ? '\''.$typeRefresh.'\'' : ''.');';

		}

		/**
		 * Метод заполняет таблицу отчётов.
		 * 
		 * @param array $document
		 */
		private function fillReportTable(array $document){

			// шапка документа
			$header = array();

			foreach(UniversalTable::$codeHeaderFields as $code => $ruLang){
				$header[] = $ruLang;
			}

			$headerKeys = array_flip($header); // переворачиваем массив и ищем по ключам
			
			$universalNameToCode = array_flip(UniversalTable::$codeHeaderFields); // массив соответствий названий колонок и кодов

			// очищаем таблицу
			Application::getConnection()->truncateTable(UniversalTable::getTableName());

			$columnList = Application::getConnection()->getTableFields(UniversalTable::getTableName());

			$columnDateList = [];

			foreach($columnList as $name => $column){

				if($column instanceof \Bitrix\Main\ORM\Fields\DatetimeField){
					$columnDateList[$name] = 'datetime';
				} else if($column instanceof \Bitrix\Main\ORM\Fields\DateField){
					$columnDateList[$name] = 'date';
				}

			}

			$dataListMulti = [];

			$split = 0;
			$splitIndex = 0;

			foreach($document['body'] as $row){

				if($splitIndex == 4000){

					$split++;

					$splitIndex = 0;

				}

				$data = [];

				$data['DEAL_ID'] = $row[$headerKeys['Номер сделки']];

				// обходим данные в строке и заполняем ORM объект
				foreach($row as $columnId => $columnValue){

					if(empty($columnValue)){
						$columnValue = '';
					} else if(array_key_exists($universalNameToCode[$header[$columnId]], $columnDateList)) {
						$columnValue = new \Bitrix\Main\Type\DateTime($columnValue);
					} else if($universalNameToCode[$header[$columnId]] == 'DATA_VYLETA' && !empty($columnValue)){
						$columnValue = explode(', ', $columnValue);
					}

					$data[$universalNameToCode[$header[$columnId]]] = $columnValue;
					
				}

				$dataListMulti[$split][] = $data;

				$splitIndex++;

			}

			foreach($dataListMulti as $dataList){
				Application::getConnection()->addMulti(UniversalTable::getTableName(), $dataList);
			}

		}

		/**
		 * Метод изменяет в таблице отчёта только те сделки, котороые были изменены или добавлены.
		 * 
		 * @param array $document
		 */
		private function updateReportTable(array $document){

			if(count($document['dealIdList']) == 0){
				return;
			}

			$universalCollection = UniversalTable::getList([
				'filter' => [
					'DEAL_ID' => $document['dealIdList']
				]
			]);

			if($universalCollection->getSelectedRowsCount() == 0){
				return;
			}

			// шапка документа
			$header = array();

			foreach(UniversalTable::$codeHeaderFields as $code => $ruLang){
				$header[] = $ruLang;
			}

			$headerKeys = array_flip($header); // переворачиваем массив и ищем по ключам
			
			$universalNameToCode = array_flip(UniversalTable::$codeHeaderFields); // массив соответствий названий колонок и кодов

			// получаем коллекцию строк в отчёте, которые нужно изменить
			$universalCollection = $universalCollection->fetchCollection();

			foreach($universalCollection as $universal){

				$document['dealIdList'] = array_diff($document['dealIdList'], [$universal->getDealId()]);

				$row = $document['body'][$universal->getDealId()];

				// обходим данные в строке и заполняем ORM объект
				foreach($row as $columnId => $columnValue){

					if($universalNameToCode[$header[$columnId]] == 'DATA_VYLETA' && !empty($columnValue)){
						$columnValue = explode(', ', $columnValue);
					}

					$universal->set($universalNameToCode[$header[$columnId]], $columnValue);

				}

				$universalCollection->add($universal); // добавляем сформированный объект в коллекцию

			}

			@$universalCollection->save(); // сохраняем коллекцию

			// создаём коллекцию
			$universalCollection = UniversalTable::createCollection();

			// обходим строки документа и записываем в коллекцию
			foreach($document['dealIdList'] as $dealId){
				
				$row = $document['body'][$dealId];

				$dealId = (int) str_replace('Сделка №', '', $row[$headerKeys['Номер сделки']]);

				// создаём объект отчёта
				$universal = UniversalTable::createObject();
				
				// получаем и заполняем идентификатор сделки
				$universal->setDealId((int) str_replace('Сделка №', '', $row[$headerKeys['Номер сделки']]));

				// обходим данные в строке и заполняем ORM объект
				foreach($row as $columnId => $columnValue){

					if($universalNameToCode[$header[$columnId]] == 'DATA_VYLETA' && !empty($columnValue)){
						$columnValue = explode(', ', $columnValue);
					}

					$universal->set($universalNameToCode[$header[$columnId]], $columnValue);

				}

				$universalCollection->add($universal); // добавляем сформированный объект в коллекцию

			}

			@$universalCollection->save(); // сохраняем коллекцию

		}

		/**
		 * Метод формирует заголовок и тело документа (отчёта).
		 * 
		 * @param string $typeRefresh
		 * @return array header,body
		 */
		private static function generateDocumentReport(string $typeRefresh){

			// подключаем класс компонента расчёта финансовых карточек
			\CBitrixComponent::includeComponentClass('brs.financialcard:financial-card.calc');

			// получаем путь к формулам финансовой карточки
			$pathFinancialCardFormulas = str_replace('class.php', 'formulas/', (new \ReflectionClass(\FinancialCalcComponent::class))->getFileName());

			// шапка документа
			$header = array();

			foreach(UniversalTable::$codeHeaderFields as $code => $ruLang){
				$header[] = $ruLang;
			}

			$headerKeys = array_flip($header);

			// тело документа
			$bodyRows = array();

			$dealDate = new \Brs\Services\DealDate;

			$dateStartDeal = (new \Brs\Services\DealDate)->getStartFieldNames();
			$dateFinishDeal = $dealDate->getCategoryFinishFieldName();

			$select = array_merge([ '*', 'IS_WON', 'UF_CRM_DEAL_PARTNER', 'UF_CRM_DEAL_DATE_OF_ARRIVAL', 'UF_CRM_DEAL_DATE_OF_DEPARTURE', 'UF_CRM_DEAL_NUMBER_OF_NIGHTS', 'UF_CRM_DEAL_CLASS_TRIP', 'UF_CRM_DEAL_NAME_OF_PASSENGERS', 'UF_CRM_DEAL_DATE_DEPARTURE', 'UF_CRM_DEAL_STAGE_OTHER_REASON', 'UF_BRS_CRM_DEAL_CITIES', 'UF_BRS_CRM_DEAL_COUNTRIES', 'UF_CRM_DEAL_MARKETING_CHANNEL', 'UF_CRM_DEAL_MARKETING_CHANNEL_OTHER', 'UF_CRM_DEAL_ORM_HOTEL', 'UF_BRS_CRM_DEAL_GUESTS', 'UF_CRM_DEAL_TYPE_CASE', 'UF_CRM_DEAL_COMPLEMENTARY_SERVICES', 'UF_BRS_CRM_DEAL_PAYMENT_TYPE', 'UF_CRM_DEAL_DELIVERY_TIME', 'UF_CRM_DEAL_RESTAURANT', 'UF_BRS_CRM_DEAL_AIRPORT', 'UF_CRM_DEAL_AIRPORT', 'UF_CRM_DEAL_COUNTRY', 'UF_CRM_DEAL_CITY_COUNTRY', 'UF_BRS_CRM_DEAL_CITY_START', 'UF_BRS_CRM_DEAL_CITY_FINISH', 'UF_CRM_DEAL_INTERVAL_2', 'UF_CRM_DEAL_MANAGER', 'UF_DATE_SERVICE_PROVISION', 'UF_CRM_DEAL_NUMBER_OF_ROOMS', 'UF_COMMENT_TEAMLEADER', Deal::BIND_LEAD, Deal::FULL_NUMBER_OF_NIGHTS, Deal::IS_CROSS_SELLING, Deal::CROSS_SELLING_REASON, Deal::DROPOFF, Deal::PICKUP, Deal::DROPOFF_HERE, Deal::FINAL_COUNTRY, Deal::FINAL_CITY ], $dateStartDeal, $dateFinishDeal);

			$arFilter = [
				'CHECK_PERMISSIONS' => 'N',
				'!CATEGORY_ID'=> '21'
			];
			
			$dealIdList = [];
			$maxDealId = 0;

			if($typeRefresh != 'all'){

				$select[] = Deal::UNIVERSAL_REPORT_UPDATE;

				$arFilter[Deal::UNIVERSAL_REPORT_UPDATE] = true;
			}

			// получаем сделки
			$dbDeals = \CAllCrmDeal::GetListEx([ 'ID' => 'DESC' ], $arFilter, false, false, $select);

			// получаем все стадии
			$dealStageDb = StatusTable::getList([])->fetchAll();

			$dealStageList = []; // формируем список стадий по их идентификатору статуса

			foreach($dealStageDb as $dealStage){
				$dealStageList[$dealStage['ENTITY_ID']][$dealStage['STATUS_ID']] = $dealStage;
			}

			unset($dealStageDb);

			// получаем свойства контактов
			$contactPropertyDb = ContactPropertyTable::getList([])->fetchAll();

			$contactPropertyList = []; // формируем список свойств по идентификатору контакта

			$contactIdList = [];

			foreach($contactPropertyDb as $contactProperty){

				$contactIdList[] = $contactProperty['CONTACT_ID'];

				$contactPropertyList[$contactProperty['CONTACT_ID']] = $contactProperty;

			}

			unset($contactPropertyDb);

			// получаем путешественников
			$guestDb = GuestTable::getList([
				'select' => [ 'id', 'name' ],
			])->fetchAll();

			$guestList = []; // формируем список путешественников по их идентификатору

			foreach($guestDb as $guest){
				$guestList[$guest['id']] = $guest['name'];
			}

			unset($guestDb);

			// получаем предложения
			$offerDb = OfferTable::getList([

				'filter' => [
					'UF_STATUS' => OfferTable::getApproachStatuses()
				],

				'select' => [ 'UF_DEAL_ID', 'UF_COMPANY_ID' ]

			])->fetchAll();

			$offerList = []; // формируем список предложений по идентификатору сделки

			foreach($offerDb as $offer){
				$offerList[$offer['UF_DEAL_ID']] = $offer['UF_COMPANY_ID'];
			}

			unset($offerDb);

			// получаем компании
			$companyDb = CompanyTable::getList([
				'select' => [ 'ID', 'TITLE', 'UF_CRM_COMPANY_CONTRAGENT', 'UF_BRS_CRM_COMPANY_TYPE', 'UF_BRS_CRM_COMPANY_PRIVILEGE', 'UF_CRM_COMPANY_CONTRACT_STATUS' ],
			])->fetchAll();

			$companyList = []; // формируем список компаний по их идентификатору

			foreach($companyDb as $company){
				$companyList[$company['ID']] = $company;
			}

			unset($companyDb);

			// получаем отложенные оплаты
			$paymentDeferredDb = PaymentDeferredTable::getList([])->fetchAll();

			$paymentDeferredList = []; // формируем список отложенных оплат по их идентификатору

			foreach($paymentDeferredDb as $paymentDeferred){
				$paymentDeferredList[$paymentDeferred['DEAL_ID']] = $paymentDeferred;
			}

			unset($paymentDeferredDb);

			// получаем категории сделок
			$dealCategoryDb = DealCategoryTable::getList([])->fetchAll();

			$dealCategoryList = []; // формируем список категорий сделок по их идентификатору

			foreach($dealCategoryDb as $dealCategory){
				$dealCategoryList[$dealCategory['ID']] = $dealCategory;
			}

			unset($dealCategoryDb);

			// получаем карты
			$cardDb = CardTable::getList([])->fetchAll();

			$cardList = []; // формируем список карт по их идентификатору

			foreach($cardDb as $card){
				$cardList[$card['id']] = $card;
			}

			unset($cardDb);

			// получаем менеджеров
			$managerDb = ManagerTable::getList([])->fetchAll();

			$managerList = []; // формируем список карт по их идентификатору

			foreach($managerDb as $manager){
				$managerList[$manager['ID']] = $manager['NAME'];
			}

			unset($managerDb);
			
			// получаем карты по контактам
			$contactCardDb = CardTable::getList([
				'select' => [
					'*', 'contactId' => 'clients.id'
				],
				'filter' => [
					'clients.id' => $contactIdList,
					'is_active' => true,
					'main' => true
				],
				'order' => [
					'id' => 'DESC'
				]
			])->fetchAll();

			$contactCardList = []; // формируем список карт по их идентификатору контакта

			foreach($contactCardDb as $contactCard){
				if(!array_key_exists($contactCard['contactId'], $contactCardList)){
					$contactCardList[$contactCard['contactId']] = $contactCard;
				}
			}

			unset($contactCardDb);

			// получаем типы карт
			$cardTypeDb = CardTypeTable::getList([])->fetchAll();

			$cardTypeList = []; // формируем список типов карт по их идентификатору

			foreach($cardTypeDb as $cardType){
				$cardTypeList[$cardType['ID']] = $cardType;
			}

			unset($cardTypeDb);

			// получаем пользователей
			$userDb = UserTable::getList([])->fetchAll();

			$userList = []; // формируем список пользователей по их идентификатору

			foreach($userDb as $user){
				$userList[$user['ID']] = $user;
			}

			unset($userDb);

			// получаем страны
			$countryDb = CountryTable::getList([])->fetchAll();

			$countryList = []; // формируем список стран по их идентификатору

			foreach($countryDb as $country){
				$countryList[$country['country_id']] = $country;
			}

			unset($countryDb);

			// получаем города
			$cityDb = CityTable::getList([])->fetchAll();

			$cityList = []; // формируем список стран по их идентификатору

			foreach($cityDb as $city){
				$cityList[$city['city_id']] = $city;
			}

			unset($cityDb);

			// получаем возвраты
			$refundCardDb = RefundCardTable::getList([
				'order' => [
					'ID' => 'DESC'
				]
			])->fetchAll();

			$refundCardList = []; // формируем список карт возврата по идентификатору сделок

			foreach($refundCardDb as $refundCard){
				$refundCardList[$refundCard['DEAL_ID']] = $refundCard;
			}

			unset($refundCardDb);

			// получаем финансовые карты
			$financialCardDb = FinancialCardTable::getList([
				'order' => [
					'ID' => 'DESC'
				],
			])->fetchAll();

			$financialCardList = []; // формируем список финансовых карт по идентификатору сделок

			foreach($financialCardDb as $financialCard){
				$financialCardList[$financialCard['DEAL_ID']] = $financialCard;
			}

			unset($financialCardDb);

			// получаем цены финансовых карт
			$financialCardPriceDb = FinancialCardPriceTable::getList([
				'order' => [
					'ID' => 'DESC'
				],
			])->fetchAll();

			$financialCardPriceList = []; // формируем список цен финансовых карт по идентификатору сделок

			foreach($financialCardPriceDb as $financialCardPrice){
				$financialCardPriceList[$financialCardPrice['ID']] = $financialCardPrice;
			}

			unset($financialCardPriceDb);

			// получаем поля финансовых карт
			$individualFieldsDb = IndividualFieldsTable::getList([])->fetchAll();

			$individualFieldsList = []; // формируем список полей финансовых карт по идентификатору сделок

			foreach($individualFieldsDb as $individualFields){
				$individualFieldsList[$individualFields['FINANCIAL_CARD_ID']] = $individualFields;
			}

			unset($individualFieldsDb);

			// получаем отели финансовых карт
			$financialCardHotelDb = FinancialCardHotelTable::getList([
				'select' => [ '*', 'CHAIN_VALUE' => 'CHAIN.VALUE' ]
			])->fetchAll();

			$financialCardHotelList = []; // формируем список полей финансовых карт по идентификатору сделок

			foreach($financialCardHotelDb as $financialCardHotel){
				$financialCardHotelList[$financialCardHotel['ID']] = $financialCardHotel;
			}

			unset($financialCardHotelDb);

			// получаем платежи
			$paymentTransactionDb = PaymentTransactionTable::getList([

				'order' => [
					'ID' => 'DESC'
				],

				'filter' => [

					'STATUS' => 'SUCCESS',

					'PAYMENT_TYPE' => [
						PaymentTransactionTable::PAYMENT_TYPE_INCOMING,
						PaymentTransactionTable::PAYMENT_TYPE_REFUND
					],

				]

			])->fetchAll();

			$paymentTransactionList = []; // формируем список категорий сделок по их идентификатору

			foreach($paymentTransactionDb as $paymentTransaction){
				$paymentTransactionList[$paymentTransaction['DEAL_ID']][] = $paymentTransaction;
			}

			unset($paymentTransactionDb);

			// получаем enum значения заранее
			$enumListDb = \CUserFieldEnum::GetList([], []);

			$enumList = []; // формируем список enum значений по их идентификатору

			while($enum = $enumListDb->Fetch()){
				$enumList[$enum['ID']] = $enum;
			}

			// получаем агентов из инфоблока
			$agentListDb = \CIblockElement::GetList([], [
				'=IBLOCK_ID' => PARTICIPATION_AGENT_IBLOCK_ID,
				'>PROPERTY_DEAL' => 0
			], false, false, ['ID', 'NAME', 'PROPERTY_AGENT', 'PROPERTY_DEAL', 'PROPERTY_PERCENT_PARTICIPATION']);

			$agentList = [];

			// обходим всех агентов и формируем массив со всеми агентам
			while($agent = $agentListDb->Fetch()){
				$agentList[$agent['PROPERTY_DEAL_VALUE']][] = $agent;
			}

			// получаем список валют по юидам
			$currencyUidListDb = CurrencyUidTable::getList([
				'select' => [ '*', 'FULL_NAME' => 'CURRENT_LANG_FORMAT.FULL_NAME' ]
			])->fetchAll();

			$currencyUidList = [];

			foreach($currencyUidListDb as $currencyUid){
				$currencyUidList[$currencyUid['CURRENCY']] = $currencyUid;
			}

			// обходим массив сделок и формируем тело документа
			while($deal = $dbDeals->Fetch()){

				$dateClose = '';

				if(!empty($deal['CLOSEDATE'])){
					$dateClose = $deal['CLOSEDATE'];
				}

				if(!empty($dateClose) && is_array($dateClose)){
					$dateClose = current($dateClose);
				} else if(!empty($dateClose) && is_object($dateClose)){
					$dateClose = $dateClose->toString();
				}

				$currentDealDate = (new \Brs\Services\DealDate)->execute($deal);

				if($maxDealId < $deal['ID']){
					$maxDealId = $deal['ID'];
				}
				
				if(str_replace(':', '', $deal['STAGE_ID']) != $deal['STAGE_ID'] && !empty($dealStageList['DEAL_STAGE_'.$deal['CATEGORY_ID']][$deal['STAGE_ID']])){
					$dealStageName = $dealStageList['DEAL_STAGE_'.$deal['CATEGORY_ID']][$deal['STAGE_ID']]['NAME'];
				} else if($dealStageList['DEAL_STAGE'][$deal['STAGE_ID']]){
					$dealStageName = $dealStageList['DEAL_STAGE'][$deal['STAGE_ID']]['NAME'];
				} else {
					$dealStageName = '';
				}

				$dealIdList[] = $deal['ID'];

				$contactCsId = '';

				// если контакт указан в сделке
				if(!empty($deal['CONTACT_ID'])){
					$contactCsId = $contactPropertyList[$deal['CONTACT_ID']]['UF_CRM_CONTACT_KS_ID'];
				}

				// формируем строку документа (заполняем по умолчанию)
				$bodyRow = array(
					
					$headerKeys['Номер сделки'] => $deal['ID'],
					$headerKeys['Название сделки'] => $deal['TITLE'],
					$headerKeys['% участия агента в продаже*'] => '',
					$headerKeys['Дата создания сделки'] => '',
					$headerKeys['Статус сделки'] => $dealStageName,
					$headerKeys['Ответственное лицо'] => implode(' ', [ $deal['ASSIGNED_BY_LAST_NAME'], $deal['ASSIGNED_BY_NAME'], $deal['ASSIGNED_BY_SECOND_NAME'] ]),
					$headerKeys['Тип клиента'] => '',
					$headerKeys['Клиент'] => implode(' ', [ $deal['CONTACT_LAST_NAME'], $deal['CONTACT_NAME'], $deal['CONTACT_SECOND_NAME'] ]),
					$headerKeys['Менеджер'] => $managerList[$deal['UF_CRM_DEAL_MANAGER']],
					$headerKeys['ID клиента'] => $contactCsId,
					$headerKeys['Дата создания фин.карты'] => '',
					$headerKeys['Создатель карты'] => '',
					$headerKeys['Дата оплаты Клиентом'] => '',
					$headerKeys['Тип карты'] => '',
					$headerKeys['Статус карты возврата'] => '',
					$headerKeys['Дата отмены операции (возврат)'] => '',
					$headerKeys['Дата возврата'] => '',
					$headerKeys['Партнер'] => '',
					$headerKeys['Количество броней (1)'] => 1, // пока всегда 1
					$headerKeys['Тип брони'] => '',
					$headerKeys['Страна'] => '',
					$headerKeys['Город'] => '',
					$headerKeys['Гостиница'] => '',
					$headerKeys['Ресторан'] => '',
					$headerKeys['Полное наименование организации'] => '',
					$headerKeys['Цепочка'] => '',
					$headerKeys['Дата заезда'] => '',
					$headerKeys['Дата выезда'] => '',
					$headerKeys['Количество ночей'] => '',
					$headerKeys['Общее количество ночей'] => '',
					$headerKeys['Категория'] => '',
					$headerKeys['Канал связи'] => '',
					$headerKeys['Маркетинговый канал'] => '',
					$headerKeys['Итого оплачено клиентом'] => 0,
					$headerKeys['Сумма продажи'] => 0,
					$headerKeys['Оплата поставщику'] => 0,
					$headerKeys['Сумма возврата поставщиком'] => 0,
					$headerKeys['Штраф от поставщика'] => 0,
					$headerKeys['Сбор поставщика на возврат'] => 0,
					$headerKeys['Продукты за сбор возврата'] => 0,
					$headerKeys['Штраф клиенту РС ТЛС'] => 0,
					$headerKeys['Возврат сбора РС ТЛС'] => 0,
					$headerKeys['Остаток сбора РС ТЛС'] => 0,
					$headerKeys['Прибыль РС ТЛС с учетом возврата'] => 0,
					$headerKeys['Удержал поставщик'] => 0,
					$headerKeys['Сумма возврата клиенту'] => 0,
					$headerKeys['Прибыль'] => 0,
					$headerKeys['Прибыль без НДС'] => 0,
					$headerKeys['Сумма прибыли с учетом возврата без НДС'] => 0,
					$headerKeys['Комиссия'] => 0,
					$headerKeys['Комиссия без НДС'] => 0,
					$headerKeys['Дополнительная выгода'] => 0,
					$headerKeys['Дополнительная выгода без НДС'] => 0,
					$headerKeys['Сервисный сбор'] => '',
					$headerKeys['Сервисный сбор без НДС'] => 0,
					$headerKeys['Нетто в Валюте поставщика'] => 0,
					$headerKeys['Брутто в Валюте поставщика'] => 0,
					$headerKeys['Комиссия поставщика в Валюте'] => 0,
					$headerKeys['Нетто в рублях'] => '',
					$headerKeys['Валюта сделки'] => '',
					$headerKeys['Название валюты сделки'] => '',
					$headerKeys['Курс оплаты'] => '',
					$headerKeys['Путешественник'] => '',
					$headerKeys['Сумма НДС'] => 0,
					$headerKeys['Сумма TID'] => '',
					$headerKeys['SR'] => '',
					$headerKeys['LR'] => '',
					$headerKeys['Тип запроса'] => '',
					$headerKeys['Связанные сделки'] => '',
					$headerKeys['Лид'] => '',
					$headerKeys['Тур'] => 'Нет',
					$headerKeys['Номер счёта'] => '',
					$headerKeys['Тип оплаты'] => '',
					$headerKeys['Дата оказания услуги'] => '',
					$headerKeys['Сумма продажи после возврата'] => 0,
					$headerKeys['Депозит'] => '', // пока пустое
					$headerKeys['Баллы AX'] => '', // пока пустое
					$headerKeys['Баллы MR'] => '', // пока пустое
					$headerKeys['Баллы IMP'] => '', // пока пустое
					$headerKeys['безнал'] => '', // пока пустое
					$headerKeys['Карта'] => '', // пока пустое
					$headerKeys['Сертификат'] => '', // пока пустое
					$headerKeys['Убыток на компанию'] => '', // пока пустое
					$headerKeys['Убыток на сотрудника'] => '', // пока пустое
					$headerKeys['Код FHR'] => '',
					$headerKeys['Класс'] => '',
					$headerKeys['Пассажир'] => '',
					$headerKeys['Дата вылета'] => '',
					$headerKeys['Дата прилета'] => '', // пока пустое
					$headerKeys['Авиакомпания'] => '',
					$headerKeys['Страна прилета (Конечная точка)'] => '', // пока пустое
					$headerKeys['Город прилета  (Конечная точка)'] => '', // пока пустое
					$headerKeys['Привилегии'] => '',
					$headerKeys['Наличие договора'] => '',
					$headerKeys['Результат сделки'] => '',
					$headerKeys['Причина стадии Сделка проиграна'] => '',
					$headerKeys['Количество сегментов'] => '',
					$headerKeys['Дата оплаты партнеру (поставщику)'] => '',
					$headerKeys['Комментарий Тимлидеру'] => $deal['UF_COMMENT_TEAMLEADER'],
					$headerKeys['Кросс-продажа'] => '',
					$headerKeys['Кросс-продажа причина'] => is_null($deal[Deal::CROSS_SELLING_REASON]) ? '' : $deal[Deal::CROSS_SELLING_REASON],
					$headerKeys['Схема финансовой карты'] => '',
					$headerKeys['Дата отложенной оплаты'] => '',
					$headerKeys['Валюта отложенной оплаты'] => '',
					$headerKeys['Сумма отложенной оплаты, руб'] => '',
					$headerKeys['Сумма отложенной оплаты, валюта'] => '',
					$headerKeys['Количество номеров'] => $deal['UF_CRM_DEAL_NUMBER_OF_ROOMS'],
					$headerKeys['Дата начала'] => is_null($currentDealDate->getStartFieldValue()) ? '' : $currentDealDate->getStartFieldValue()->toString(),
					$headerKeys['Дата окончания'] => is_null($currentDealDate->getFinishFieldValue()) ? '' : $currentDealDate->getFinishFieldValue()->toString(),
					$headerKeys['Дата завершения'] => $dateClose,

				);

				// заполняем поля отложенной оплаты
				if($deal['STAGE_SEMANTIC_ID'] == 'P' && !empty($paymentDeferredList[$deal['ID']])){ // если отложенная оплата была обнаружена по сделке

					$paymentDeffered = $paymentDeferredList[$deal['ID']];

					$bodyRow[$headerKeys['Дата отложенной оплаты']] = (new \DateTime($paymentDeffered['DATE_ACTIVE_FINISH']))->format('d.m.Y');
					$bodyRow[$headerKeys['Валюта отложенной оплаты']] = $paymentDeffered['CURRENCY'];
					$bodyRow[$headerKeys['Сумма отложенной оплаты, руб']] = number_format((float)$paymentDeffered['AMOUNT'], 2, ',', '');
					$bodyRow[$headerKeys['Сумма отложенной оплаты, валюта']] = number_format((float)$paymentDeffered['AMOUNT_CURRENCY'], 2, ',', '');

					unset($paymentDeffered);

				}

				if(!empty($deal[Deal::IS_CROSS_SELLING])){

					// получаем значения пользовательских полей
					$enum = $enumList[$deal[Deal::IS_CROSS_SELLING]];

					if(!empty($enum)){
						$bodyRow[$headerKeys['Кросс-продажа']] = $enum['VALUE'];
					}
					
				}

				// путешественники
				if(!empty($deal['UF_BRS_CRM_DEAL_GUESTS']) && count($deal['UF_BRS_CRM_DEAL_GUESTS']) > 0){

					$guestString = [];

					foreach($deal['UF_BRS_CRM_DEAL_GUESTS'] as $guestId){
						$guestString[] = $guestList[$guestId];
					}

					$guestString = implode(', ', $guestString);

					$bodyRow[$headerKeys['Путешественник']] = $guestString;

				}

				// получаем название компании из предложений
				if(!empty($offerList[$deal['ID']])){
					$bodyRow[$headerKeys['Полное наименование организации']] = $companyList[$offerList[$deal['ID']]]['UF_CRM_COMPANY_CONTRAGENT'];
				}

				$dealCategoryName = '';

				// если идентификатор категории указан и удалось получить массив категории
				if($deal['CATEGORY_ID'] > 0 && !empty($dealCategoryList[$deal['CATEGORY_ID']])){

					$dealCategoryName = $dealCategoryList[$deal['CATEGORY_ID']]['NAME'];

					$bodyRow[$headerKeys['Категория']] = $dealCategoryName; // указываем название категории

				} else {
					$bodyRow[$headerKeys['Категория']] = 'Другое';
				}

				$bodyRow[$headerKeys['Дата создания сделки']] = (new \DateTime($deal['DATE_CREATE']))->format('d.m.Y');
				$bodyRow[$headerKeys['Ресторан']] = current($deal['UF_CRM_DEAL_RESTAURANT']);

				$date = '';

				if(!empty($deal['UF_DATE_SERVICE_PROVISION']) && !is_null($deal['UF_DATE_SERVICE_PROVISION'])){
					$date = $deal['UF_DATE_SERVICE_PROVISION'];
				}

				$bodyRow[$headerKeys['Дата оказания услуги']] = $date;

				if($bodyRow[$headerKeys['Категория']] == 'Другое'){
					$bodyRow[$headerKeys['Дата оказания услуги']] = $deal['UF_CRM_DEAL_INTERVAL_2'];
				}

				// если указан маркетинговый канал
				if(!empty($deal['UF_CRM_DEAL_MARKETING_CHANNEL']) && is_numeric($deal['UF_CRM_DEAL_MARKETING_CHANNEL']) && $deal['UF_CRM_DEAL_MARKETING_CHANNEL'] > 0){

					// получаем значения пользовательских полей
					$enum = $enumList[$deal['UF_CRM_DEAL_MARKETING_CHANNEL']];

					if(!empty($enum)){
						$bodyRow[$headerKeys['Маркетинговый канал']] = $enum['VALUE'];
					}

					if($bodyRow[$headerKeys['Маркетинговый канал']] == 'Нет (другое)'){
						$bodyRow[$headerKeys['Маркетинговый канал']] = 'Нет (другое) '.$deal['UF_CRM_DEAL_MARKETING_CHANNEL_OTHER'];
					}

				}

				// если указан тип запроса
				if(!empty($deal['UF_CRM_DEAL_TYPE_CASE']) && is_numeric($deal['UF_CRM_DEAL_TYPE_CASE']) && $deal['UF_CRM_DEAL_TYPE_CASE'] > 0){

					// получаем значения пользовательских полей
					$enum = $enumList[$deal['UF_CRM_DEAL_TYPE_CASE']];

					if(!empty($enum)){
						$bodyRow[$headerKeys['Тип запроса']] = $enum['VALUE'];
					}

				}

				// если указан тип оплаты
				if(!empty($deal['UF_BRS_CRM_DEAL_PAYMENT_TYPE']) && is_numeric($deal['UF_BRS_CRM_DEAL_PAYMENT_TYPE']) && $deal['UF_BRS_CRM_DEAL_PAYMENT_TYPE'] > 0){

					// получаем значения пользовательских полей
					$enum = $enumList[$deal['UF_BRS_CRM_DEAL_PAYMENT_TYPE']];

					if(!empty($enum)){
						$bodyRow[$headerKeys['Тип оплаты']] = $enum['VALUE'];
					}

				}

				if($dealCategoryName == 'Круиз'){
					$bodyRow[$headerKeys['Тур']] = 'Да';
				}

				// если указан лид
				if(!empty($deal[Deal::BIND_LEAD])){
					$bodyRow[$headerKeys['Лид']] = $deal[Deal::BIND_LEAD];
				}

				// если указаны связанные сделки
				if(!empty($deal['UF_CRM_DEAL_COMPLEMENTARY_SERVICES']) && count($deal['UF_CRM_DEAL_COMPLEMENTARY_SERVICES']) > 0){

					// получаем сделки
					$dbBindDeals = \CAllCrmDeal::GetListEx([ 'ID' => 'DESC' ], [
						'ID' => $deal['UF_CRM_DEAL_COMPLEMENTARY_SERVICES'],
						'CHECK_PERMISSIONS' => 'N',
					], false, false, [ 'ID', 'CATEGORY_ID' ]);

					$bindDealText = [];
					$bindDealTextArray = [];
					$bindDealTourTrue = [];

					// обходим массив сделок и формируем тело документа
					while($bindDeal = $dbBindDeals->Fetch()){

						$bindDealCategoryName = 'Не указана';

						// если идентификатор категории указан
						if($bindDeal['CATEGORY_ID'] > 0){

							// получаем массив категории
							$bindDealCategory = DealCategoryTable::getByPrimary($bindDeal['CATEGORY_ID'])->fetch();

							// если удалось получить объект категории
							if($bindDealCategory){
								$bindDealCategoryName = $bindDealCategory['NAME'];
								$bindDealTourTrue[$bindDealCategoryName] = true;
							}

						}

						$bindDealTextArray[] = $bindDealCategoryName;

						$bindDealText[] = $bindDeal['ID'].'='.$bindDealCategoryName;

					}

					$bodyRow[$headerKeys['Связанные сделки']] = implode('; ', $bindDealText);

					if($dealCategoryName == 'Экскурсия' && (str_replace(['Отель'], '', $bodyRow[$headerKeys['Связанные сделки']]) != $bodyRow[$headerKeys['Связанные сделки']] || str_replace(['Круиз'], '', $bodyRow[$headerKeys['Связанные сделки']]) != $bodyRow[$headerKeys['Связанные сделки']])){
						$bodyRow[$headerKeys['Тур']] = 'Да';
					} else if($dealCategoryName == 'Круиз'){
						$bodyRow[$headerKeys['Тур']] = 'Да';
					} else if($dealCategoryName == 'Отель' && (str_replace(['Экскурсия'], '', $bodyRow[$headerKeys['Связанные сделки']]) != $bodyRow[$headerKeys['Связанные сделки']] || str_replace(['Круиз'], '', $bodyRow[$headerKeys['Связанные сделки']]) != $bodyRow[$headerKeys['Связанные сделки']])){
						$bodyRow[$headerKeys['Тур']] = 'Да';
					} else if(str_replace(['Круиз'], '', $bodyRow[$headerKeys['Связанные сделки']]) != $bodyRow[$headerKeys['Связанные сделки']] || (str_replace(['Экскурсия'], '', $bodyRow[$headerKeys['Связанные сделки']]) != $bodyRow[$headerKeys['Связанные сделки']] && str_replace(['Отель'], '', $bodyRow[$headerKeys['Связанные сделки']]) != $bodyRow[$headerKeys['Связанные сделки']])){
						$bodyRow[$headerKeys['Тур']] = 'Да';
					}

				}

				// если указан тип клиента
				if(!empty($deal['CONTACT_TYPE_ID']) && !empty($dealStageList['CONTACT_TYPE'][$deal['CONTACT_TYPE_ID']])){
					$bodyRow[$headerKeys['Тип клиента']] = $dealStageList['CONTACT_TYPE'][$deal['CONTACT_TYPE_ID']]['NAME'];
				}

				$currencyRealId = false;

				// сумма из которой вычисляется проценты по агентам
				$amountPercentageAgentParticipation = 0;

				$totalPaidClientSuccess = 0;
				$totalPaidClientRefund = 0;
				
				$isPoint = false;
				$pointCurrency = '';

				$isPay = false;

				// если входящие платежи есть (последний входящий платёж)
				if(count($paymentTransactionList[$deal['ID']]) > 0){

					$isPay = true;

					$lastPaymentTransaction = '';
					$lastPaymentRefoundTransaction = '';

					$firstPaymentTransactionCollection = $paymentTransactionList[$deal['ID']];

					foreach($paymentTransactionList[$deal['ID']] as $paymentTransaction){

						// если это входящий авансовый платёж, то прибавляем к итого оплачено клиентом
						if($paymentTransaction['PAYMENT_TYPE'] == PaymentTransactionTable::PAYMENT_TYPE_INCOMING){

							$totalPaidClientSuccess += $paymentTransaction['AMOUNT'];

							if(!empty($paymentTransaction['PAYMENT_BY_LINK'])){
								$bodyRow[$headerKeys['Тип карты']] = 'Ссылка';
							}

							// получаем объект карты
							$card = $cardList[$paymentTransaction['CARD_ID']];

							if(!empty($card) && $bodyRow[$headerKeys['Тип карты']] != 'Ссылка'){
								
								$type = $cardTypeList[$card['type_id']];

								if(!empty($type)){

									if(is_array($bodyRow[$headerKeys['Тип карты']]) && !in_array($type['NAME'], $bodyRow[$headerKeys['Тип карты']])){
										$bodyRow[$headerKeys['Тип карты']][] = $type['NAME'];
									} else if(empty($bodyRow[$headerKeys['Тип карты']])){

										$bodyRow[$headerKeys['Тип карты']] = [];

										$bodyRow[$headerKeys['Тип карты']][] = $type['NAME'];

									}

								}

							}

						} else {

							$totalPaidClientRefund += $paymentTransaction['AMOUNT'];

							$totalPaidClientSuccess -= $paymentTransaction['AMOUNT'];

							$lastPaymentRefoundTransaction = $paymentTransaction;

						}

						$lastPaymentTransaction = $paymentTransaction;

						$bodyRow[$headerKeys['Дата оплаты Клиентом']] = !is_null($lastPaymentTransaction['DATE']) ? $lastPaymentTransaction['DATE']->format('d.m.Y') : '';
						$bodyRow[$headerKeys['Дата возврата']] = $lastPaymentRefoundTransaction == '' ? '' : $lastPaymentRefoundTransaction['DATE']->format('d.m.Y');

						if(!empty($paymentTransaction['PAYMENT_BY_POINT'])){

							$isPoint = true;

							$pointCurrency = $paymentTransaction['CURRENCY'];

						}

					}

				}

				// если были найдены возвраты
				if(array_key_exists($deal['ID'], $refundCardList)){

					$refund = $refundCardList[$deal['ID']]; // массив возврата

					$bodyRow[$headerKeys['Дата отмены операции (возврат)']] = $refund['REFUND_DATE'] ? $refund['REFUND_DATE']->format('d.m.Y') : '';

				} else {
					$refund = false;
				}

				$bodyRow[$headerKeys['Итого оплачено клиентом']] = number_format((float)($totalPaidClientSuccess+$totalPaidClientRefund), 2, '.', '');

				if($isPoint && $pointCurrency == 'MR'){
					$bodyRow[$headerKeys['Баллы MR']] = $bodyRow[$headerKeys['Итого оплачено клиентом']];
				} else if($isPoint && $pointCurrency == 'IR') {
					$bodyRow[$headerKeys['Баллы IMP']] = $bodyRow[$headerKeys['Итого оплачено клиентом']];
				}

				// финансовая карточка
				$financialCard = $financialCardList[$deal['ID']];

				$isNds = false; // есть НДС или нет

				// заполняем поля финансовой карточки в строку документа
				if($financialCard){

					if(!empty($financialCard['SCHEME_WORK'])){
						$bodyRow[$headerKeys['Схема финансовой карты']] = self::$financialCardSchemeWork[$financialCard['SCHEME_WORK']];
					}

					$bodyRow[$headerKeys['Номер счёта']] = $financialCard['SUPPLIER_NUMBER_INVOICE'];

					$bodyRow[$headerKeys['Дата создания фин.карты']] = $financialCard['DATE_CREATE']->format('d.m.Y');

					$user = $userList[$financialCard['USER_ID_CREATOR']];

					if(!empty($user)){
						$bodyRow[$headerKeys['Создатель карты']] = implode(' ', [ $user['LAST_NAME'], $user['NAME'], $user['SECOND_NAME'] ]);
					}

					if(in_array($financialCard['SUPPLIER_VAT'], [ 'VAT_0', 'VAT_NO' ])){
						$isNds = false;
					} else {
						$isNds = true;
					}

					// получаем массив цен финансовой карточки
					$cardPrice = $financialCardPriceList[$financialCard['FINANCIAL_CARD_PRICE_ID']];

					if(!empty($cardPrice)){

						$ndsProvider = $financialCard['SUPPLIER_VAT'];

						$amountPercentageAgentParticipation = $cardPrice['RESULT']; // указываем сумму "Всего к оплате клиентом" для дальнейшего расчёта процентов участия агентов

						// получаем оригинальную валюту
						if(!empty($cardPrice['CURRENCY_ID'])){

							$bodyRow[$headerKeys['Название валюты сделки']] = $cardPrice['CURRENCY_ID'];
							$bodyRow[$headerKeys['Валюта сделки']] = $cardPrice['CURRENCY_ID'];

							$currencyRealId = $cardPrice['CURRENCY_ID'];

							$currency = $currencyUidList[$cardPrice['CURRENCY_ID']];

							if(!empty($currency)){

								$currencyOwner = $currency['OWNER'];

								if(!empty($currencyOwner)){
									$bodyRow[$headerKeys['Название валюты сделки']] = $currencyOwner;
									$bodyRow[$headerKeys['Валюта сделки']] = $currencyOwner;
								}

							}

							$bodyRow[$headerKeys['Название валюты сделки']] = $currency['FULL_NAME'];

						}

						// получаем агентов из инфоблока
						$agents = $agentList[$deal['ID']];

						$percentAgentsSum = [];
						$userAgentIds = [];

						// обходим всех агентов и высчитываем сумму процентов
						foreach($agents as $agent){

							$userAgentIds[] = $agent['PROPERTY_AGENT_VALUE'];

							$percentAgentsSum[$agent['PROPERTY_AGENT_VALUE']] = number_format(($totalPaidClientSuccess/100*$agent['PROPERTY_PERCENT_PARTICIPATION_VALUE']), 2, '.', '');

						}

						if(count($userAgentIds) > 0){

							$userAgents = [];

							foreach($userAgentIds as $userId){

								$user = $userList[$userId];

								if(!empty($user)){
									$userAgents[] = $user;
								}

							}

							if(count($userAgents) > 0){

								foreach($userAgents as $agent){
									$percentAgentsSum[$agent['ID']] = trim(implode(' ', [ $agent['LAST_NAME'], $agent['NAME'] ])).'='.$percentAgentsSum[$agent['ID']];
								}

								$bodyRow[$headerKeys['% участия агента в продаже*']] = implode("\n", $percentAgentsSum);

							}

						}

						$totalPaidClient = [
							'success' => 0,
							'refund' => 0
						];

						// истого оплачено клиентом в валюте, курс с реальной валютой (может быть с процентами)
						$totalPaidClientCurrency = [
							'success' => 0,
							'refund' => 0
						];

						// истого оплачено клиентом в валюте, чистый курс оригинальной валюты (всегда без процентов)
						$originalTotalPaidClientCurrency = [
							'success' => 0,
							'refund' => 0
						];

						$totalAmountCurrency = 0;

						$middleRate = Course::getMiddle($deal['ID'], false, $financialCard, $cardPrice, !is_null($paymentTransactionList[$deal['ID']]) ? $paymentTransactionList[$deal['ID']] : []); // получаем карточный средний курс и центробанковский курс

						$middleCourse = $middleRate['PRICE']; // средний курс по карточному курсу
						$middleCourseCentralBank = $middleRate['PRICE_CENTRAL_BANK']; // средний курс по ЦБ

						$successPaymentCount = 0;
						$oneSuccessPaymentTransactionCourse = 0;
						$oneSuccessPaymentTransactionOriginalCourse = 0;

						// если входящие платежи есть (последний входящий платёж)
						if(count($paymentTransactionList[$deal['ID']]) > 0){

							$paymentTransactionCollection = $firstPaymentTransactionCollection;

							$lastPaymentTransaction = ''; // переменная последнего авансового платежа

							foreach($paymentTransactionCollection as $paymentTransaction){

								// формируем объект последнего входящего авансового платежа
								if($lastPaymentTransaction == '' && $paymentTransaction['PAYMENT_TYPE'] == PaymentTransactionTable::PAYMENT_TYPE_INCOMING){
									$lastPaymentTransaction = $paymentTransaction;
								}

								// если это входящий авансовый платёж, то прибавляем к итого оплачено клиентом
								if($paymentTransaction['PAYMENT_TYPE'] == PaymentTransactionTable::PAYMENT_TYPE_INCOMING){

									$successPaymentCount++;

									$totalPaidClient['success'] += $paymentTransaction['AMOUNT'];

								} else {
									$totalPaidClient['refund'] -= $paymentTransaction['AMOUNT'];
								}

								// елси финансовая краточка в валюте
								if($bodyRow[$headerKeys['Валюта сделки']] != 'RUB' && $bodyRow[$headerKeys['Валюта сделки']] != ''){

									// получаем дату транзакции
									$transactionDate = $paymentTransaction['DATE']->format('d.m.Y');
									$transactionDateTime = $paymentTransaction['DATE']->format('d.m.Y H:i:s');

									// получаем объект курса валют по дате входящего авансового платежа
									$currency = CurrencyRateTable::getList([
										'filter' => [
											'CURRENCY' => $currencyRealId,
											'=DATE_RATE' => $transactionDate,
											'<=DATE_RATE_START' => $transactionDateTime,
											'>=DATE_RATE_FINISH' => $transactionDateTime,
										],
										'order' => [
											'ID' => 'DESC'
										]
									])->fetch();

									if($currency){
										// получаем курс
										$course = $currency['RATE']/$currency['RATE_CNT'];
									} else {

										// получаем объект курса валют по дате входящего авансового платежа
										$currency = CurrencyRateTable::getList([
											'order' => [
												'DATE_RATE' => 'DESC'
											],
											'filter' => [
												'CURRENCY' => $currencyRealId,
											]
										])->fetch();

										// получаем курс
										$course = $currency['RATE']/$currency['RATE_CNT'];

									}

									$oneSuccessPaymentTransactionCourse = $course;

									$amountCurrency = $paymentTransaction['AMOUNT'] / $course;

									// если это входящий авансовый платёж, то прибавляем к итого оплачено клиентом
									if($paymentTransaction['PAYMENT_TYPE'] == PaymentTransactionTable::PAYMENT_TYPE_INCOMING){
										$totalPaidClientCurrency['success'] += $amountCurrency;
									} else {
										$totalPaidClientCurrency['refund'] -= $amountCurrency;
									}

									// чистый средний курс получаем объект курса валют по дате входящего авансового платежа
									$currency = CurrencyRateTable::getList([
										'filter' => [
											'CURRENCY' => \convertCurrencyCardToCurrency($bodyRow[$headerKeys['Валюта сделки']]),
											'=DATE_RATE' => $transactionDate,
											'<=DATE_RATE_START' => $transactionDateTime,
											'>=DATE_RATE_FINISH' => $transactionDateTime,
										],
										'order' => [
											'ID' => 'DESC'
										]
									])->fetch();

									if($currency){
										// получаем курс
										$course = $currency['RATE']/$currency['RATE_CNT'];
									} else {

										// получаем объект курса валют по дате входящего авансового платежа
										$currency = CurrencyRateTable::getList([
											'order' => [
												'DATE_RATE' => 'DESC'
											],
											'filter' => [
												'CURRENCY' => \convertCurrencyCardToCurrency($bodyRow[$headerKeys['Валюта сделки']]),
											]
										])->fetch();

										// получаем курс
										$course = $currency['RATE']/$currency['RATE_CNT'];

									}

									$oneSuccessPaymentTransactionOriginalCourse = $course;

									$amountCurrency = $paymentTransaction['DATE'] / $course;

									// если это входящий авансовый платёж, то прибавляем к итого оплачено клиентом
									if($paymentTransaction['PAYMENT_TYPE'] == PaymentTransactionTable::PAYMENT_TYPE_INCOMING){
										$originalTotalPaidClientCurrency['success'] += $amountCurrency;
									} else {
										$originalTotalPaidClientCurrency['refund'] -= $amountCurrency;
									}

								}

								// Устанавливае срдний курс который используется в возвратах
								$totalPaidClient['AVERAGE_RATE'] = number_format($paymentTransaction['AVERAGE_RATE'] * $paymentTransaction['AVERAGE_RATE_CNT'], 2, '.', '');;
							}

							// если были найдены возвраты


							$totalPaidClientCurrency['success'] = number_format($totalPaidClientCurrency['success'], 2, '.', '');
							$totalPaidClientCurrency['refund'] = number_format($totalPaidClientCurrency['refund'], 2, '.', '');

							// получаем средний курс
							if($bodyRow[$headerKeys['Валюта сделки']] != 'RUB' && $bodyRow[$headerKeys['Валюта сделки']] != ''){

								if($successPaymentCount == 1){ // если всего один платёж, то берём его курс
									$originalMiddleCourse = $oneSuccessPaymentTransactionOriginalCourse;
								} else {
									$originalMiddleCourse = str_replace('INF', '0', ($totalPaidClient['success'] + $totalPaidClient['refund'])/($originalTotalPaidClientCurrency['success'] + $originalTotalPaidClientCurrency['refund'])); // получаем средний курс из оригинальной валюты
								}

								$bodyRow[$headerKeys['Сумма продажи']] = number_format($totalPaidClient['success'], 2, '.', '');

							} else {
								$bodyRow[$headerKeys['Сумма продажи']] = number_format($totalPaidClient['success'], 2, '.', '');
							}

							$bodyRow[$headerKeys['Сумма продажи после возврата']] = $bodyRow[$headerKeys['Сумма продажи']] + $totalPaidClient['refund'];

							$bodyRow[$headerKeys['Дата оплаты Клиентом']] = $lastPaymentTransaction['DATE']->format('d.m.Y');

						}

						// получаем формулу в зависимости от схемы реализации услуг
						if(array_key_exists($financialCard['SCHEME_WORK'], \FinancialCalcComponent::CALC_FORMULAS)){

							$isRubСurrency = true;

							if($cardPrice['CURRENCY'] != 1 && $cardPrice['CURRENCY_ID'] == 'RUB'){
								$filePathFormula = $pathFinancialCardFormulas.\FinancialCalcComponent::CALC_FORMULAS[$financialCard['SCHEME_WORK']]['rub'];
							} else {

								$filePathFormula = $pathFinancialCardFormulas.\FinancialCalcComponent::CALC_FORMULAS[$financialCard['SCHEME_WORK']]['currency'];

								$isRubСurrency = false;

							}

							if($bodyRow[$headerKeys['Валюта сделки']] != 'RUB' && $bodyRow[$headerKeys['Валюта сделки']] != ''){
								$isRubСurrency = false;
							} else {
								$isRubСurrency = true;
							}

							$cardFormula = include($filePathFormula); // получаем массив из файла

							$financialCardColumnName = array(

								'Дополнительная выгода' => false,
								'Сервисный сбор' => false,
								'Комиссия' => false,

								'Сумма по счету Поставщика (НЕТТО)' => false,
								'Сумма по счету Поставщика (БРУТТО)' => false,

								'Всего к оплате Поставщику' => false,

							);

							// получаем названия столбцов
							foreach($cardFormula as $columnName => $arField){
								if('Дополнительная выгода' == $arField['title']){
									$financialCardColumnName['Дополнительная выгода'] = $arField['name'];
								} else if('Сервисный сбор' == $arField['title']){
									$financialCardColumnName['Сервисный сбор'] = $arField['name'];
								} else if('Комиссия' == $arField['title']){
									$financialCardColumnName['Комиссия'] = $arField['name'];
								} else if('Сбор поставщика' == $arField['title']){
									$financialCardColumnName['Сбор поставщика'] = $arField['name'];
								} else if('Сумма по счету Поставщика (НЕТТО)' == $arField['title']){
									$financialCardColumnName['Сумма по счету Поставщика (НЕТТО)'] = $arField['name'];
								} else if('Сумма по счету Поставщика (БРУТТО)' == $arField['title']){
									$financialCardColumnName['Сумма по счету Поставщика (БРУТТО)'] = $arField['name'];
								} else if('Всего к оплате Поставщику' == $arField['title']){
									$financialCardColumnName['Всего к оплате Поставщику'] = $arField['name'];
								}
							}
						}

						if($middleCourse > 0){
							$bodyRow[$headerKeys['Курс оплаты']] = number_format($middleCourse, 4, '.', '');
						}
						if($middleCourseCentralBank > 0){
							$bodyRow[$headerKeys['Курс оплаты ЦБ']] = number_format($middleCourseCentralBank, 4, '.', '');
						}

						// если в данной схеме есть поле "Всего к оплате Поставщику", то считаем его
						if($financialCardColumnName['Всего к оплате Поставщику']){

							$bodyRow[$headerKeys['Оплата поставщику']] = $cardPrice[$financialCardColumnName['Всего к оплате Поставщику']];

							// если в валюте
							if(!$isRubСurrency){
								$bodyRow[$headerKeys['Оплата поставщику']] = $bodyRow[$headerKeys['Оплата поставщику']]*$middleCourse;
							}

						}

						// если в данной схеме есть поле "Сумма по счету Поставщика (НЕТТО)", то считаем его
						if($financialCardColumnName['Сумма по счету Поставщика (НЕТТО)']){

							$bodyRow[$headerKeys['Нетто в Валюте поставщика']] = $cardPrice[$financialCardColumnName['Сумма по счету Поставщика (НЕТТО)']];

							// если нетто в рублях, то выводим сразу
							if($isRubСurrency){
								$bodyRow[$headerKeys['Нетто в рублях']] = $bodyRow[$headerKeys['Нетто в Валюте поставщика']];
							}

							// если есть нетто в валюте, то осчитаем нетто в рублях
							if($bodyRow[$headerKeys['Нетто в Валюте поставщика']] > 0 && $bodyRow[$headerKeys['Валюта сделки']] != 'RUB' && $bodyRow[$headerKeys['Валюта сделки']] != ''){
								if($financialCardColumnName['Сбор поставщика']){
									$bodyRow[$headerKeys['Нетто в рублях']] = $bodyRow[$headerKeys['Нетто в Валюте поставщика']];
								} else {
									$bodyRow[$headerKeys['Нетто в рублях']] = $bodyRow[$headerKeys['Нетто в Валюте поставщика']]*$middleCourseCentralBank;
								}
							}

						}

						// если в данной схеме есть поле "Сумма по счету Поставщика (БРУТТО)", то считаем его
						if($financialCardColumnName['Сумма по счету Поставщика (БРУТТО)']){
							$bodyRow[$headerKeys['Брутто в Валюте поставщика']] = $cardPrice[$financialCardColumnName['Сумма по счету Поставщика (БРУТТО)']];
						} else if(!$financialCardColumnName['Сумма по счету Поставщика (БРУТТО)'] && $financialCardColumnName['Всего к оплате Поставщику']){ // если в схеме нет поля "Сумма по счету Поставщика (БРУТТО)", но есть поле "Брутто в Валюте поставщика", то тянем данные из него
							$bodyRow[$headerKeys['Брутто в Валюте поставщика']] = $cardPrice[$financialCardColumnName['Всего к оплате Поставщику']];
						}

						// если в данной схеме есть поле "Дополнительная выгода", то считаем его
						if($financialCardColumnName['Дополнительная выгода']){

							// если дополнительная выгода в рублях, то выводим сразу
							if($isRubСurrency){
								$bodyRow[$headerKeys['Дополнительная выгода']] = $cardPrice[$financialCardColumnName['Дополнительная выгода']];
							} else { // если дополнительная выгода в валюте, то считаем по курсу финансовой карточки
								$bodyRow[$headerKeys['Дополнительная выгода']] = $middleCourse * $cardPrice[$financialCardColumnName['Дополнительная выгода']];
							}

							// в зависимости от типа НДС применяем формулу расчёта поля без НДС
							$bodyRow[$headerKeys['Дополнительная выгода без НДС']] = $bodyRow[$headerKeys['Дополнительная выгода']]/self::VAT_COEFFICIENT;

						}

						// если в данной схеме есть комиссия, то считаем её
						if($financialCardColumnName['Комиссия']){

							$bodyRow[$headerKeys['Комиссия поставщика в Валюте']] = $cardPrice[$financialCardColumnName['Комиссия']];

							// если комиссия в рублях, то выводим сразу
							if($isRubСurrency){
								$bodyRow[$headerKeys['Комиссия']] = $cardPrice[$financialCardColumnName['Комиссия']];
							} else { // если комиссия в валюте, то считаем по курсу финансовой карточки
								$bodyRow[$headerKeys['Комиссия']] = $middleCourseCentralBank * $cardPrice[$financialCardColumnName['Комиссия']];
							}

							$bodyRow[$headerKeys['Комиссия без НДС']] = $bodyRow[$headerKeys['Комиссия']]/self::VAT_COEFFICIENT; // всегда -22%

							$bodyRow[$headerKeys['Комиссия']] = number_format((float)$bodyRow[$headerKeys['Комиссия']], 2, '.', '');

						}

						// если в данной схеме есть сервисный сбор, то считаем его
						if($financialCardColumnName['Сервисный сбор']){

							// если сервисный сбор в рублях, то выводим сразу
							if($isRubСurrency){
								$bodyRow[$headerKeys['Сервисный сбор']] = $cardPrice[$financialCardColumnName['Сервисный сбор']];
							} else { // если комиссия в валюте, то считаем по курсу финансовой карточки
								// если схема реализации услуг "Агент покупателя" или "Агент Поставщика SR", то конвертируем из валюты в рубли
								if($financialCard['SCHEME_WORK'] == FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT || $financialCard['SCHEME_WORK'] == FinancialCardTable::SCHEME_BUYER_AGENT){
									$bodyRow[$headerKeys['Сервисный сбор']] = $middleCourse * $cardPrice[$financialCardColumnName['Сервисный сбор']];
								} else {
									$bodyRow[$headerKeys['Сервисный сбор']] = $cardPrice[$financialCardColumnName['Сервисный сбор']];
								}

							}

							$bodyRow[$headerKeys['Сервисный сбор без НДС']] = $bodyRow[$headerKeys['Сервисный сбор']]/self::VAT_COEFFICIENT; // всегда -22%

						}

						// если в данной схеме есть Сбор поставщика, то считаем его
						if($financialCardColumnName['Сбор поставщика']){

							// если Сбор поставщика в рублях, то выводим сразу
							if($isRubСurrency){
								$bodyRow[$headerKeys['Сбор поставщика']] = $cardPrice[$financialCardColumnName['Сбор поставщика']];
								if(!empty($bodyRow[$headerKeys['Нетто в рублях']])){
									$bodyRow[$headerKeys['Нетто в рублях']] = $bodyRow[$headerKeys['Нетто в рублях']] + $cardPrice[$financialCardColumnName['Сбор поставщика']];
								}

							} else { // если нетто в валюте, то считаем по курсу финансовой карточки
								$bodyRow[$headerKeys['Сбор поставщика']] = $cardPrice[$financialCardColumnName['Сбор поставщика']]*$middleCourseCentralBank;
								if(!empty($bodyRow[$headerKeys['Нетто в рублях']])){
									$bodyRow[$headerKeys['Нетто в рублях']] = ($bodyRow[$headerKeys['Нетто в рублях']] + $cardPrice[$financialCardColumnName['Сбор поставщика']])*$middleCourseCentralBank;
								}

							}
						}

						// считаем поля SR и LR в зависимости от выбранной в карточке схеме
						if($financialCard['SCHEME_WORK'] == FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT){ // если схема LR

							$bodyRow[$headerKeys['LR']] = number_format($bodyRow[$headerKeys['Итого оплачено клиентом']], 2, '.', '');
							$bodyRow[$headerKeys['SR']] = 0;

						} else if($financialCard['SCHEME_WORK'] == FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT) { // если схема SR

							$bodyRow[$headerKeys['LR']] = 0;
							$bodyRow[$headerKeys['SR']] = number_format($bodyRow[$headerKeys['Итого оплачено клиентом']], 2, '.', '');

						} else {
							$bodyRow[$headerKeys['LR']] = 0;
							$bodyRow[$headerKeys['SR']] = 0;
						}

						// рассчитываем поле прибыль
						$profit = 0;
						$profitMinusNds = 0;

						$totalPayClient = $cardPrice['RESULT'];

						if($totalPayClient > 0 && $bodyRow[$headerKeys['Валюта сделки']] != 'RUB' && $bodyRow[$headerKeys['Валюта сделки']] != ''){ // если валютная сделка
							$profit = $totalPaidClient['success'] - number_format((float)$bodyRow[$headerKeys['Нетто в рублях']], 2, '.', '');
						} else if($totalPayClient > 0){ // если рублёвая сделка
							$profit = $totalPayClient - number_format((float)$bodyRow[$headerKeys['Нетто в рублях']], 2, '.', '');
						}

						// считаем прибыль без ндс
						if($bodyRow[$headerKeys['Схема финансовой карты']] === 'Оказание услуг' && !$isNds && $profit > 0){
							$profitMinusNds = $profit;
						} else if($profit > 0){
							$profitMinusNds = $profit/self::VAT_COEFFICIENT;
						}

						// заполняем общие поля с ндс
						$bodyRow[$headerKeys['Прибыль']] = (float) $profit;
						$bodyRow[$headerKeys['Прибыль РС ТЛС с учетом возврата']] = (float) $profit;
						$bodyRow[$headerKeys['Прибыль без НДС']] = (float) $profitMinusNds;

						if((int) ($totalPaidClient['success'] + $totalPaidClient['refund']) == 0){
							$bodyRow[$headerKeys['Сумма прибыли с учетом возврата без НДС']] = 0;
						} else if($totalPaidClient['refund'] < 0){
							$bodyRow[$headerKeys['Сумма прибыли с учетом возврата без НДС']] = $profit + ($totalPaidClient['refund']/self::VAT_COEFFICIENT);
						}

						// если были найдены возвраты
						if(!$refund){
							$bodyRow[$headerKeys['Сумма прибыли с учетом возврата без НДС']] = $bodyRow[$headerKeys['Прибыль без НДС']];
						}

						$bodyRow[$headerKeys['Сумма продажи после возврата']] = number_format($bodyRow[$headerKeys['Сумма продажи после возврата']], 2, '.', '');

						$bodyRow[$headerKeys['Прибыль РС ТЛС с учетом возврата']] = number_format((float)$bodyRow[$headerKeys['Прибыль РС ТЛС с учетом возврата']], 2, '.', '');

						$bodyRow[$headerKeys['Прибыль']] = number_format($bodyRow[$headerKeys['Прибыль']], 2, '.', '');
						$bodyRow[$headerKeys['Прибыль без НДС']] = number_format($bodyRow[$headerKeys['Прибыль без НДС']], 2, '.', '');
						$bodyRow[$headerKeys['Сумма прибыли с учетом возврата без НДС']] = number_format((float)$bodyRow[$headerKeys['Сумма прибыли с учетом возврата без НДС']], 2, '.', '');

						$bodyRow[$headerKeys['Дополнительная выгода']] = number_format((float)$bodyRow[$headerKeys['Дополнительная выгода']], 2, '.', '');
						$bodyRow[$headerKeys['Сервисный сбор']] = number_format((float)$bodyRow[$headerKeys['Сервисный сбор']], 2, '.', '');

						$bodyRow[$headerKeys['Сумма TID']] = 0;

						$bodyRow[$headerKeys['Комиссия без НДС']] = number_format((float)$bodyRow[$headerKeys['Комиссия без НДС']], 2, '.', '');
						$bodyRow[$headerKeys['Дополнительная выгода без НДС']] = number_format((float)$bodyRow[$headerKeys['Дополнительная выгода без НДС']], 2, '.', '');
						$bodyRow[$headerKeys['Сервисный сбор без НДС']] = number_format((float)$bodyRow[$headerKeys['Сервисный сбор без НДС']], 2, '.', '');

						$bodyRow[$headerKeys['Нетто в Валюте поставщика']] = number_format((float)$bodyRow[$headerKeys['Нетто в Валюте поставщика']], 2, '.', '');
						$bodyRow[$headerKeys['Нетто в рублях']] = number_format((float)$bodyRow[$headerKeys['Нетто в рублях']], 2, '.', '');
						$bodyRow[$headerKeys['Брутто в Валюте поставщика']] = number_format((float)$bodyRow[$headerKeys['Брутто в Валюте поставщика']], 2, '.', '');
						$bodyRow[$headerKeys['Комиссия поставщика в Валюте']] = number_format((float)	$bodyRow[$headerKeys['Комиссия поставщика в Валюте']], 2, '.', '');

						$bodyRow[$headerKeys['Оплата поставщику']] = number_format((float) $bodyRow[$headerKeys['Оплата поставщику']], 2, '.', '');

						$bodyRow[$headerKeys['Сумма TID']] = 0;

						// Устанавливае срдний курс который используется в возвратах
						$bodyRow[$headerKeys['Средний курс для возврата']] = $totalPaidClient['AVERAGE_RATE'];
					}

					$bodyRow[$headerKeys['Сумма НДС']] = ($bodyRow[$headerKeys['Комиссия']] - $bodyRow[$headerKeys['Комиссия без НДС']]) + ($bodyRow[$headerKeys['Дополнительная выгода']] - $bodyRow[$headerKeys['Дополнительная выгода без НДС']]) + ($bodyRow[$headerKeys['Сервисный сбор']] - $bodyRow[$headerKeys['Сервисный сбор без НДС']]);

					$bodyRow[$headerKeys['Сумма НДС']] = number_format((float) $bodyRow[$headerKeys['Сумма НДС']], 2, '.', '');

					if($financialCard['PAYMENT_DATE']){
						$bodyRow[$headerKeys['Дата оплаты партнеру (поставщику)']] = $financialCard['PAYMENT_DATE']->format('d.m.Y');
					}

					// получаем объект полей финансовой карточки
					$financialCardField = $individualFieldsList[$financialCard['ID']];

					$bodyRow[$headerKeys['Дата заезда']] = $deal['UF_CRM_DEAL_DATE_OF_ARRIVAL'];
					$bodyRow[$headerKeys['Дата выезда']] = $deal['UF_CRM_DEAL_DATE_OF_DEPARTURE'];
					$bodyRow[$headerKeys['Количество ночей']] = $deal['UF_CRM_DEAL_NUMBER_OF_NIGHTS'];

					// если указано Общее количество ночей
					if(!empty($deal[Deal::FULL_NUMBER_OF_NIGHTS])){
						$bodyRow[$headerKeys['Общее количество ночей']] = $deal[Deal::FULL_NUMBER_OF_NIGHTS];
					}

					// если он был получен, то заполняем
					if($financialCardField){

						if($deal['CATEGORY_ID'] == Deal\RentCarWithoutDriver::CATEGORY_ID && $deal[Deal::DROPOFF_HERE]){
							if(!empty($deal[Deal::PICKUP])){

								$cityDb = $cityList[$deal[Deal::PICKUP]];
								$city = $cityDb['title_ru'];
								$country = $countryList[$cityDb['country_id']]['title_ru'];

							}
						} else if ($deal['CATEGORY_ID'] == Deal\RentCarWithoutDriver::CATEGORY_ID && !$deal[Deal::DROPOFF_HERE]){
							if(!empty($deal[Deal::DROPOFF])){

								$cityDb = $cityList[$deal[Deal::DROPOFF]];
								$city = $cityDb['title_ru'];
								$country = $countryList[$cityDb['country_id']]['title_ru'];

							}
						} else {

							$country = $countryList[$financialCardField['COUNTRY_ID']];
							$city = $cityList[$financialCardField['CITY_ID']];

							if(!empty($country)){
								$country = $country['title_ru'];
							} else {
								$country = '';
							}

							if(!empty($city)){
								$city = $city['title_ru'];
							} else {
								$city = '';
							}

						}

						$bodyRow[$headerKeys['Страна']] = $country ?? '';
						$bodyRow[$headerKeys['Город']] = $city ?? '';

						// получаем массив компании
						$company = $companyList[$financialCardField['PARTNER_ID']];

						$companyName = '';

						if(!empty($company)){

							$companyName = $company['TITLE'];

							$bodyRow[$headerKeys['Гостиница']] = $companyName;

						}

						if((int) $deal['UF_CRM_DEAL_ORM_HOTEL'] > 0){

							$hotel = $financialCardHotelList[$deal['UF_CRM_DEAL_ORM_HOTEL']];

							if(!empty($hotel)){
								$bodyRow[$headerKeys['Гостиница']] = $hotel['NAME'];
							}

						}

						$bodyRow[$headerKeys['Цепочка']] = $financialCardField['CHAIN'];

						$bodyRow[$headerKeys['Код FHR']] = $financialCardField['FHR'];

						// получаем значения поля "Класс" в отчёте
						if(count($deal['UF_CRM_DEAL_CLASS_TRIP']) > 0){

							$classAvia = [];

							foreach($deal['UF_CRM_DEAL_CLASS_TRIP'] as $enumId){

								$enum = $enumList[$enumId]; // получаем значения пользовательских полей

								if(!empty($enum)){
									$classAvia[] = $enum['VALUE'];
								}

							}

							$classAvia = implode(', ', $classAvia);

							// ограничиваем размер выводимыхх данных в столбец
							if(strlen($classAvia) > 128){
								$bodyRow[$headerKeys['Класс']] = substr($classAvia, 0, 128).'...';
							} else {
								$bodyRow[$headerKeys['Класс']] = $classAvia;
							}

						}

						$bodyRow[$headerKeys['Пассажир']] = implode(', ', $deal['UF_CRM_DEAL_NAME_OF_PASSENGERS']);
						$bodyRow[$headerKeys['Дата вылета']] = implode(', ', $deal['UF_CRM_DEAL_DATE_DEPARTURE']);

						// работаем с сегментами (билетами например)
						if(count($financialCardField['SEGMENTS']) > 0){

							// получаем массив сегментов
							$segments = $financialCardField['SEGMENTS'];

							// в зависимости от категории устанавливаем количество сегментов
							if($dealCategoryName == 'Отель'){
								$bodyRow[$headerKeys['Количество сегментов']] = $financialCardField['ROOMS_NUMBER'];
							} else if($dealCategoryName == 'Авиабилет'){
								$bodyRow[$headerKeys['Количество сегментов']] = count($segments);
							} else if($dealCategoryName == 'Ж.д.'){
								$bodyRow[$headerKeys['Количество сегментов']] = count($segments);
							}

							// получаем список авиакомпаний
							$companyAviaColumn = [];

							foreach($segments as $arSegment){

								if(!empty($arSegment['AIRLINE']) && $arSegment['AIRLINE'] > 0){

									$segmentCompany = $companyList[$arSegment['AIRLINE']];

									if(!empty($segmentCompany)){
										$companyAviaColumn[] = $segmentCompany['TITLE'];
									}

								}

							}

							$bodyRow[$headerKeys['Авиакомпания']] = implode(', ', $companyAviaColumn);

						}

					}

				} else {

					// если финансовой краточки нет, то ищем основную карту по прикреплённому контакту (клиенту)
					if(!empty($deal['CONTACT_ID']) && $isPay === false && $bodyRow[$headerKeys['Тип карты']] != 'Ссылка'){

						$card = $contactCardList[$deal['CONTACT_ID']];

						if(!empty($card)){

							$type = $cardTypeList[$card['type_id']];

							if(!empty($type)){
								$bodyRow[$headerKeys['Тип карты']] = $type['NAME'];
							}

						}

					}

				}
				// если есть карта возврата
				if($refund){

					if($refund['DIRECTION_TYPE'] != 'CARD' || empty($totalPaidClient['refund'])){

						$totalPaidClient['refund'] -= $refund['RETURN_CASH'];

						// если возврат не является карточным, то дополняем платёж
						if($bodyRow[$headerKeys['Валюта сделки']] != 'RUB' && $bodyRow[$headerKeys['Валюта сделки']] != ''){

							$transactionDate = $refund['DATE_CREATE']->format('d.m.Y');
							$transactionDateTime = $refund['DATE_CREATE']->toString();

							// чистый средний курс получаем объект курса валют по дате входящего авансового платежа
							$currency = CurrencyRateTable::getList([
								'filter' => [
									'CURRENCY' => \convertCurrencyCardToCurrency($bodyRow[$headerKeys['Валюта сделки']]),
									'=DATE_RATE' => $transactionDate,
									'<=DATE_RATE_START' => $transactionDateTime,
									'>=DATE_RATE_FINISH' => $transactionDateTime,
								],
								'order' => [
									'ID' => 'DESC'
								]
							])->fetch();

							if($currency){
								// получаем курс
								$course = $currency['RATE']/$currency['RATE_CNT'];
							} else {

								// получаем объект курса валют по дате входящего авансового платежа
								$currency = CurrencyRateTable::getList([
									'order' => [
										'DATE_RATE' => 'DESC'
									],
									'filter' => [
										'CURRENCY' => \convertCurrencyCardToCurrency($bodyRow[$headerKeys['Валюта сделки']]),
									]
								])->fetch();

								// получаем курс
								$course = $currency['RATE']/$currency['RATE_CNT'];

							}

							$originalTotalPaidClientCurrency['refund'] -= $paymentTransaction['AMOUNT'] / $course;

						}

					}




					$bodyRow[$headerKeys['Сумма прибыли с учетом возврата без НДС']] = 0;

					$bodyRow[$headerKeys['Сумма продажи']] = $totalPaidClient['success'];
					$bodyRow[$headerKeys['Сумма продажи после возврата']] = $bodyRow[$headerKeys['Сумма продажи']] + $totalPaidClient['refund'];
					$bodyRow[$headerKeys['Сумма продажи']] = number_format($bodyRow[$headerKeys['Сумма продажи']], 2, '.', '');

					$bodyRow[$headerKeys['Статус карты возврата']] = self::getLangRefundStatus($refund['STATUS']);
					$bodyRow[$headerKeys['Возврат сбора РС ТЛС']] = $refund['RS_TLS_FEE'];
					$bodyRow[$headerKeys['Сумма возврата поставщиком']] = $refund['RETURN_SUPPLIER']*-1;
					$bodyRow[$headerKeys['Штраф от поставщика']] = $refund['SUPPLIER_PENALTY'];
					$bodyRow[$headerKeys['Сбор поставщика на возврат']] = $refund['SUPPLIER_FEE'];
					$bodyRow[$headerKeys['Продукты за сбор возврата']] = $refund['PRODUCT'];
					$bodyRow[$headerKeys['Штраф клиенту РС ТЛС']] = $refund['CLIENT_PENALTY'];
					$bodyRow[$headerKeys['Остаток сбора РС ТЛС']] = $refund['RS_TLS_REMAINING_FEE'];

					$bodyRow[$headerKeys['Удержал поставщик']] = $bodyRow[$headerKeys['Штраф от поставщика']] + $bodyRow[$headerKeys['Сбор поставщика на возврат']] + $bodyRow[$headerKeys['Продукты за сбор возврата']];

					$bodyRow[$headerKeys['Прибыль РС ТЛС с учетом возврата']] = $bodyRow[$headerKeys['Штраф клиенту РС ТЛС']]+$bodyRow[$headerKeys['Остаток сбора РС ТЛС']];

					$bodyRow[$headerKeys['Удержал поставщик']] = $bodyRow[$headerKeys['Штраф от поставщика']]+$bodyRow[$headerKeys['Сбор поставщика на возврат']];
					$bodyRow[$headerKeys['Сумма возврата клиенту']] = $refund['RETURN_CASH'];
					$bodyRow[$headerKeys['Дата отмены операции (возврат)']] = $refund['REFUND_DATE'] ? $refund['REFUND_DATE']->format('d.m.Y') : '';

					if($refund['STATUS'] == 'COMPLETED' && $refund['STATUS'] != 'CLOSE'){
						$bodyRow[$headerKeys['Сумма НДС']] = 0;
					}

					// приводим в числовой формат
					$bodyRow[$headerKeys['Возврат сбора РС ТЛС']] = number_format((float)$bodyRow[$headerKeys['Возврат сбора РС ТЛС']], 2, '.', '');
					$bodyRow[$headerKeys['Удержал поставщик']] = number_format((float)$bodyRow[$headerKeys['Удержал поставщик']], 2, '.', '');
					$bodyRow[$headerKeys['Сумма возврата поставщиком']] = number_format((float)$bodyRow[$headerKeys['Сумма возврата поставщиком']], 2, '.', '');
					$bodyRow[$headerKeys['Штраф от поставщика']] = number_format((float)$bodyRow[$headerKeys['Штраф от поставщика']], 2, '.', '');
					$bodyRow[$headerKeys['Сбор поставщика на возврат']] = number_format((float)$bodyRow[$headerKeys['Сбор поставщика на возврат']], 2, '.', '');
					$bodyRow[$headerKeys['Продукты за сбор возврата']] = number_format((float)$bodyRow[$headerKeys['Продукты за сбор возврата']], 2, '.', '');
					$bodyRow[$headerKeys['Штраф клиенту РС ТЛС']] = number_format((float)$bodyRow[$headerKeys['Штраф клиенту РС ТЛС']], 2, '.', '');
					$bodyRow[$headerKeys['Остаток сбора РС ТЛС']] = number_format((float)$bodyRow[$headerKeys['Остаток сбора РС ТЛС']], 2, '.', '');
					$bodyRow[$headerKeys['Удержал поставщик']] = number_format((float)$bodyRow[$headerKeys['Удержал поставщик']], 2, '.', '');
					$bodyRow[$headerKeys['Сумма возврата клиенту']] = number_format((float)$bodyRow[$headerKeys['Сумма возврата клиенту']], 2, '.', '');


				}
				if(empty($bodyRow[$headerKeys['Страна']]) && !empty($deal[Deal::FINAL_COUNTRY])){
					$bodyRow[$headerKeys['Страна']] = $countryList[$deal[Deal::FINAL_COUNTRY]]['title_ru'];
				}

				if(empty($bodyRow[$headerKeys['Страна']]) && !empty($deal[Deal::FINAL_COUNTRY])){
					$bodyRow[$headerKeys['Страна']] = $countryList[$deal[Deal::FINAL_COUNTRY]]['title_ru'];
				}

				if(empty($bodyRow[$headerKeys['Город']]) && !empty($deal[Deal::FINAL_CITY])){
					$bodyRow[$headerKeys['Город']] = $cityList[$deal[Deal::FINAL_CITY]]['title_ru'];
				}

				if((int) $deal['UF_CRM_DEAL_ORM_HOTEL'] > 0){

					$hotel = $financialCardHotelList[$deal['UF_CRM_DEAL_ORM_HOTEL']];

					if(!empty($hotel)){

						$bodyRow[$headerKeys['Гостиница']] = $hotel['NAME'];
						$bodyRow[$headerKeys['Цепочка']] = $hotel['CHAIN_VALUE'];
						
					}

				}

				// проверяем, есть ли партнёр у сделки
				$partnerCompanyId = $offerList[$deal['ID']];

				// если указан партнёр, то формируем строку документа
				if($partnerCompanyId > 0){

					$companyType = [];
					$companyDocument = 'нет'; // по-умолчанию значение "Наличие договора"

					if($partnerCompanyId > 0){
						
						$company = $companyList[$partnerCompanyId];

						if(empty($company)){
							$bodyRow[$headerKeys['Наличие договора']] = $companyDocument;
						} else {

							$filterId = $company['UF_BRS_CRM_COMPANY_TYPE'];

							// добавляем значения идентификаторов полей, которые нужно получить
							if($company['UF_CRM_COMPANY_CONTRACT_STATUS'] > 0){
								$filterId[] = $company['UF_CRM_COMPANY_CONTRACT_STATUS'];
							}

							if(!is_array($company['UF_BRS_CRM_COMPANY_TYPE'])){
								$company['UF_BRS_CRM_COMPANY_TYPE'] = [];
							}

							foreach($filterId as $enumId){

								$enum = $enumList[$enumId]; // получаем значения пользовательских полей

								if(!empty($enum)){
									if(in_array($enum['ID'], $company['UF_BRS_CRM_COMPANY_TYPE'])){
										$companyType[] = $enum['VALUE'];
									} else if($enum['ID'] == $company['UF_CRM_COMPANY_CONTRACT_STATUS']){
										$companyDocument = mb_strtolower($enum['VALUE']);
									}
								}

							}

							$bodyRow[$headerKeys['Партнер']] = $company['TITLE'];
							$bodyRow[$headerKeys['Тип брони']] = implode(', ', $companyType);

							if(count($company['UF_BRS_CRM_COMPANY_PRIVILEGE']) > 0){
								$bodyRow[$headerKeys['Привилегии']] = 'да';
							} else {
								$bodyRow[$headerKeys['Привилегии']] = 'нет';
							}

							$bodyRow[$headerKeys['Наличие договора']] = $companyDocument;

						}

					} else {
						$bodyRow[$headerKeys['Наличие договора']] = $companyDocument;
					}

				}

				// если идентификатор источника указан
				if(!empty($deal['SOURCE_ID'])){

					$dealSource = $dealStageList['SOURCE'][$deal['SOURCE_ID']];

					if(!empty($dealSource)){
						$bodyRow[$headerKeys['Канал связи']] = $dealSource['NAME']; // указываем название источника
					}

				}

				// определяем результат сделки
				if($deal['STAGE_SEMANTIC_ID'] == 'S'){
					$bodyRow[$headerKeys['Результат сделки']] = 'Успех';
				} else if($deal['STAGE_SEMANTIC_ID'] == 'F'){

					$bodyRow[$headerKeys['Результат сделки']] = 'Проиграна';

					if($dealStageName == 'Другое'){
						$bodyRow[$headerKeys['Причина стадии Сделка проиграна']] = $deal['UF_CRM_DEAL_STAGE_OTHER_REASON'];
					} else {
						$bodyRow[$headerKeys['Причина стадии Сделка проиграна']] = $dealStageName;
					}

				} else {
					$bodyRow[$headerKeys['Результат сделки']] = 'В процессе';
				}

				if(!empty($bodyRow[$headerKeys['Тип карты']]) && is_array($bodyRow[$headerKeys['Тип карты']])){
					$bodyRow[$headerKeys['Тип карты']] = implode(', ', $bodyRow[$headerKeys['Тип карты']]);
				}

				unset($country);
				unset($city);

				foreach($bodyRow as $value => $row){
					if(is_numeric($row)){
						$bodyRow[$value] = str_replace('.', ',', $row);
					}
				}

				ksort($bodyRow);
				
				$bodyRows[$deal['ID']] = $bodyRow;
				
			}

			return array(
				'header' => $header,
				'body' => $bodyRows,
				'maxDealId' => $maxDealId,
				'dealIdList' => $dealIdList
			);

		}

		/**
		 * Метод устанавливает сделкам значение "Да" в свойство "Обновить в универсальном отчёте"
		 * 
		 * @param array $dealIdList
		 * @return void
		 */
		private function updatePropertyDeal(array $dealIdList = []): void {

			if(count($dealIdList) == 0){
				return;
			}

			$propertyCollection = DealPropertyTable::getList([
				'filter' => [
					'DEAL_ID' => $dealIdList
				]
			]);

			if($propertyCollection->getSelectedRowsCount() == 0){
				return;
			}

			$propertyCollection = $propertyCollection->fetchCollection();

			foreach($propertyCollection as $property){
				$property->set(Deal::UNIVERSAL_REPORT_UPDATE, false);
			}

			$propertyCollection->save(); // сохраняем коллекцию

		}

		/**
		 * Отдаёт название статуса.
		 * 
		 * @param string $statusCode
		 * @return string
		 */
		private function getLangRefundStatus(string $statusCode){

			$lang = \Brs\FinancialCard\Repository\RefundCard::AUDITION_STATUS;

			if(array_key_exists($statusCode, $lang)){
				return $lang[$statusCode];
			} else {
				return $statusCode;
			}

		}

	}
