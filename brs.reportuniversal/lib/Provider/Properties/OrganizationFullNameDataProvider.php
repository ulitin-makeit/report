<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Полное наименование организации"
 * Преобразует COMPANY_ID в название из пользовательского свойства UF_CRM_COMPANY_CONTRAGENT
 */
class OrganizationFullNameDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные организаций [company_id => organization_name] */
	private array $organizations = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Полное наименование организации';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные организаций из пользовательского свойства компаний
	 */
	public function preloadData(): void
	{
		try {
			$sql = "
                SELECT 
                    VALUE_ID as COMPANY_ID,
                    UF_CRM_COMPANY_CONTRAGENT as ORGANIZATION_NAME
                FROM b_uts_crm_company
                WHERE UF_CRM_COMPANY_CONTRAGENT IS NOT NULL 
                AND UF_CRM_COMPANY_CONTRAGENT != ''
            ";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки организаций: " . mysqli_error($this->connection));
			}

			while ($row = mysqli_fetch_assoc($result)) {
				$companyId = (int)$row['COMPANY_ID'];
				$organizationName = trim($row['ORGANIZATION_NAME']);
				$this->organizations[$companyId] = $organizationName;
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки организаций: " . $e->getMessage(), 0, $e);
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

		if ($companyId && isset($this->organizations[(int)$companyId])) {
			$organizationName = $this->organizations[(int)$companyId];
		} else {
			$organizationName = '';
		}

		return [
			self::COLUMN_NAME => $organizationName
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