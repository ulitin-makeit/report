<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Ответственный"
 * Преобразует ASSIGNED_BY_ID в ФИО пользователя из b_user
 */
class ResponsibleUserDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные пользователей [user_id => full_name] */
	private array $users = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Ответственный';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные пользователей
	 */
	public function preloadData(): void
	{
		try {
			$sql = "
                SELECT 
                    ID, 
                    CONCAT(LAST_NAME, ' ', NAME, ' ', SECOND_NAME) as FULL_NAME
                FROM b_user
                WHERE ACTIVE = 'Y'
            ";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки пользователей: " . mysqli_error($this->connection));
			}

			while ($row = mysqli_fetch_assoc($result)) {
				// Убираем лишние пробелы из ФИО
				$fullName = preg_replace('/\s+/', ' ', trim($row['FULL_NAME']));
				$this->users[(int)$row['ID']] = $fullName;
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки пользователей: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Заполняет данными сделку
	 *
	 * @param array $dealData Данные сделки (содержит ASSIGNED_BY_ID)
	 * @param int $dealId ID сделки
	 * @return array
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$userId = $dealData['ASSIGNED_BY_ID'] ?? null;

		if ($userId && isset($this->users[(int)$userId])) {
			$userName = $this->users[(int)$userId];
		} else {
			$userName = '';
		}

		return [
			self::COLUMN_NAME => $userName
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