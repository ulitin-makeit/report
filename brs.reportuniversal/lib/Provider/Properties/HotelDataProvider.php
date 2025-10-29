<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Отель"
 */
class HotelDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/**
	 * @var array<int, string|null> Кэш данных, где ключ - ID сделки, значение - название отеля
	 */
	private array $data = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Отель';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/** Предзагружает данные */
	public function preloadData(): void
	{
		try {
			// Объединяем запросы в один для эффективности.
			// Выбираем ID сделки (VALUE_ID) и соответствующее ему название отеля (NAME)
			// через связь по полю UF_CRM_DEAL_ORM_HOTEL.
			$sql = "
				SELECT
					uts.VALUE_ID,
					hotel.NAME
				FROM
					b_uts_crm_deal AS uts
				LEFT JOIN
					brs_financial_card_hotel AS hotel ON uts.UF_CRM_DEAL_ORM_HOTEL = hotel.ID
				WHERE
				    uts.UF_CRM_DEAL_ORM_HOTEL IS NOT NULL AND uts.UF_CRM_DEAL_ORM_HOTEL > 0
			";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки данных по отелям: " . mysqli_error($this->connection));
			}

			// Заполняем массив $this->data, где ключ - это ID сделки, а значение - название отеля.
			while ($row = mysqli_fetch_assoc($result)) {
				// $row['VALUE_ID'] - это ID сделки (из b_uts_crm_deal)
				// $row['NAME'] - это название отеля (из brs_financial_card_hotel)
				$this->data[$row['VALUE_ID']] = $row['NAME'];
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных по отелям: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Заполняет данными сделку
	 *
	 * @param array $dealData Данные сделки
	 * @param int $dealId ID сделки
	 * @return array
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$hotelName = $this->data[$dealId] ?? '';

		return [
			self::COLUMN_NAME => $hotelName
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