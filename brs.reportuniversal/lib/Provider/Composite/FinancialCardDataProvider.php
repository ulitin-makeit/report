<?php

namespace Brs\ReportUniversal\Provider\Composite;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * Composite DataProvider для финансовых карт и их цен
 * 
 * Загружает данные из двух связанных таблиц:
 * - brs_financial_card (связь через DEAL_ID)
 * - brs_financial_card_price (связь через FINANCIAL_CARD_PRICE_ID)
 * 
 * Возвращает фиксированный набор колонок с префиксами:
 * - FC_* - поля из brs_financial_card
 * - FCP_* - поля из brs_financial_card_price
 * 
 * Всегда возвращает одинаковый набор колонок (пустые строки если данных нет)
 */
class FinancialCardDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные по сделкам [deal_id => [...все поля с префиксами...]] */
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
	 * Предзагружает данные финансовых карт с ценами
	 */
	public function preloadData(): void
	{
		try {
			$this->loadFinancialCardData();

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных финансовых карт: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Загружает данные из brs_financial_card + brs_financial_card_price
	 * 
	 * @return void
	 * @throws ReportException
	 */
	private function loadFinancialCardData(): void
	{
		// Получаем список колонок из каждой таблицы
		$fcColumns = $this->getTableColumns('brs_financial_card', ['DEAL_ID']); // DEAL_ID используем для связи
		$fcpColumns = $this->getTableColumns('brs_financial_card_price', ['ID']); // ID не нужен (дубль)

		// Формируем SELECT с префиксами
		$selectParts = [];

		// Поля из brs_financial_card с префиксом FC_
		foreach ($fcColumns as $column) {
			$selectParts[] = "fc.`{$column}` AS FC_{$column}";
		}

		// Поля из brs_financial_card_price с префиксом FCP_
		foreach ($fcpColumns as $column) {
			$selectParts[] = "fcp.`{$column}` AS FCP_{$column}";
		}

		$selectClause = implode(",\n\t\t\t", $selectParts);

		$sql = "
			SELECT 
				fc.DEAL_ID,
				{$selectClause}
			FROM brs_financial_card fc
			LEFT JOIN brs_financial_card_price fcp 
				ON fc.FINANCIAL_CARD_PRICE_ID = fcp.ID
		";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки финансовых карт: " . mysqli_error($this->connection));
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

			// Сохраняем все поля с префиксами (кроме DEAL_ID)
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
			$this->initializeEmptyColumnNames($fcColumns, $fcpColumns);
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
	 * Инициализирует названия колонок если данных в таблицах нет
	 * 
	 * @param array $fcColumns Колонки из brs_financial_card
	 * @param array $fcpColumns Колонки из brs_financial_card_price
	 * @return void
	 */
	private function initializeEmptyColumnNames(array $fcColumns, array $fcpColumns): void
	{
		// Формируем названия колонок с префиксами
		foreach ($fcColumns as $column) {
			$this->columnNames[] = 'FC_' . $column;
		}
		foreach ($fcpColumns as $column) {
			$this->columnNames[] = 'FCP_' . $column;
		}
	}

	/**
	 * Возвращает названия всех колонок
	 * 
	 * Формат: FC_* (financial_card), FCP_* (price)
	 * 
	 * @return array
	 */
	public function getColumnNames(): array
	{
		if (empty($this->columnNames)) {
			throw new ReportException("Колонки финансовых карт не были загружены. Вызовите preloadData() сначала.");
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
	 * @return array Массив с колонками финансовых карт
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