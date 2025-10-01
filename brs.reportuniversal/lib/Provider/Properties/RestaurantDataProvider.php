<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Ресторан"
 */
class RestaurantDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	private array $data = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Ресторан';

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
			$sql = "SELECT UF_CRM_DEAL_RESTAURANT, VALUE_ID FROM b_uts_crm_deal";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки ресторанов: " . mysqli_error($this->connection));
			}

			while ($row = mysqli_fetch_assoc($result)) {
				$unserialized = @unserialize($row['UF_CRM_DEAL_RESTAURANT']);
				if ($unserialized) {
					$this->data[$row['VALUE_ID']] = implode(',', $unserialized);
				}
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки стадий: " . $e->getMessage(), 0, $e);
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
		if (isset($this->data[$dealId])) {
			$restaurant = $this->data[$dealId];
		} else {
			$restaurant = '';
		}

		return [
			self::COLUMN_NAME => $restaurant
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
