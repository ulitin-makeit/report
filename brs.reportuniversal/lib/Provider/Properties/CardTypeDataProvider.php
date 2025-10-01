<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Тип карты"
 * Определяет тип оплаты по первой успешной транзакции из brs_ecomm_incoming_payment_transaction
 */
class CardTypeDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные типов карт [card_id => card_name] */
	private array $cardTypes = [];

	/** @var array Данные первых успешных транзакций [deal_id => transaction_data] */
	private array $transactions = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Тип карты';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные типов карт и транзакций
	 */
	public function preloadData(): void
	{
		try {
			// Загружаем справочник типов карт
			$this->loadCardTypes();

			// Загружаем первые успешные транзакции для каждой сделки
			$this->loadFirstSuccessfulTransactions();

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных типов карт: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Загружает справочник типов карт из таблицы brs_cards
	 *
	 * @return void
	 * @throws ReportException
	 */
	private function loadCardTypes(): void
	{
		$sql = "SELECT ID, TYPE FROM brs_cards";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки типов карт: " . mysqli_error($this->connection));
		}

		while ($row = mysqli_fetch_assoc($result)) {
			$this->cardTypes[(int)$row['ID']] = trim($row['TYPE']);
		}

		mysqli_free_result($result);
	}

	/**
	 * Загружает первые успешные транзакции для каждой сделки
	 * Фильтр: PAYMENT_TYPE = 'INCOMING' AND STATUS = 'SUCCESS'
	 *
	 * @return void
	 * @throws ReportException
	 */
	private function loadFirstSuccessfulTransactions(): void
	{
		$sql = "
            SELECT 
                t1.DEAL_ID,
                t1.PAYMENT_BY_LINK,
                t1.PAYMENT_BY_POINT,
                t1.CARD_ID,
                t1.CURRENCY
            FROM brs_ecomm_incoming_payment_transaction t1
            INNER JOIN (
                SELECT 
                    DEAL_ID,
                    MIN(ID) as FIRST_ID
                FROM brs_ecomm_incoming_payment_transaction
                WHERE PAYMENT_TYPE = 'INCOMING' 
                AND STATUS = 'SUCCESS'
                GROUP BY DEAL_ID
            ) t2 ON t1.ID = t2.FIRST_ID
        ";

		$result = mysqli_query($this->connection, $sql);
		if (!$result) {
			throw new ReportException("Ошибка загрузки транзакций: " . mysqli_error($this->connection));
		}

		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];
			
			$this->transactions[$dealId] = [
				'PAYMENT_BY_LINK' => (int)$row['PAYMENT_BY_LINK'],
				'PAYMENT_BY_POINT' => (int)$row['PAYMENT_BY_POINT'],
				'CARD_ID' => (int)$row['CARD_ID'],
				'CURRENCY' => trim($row['CURRENCY'] ?? '')
			];
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
		$cardType = '';

		// Проверяем есть ли транзакция для этой сделки
		if (isset($this->transactions[$dealId])) {
			$transaction = $this->transactions[$dealId];
			$cardType = $this->determineCardType($transaction);
		}

		return [
			self::COLUMN_NAME => $cardType
		];
	}

	/**
	 * Определяет тип карты/оплаты на основе данных транзакции
	 *
	 * Логика:
	 * 1. Если PAYMENT_BY_LINK = 1 → "Оплата по ссылке"
	 * 2. Иначе если PAYMENT_BY_POINT = 1 → "Оплата балами " + CURRENCY
	 * 3. Иначе если CARD_ID > 0 → название карты из справочника
	 * 4. Иначе → "неизвестно"
	 *
	 * @param array $transaction Данные транзакции
	 * @return string Тип карты/оплаты
	 */
	private function determineCardType(array $transaction): string
	{
		// Проверяем оплату по ссылке
		if ($transaction['PAYMENT_BY_LINK'] === 1) {
			return 'Оплата по ссылке';
		}

		// Проверяем оплату баллами
		if ($transaction['PAYMENT_BY_POINT'] === 1) {
			$currency = $transaction['CURRENCY'];
			return 'Оплата балами' . ($currency ? ' ' . $currency : '');
		}

		// Проверяем оплату картой
		$cardId = $transaction['CARD_ID'];
		if ($cardId > 0) {
			if (isset($this->cardTypes[$cardId])) {
				return $this->cardTypes[$cardId];
			}
		}

		// По умолчанию - неизвестно
		return 'неизвестно';
	}

	/**
	 * Возвращает названия колонок
	 */
	public function getColumnNames(): array
	{
		return [self::COLUMN_NAME];
	}
}