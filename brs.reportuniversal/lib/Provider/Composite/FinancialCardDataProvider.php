<?php

namespace Brs\ReportUniversal\Provider\Composite;

use Brs\ReportUniversal\Exception\ReportException;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\FinancialCard\Models\FinancialCardPriceTable;

/**
 * Composite DataProvider для финансовых карт и их цен
 *
 * Загружает данные из двух связанных таблиц через ORM с ПРЕДЗАГРУЗКОЙ
 */
class FinancialCardDataProvider
{
	/** @var \mysqli Подключение к БД (для совместимости) */
	private \mysqli $connection;

	/** @var array Предзагруженные данные [deal_id => [...поля...]] */
	private array $dealData = [];

	/** @var array Названия колонок */
	private array $columnNames = [];

	/** @var FinancialCardSchemeProcessor Процессор логики схем работы */
	private FinancialCardSchemeProcessor $schemeProcessor;

	/** @var array Список колонок из FinancialCardPriceTable */
	private const PRICE_COLUMNS = [
		'Курс оплаты' => 'CURRENCY_RATE',
		'Валюта сделки' => 'CURRENCY_ID',
		'Сумма по счету Поставщика (НЕТТО)' => 'SUPPLIER_NET',
		'Сумма по счету Поставщика (НЕТТО) в валюте' => 'SUPPLIER_NET_CURRENCY',
		'Дополнительная выгода' => 'COMMISSION',
		'Дополнительная выгода в валюте' => 'COMMISSION_CURRENCY',
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

	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
		$this->schemeProcessor = new FinancialCardSchemeProcessor();
		$this->initColumnNames();
	}

	/**
	 * Инициализирует названия колонок
	 */
	private function initColumnNames(): void
	{
		$this->columnNames = array_merge(
			['Схема финансовой карты'],
			array_keys(self::PRICE_COLUMNS)
		);
	}

	/**
	 * ПРЕДЗАГРУЗКА: загружает ВСЕ финансовые карты ОДИН РАЗ
	 */
	public function preloadData(): void
	{
		try {
			// ШАГ 1: Загружаем все финансовые карты
			$financialCards = FinancialCardTable::getList([
				'select' => ['ID', 'DEAL_ID', 'SCHEME_WORK', 'FINANCIAL_CARD_PRICE_ID', 'SUPPLIER_COMMISSION']
			])->fetchAll();

			// Собираем ID прайсов для батч-загрузки
			$priceIds = [];
			$cardsByDeal = [];

			foreach ($financialCards as $card) {
				$dealId = (int)$card['DEAL_ID'];
				$priceId = (int)($card['FINANCIAL_CARD_PRICE_ID'] ?? 0);

				$cardsByDeal[$dealId] = [
					'SCHEME_WORK' => $card['SCHEME_WORK'] ?? '',
					'PRICE_ID' => $priceId,
					'SUPPLIER_COMMISSION' => $card['SUPPLIER_COMMISSION'] ?? null
				];

				if ($priceId > 0) {
					$priceIds[] = $priceId;
				}
			}

			// ШАГ 2: Загружаем все прайсы ОДНИМ запросом
			$pricesData = $this->loadPrices($priceIds);

			// ШАГ 3: Формируем итоговый массив данных
			foreach ($cardsByDeal as $dealId => $cardData) {
				$result = $this->buildDealResult($cardData, $pricesData);
				$this->dealData[$dealId] = $result;
			}

		} catch (\Exception $e) {
			throw new ReportException(
				"Ошибка предзагрузки данных финансовых карт: " . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * Загружает данные прайсов по списку ID
	 *
	 * @param array $priceIds Массив ID прайсов
	 * @return array Ассоциативный массив [price_id => price_data]
	 */
	private function loadPrices(array $priceIds): array
	{
		if (empty($priceIds)) {
			return [];
		}

		$selectFields = array_values(self::PRICE_COLUMNS);
		$selectFields[] = 'ID'; // Добавляем ID для индексации
		$selectFields[] = 'SUPPLIER_GROSS'; // Добавляем БРУТТО
		$selectFields[] = 'SUPPLIER_GROSS_CURRENCY'; // Добавляем БРУТТО в валюте

		$prices = FinancialCardPriceTable::getList([
			'filter' => ['ID' => array_unique($priceIds)],
			'select' => $selectFields
		])->fetchAll();

		$pricesData = [];
		foreach ($prices as $price) {
			$pricesData[(int)$price['ID']] = $price;
		}

		return $pricesData;
	}

	/**
	 * Формирует результирующий массив данных для одной сделки
	 *
	 * @param array $cardData Данные карты (SCHEME_WORK, PRICE_ID, SUPPLIER_COMMISSION)
	 * @param array $pricesData Предзагруженные данные прайсов
	 * @return array Массив данных для записи в CSV
	 */
	private function buildDealResult(array $cardData, array $pricesData): array
	{
		// Инициализируем все колонки пустыми значениями
		$result = $this->initializeEmptyResult();

		// Заполняем схему работы
		$schemeWork = $cardData['SCHEME_WORK'];
		$result['Схема финансовой карты'] = $this->schemeProcessor->getSchemeDisplayName($schemeWork);

		// Заполняем данные прайса
		$priceId = $cardData['PRICE_ID'];
		if ($priceId > 0 && isset($pricesData[$priceId])) {
			$this->fillPriceData($result, $pricesData[$priceId]);
			
			// Применяем бизнес-логику схем работы
			$this->schemeProcessor->applySchemeWorkLogic($result, $schemeWork);
			
			// Применяем логику для схемы SR_SUPPLIER_AGENT
			if ($this->schemeProcessor->isSRSupplierAgentScheme($schemeWork)) {
				$this->schemeProcessor->applySRSupplierAgentLogic($result, $cardData, $pricesData[$priceId]);
			}
		}

		return $result;
	}

	/**
	 * Инициализирует пустой массив результата
	 *
	 * @return array
	 */
	private function initializeEmptyResult(): array
	{
		$result = [];
		foreach ($this->columnNames as $columnName) {
			$result[$columnName] = '';
		}
		return $result;
	}

	/**
	 * Заполняет результат данными из прайса
	 *
	 * @param array &$result Массив результата
	 * @param array $priceData Данные прайса
	 * @return void
	 */
	private function fillPriceData(array &$result, array $priceData): void
	{
		foreach (self::PRICE_COLUMNS as $columnName => $fieldCode) {
			$value = $priceData[$fieldCode] ?? '';
			$result[$columnName] = $this->formatValue($value);
		}
	}

	/**
	 * Возвращает названия всех колонок
	 */
	public function getColumnNames(): array
	{
		return $this->columnNames;
	}

	/**
	 * Заполняет данными сделку (берёт из предзагруженного массива)
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		// Если есть предзагруженные данные - возвращаем их
		if (isset($this->dealData[$dealId])) {
			return $this->dealData[$dealId];
		}

		// Иначе возвращаем пустой результат
		return $this->initializeEmptyResult();
	}

	/**
	 * Форматирует значение для записи в CSV
	 */
	private function formatValue($value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		return trim((string)$value);
	}
}