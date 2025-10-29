<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Страна"
 * Реализован через предварительную загрузку справочника стран.
 */
class CountryDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/**
	 * @var array<int, string|null> Кэш данных, где ключ - ID сделки, значение - название страны
	 */
	private array $data = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Страна';

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
			// --- ЭТАП 1: Загружаем весь справочник стран в память для быстрого доступа ---
			$countriesMap = [];
			$sqlCountries = "SELECT country_id, title_ru FROM brs_countries";

			$resultCountries = mysqli_query($this->connection, $sqlCountries);
			if (!$resultCountries) {
				throw new ReportException("Ошибка загрузки справочника стран: " . mysqli_error($this->connection));
			}

			// Создаем карту [ID страны => Название страны]
			while ($row = mysqli_fetch_assoc($resultCountries)) {
				$countriesMap[$row['country_id']] = $row['title_ru'];
			}
			mysqli_free_result($resultCountries);


			// --- ЭТАП 2: Загружаем ID сделок и ID их стран, а затем сопоставляем со справочником в PHP ---
			$sqlDeals = "SELECT VALUE_ID, UF_DEAL_COUNTRY FROM b_uts_crm_deal WHERE UF_DEAL_COUNTRY IS NOT NULL AND UF_DEAL_COUNTRY > 0";

			$resultDeals = mysqli_query($this->connection, $sqlDeals);
			if (!$resultDeals) {
				throw new ReportException("Ошибка загрузки данных по сделкам для стран: " . mysqli_error($this->connection));
			}

			while ($dealRow = mysqli_fetch_assoc($resultDeals)) {
				$dealId = $dealRow['VALUE_ID'];
				$countryId = $dealRow['UF_DEAL_COUNTRY'];

				// Подставляем название страны из карты, созданной на первом этапе.
				// Оператор `??` защитит от ошибок, если в сделке указан ID страны, которой нет в справочнике.
				$countryName = $countriesMap[$countryId] ?? null;

				$this->data[$dealId] = $countryName;
			}
			mysqli_free_result($resultDeals);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных по странам: " . $e->getMessage(), 0, $e);
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
		$countryName = $this->data[$dealId] ?? '';

		return [
			self::COLUMN_NAME => $countryName
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