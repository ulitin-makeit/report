<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Канал связи"
 * Преобразует SOURCE_ID в название Канал связи из b_crm_status
 */
class CommunicationChannelDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные Канал связи [SOURCE_ID => name] */
	private array $source = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Канал связи';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные SOURCE сделок
	 */
	public function preloadData(): void
	{
		try {
			$sql = "
                SELECT 
                    STATUS_ID, 
                    NAME
                FROM b_crm_status 
            ";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки SOURCE: " . mysqli_error($this->connection));
			}

			while ($row = mysqli_fetch_assoc($result)) {
				$this->source[$row['STATUS_ID']] = $row['NAME'];
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки SOURCE: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Заполняет данными сделку
	 *
	 * @param array $dealData Данные сделки (содержит SOURCE_ID)
	 * @param int $dealId ID сделки
	 * @return array
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$sourceId = $dealData['SOURCE_ID'] ?? null;

		if ($sourceId && isset($this->source[$sourceId])) {
			$stageName = $this->source[$sourceId];
		} else {
			$stageName = '';
		}

		return [
			self::COLUMN_NAME => $stageName
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