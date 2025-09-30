<?php

namespace Brs\ReportUniversal\Provider\Helper;

use Brs\ReportUniversal\Exception\ReportException;

/**
 * Хелпер для работы с пользовательскими полями типа "дата" (date) и "дата и время" (datetime)
 * Загружает значения полей для сделок и форматирует их для отображения
 */
class DateFieldHelper
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Загружает данные для поля типа date или datetime
	 *
	 * @param string $fieldCode Код поля (например: UF_CRM_DEAL_START_DATE, UF_DATE_SERVICE_PROVISION)
	 * @param array $fieldInfo Информация о поле из UserFieldMetaHelper
	 * @return array Ассоциативный массив [deal_id => formatted_date]
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	public function loadFieldData(string $fieldCode, array $fieldInfo): array
	{
		// Поддерживаем типы date и datetime
		if (!in_array($fieldInfo['type'], ['date', 'datetime'], true)) {
			throw new ReportException("Неподдерживаемый тип поля: {$fieldInfo['type']}. Ожидается date или datetime.");
		}

		if ($fieldInfo['multiple']) {
			return $this->loadMultipleData($fieldCode, $fieldInfo['type']);
		} else {
			return $this->loadSingleData($fieldCode, $fieldInfo['type']);
		}
	}

	/**
	 * Загружает данные для одиночного поля (date или datetime)
	 *
	 * @param string $fieldCode Код поля
	 * @param string $fieldType Тип поля (date или datetime)
	 * @return array Ассоциативный массив [deal_id => formatted_date]
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	private function loadSingleData(string $fieldCode, string $fieldType): array
	{
		$sql = "SELECT VALUE_ID as DEAL_ID, `{$fieldCode}` as FIELD_VALUE FROM b_uts_crm_deal";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки данных поля {$fieldCode}: " . mysqli_error($this->connection));
		}

		$data = [];
		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];
			$value = $row['FIELD_VALUE'];

			if ($value === null || $value === '') {
				$data[$dealId] = '';
			} else {
				$data[$dealId] = $this->formatDate($value, $fieldType);
			}
		}

		mysqli_free_result($result);
		return $data;
	}

	/**
	 * Загружает данные для множественного поля (date или datetime)
	 *
	 * @param string $fieldCode Код поля
	 * @param string $fieldType Тип поля (date или datetime)
	 * @return array Ассоциативный массив [deal_id => 'date1, date2, date3']
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	private function loadMultipleData(string $fieldCode, string $fieldType): array
	{
		$tableName = "b_uts_crm_deal_" . strtolower($fieldCode);
		$sql = "SELECT VALUE_ID as DEAL_ID, VALUE as FIELD_VALUE FROM `{$tableName}` ORDER BY VALUE_ID, ID";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			// Таблица может не существовать если поле не использовалось
			return [];
		}

		$dealValues = [];
		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];
			$value = $row['FIELD_VALUE'];

			if ($value !== null && $value !== '') {
				if (!isset($dealValues[$dealId])) {
					$dealValues[$dealId] = [];
				}
				$dealValues[$dealId][] = $this->formatDate($value, $fieldType);
			}
		}

		mysqli_free_result($result);

		// Объединяем множественные значения через запятую
		$data = [];
		foreach ($dealValues as $dealId => $dates) {
			$uniqueDates = array_filter(array_unique($dates));
			// Сортируем даты
			sort($uniqueDates);
			$data[$dealId] = implode(', ', $uniqueDates);
		}

		return $data;
	}

	/**
	 * Форматирует дату для отображения в CSV
	 *
	 * @param string $value Исходное значение даты из БД
	 * @param string $fieldType Тип поля (date или datetime)
	 * @return string Форматированная дата
	 */
	private function formatDate(string $value, string $fieldType): string
	{
		// Пытаемся распарсить дату
		$timestamp = strtotime($value);

		if ($timestamp === false) {
			// Если не удалось распарсить - возвращаем как есть
			return trim($value);
		}

		// Форматируем в зависимости от типа поля
		if ($fieldType === 'datetime') {
			// Для datetime: "DD.MM.YYYY HH:MM:SS"
			return date('d.m.Y H:i:s', $timestamp);
		} else {
			// Для date: "DD.MM.YYYY"
			return date('d.m.Y', $timestamp);
		}
	}
}