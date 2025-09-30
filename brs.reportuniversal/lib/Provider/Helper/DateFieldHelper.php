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

		return $this->loadSingleData($fieldCode, $fieldInfo['type']);
	}

	/**
	 * Загружает данные для поля (date или datetime)
	 * Обрабатывает как обычные значения, так и сериализованные массивы
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
				$data[$dealId] = $this->processValue($value, $fieldType);
			}
		}

		mysqli_free_result($result);
		return $data;
	}

	/**
	 * Обрабатывает значение поля
	 * Определяет является ли значение сериализованным массивом или обычной датой
	 *
	 * @param string $value Исходное значение из БД
	 * @param string $fieldType Тип поля (date или datetime)
	 * @return string Форматированная дата или несколько дат через запятую
	 */
	private function processValue(string $value, string $fieldType): string
	{
		// Проверяем является ли значение сериализованным массивом
		if ($this->isSerialized($value)) {
			$unserialized = @unserialize($value);
			
			if (is_array($unserialized)) {
				// Обрабатываем каждый элемент массива
				$dates = [];
				foreach ($unserialized as $dateValue) {
					if ($dateValue !== null && $dateValue !== '') {
						$formatted = $this->formatDate((string)$dateValue, $fieldType);
						if ($formatted !== '') {
							$dates[] = $formatted;
						}
					}
				}
				
				// Удаляем дубликаты и сортируем
				$dates = array_unique($dates);
				sort($dates);
				
				return implode(', ', $dates);
			}
		}
		
		// Обычное значение даты
		return $this->formatDate($value, $fieldType);
	}

	/**
	 * Проверяет является ли строка сериализованными данными
	 *
	 * @param string $value Проверяемое значение
	 * @return bool
	 */
	private function isSerialized(string $value): bool
	{
		// Проверяем типичные паттерны сериализации PHP
		if ($value === 'b:0;' || $value === 'b:1;') {
			return true;
		}
		
		if ($value === 'N;') {
			return true;
		}
		
		// Проверяем паттерны массивов, строк и объектов
		if (preg_match('/^(a|O|s):\d+:/', $value)) {
			return true;
		}
		
		return false;
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