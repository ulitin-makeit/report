<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Гости"
 * Преобразует сериализованные ID гостей из UF_BRS_CRM_DEAL_GUESTS в имена из таблицы brs_guests
 */
class GuestsDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные гостей [guest_id => guest_name] */
	private array $guests = [];

	/** @var array Данные привязки сделок к гостям [deal_id => serialized_guest_ids] */
	private array $dealGuestsMapping = [];

	/** @var string Код поля в Битрикс */
	private const FIELD_CODE = 'UF_BRS_CRM_DEAL_GUESTS';

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Гости';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные гостей и их привязки к сделкам
	 */
	public function preloadData(): void
	{
		try {
			// Загружаем справочник гостей из brs_guests
			$this->loadGuests();

			// Загружаем привязку сделок к гостям
			$this->loadDealGuestsMapping();

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных гостей: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Загружает справочник гостей из таблицы brs_guests
	 *
	 * @return void
	 * @throws ReportException
	 */
	private function loadGuests(): void
	{
		$sql = "SELECT id, name FROM brs_guests";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки гостей: " . mysqli_error($this->connection));
		}

		while ($row = mysqli_fetch_assoc($result)) {
			$this->guests[(int)$row['id']] = trim($row['name']);
		}

		mysqli_free_result($result);
	}

	/**
	 * Загружает привязку сделок к гостям из b_uts_crm_deal
	 *
	 * @return void
	 * @throws ReportException
	 */
	private function loadDealGuestsMapping(): void
	{
		$sql = "
            SELECT 
                VALUE_ID as DEAL_ID,
                " . self::FIELD_CODE . " as GUEST_IDS
            FROM b_uts_crm_deal
            WHERE " . self::FIELD_CODE . " IS NOT NULL 
            AND " . self::FIELD_CODE . " != ''
        ";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки привязки гостей к сделкам: " . mysqli_error($this->connection));
		}

		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];
			$serializedIds = $row['GUEST_IDS'];

			if ($serializedIds) {
				$this->dealGuestsMapping[$dealId] = $serializedIds;
			}
		}

		mysqli_free_result($result);
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
		$guestNames = '';

		// Проверяем есть ли данные по гостям для этой сделки
		if (isset($this->dealGuestsMapping[$dealId])) {
			$serializedIds = $this->dealGuestsMapping[$dealId];

			// Десериализуем массив ID гостей
			$guestIds = $this->unserializeGuestIds($serializedIds);

			if (!empty($guestIds)) {
				// Преобразуем ID в имена
				$names = [];
				foreach ($guestIds as $guestId) {
					$guestIdInt = (int)$guestId;
					if (isset($this->guests[$guestIdInt])) {
						$names[] = $this->guests[$guestIdInt];
					}
				}

				// Объединяем имена через запятую
				if (!empty($names)) {
					$guestNames = implode(', ', $names);
				}
			}
		}

		return [
			self::COLUMN_NAME => $guestNames
		];
	}

	/**
	 * Десериализует массив ID гостей
	 *
	 * @param string $serialized Сериализованный массив
	 * @return array Массив ID гостей
	 */
	private function unserializeGuestIds(string $serialized): array
	{
		// Попытка десериализации
		$unserialized = @unserialize($serialized);

		// Проверяем что получился массив
		if (!is_array($unserialized)) {
			return [];
		}

		// Фильтруем пустые и невалидные значения
		$guestIds = [];
		foreach ($unserialized as $value) {
			if ($value !== null && $value !== '' && is_numeric($value)) {
				$guestIds[] = (string)$value;
			}
		}

		return $guestIds;
	}

	/**
	 * Возвращает названия колонок
	 */
	public function getColumnNames(): array
	{
		return [self::COLUMN_NAME];
	}
}