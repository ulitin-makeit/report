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

		return $this->loadSingleData($fieldCode);
	}

	/**
	 * Загружает данные для поля типа crm
	 * Обрабатывает как обычные значения, так и сериализованные массивы
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
				$data[$dealId] = $this->processValue($value);
			}
		}

		mysqli_free_result($result);
		return $data;
	}

	/**
	 * Обрабатывает значение поля
	 * Определяет является ли значение сериализованным массивом или обычным значением
	 *
	 * @param string $value Исходное значение из БД
	 * @return string Очищенное значение или несколько значений через запятую
	 */
	private function processValue(string $value): string
	{
		// Проверяем является ли значение сериализованным массивом
		if ($this->isSerialized($value)) {
			$unserialized = @unserialize($value);

			if (is_array($unserialized)) {
				// Обрабатываем каждый элемент массива
				$values = [];
				foreach ($unserialized as $crmValue) {
					if ($crmValue !== null && $crmValue !== '') {
						$cleaned = $this->cleanValue((string)$crmValue);
						if ($cleaned !== '') {
							$values[] = $cleaned;
						}
					}
				}

				// Удаляем дубликаты
				$values = array_unique($values);

				return implode(', ', $values);
			}
		}

		// Обычное значение
		return $this->cleanValue($value);
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