<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "% участия агента в продаже"
 */
class AgentParticipationPercentDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/**
	 * @var array<int, string|null> Кэш данных
	 */
	private array $data = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = '% участия агента в продаже';

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
			$allUsers = [];

// Делаем выборку с помощью ORM D7
			$result = \Bitrix\Main\UserTable::getList([
				'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
				'filter' => [],
				'order' => []
			]);

// Перебираем результат
			while ($user = $result->fetch()) {
				// Собираем полное ФИО
				$fullName = trim($user['LAST_NAME'] . ' ' . $user['NAME'] . ' ' . $user['SECOND_NAME']);

				$allUsers[$user['ID']] = $fullName;
			}



			$agents = \CIblockElement::GetList(
				[],
				['=IBLOCK_ID' => PARTICIPATION_AGENT_IBLOCK_ID],
				false,
				false,
				['ID', 'PROPERTY_AGENT', 'PROPERTY_DEAL', 'PROPERTY_PERCENT_PARTICIPATION']
			);

			// обходим всех агентов
			while ($agent = $agents->Fetch()) {
				$this->data[$agent['PROPERTY_DEAL_VALUE']][] = [
					'USER' => $allUsers[$agent['PROPERTY_AGENT_VALUE']],
					'PERCENT' => $agent['PROPERTY_PERCENT_PARTICIPATION_VALUE']
				];
			}


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
		$agents = $this->data[$dealId];

		$result = '';
		
		if ($agents) {
			foreach ($agents as $agent) {
				$result .= $agent['USER'] . '=' . $agent['PERCENT'] . '%';
			}
		}
		
		return [
			self::COLUMN_NAME => $result
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
