<?php

namespace Brs\ReportUniversal\Provider\Helper;

use Brs\ReportUniversal\Exception\ReportException;

/**
 * Хелпер для работы с пользовательскими полями типа "строка", "число", "дата" и "дата/время"
 * 
 * Поддерживаемые типы полей:
 * - string (строка)
 * - integer (число)
 * - date (дата)
 * - datetime (дата и время)
 * 
 * Особенности:
 * - Автоматически определяет структуру таблиц для множественных полей
 * - Поддерживает разные варианты хранения datetime в Bitrix (VALUE и VALUE_DATE)
 * - Форматирует даты в читаемый вид (DD.MM.YYYY HH:MM:SS)
 * - Логирует ошибки для диагностики проблем
 */
class StringFieldHelper
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
	 * Загружает данные для строкового, числового или datetime поля
	 *
	 * Основной метод для получения данных поля любого поддерживаемого типа.
	 * Автоматически определяет одиночное или множественное поле и вызывает соответствующий метод.
	 *
	 * @param string $fieldCode Код поля (например: UF_CRM_COMMENT, UF_CRM_COUNT, UF_CRM_DATE)
	 * @param array $fieldInfo Информация о поле из UserFieldMetaHelper, должна содержать:
	 *                         - 'type': тип поля (string|integer|datetime|date)
	 *                         - 'multiple': булево значение (одиночное или множественное)
	 * @return array Ассоциативный массив [deal_id => value] или [deal_id => 'value1, value2']
	 * @throws ReportException При ошибке выполнения SQL запроса или неподдерживаемом типе
	 */
	public function loadFieldData(string $fieldCode, array $fieldInfo): array
	{
		// Проверка поддерживаемых типов
		if (!in_array($fieldInfo['type'], ['string', 'integer', 'datetime', 'date'])) {
			throw new ReportException(
				"Неподдерживаемый тип поля: {$fieldInfo['type']}. " .
				"Ожидается string, integer, date или datetime."
			);
		}

		// Выбираем метод загрузки в зависимости от множественности поля
		if ($fieldInfo['multiple']) {
			return $this->loadMultipleData($fieldCode, $fieldInfo['type']);
		} else {
			return $this->loadSingleData($fieldCode, $fieldInfo['type']);
		}
	}

	/**
	 * Загружает данные для одиночного поля
	 *
	 * Одиночные поля хранятся в основной таблице b_uts_crm_deal
	 * в колонке с именем, соответствующим коду поля.
	 *
	 * @param string $fieldCode Код поля
	 * @param string $fieldType Тип поля (string|integer|datetime|date)
	 * @return array Ассоциативный массив [deal_id => cleaned_value]
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	private function loadSingleData(string $fieldCode, string $fieldType): array
	{
		$sql = "SELECT VALUE_ID as DEAL_ID, `{$fieldCode}` as FIELD_VALUE FROM b_uts_crm_deal";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException(
				"Ошибка загрузки данных поля {$fieldCode}: " . mysqli_error($this->connection)
			);
		}

		$data = [];
		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];
			$value = $row['FIELD_VALUE'];

			if ($value === null || $value === '') {
				$data[$dealId] = '';
			} else {
				$data[$dealId] = $this->cleanValue((string)$value, $fieldType);
			}
		}

		mysqli_free_result($result);
		return $data;
	}

	/**
	 * Загружает данные для множественного поля
	 *
	 * Множественные поля хранятся в отдельных таблицах вида b_uts_crm_deal_{field_code}.
	 * Для datetime полей использует специальную обработку из-за особенностей хранения в Bitrix.
	 *
	 * @param string $fieldCode Код поля
	 * @param string $fieldType Тип поля (string|integer|datetime|date)
	 * @return array Ассоциативный массив [deal_id => 'value1, value2, value3']
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	private function loadMultipleData(string $fieldCode, string $fieldType): array
	{
		$tableName = "b_uts_crm_deal_" . strtolower($fieldCode);
		
		// Datetime поля требуют особой обработки
		if ($fieldType === 'datetime' || $fieldType === 'date') {
			return $this->loadMultipleDatetimeData($tableName, $fieldCode, $fieldType);
		}
		
		// Для обычных полей (string, integer)
		$sql = "SELECT VALUE_ID as DEAL_ID, VALUE as FIELD_VALUE FROM `{$tableName}` ORDER BY VALUE_ID, ID";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			// Таблица может не существовать если поле никогда не заполнялось
			error_log("[StringFieldHelper] Таблица {$tableName} не найдена или ошибка доступа: " . mysqli_error($this->connection));
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
				$dealValues[$dealId][] = $this->cleanValue((string)$value, $fieldType);
			}
		}

		mysqli_free_result($result);

		// Объединяем множественные значения через запятую
		$data = [];
		foreach ($dealValues as $dealId => $values) {
			$uniqueValues = array_filter(array_unique($values));
			$data[$dealId] = implode(', ', $uniqueValues);
		}

		return $data;
	}

	/**
	 * Загружает данные для множественного datetime/date поля
	 *
	 * Особенности datetime полей в Bitrix24:
	 * - Могут использовать колонку VALUE_DATE вместо VALUE
	 * - Структура таблицы может отличаться в разных версиях
	 * - Требуется проверка структуры перед запросом
	 *
	 * @param string $tableName Название таблицы (b_uts_crm_deal_{field_code})
	 * @param string $fieldCode Код поля для логирования
	 * @param string $fieldType Тип поля (datetime|date)
	 * @return array Ассоциативный массив [deal_id => 'date1, date2, date3']
	 */
	private function loadMultipleDatetimeData(string $tableName, string $fieldCode, string $fieldType): array
	{
		// Шаг 1: Проверяем существование таблицы
		$checkSql = "SHOW TABLES LIKE '{$tableName}'";
		$checkResult = mysqli_query($this->connection, $checkSql);
		
		if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
			error_log("[StringFieldHelper] Таблица {$tableName} для поля {$fieldCode} не существует");
			return [];
		}
		mysqli_free_result($checkResult);

		// Шаг 2: Получаем структуру таблицы
		$columnsSql = "SHOW COLUMNS FROM `{$tableName}`";
		$columnsResult = mysqli_query($this->connection, $columnsSql);
		
		if (!$columnsResult) {
			error_log("[StringFieldHelper] Ошибка получения структуры таблицы {$tableName}: " . mysqli_error($this->connection));
			return [];
		}

		$availableColumns = [];
		while ($col = mysqli_fetch_assoc($columnsResult)) {
			$availableColumns[] = $col['Field'];
		}
		mysqli_free_result($columnsResult);

		// Шаг 3: Определяем какую колонку использовать для значения
		// В разных версиях Bitrix datetime может храниться в VALUE или VALUE_DATE
		$valueColumn = 'VALUE';
		if (in_array('VALUE_DATE', $availableColumns)) {
			$valueColumn = 'VALUE_DATE';
		}

		// Шаг 4: Проверяем наличие необходимых колонок
		if (!in_array('VALUE_ID', $availableColumns)) {
			error_log("[StringFieldHelper] В таблице {$tableName} отсутствует обязательная колонка VALUE_ID. Доступные: " . implode(', ', $availableColumns));
			return [];
		}

		if (!in_array($valueColumn, $availableColumns)) {
			error_log("[StringFieldHelper] В таблице {$tableName} отсутствует колонка {$valueColumn}. Доступные: " . implode(', ', $availableColumns));
			return [];
		}

		// Шаг 5: Загружаем данные
		$sql = "SELECT VALUE_ID as DEAL_ID, `{$valueColumn}` as FIELD_VALUE FROM `{$tableName}` ORDER BY VALUE_ID, ID";
		$result = mysqli_query($this->connection, $sql);

		if (!$result) {
			error_log("[StringFieldHelper] Ошибка выполнения запроса для {$tableName}: " . mysqli_error($this->connection));
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
				
				$cleanedValue = $this->cleanValue((string)$value, $fieldType);
				if ($cleanedValue !== '') {
					$dealValues[$dealId][] = $cleanedValue;
				}
			}
		}

		mysqli_free_result($result);

		// Шаг 6: Объединяем результаты
		$data = [];
		foreach ($dealValues as $dealId => $values) {
			// Сортируем даты для стабильного порядка
			sort($values);
			$uniqueValues = array_filter(array_unique($values));
			$data[$dealId] = implode(', ', $uniqueValues);
		}

		return $data;
	}

	/**
	 * Очищает и форматирует значение для записи в CSV
	 *
	 * Обработка зависит от типа поля:
	 * - integer: оставляет только цифры и знаки, валидирует число
	 * - datetime/date: преобразует в формат DD.MM.YYYY HH:MM:SS
	 * - string: удаляет HTML, лишние пробелы, декодирует entities
	 *
	 * @param string $value Исходное значение из БД
	 * @param string $fieldType Тип поля (string|integer|datetime|date)
	 * @return string Очищенное значение готовое для записи в CSV
	 */
	private function cleanValue(string $value, string $fieldType): string
	{
		// Обработка числовых полей
		if ($fieldType === 'integer') {
			// Оставляем только цифры, плюс и минус
			$cleaned = preg_replace('/[^0-9\-+]/', '', $value);

			// Если после очистки ничего не осталось
			if ($cleaned === '') {
				return '';
			}

			// Проверяем что это валидное число
			if (is_numeric($cleaned)) {
				return $cleaned;
			} else {
				return '';
			}
		}
		
		// Обработка полей даты и времени
		if ($fieldType === 'datetime' || $fieldType === 'date') {
			if (empty($value)) {
				return '';
			}

			// Пытаемся распарсить дату
			$timestamp = strtotime($value);
			if ($timestamp === false) {
				// Если не удалось распарсить - возвращаем как есть
				return trim($value);
			}

			// Форматируем в читаемый формат
			return date('d.m.Y H:i:s', $timestamp);
		}
		
		// Обработка строковых полей
		// Удаляем переносы строк и лишние пробелы
		$cleaned = preg_replace('/\s+/', ' ', $value);
		$cleaned = trim($cleaned);

		// Удаляем HTML теги если есть
		$cleaned = strip_tags($cleaned);

		// Декодируем HTML entities (например &nbsp; в пробел)
		$cleaned = html_entity_decode($cleaned, ENT_QUOTES, 'UTF-8');

		return $cleaned;
	}
}