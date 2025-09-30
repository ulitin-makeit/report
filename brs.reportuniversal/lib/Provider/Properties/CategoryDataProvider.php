<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Категория"
 * Преобразует CATEGORY_ID в название категории из b_crm_deal_category
 */
class CategoryDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные категорий [category_id => category_name] */
	private array $categories = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Категория';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные категорий
	 */
	public function preloadData(): void
	{
		try {
			$sql = "SELECT ID, NAME FROM b_crm_deal_category ORDER BY SORT, NAME";

			$result = mysqli_query($this->connection, $sql);
			if (!$result) {
				throw new ReportException("Ошибка загрузки категорий: " . mysqli_error($this->connection));
			}

			while ($row = mysqli_fetch_assoc($result)) {
				$this->categories[(int)$row['ID']] = $row['NAME'];
			}

			mysqli_free_result($result);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки категорий: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Заполняет данными сделку
	 *
	 * @param array $dealData Данные сделки (содержит CATEGORY_ID)
	 * @param int $dealId ID сделки
	 * @return array
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		$categoryId = $dealData['CATEGORY_ID'] ?? null;

		if ($categoryId && isset($this->categories[(int)$categoryId])) {
			$categoryName = $this->categories[(int)$categoryId];
		} else {
			$categoryName = '';
		}

		return [
			self::COLUMN_NAME => $categoryName
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