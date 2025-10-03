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

	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
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
				'select' => ['ID', 'DEAL_ID', 'SCHEME_WORK', 'FINANCIAL_CARD_PRICE_ID'],
				'order' => ['DEAL_ID' => 'ASC']
			])->fetchAll();

			// Собираем ID прайсов для батч-загрузки
			$priceIds = [];
			$cardsByDeal = [];
			
			foreach ($financialCards as $card) {
				$dealId = (int)$card['DEAL_ID'];
				$priceId = (int)($card['FINANCIAL_CARD_PRICE_ID'] ?? 0);
				
				$cardsByDeal[$dealId] = [
					'SCHEME_WORK' => $card['SCHEME_WORK'] ?? '',
					'PRICE_ID' => $priceId
				];
				
				if ($priceId > 0) {
					$priceIds[] = $priceId;
				}
			}

			// ШАГ 2: Загружаем все прайсы ОДНИМ запросом
			$pricesData = [];
			if (!empty($priceIds)) {
				$selectFields = array_values(self::PRICE_COLUMNS);
				$selectFields[] = 'ID'; // Добавляем ID для индексации
				
				$prices = FinancialCardPriceTable::getList([
					'filter' => ['@ID' => array_unique($priceIds)],
					'select' => $selectFields
				])->fetchAll();

				foreach ($prices as $price) {
					$pricesData[(int)$price['ID']] = $price;
				}
			}

			// ШАГ 3: Формируем итоговый массив данных
			foreach ($cardsByDeal as $dealId => $cardData) {
				$result = [];
				
				// Инициализируем все колонки пустыми значениями
				foreach ($this->columnNames as $columnName) {
					$result[$columnName] = '';
				}

				// Заполняем схему работы
				$schemeWork = $cardData['SCHEME_WORK'];
				$result['Схема финансовой карты'] = self::SCHEME_WORK_MAP[$schemeWork] ?? $schemeWork;

				// Заполняем данные прайса
				$priceId = $cardData['PRICE_ID'];
				if ($priceId > 0 && isset($pricesData[$priceId])) {
					$priceData = $pricesData[$priceId];
					
					foreach (self::PRICE_COLUMNS as $columnName => $fieldCode) {
						$value = $priceData[$fieldCode] ?? '';
						$result[$columnName] = $this->formatValue($value);
					}
				}

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
		// Инициализируем пустыми значениями
		$result = [];
		foreach ($this->columnNames as $columnName) {
			$result[$columnName] = '';
		}

		// Если есть предзагруженные данные - возвращаем их
		if (isset($this->dealData[$dealId])) {
			return $this->dealData[$dealId];
		}

		return $result;
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