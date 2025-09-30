<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Тип клиента"
 * Преобразует CONTACT_ID -> TYPE_ID -> название типа из b_crm_status
 */
class ClientTypeDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные типов контактов [type_id => type_name] из b_crm_status */
	private array $contactTypes = [];

	/** @var array Данные привязки контактов к типам [contact_id => type_id] */
	private array $contactTypeMapping = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Тип клиента';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные типов контактов и их привязки
	 */
	public function preloadData(): void
	{
		try {
			// Загружаем справочник типов контактов из b_crm_status
			$this->loadContactTypes();

			// Загружаем привязку контактов к типам
			$this->loadContactTypeMapping();

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки типов контактов: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Загружает справочник типов контактов из b_crm_status
	 *
	 * @return void
	 * @throws ReportException
	 */
	private function loadContactTypes(): void
	{
		$sql = "
            SELECT 
                STATUS_ID, 
                NAME,
                SORT
            FROM b_crm_status 
            WHERE ENTITY_ID = 'CONTACT_TYPE'
            ORDER BY SORT, NAME
        ";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки типов контактов: " . mysqli_error($this->connection));
		}

		while ($row = mysqli_fetch_assoc($result)) {
			$this->contactTypes[$row['STATUS_ID']] = $row['NAME'];
		}

		mysqli_free_result($result);
	}

	/**
	 * Загружает привязку контактов к типам из b_crm_contact
	 *
	 * @return void
	 * @throws ReportException
	 */
	private function loadContactTypeMapping(): void
	{
		$sql = "
            SELECT 
                ID as CONTACT_ID,
                TYPE_ID
            FROM b_crm_contact
            WHERE TYPE_ID IS NOT NULL AND TYPE_ID != ''
        ";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки привязки типов контактов: " . mysqli_error($this->connection));
		}

		while ($row = mysqli_fetch_assoc($result)) {
			$this->contactTypeMapping[(int)$row['CONTACT_ID']] = $row['TYPE_ID'];
		}

		mysqli_free_result($result);
	}

	/**
	 * Заполняет данными сделку
	 *
	 * @param array $dealData Данные сделки (содержит CONTACT_ID)
	 * @param int $dealId ID сделки
	 * @return array
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$contactId = $dealData['CONTACT_ID'] ?? null;
		$typeName = '';

		if ($contactId) {
			$contactIdInt = (int)$contactId;
			
			// Получаем TYPE_ID для контакта
			if (isset($this->contactTypeMapping[$contactIdInt])) {
				$typeId = $this->contactTypeMapping[$contactIdInt];
				
				// Получаем название типа
				if (isset($this->contactTypes[$typeId])) {
					$typeName = $this->contactTypes[$typeId];
				}
			}
		}

		return [
			self::COLUMN_NAME => $typeName
		];
	}

	/**
	 * Возвращает названия колонок
	 */
	public function getColumnNames(): array
	{
		return [self::COLUMN_NAME];
	}
}