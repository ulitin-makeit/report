<?php

namespace Brs\ReportUniversal\Provider\Composite;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * Composite DataProvider для финансовых карт и их цен
 * 
 * Загружает данные из двух таблиц БЕЗ JOIN (отдельными запросами):
 * - brs_financial_card (SCHEME_WORK, FINANCIAL_CARD_PRICE_ID)
 * - brs_financial_card_price (все финансовые поля)
 * 
 * Возвращает фиксированный набор колонок с финансовыми данными
 */
class FinancialCardDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Финансовые карты [deal_id => ['FINANCIAL_CARD_PRICE_ID' => X, 'SCHEME_WORK' => 'value']] */
	private array $financialCards = [];

	/** @var array Цены финансовых карт [price_id => [...поля...]] */
	private array $prices = [];

	/**
	 * Маппинг полей из brs_financial_card_price
	 * Формат: 'Название колонки в CSV' => 'FIELD_NAME'
	 */
	private const PRICE_FIELDS_MAPPING = [
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
		'Всего к оплате Клиентом валюта' => 'RESULT_CURRENCY',
	];

	/**
	 * Маппинг значений SCHEME_WORK
	 * Формат: 'db_value' => 'Отображаемое значение'
	 */
	private const SCHEME_WORK_MAPPING = [
		'BUYER_AGENT' => 'Агент покупателя',
		'SR_SUPPLIER_AGENT' => 'Агент Поставщика SR',
		'LR_SUPPLIER_AGENT' => 'Агент Поставщика LR',
		'PROVISION_SERVICES' => 'Оказание услуг',
		'RS_TLS_SERVICE_FEE' => 'Сервисный сбор РС ТЛС',
	];

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные финансовых карт и цен (БЕЗ JOIN)
	 */
	public function preloadData(): void
	{
		try {
			// Шаг 1: Загружаем финансовые карты
			$this->loadFinancialCards();

			// Шаг 2: Загружаем цены
			$this->loadPrices();

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных финансовых карт: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Загружает данные из brs_financial_card
	 * 
	 * @return void
	 * @throws ReportException
	 */
	private function loadFinancialCards(): void
	{
		$sql = "
			SELECT 
				DEAL_ID,
				FINANCIAL_CARD_PRICE_ID,
				SCHEME_WORK
			FROM brs_financial_card
		";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки финансовых карт: " . mysqli_error($this->connection));
		}

		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];

			$this->financialCards[$dealId] = [
				'FINANCIAL_CARD_PRICE_ID' => $row['FINANCIAL_CARD_PRICE_ID'] ? (int)$row['FINANCIAL_CARD_PRICE_ID'] : null,
				'SCHEME_WORK' => $row['SCHEME_WORK'] ?? '',
			];
		}

		mysqli_free_result($result);
	}

	/**
	 * Загружает данные из brs_financial_card_price
	 * 
	 * @return void
	 * @throws ReportException
	 */
	private function loadPrices(): void
	{
		// Формируем список полей для SELECT
		$fields = array_values(self::PRICE_FIELDS_MAPPING);
		$fieldsStr = '`' . implode('`, `', $fields) . '`';

		$sql = "
			SELECT 
				ID,
				{$fieldsStr}
			FROM brs_financial_card_price
		";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки цен финансовых карт: " . mysqli_error($this->connection));
		}

		while ($row = mysqli_fetch_assoc($result)) {
			$priceId = (int)$row['ID'];

			// Сохраняем все поля кроме ID
			$data = [];
			foreach (self::PRICE_FIELDS_MAPPING as $fieldName) {
				$data[$fieldName] = $row[$fieldName] ?? '';
			}

			$this->prices[$priceId] = $data;
		}

		mysqli_free_result($result);
	}

	/**
	 * Возвращает названия всех колонок
	 * 
	 * @return array
	 */
	public function getColumnNames(): array
	{
		// Сначала колонка со схемой работы
		$columns = ['Схема финансовой карты'];

		// Затем все колонки из price таблицы
		$columns = array_merge($columns, array_keys(self::PRICE_FIELDS_MAPPING));

		return $columns;
	}

	/**
	 * Заполняет данными сделку
	 * 
	 * @param array $dealData Данные сделки
	 * @param int $dealId ID сделки
	 * @return array Массив с колонками финансовых карт
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$result = [];

		// Инициализируем все колонки пустыми значениями
		foreach ($this->getColumnNames() as $columnName) {
			$result[$columnName] = '';
		}

		// Проверяем есть ли финансовая карта для этой сделки
		if (!isset($this->financialCards[$dealId])) {
			return $result;
		}

		$financialCard = $this->financialCards[$dealId];

		// Заполняем схему работы
		$schemeWork = $financialCard['SCHEME_WORK'];
		$result['Схема финансовой карты'] = $this->mapSchemeWork($schemeWork);

		// Заполняем данные из price таблицы
		$priceId = $financialCard['FINANCIAL_CARD_PRICE_ID'];
		if ($priceId && isset($this->prices[$priceId])) {
			$priceData = $this->prices[$priceId];

			// Заполняем каждое поле
			foreach (self::PRICE_FIELDS_MAPPING as $csvColumn => $dbField) {
				if (isset($priceData[$dbField])) {
					$result[$csvColumn] = $priceData[$dbField];
				}
			}
		}

		return $result;
	}

	/**
	 * Преобразует значение SCHEME_WORK в читаемый вид
	 * 
	 * @param string $schemeWork Значение из БД
	 * @return string Отображаемое значение
	 */
	private function mapSchemeWork(string $schemeWork): string
	{
		if ($schemeWork === '' || $schemeWork === null) {
			return '';
		}

		return self::SCHEME_WORK_MAPPING[$schemeWork] ?? $schemeWork;
	}
}