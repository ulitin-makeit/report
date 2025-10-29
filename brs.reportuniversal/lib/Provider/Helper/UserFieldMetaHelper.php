<?php

namespace Brs\ReportUniversal\Provider\Helper;

use Brs\ReportUniversal\Exception\ReportException;

/**
 * Хелпер для работы с метаданными пользовательских полей
 * Загружает информацию о UF полях из таблицы b_user_field
 */
class UserFieldMetaHelper
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Получает информацию о пользовательском поле
	 *
	 * @param string $fieldCode Код поля (например: UF_CRM_CATEGORY)
	 * @return array|null Информация о поле или null если не найдено
	 * @throws ReportException
	 */
	public function getFieldInfo(string $fieldCode): ?array
	{
		$sql = "
            SELECT 
                ID,
                FIELD_NAME,
                ENTITY_ID,
                USER_TYPE_ID,
                SORT,
                MULTIPLE,
                SETTINGS
            FROM b_user_field 
            WHERE FIELD_NAME = ?
        ";

		$stmt = mysqli_prepare($this->connection, $sql);
		if (!$stmt) {
			throw new ReportException("Ошибка подготовки запроса: " . mysqli_error($this->connection));
		}

		mysqli_stmt_bind_param($stmt, 's', $fieldCode);
		mysqli_stmt_execute($stmt);

		$result = mysqli_stmt_get_result($stmt);
		$row = mysqli_fetch_assoc($result);

		mysqli_stmt_close($stmt);

		if (!$row) {
			return null;
		}

		return [
			'id' => (int)$row['ID'],
			'name' => $row['FIELD_NAME'],
			'entity_id' => $row['ENTITY_ID'],
			'type' => $row['USER_TYPE_ID'],
			'multiple' => $row['MULTIPLE'] === 'Y',
			'sort' => (int)$row['SORT'],
			'settings' => $row['SETTINGS'] ? unserialize($row['SETTINGS']) : []
		];
	}

	/**
	 * Проверяет существование поля
	 *
	 * @param string $fieldCode Код поля
	 * @return bool
	 */
	public function fieldExists(string $fieldCode): bool
	{
		return $this->getFieldInfo($fieldCode) !== null;
	}
}