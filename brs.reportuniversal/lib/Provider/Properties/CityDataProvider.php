<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Город"
 * Реализован через предварительную загрузку справочника городов.
 */
class CityDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/**
	 * @var array<int, string|null> Кэш данных, где ключ - ID сделки, значение - название города
	 */
	private array $data = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Город';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/** Предзагружает данные в два этапа */
	public function preloadData(): void
	{
		try {
			// --- ЭТАП 1: Загружаем весь справочник городов в память для быстрого доступа ---
			$citiesMap = [];
			$sqlCities = "SELECT city_id, title_ru FROM brs_cities";

			$resultCities = mysqli_query($this->connection, $sqlCities);
			if (!$resultCities) {
				throw new ReportException("Ошибка загрузки справочника городов: " . mysqli_error($this->connection));
			}

			// Создаем карту [ID города => Название города]
			while ($row = mysqli_fetch_assoc($resultCities)) {
				$citiesMap[$row['city_id']] = $row['title_ru'];
			}
			mysqli_free_result($resultCities);


			// --- ЭТАП 2: Загружаем ID сделок и ID их городов, а затем сопоставляем со справочником в PHP ---
			$sqlDeals = "SELECT VALUE_ID, UF_DEAL_CITY FROM b_uts_crm_deal WHERE UF_DEAL_CITY IS NOT NULL AND UF_DEAL_CITY > 0";

			$resultDeals = mysqli_query($this->connection, $sqlDeals);
			if (!$resultDeals) {
				throw new ReportException("Ошибка загрузки данных по сделкам для городов: " . mysqli_error($this->connection));
			}

			while ($dealRow = mysqli_fetch_assoc($resultDeals)) {
				$dealId = $dealRow['VALUE_ID'];
				$cityId = $dealRow['UF_DEAL_CITY'];

				// Подставляем название города из карты, созданной на первом этапе.
				$cityName = $citiesMap[$cityId] ?? null;

				$this->data[$dealId] = $cityName;
			}
			mysqli_free_result($resultDeals);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных по городам: " . $e->getMessage(), 0, $e);
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
		$cityName = $this->data[$dealId] ?? '';

		return [
			self::COLUMN_NAME => $cityName
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