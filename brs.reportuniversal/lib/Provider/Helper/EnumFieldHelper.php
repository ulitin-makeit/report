<?php

namespace Brs\ReportUniversal\Provider\Helper;

use Brs\ReportUniversal\Exception\ReportException;

/**
 * Хелпер для работы с пользовательскими полями типа "список" (enumeration)
 * Загружает варианты списков и значения полей для сделок
 */
class EnumFieldHelper
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
	 * Загружает данные для поля типа enumeration
	 *
	 * @param string $fieldCode Код поля (например: UF_CRM_CATEGORY)
	 * @param array $fieldInfo Информация о поле из UserFieldMetaHelper
	 * @return array Ассоциативный массив [deal_id => formatted_value]
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	public function loadFieldData(string $fieldCode, array $fieldInfo): array
	{
		// Загружаем варианты списка
		$enumValues = $this->loadEnumValues($fieldCode);

		if ($fieldInfo['multiple']) {
			return $this->loadMultipleEnumData($fieldCode, $enumValues);
		} else {
			return $this->loadSingleEnumData($fieldCode, $enumValues);
		}
	}

	/**
	 * Загружает варианты для поля типа список
	 *
	 * @param string $fieldCode Код поля
	 * @return array Ассоциативный массив [enum_id => ['value' => string, 'sort' => int]]
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	private function loadEnumValues(string $fieldCode): array
	{
		$sql = "
            SELECT 
                ue.ID as ENUM_ID,
                ue.VALUE,
                ue.SORT
            FROM b_user_field uf
            INNER JOIN b_user_field_enum ue ON uf.ID = ue.USER_FIELD_ID
            WHERE uf.FIELD_NAME = ?
            ORDER BY ue.SORT, ue.VALUE
        ";

		$stmt = mysqli_prepare($this->connection, $sql);
		if (!$stmt) {
			throw new ReportException("Ошибка подготовки запроса для загрузки вариантов списка: " . mysqli_error($this->connection));
		}

		mysqli_stmt_bind_param($stmt, 's', $fieldCode);
		mysqli_stmt_execute($stmt);

		$result = mysqli_stmt_get_result($stmt);
		$enumValues = [];

		while ($row = mysqli_fetch_assoc($result)) {
			$enumValues[$row['ENUM_ID']] = [
				'value' => $row['VALUE'],
				'sort' => (int)$row['SORT']
			];
		}

		mysqli_stmt_close($stmt);

		return $enumValues;
	}

	/**
	 * Загружает данные для одиночного поля типа список
	 *
	 * @param string $fieldCode Код поля
	 * @param array $enumValues Варианты списка из loadEnumValues()
	 * @return array Ассоциативный массив [deal_id => enum_text_value]
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	private function loadSingleEnumData(string $fieldCode, array $enumValues): array
	{
		$sql = "SELECT VALUE_ID as DEAL_ID, `{$fieldCode}` as FIELD_VALUE FROM b_uts_crm_deal";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки данных поля {$fieldCode}: " . mysqli_error($this->connection));
		}

		$data = [];
		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];
			$enumId = $row['FIELD_VALUE'];

			if ($enumId && isset($enumValues[$enumId])) {
				$data[$dealId] = $enumValues[$enumId]['value'];
			} else {
				$data[$dealId] = '';
			}
		}

		mysqli_free_result($result);
		return $data;
	}

	/**
	 * Загружает данные для множественного поля типа список
	 *
	 * @param string $fieldCode Код поля
	 * @param array $enumValues Варианты списка из loadEnumValues()
	 * @return array Ассоциативный массив [deal_id => 'value1, value2, value3']
	 * @throws ReportException При ошибке выполнения SQL запроса
	 */
	private function loadMultipleEnumData(string $fieldCode, array $enumValues): array
	{
		$tableName = "b_uts_crm_deal_" . strtolower($fieldCode);
		$sql = "SELECT VALUE_ID as DEAL_ID, VALUE as FIELD_VALUE FROM `{$tableName}`";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			// Таблица может не существовать если поле не использовалось
			return [];
		}

		$dealValues = [];
		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];
			$enumId = $row['FIELD_VALUE'];

			if ($enumId && isset($enumValues[$enumId])) {
				if (!isset($dealValues[$dealId])) {
					$dealValues[$dealId] = [];
				}
				$dealValues[$dealId][] = [
					'value' => $enumValues[$enumId]['value'],
					'sort' => $enumValues[$enumId]['sort']
				];
			}
		}

		mysqli_free_result($result);

		// Сортируем и объединяем множественные значения
		$data = [];
		foreach ($dealValues as $dealId => $values) {
			// Сортируем по sort, потом по значению
			usort($values, function($a, $b) {
				if ($a['sort'] === $b['sort']) {
					return strcmp($a['value'], $b['value']);
				}
				return $a['sort'] <=> $b['sort'];
			});

			// Извлекаем только значения и объединяем через запятую
			$sortedValues = array_column($values, 'value');
			$data[$dealId] = implode(', ', $sortedValues);
		}

		return $data;
	}
}