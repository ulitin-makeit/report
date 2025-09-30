<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Компания"
 * Преобразует COMPANY_ID в название компании из b_crm_company
 */
class PartnerDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные компаний [company_id => company_title] */
	private array $companies = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Компания';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные компаний
	 */
	public function preloadData(): void
	{
		try {
			$sql = "
                SELECT 
                    ID, 
                    TITLE
                FROM b_crm_company
            ";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки компаний: " . mysqli_error($this->connection));
			}

			while ($row = mysqli_fetch_assoc($result)) {
				$this->companies[(int)$row['ID']] = trim($row['TITLE']);
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки компаний: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Заполняет данными сделку
	 *
	 * @param array $dealData Данные сделки (содержит COMPANY_ID)
	 * @param int $dealId ID сделки
	 * @return array
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$companyId = $dealData['COMPANY_ID'] ?? null;

		if ($companyId && isset($this->companies[(int)$companyId])) {
			$companyTitle = $this->companies[(int)$companyId];
		} else {
			$companyTitle = '';
		}

		return [
			self::COLUMN_NAME => $companyTitle
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