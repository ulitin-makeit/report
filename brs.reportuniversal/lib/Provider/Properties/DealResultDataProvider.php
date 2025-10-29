<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Результат сделки"
 * Преобразует STAGE_ID в название стадии из b_crm_status
 */
class DealResultDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные стадий [stage_id => stage_name] */
	private array $stages = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Результат сделки';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные стадий сделок
	 */
	public function preloadData(): void
	{
		try {
			$sql = "
                SELECT STATUS_ID, SEMANTICS
                FROM b_crm_status 
            ";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки стадий: " . mysqli_error($this->connection));
			}

			while ($row = mysqli_fetch_assoc($result)) {
				$status = '';

				if ($row['SEMANTICS'] === 'S') {
					$status = 'Успех';
				}

				if ($row['SEMANTICS'] === 'F') {
					$status = 'Проиграна';
				}

				$this->stages[$row['STATUS_ID']] = $status;
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки стадий: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Заполняет данными сделку
	 *
	 * @param array $dealData Данные сделки (содержит STAGE_ID)
	 * @param int $dealId ID сделки
	 * @return array
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$stageId = $dealData['STAGE_ID'] ?? null;

		if ($stageId && isset($this->stages[$stageId])) {
			$stageName = $this->stages[$stageId];
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