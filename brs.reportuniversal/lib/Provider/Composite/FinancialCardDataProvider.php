<?php

namespace Brs\ReportUniversal\Provider\Composite;

use Brs\ReportUniversal\Exception\ReportException;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\FinancialCard\Models\FinancialCardPriceTable;

/**
 * Composite DataProvider для финансовых карт и их цен
 * 
 * Загружает данные из двух связанных таблиц через ORM:
 * - brs_financial_card (связь через DEAL_ID)
 * - brs_financial_card_price (связь через FINANCIAL_CARD_PRICE_ID)
 * 
 * Данные загружаются в момент запроса fillDealData для каждой сделки
 */
class FinancialCardDataProvider
{
	/** @var \mysqli Подключение к БД (для совместимости) */
	private \mysqli $connection;

	/** @var array Маппинг значений схемы работы */
	private const SCHEME_WORK_MAP = [
		'BUYER_AGENT' => 'Агент покупателя',
		'SR_SUPPLIER_AGENT' => 'Агент Поставщика SR',
		'LR_SUPPLIER_AGENT' => 'Агент Поставщика LR',
		'PROVISION_SERVICES' => 'Оказание услуг',
		'RS_TLS_SERVICE_FEE' => 'Сервисный сбор РС ТЛС'
	];

	/** @var array Список колонок из FinancialCardPriceTable */
	private const PRICE_COLUMNS = [
		'Курс оплаты' => 'CURRENCY_RATE',
		'Валюта сделки' => 'CURRENCY_ID',
		'Сумма по счету Поставщика (НЕТТО)' => 'SUPPLIER_NET',
		'Сумма по счету Поставщика (НЕТТО) в валюте' => 'SUPPLIER_NET_CURRENCY',
		'Дополнительная выгода' => 'ADDITIONAL_BENEFIT',
		'Дополнительная выгода в валюте' => 'ADDITIONAL_BENEFIT_CURRENCY',
		'Сбор поставщика' => 'SUPPLIER',
		'Сбор поставщика в валюте' => 'SUPPLIER_CURRENCY',
		'Сервисный сбор' => 'SERVICE',
		'Сервисный сбор в валюте' => 'SERVICE_CURRENCY',
		'Комиссия' => 'COMMISSION',
		'Комиссия в Валюте' => 'COMMISSION_CURRENCY',
		'Всего к оплате Поставщику' => 'SUPPLIER_TOTAL_PAID',
		'Всего к оплате Поставщику в валюте' => 'SUPPLIER_TOTAL_PAID_CURRENCY',
		'Всего к оплате Клиентом' => 'RESULT',
		'Всего к оплате Клиентом валюта' => 'RESULT_CURRENCY'
	];

	/**
	 * @param \mysqli $connection Нативное подключение mysqli (для совместимости)
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Заглушка для совместимости с DealsReportGenerator
	 * Предзагрузка не используется, данные загружаются по требованию
	 */
	public function preloadData(): void
	{
		// Ничего не делаем - загрузка происходит в fillDealData
	}

	/**
	 * Возвращает названия всех колонок
	 * 
	 * @return array
	 */
	public function getColumnNames(): array
	{
		// Сначала схема работы, потом все поля из прайса
		return array_merge(
			['Схема финансовой карты'],
			array_keys(self::PRICE_COLUMNS)
		);
	}

	/**
	 * Заполняет данными сделку
	 * Загружает данные из БД через ORM в момент вызова
	 * 
	 * @param array $dealData Данные сделки
	 * @param int $dealId ID сделки
	 * @return array Массив с колонками финансовых карт
	 * @throws ReportException При ошибке загрузки данных
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$result = [];

		// Инициализируем все колонки пустыми значениями
		foreach ($this->getColumnNames() as $columnName) {
			$result[$columnName] = '';
		}

		try {
			// Загружаем финансовую карту по DEAL_ID
			$financialCard = FinancialCardTable::getList([
				'filter' => ['=DEAL_ID' => $dealId],
				'select' => ['SCHEME_WORK', 'FINANCIAL_CARD_PRICE_ID'],
				'limit' => 1
			])->fetch();

			if (!$financialCard) {
				// Нет финансовой карты для этой сделки
				return $result;
			}

			// Заполняем схему работы с маппингом
			$schemeWork = $financialCard['SCHEME_WORK'] ?? '';
			$result['Схема финансовой карты'] = self::SCHEME_WORK_MAP[$schemeWork] ?? $schemeWork;

			// Загружаем данные прайса если есть связь
			$priceId = $financialCard['FINANCIAL_CARD_PRICE_ID'] ?? null;
			if ($priceId) {
				$this->fillPriceData($result, (int)$priceId);
			}

		} catch (\Exception $e) {
			throw new ReportException(
				"Ошибка загрузки данных финансовой карты для сделки {$dealId}: " . $e->getMessage(),
				0,
				$e
			);
		}

		return $result;
	}

	/**
	 * Заполняет данные из таблицы цен
	 * 
	 * @param array &$result Массив результатов (передаётся по ссылке)
	 * @param int $priceId ID записи в таблице цен
	 * @return void
	 * @throws ReportException При ошибке загрузки
	 */
	private function fillPriceData(array &$result, int $priceId): void
	{
		// Формируем список полей для выборки
		$selectFields = array_values(self::PRICE_COLUMNS);

		// Загружаем данные прайса
		$priceData = FinancialCardPriceTable::getList([
			'filter' => ['=ID' => $priceId],
			'select' => $selectFields,
			'limit' => 1
		])->fetch();

		if (!$priceData) {
			// Нет данных прайса (битая связь)
			return;
		}

		// Заполняем результат
		foreach (self::PRICE_COLUMNS as $columnName => $fieldCode) {
			$value = $priceData[$fieldCode] ?? '';
			
			// Форматируем значение для CSV
			$result[$columnName] = $this->formatValue($value);
		}
	}

	/**
	 * Форматирует значение для записи в CSV
	 * 
	 * @param mixed $value Исходное значение
	 * @return string Отформатированное значение
	 */
	private function formatValue($value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		// Преобразуем в строку
		$stringValue = (string)$value;

		// Удаляем лишние пробелы
		$stringValue = trim($stringValue);

		return $stringValue;
	}
}