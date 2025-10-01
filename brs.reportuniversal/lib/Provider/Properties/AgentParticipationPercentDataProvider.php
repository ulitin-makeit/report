<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "% участия агента в продаже"
 * Загружает данные о процентах участия агентов в сделках из инфоблока
 */
class AgentParticipationPercentDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Кэш данных [deal_id => [['USER' => 'ФИО', 'PERCENT' => '50'], ...]] */
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

	/**
	 * Предзагружает данные о пользователях и их участии в сделках
	 */
	public function preloadData(): void
	{
		try {
			// Загружаем всех пользователей (для преобразования ID в ФИО)
			$allUsers = $this->loadAllUsers();

			// Загружаем данные об участии агентов в сделках
			$this->loadAgentParticipation($allUsers);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных об участии агентов: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Загружает всех пользователей системы
	 *
	 * @return array [user_id => full_name]
	 */
	private function loadAllUsers(): array
	{
		$allUsers = [];

		$result = \Bitrix\Main\UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
			'filter' => [],
			'order' => []
		]);

		while ($user = $result->fetch()) {
			$fullName = trim($user['LAST_NAME'] . ' ' . $user['NAME'] . ' ' . $user['SECOND_NAME']);
			$allUsers[$user['ID']] = $fullName;
		}

		return $allUsers;
	}

	/**
	 * Загружает данные об участии агентов в сделках из инфоблока
	 *
	 * @param array $allUsers Массив пользователей [user_id => full_name]
	 * @return void
	 */
	private function loadAgentParticipation(array $allUsers): void
	{
		$agents = \CIblockElement::GetList(
			[],
			['=IBLOCK_ID' => PARTICIPATION_AGENT_IBLOCK_ID],
			false,
			false,
			['ID', 'PROPERTY_AGENT', 'PROPERTY_DEAL', 'PROPERTY_PERCENT_PARTICIPATION']
		);

		while ($agent = $agents->Fetch()) {
			$dealId = $agent['PROPERTY_DEAL_VALUE'];
			$agentId = $agent['PROPERTY_AGENT_VALUE'];
			$percent = $agent['PROPERTY_PERCENT_PARTICIPATION_VALUE'];

			$this->data[$dealId][] = [
				'USER' => $allUsers[$agentId] ?? '',
				'PERCENT' => $percent
			];
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
		$result = '';

		if (isset($this->data[$dealId]) && !empty($this->data[$dealId])) {
			$agentStrings = [];

			foreach ($this->data[$dealId] as $agent) {
				$agentStrings[] = $agent['USER'] . '=' . $agent['PERCENT'] . '%';
			}

			$result = implode(', ', $agentStrings);
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