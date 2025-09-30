<?php

namespace Brs\ReportUniversal\Provider\Helper;

use Brs\ReportUniversal\Exception\ReportException;

/**
 * Хелпер для работы с пользовательскими полями типа "crm"
 * Загружает значения полей связи с CRM сущностями (контакты, компании, лиды)
 */
class CrmFieldHelper
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
	 * Загружает данные для поля типа crm
	 *
	 * @param string $fieldCode Код поля (например: UF_CRM_CONTACT)
	 * @param array $fieldInfo Информация о поле из UserFieldMetaHelper
	 * @return array Ассоциативный массив [deal_id => value]
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	public function loadFieldData(string $fieldCode, array $fieldInfo): array
	{
		// Поддерживаем только тип crm
		if ($fieldInfo['type'] !== 'crm') {
			throw new ReportException("Неподдерживаемый тип поля: {$fieldInfo['type']}. Ожидается crm.");
		}

		if ($fieldInfo['multiple']) {
			return $this->loadMultipleData($fieldCode);
		} else {
			return $this->loadSingleData($fieldCode);
		}
	}

	/**
	 * Загружает данные для одиночного поля типа crm
	 *
	 * @param string $fieldCode Код поля
	 * @return array Ассоциативный массив [deal_id => crm_value]
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	private function loadSingleData(string $fieldCode): array
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
				$data[$dealId] = $this->cleanValue((string)$value);
			}
		}

		mysqli_free_result($result);
		return $data;
	}

	/**
	 * Загружает данные для множественного поля типа crm
	 *
	 * @param string $fieldCode Код поля
	 * @return array Ассоциативный массив [deal_id => 'value1, value2, value3']
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	private function loadMultipleData(string $fieldCode): array
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
				$dealValues[$dealId][] = $this->cleanValue((string)$value);
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
	 * Очищает значение CRM поля для корректного отображения в CSV
	 * Значения могут быть в формате: "C_123" (контакт), "CO_456" (компания), "L_789" (лид)
	 *
	 * @param string $value Исходное значение
	 * @return string Очищенное значение для записи в CSV
	 */
	private function cleanValue(string $value): string
	{
		// Удаляем переносы строк и лишние пробелы
		$cleaned = preg_replace('/\s+/', ' ', $value);
		$cleaned = trim($cleaned);

		// Удаляем HTML теги если есть
		$cleaned = strip_tags($cleaned);

		// Декодируем HTML entities
		$cleaned = html_entity_decode($cleaned, ENT_QUOTES, 'UTF-8');

		return $cleaned;
	}
}