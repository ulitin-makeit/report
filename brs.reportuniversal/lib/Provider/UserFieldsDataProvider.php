<?php

namespace Brs\ReportUniversal\Provider;

use Brs\ReportUniversal\Exception\ReportException;
use Brs\ReportUniversal\Provider\Helper\UserFieldMetaHelper;
use Brs\ReportUniversal\Provider\Helper\EnumFieldHelper;
use Brs\ReportUniversal\Provider\Helper\StringFieldHelper;
use Brs\ReportUniversal\Provider\Helper\OrmListFindFieldHelper;

/**
 * Утилитарный класс для загрузки данных пользовательских полей (UF_*)
 * Координирует работу хелперов для различных типов полей
 * Используется другими provider'ами для получения готовых данных по CODE поля
 */
class UserFieldsDataProvider
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var UserFieldMetaHelper Хелпер для метаданных */
	private UserFieldMetaHelper $metaHelper;

	/** @var EnumFieldHelper Хелпер для полей типа список */
	private EnumFieldHelper $enumHelper;

	/** @var StringFieldHelper Хелпер для строковых полей */
	private StringFieldHelper $stringHelper;

	/** @var OrmListFindFieldHelper Хелпер для полей типа orm_list_find */
	private OrmListFindFieldHelper $ormHelper;

	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
		$this->initHelpers();
	}

	/**
	 * Инициализирует хелперы
	 */
	private function initHelpers(): void
	{
		$this->metaHelper = new UserFieldMetaHelper($this->connection);
		$this->enumHelper = new EnumFieldHelper($this->connection);
		$this->stringHelper = new StringFieldHelper($this->connection);
		$this->ormHelper = new OrmListFindFieldHelper($this->connection);
	}

	/**
	 * Загружает данные для указанного UF поля
	 *
	 * @param string $fieldCode Код поля (например: UF_CRM_CATEGORY)
	 * @return array Ассоциативный массив [deal_id => formatted_value]
	 * @throws ReportException
	 */
	public function loadFieldData(string $fieldCode): array
	{
		// Получаем информацию о поле через метахелпер
		$fieldInfo = $this->metaHelper->getFieldInfo($fieldCode);

		if (!$fieldInfo) {
			return []; // Поле не найдено
		}

		// Загружаем данные через соответствующий хелпер
		try {
			switch ($fieldInfo['type']) {
				case 'enumeration':
					return $this->enumHelper->loadFieldData($fieldCode, $fieldInfo);

				case 'string':
				case 'integer':
				case 'datetime':
				case 'date':
					return $this->stringHelper->loadFieldData($fieldCode, $fieldInfo);

				case 'orm_list_find':
					return $this->ormHelper->loadFieldData($fieldCode, $fieldInfo);

				default:
					throw new ReportException("Неподдерживаемый тип поля: " . $fieldInfo['type']);
			}
		} catch (\Exception $e) {
			throw new ReportException("Ошибка загрузки данных поля {$fieldCode}: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Проверяет существование поля
	 *
	 * @param string $fieldCode Код поля
	 * @return bool
	 */
	public function fieldExists(string $fieldCode): bool
	{
		return $this->metaHelper->fieldExists($fieldCode);
	}
}