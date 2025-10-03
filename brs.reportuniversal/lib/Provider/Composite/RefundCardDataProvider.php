<?php

namespace Brs\ReportUniversal\Provider\Composite;

use Brs\ReportUniversal\Exception\ReportException;
use Brs\RefundCard\Models\RefundCardTable;

/**
 * Composite DataProvider для карт возврата
 * 
 * Загружает данные из таблицы с ПРЕДЗАГРУЗКОЙ
 */
class RefundCardDataProvider
{
	/** @var \mysqli Подключение к БД (для совместимости) */
	private \mysqli $connection;

	/** @var array Предзагруженные данные [deal_id => [...поля...]] */
	private array $dealData = [];

	/** @var array Названия колонок */
	private array $columnNames = [];

	/** @var array Список колонок из RefundCardTable */
	private const REFUND_COLUMNS = [
		'Курс' => 'CURRENCY',
		'Валюта сделки' => 'ID'
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
		$this->columnNames = array_keys(self::REFUND_COLUMNS);
	}

	/**
	 * ПРЕДЗАГРУЗКА: загружает ВСЕ карты возврата ОДИН РАЗ
	 */
	public function preloadData(): void
	{
		try {
			$selectFields = array_values(self::REFUND_COLUMNS);
			$selectFields[] = 'DEAL_ID'; // Добавляем для индексации

			$refundCards = RefundCardTable::getList([
				'select' => $selectFields,
				'order' => ['DEAL_ID' => 'ASC']
			])->fetchAll();

			foreach ($refundCards as $card) {
				$dealId = (int)$card['DEAL_ID'];
				$result = [];

				// Инициализируем все колонки
				foreach ($this->columnNames as $columnName) {
					$result[$columnName] = '';
				}

				// Заполняем данными
				foreach (self::REFUND_COLUMNS as $columnName => $fieldCode) {
					$value = $card[$fieldCode] ?? '';
					$result[$columnName] = $this->formatValue($value);
				}

				$this->dealData[$dealId] = $result;
			}

		} catch (\Exception $e) {
			throw new ReportException(
				"Ошибка предзагрузки данных карт возврата: " . $e->getMessage(),
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