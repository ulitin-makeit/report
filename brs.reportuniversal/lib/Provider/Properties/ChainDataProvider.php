<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Цепочка"
 */
class ChainDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/**
	 * @var array<int, string|null> Кэш данных, где ключ - ID сделки, значение - название цепочки
	 */
	private array $data = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Цепочка';

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
			// Объединяем три таблицы для получения названия цепочки отелей для каждой сделки.
			// 1. b_uts_crm_deal (uts) - основная таблица со сделками.
			// 2. brs_financial_card_hotel (hotel) - справочник отелей (связь по UF_CRM_DEAL_ORM_HOTEL).
			// 3. brs_financial_card_hotel_chain (chain) - справочник цепочек (связь по hotel.CHAIN_ID).
			$sql = "
				SELECT
					uts.VALUE_ID,
					chain.VALUE AS chain_name
				FROM
					b_uts_crm_deal AS uts
				LEFT JOIN
					brs_financial_card_hotel AS hotel ON uts.UF_CRM_DEAL_ORM_HOTEL = hotel.ID
				LEFT JOIN
				    brs_financial_card_hotel_chain AS chain ON hotel.CHAIN_ID = chain.ID
				WHERE
				    uts.UF_CRM_DEAL_ORM_HOTEL IS NOT NULL AND uts.UF_CRM_DEAL_ORM_HOTEL > 0
			";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки данных по цепочкам отелей: " . mysqli_error($this->connection));
			}

			// Заполняем массив $this->data, где ключ - это ID сделки, а значение - название цепочки.
			while ($row = mysqli_fetch_assoc($result)) {
				// $row['VALUE_ID'] - это ID сделки (из b_uts_crm_deal)
				// $row['chain_name'] - это название цепочки (из brs_financial_card_hotel_chain.VALUE)
				$this->data[$row['VALUE_ID']] = $row['chain_name'];
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных по цепочкам отелей: " . $e->getMessage(), 0, $e);
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
		$chainName = $this->data[$dealId] ?? '';

		return [
			self::COLUMN_NAME => $chainName
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