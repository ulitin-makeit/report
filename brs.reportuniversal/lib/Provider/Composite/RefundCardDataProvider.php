<?php

namespace Brs\ReportUniversal\Provider\Composite;

use Brs\ReportUniversal\Exception\ReportException;
use Brs\RefundCard\Models\RefundCardTable;

/**
 * Composite DataProvider для карт возврата
 * 
 * Загружает данные из таблицы:
 * - brs_refund_card (связь через DEAL_ID)
 * 
 * Данные загружаются в момент запроса fillDealData для каждой сделки
 */
class RefundCardDataProvider
{
	/** @var \mysqli Подключение к БД (для совместимости) */
	private \mysqli $connection;

	/** @var array Список колонок из RefundCardTable */
	private const REFUND_COLUMNS = [
		'Курс' => 'CURRENCY',
		'Валюта сделки' => 'ID'
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
		return array_keys(self::REFUND_COLUMNS);
	}

	/**
	 * Заполняет данными сделку
	 * Загружает данные из БД через ORM в момент вызова
	 * 
	 * @param array $dealData Данные сделки
	 * @param int $dealId ID сделки
	 * @return array Массив с колонками карт возврата
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
			// Формируем список полей для выборки
			$selectFields = array_values(self::REFUND_COLUMNS);

			// Загружаем карту возврата по DEAL_ID
			$refundCard = RefundCardTable::getList([
				'filter' => ['=DEAL_ID' => $dealId],
				'select' => $selectFields,
				'limit' => 1
			])->fetch();

			if (!$refundCard) {
				// Нет карты возврата для этой сделки
				return $result;
			}

			// Заполняем результат
			foreach (self::REFUND_COLUMNS as $columnName => $fieldCode) {
				$value = $refundCard[$fieldCode] ?? '';
				
				// Форматируем значение для CSV
				$result[$columnName] = $this->formatValue($value);
			}

		} catch (\Exception $e) {
			throw new ReportException(
				"Ошибка загрузки данных карты возврата для сделки {$dealId}: " . $e->getMessage(),
				0,
				$e
			);
		}

		return $result;
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