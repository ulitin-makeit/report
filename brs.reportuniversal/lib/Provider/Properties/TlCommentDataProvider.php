<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Provider\UserFieldsDataProvider;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Комментарий ТЛ"
 */
class TlCommentDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные типов запросов [deal_id => value] */
	private array $data = [];

	/** @var string Код поля в Битрикс */
	private const FIELD_CODE = 'UF_COMMENT_TEAMLEADER';

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Комментарий ТЛ';

	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные поля
	 */
	public function preloadData(): void
	{
		try {
			$helper = new UserFieldsDataProvider($this->connection);

			if ($helper->fieldExists(self::FIELD_CODE)) {
				$this->data = $helper->loadFieldData(self::FIELD_CODE);
			}

		} catch (\Exception $e) {
			throw new ReportException("Ошибка загрузки данных поля " . self::FIELD_CODE . ": " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Заполняет данными сделку
	 */
	public function fillDealData(array $dealData, int $dealId): array
	{
		return [
			self::COLUMN_NAME => $this->data[$dealId] ?? ''
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