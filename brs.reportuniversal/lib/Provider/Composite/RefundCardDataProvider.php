<?php

namespace Brs\ReportUniversal\Provider\Composite;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * Composite DataProvider для карт возврата
 * 
 * Загружает данные из таблицы:
 * - brs_refund_card (связь через DEAL_ID)
 * 
 * Возвращает фиксированный набор колонок с префиксом:
 * - RC_* - поля из brs_refund_card
 * 
 * Всегда возвращает одинаковый набор колонок (пустые строки если данных нет)
 */
class RefundCardDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные по сделкам [deal_id => [...все поля с префиксом RC_...]] */
	private array $dealData = [];

	/** @var array Названия всех колонок (заполняется при первой загрузке) */
	private array $columnNames = [];

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные карт возврата
	 */
	public function preloadData(): void
	{
		try {
			$this->loadRefundCardData();

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных карт возврата: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Загружает данные из brs_refund_card
	 * 
	 * @return void
	 * @throws ReportException
	 */
	private function loadRefundCardData(): void
	{
		// Получаем список колонок из таблицы
		$rcColumns = $this->getTableColumns('brs_refund_card', ['DEAL_ID']); // DEAL_ID используем для связи

		// Формируем SELECT с префиксом RC_
		$selectParts = [];
		foreach ($rcColumns as $column) {
			$selectParts[] = "rc.`{$column}` AS RC_{$column}";
		}

		$selectClause = implode(",\n\t\t\t", $selectParts);

		$sql = "
			SELECT 
				rc.DEAL_ID,
				{$selectClause}
			FROM brs_refund_card rc
		";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки карт возврата: " . mysqli_error($this->connection));
		}

		// Получаем названия колонок из результата
		if (empty($this->columnNames) && $result->field_count > 0) {
			$fields = mysqli_fetch_fields($result);
			foreach ($fields as $field) {
				$columnName = $field->name;
				// Пропускаем служебное поле DEAL_ID
				if ($columnName !== 'DEAL_ID') {
					$this->columnNames[] = $columnName;
				}
			}
		}

		// Загружаем данные
		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];

			// Сохраняем все поля с префиксом RC_ (кроме DEAL_ID)
			$data = [];
			foreach ($row as $key => $value) {
				if ($key !== 'DEAL_ID') {
					$data[$key] = $value ?? '';
				}
			}

			$this->dealData[$dealId] = $data;
		}

		mysqli_free_result($result);

		// Если нет данных вообще, нужно всё равно получить названия колонок
		if (empty($this->columnNames)) {
			$this->initializeEmptyColumnNames($rcColumns);
		}
	}

	/**
	 * Получает список колонок таблицы
	 * 
	 * @param string $tableName Имя таблицы
	 * @param array $excludeColumns Колонки которые нужно исключить
	 * @return array Массив названий колонок
	 * @throws ReportException
	 */
	private function getTableColumns(string $tableName, array $excludeColumns = []): array
	{
		$sql = "SHOW COLUMNS FROM `{$tableName}`";
		$result = mysqli_query($this->connection, $sql);

		if (!$result) {
			throw new ReportException("Ошибка получения колонок таблицы {$tableName}: " . mysqli_error($this->connection));
		}

		$columns = [];
		while ($row = mysqli_fetch_assoc($result)) {
			$columnName = $row['Field'];
			if (!in_array($columnName, $excludeColumns, true)) {
				$columns[] = $columnName;
			}
		}

		mysqli_free_result($result);
		return $columns;
	}

	/**
	 * Инициализирует названия колонок если данных в таблице нет
	 * 
	 * @param array $rcColumns Колонки из brs_refund_card
	 * @return void
	 */
	private function initializeEmptyColumnNames(array $rcColumns): void
	{
		// Формируем названия колонок с префиксом RC_
		foreach ($rcColumns as $column) {
			$this->columnNames[] = 'RC_' . $column;
		}
	}

	/**
	 * Возвращает названия всех колонок
	 * 
	 * Формат: RC_* (refund_card)
	 * 
	 * @return array
	 */
	public function getColumnNames(): array
	{
		if (empty($this->columnNames)) {
			throw new ReportException("Колонки карт возврата не были загружены. Вызовите preloadData() сначала.");
		}

		return $this->columnNames;
	}

	/**
	 * Заполняет данными сделку
	 * 
	 * Возвращает все колонки (пустые строки если данных нет)
	 * 
	 * @param array $dealData Данные сделки
	 * @param int $dealId ID сделки
	 * @return array Массив с колонками карт возврата
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$result = [];

		// Инициализируем все колонки пустыми значениями
		foreach ($this->columnNames as $columnName) {
			$result[$columnName] = '';
		}

		// Если есть данные для этой сделки - заполняем
		if (isset($this->dealData[$dealId])) {
			$data = $this->dealData[$dealId];

			foreach ($data as $key => $value) {
				if (isset($result[$key])) {
					$result[$key] = $value;
				}
			}
		}

		return $result;
	}
}