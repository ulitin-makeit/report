<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Клиент"
 * Преобразует CONTACT_ID в ФИО контакта из b_crm_contact
 */
class ClientDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные контактов [contact_id => full_name] */
	private array $contacts = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Клиент';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные контактов
	 */
	public function preloadData(): void
	{
		try {
			$sql = "
                SELECT 
                    ID, 
                    CONCAT(LAST_NAME, ' ', NAME, ' ', SECOND_NAME) as FULL_NAME
                FROM b_crm_contact
            ";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки контактов: " . mysqli_error($this->connection));
			}

			while ($row = mysqli_fetch_assoc($result)) {
				// Убираем лишние пробелы из ФИО
				$fullName = preg_replace('/\s+/', ' ', trim($row['FULL_NAME']));
				$this->contacts[(int)$row['ID']] = $fullName;
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки контактов: " . $e->getMessage(), 0, $e);
		}
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

		if ($contactId && isset($this->contacts[(int)$contactId])) {
			$contactName = $this->contacts[(int)$contactId];
		} else {
			$contactName = '';
		}

		return [
			self::COLUMN_NAME => $contactName
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