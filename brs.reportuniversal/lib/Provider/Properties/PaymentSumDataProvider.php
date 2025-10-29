<?php

namespace Brs\ReportUniversal\Provider\Properties;

use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * DataProvider для поля "Сумма оплаты"
 * Вычисляет сумму как: сумма успешных INCOMING транзакций - сумма успешных REFUND транзакций
 */
class PaymentSumDataProvider implements DataProviderInterface
{
	/** @var \mysqli Подключение к БД */
	private \mysqli $connection;

	/** @var array Данные сумм оплат [deal_id => payment_sum] */
	private array $paymentSums = [];

	/** @var string Название колонки в CSV */
	private const COLUMN_NAME = 'Сумма оплаты';

	/**
	 * @param \mysqli $connection Нативное подключение mysqli
	 */
	public function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}

	/**
	 * Предзагружает данные платежей и вычисляет суммы
	 */
	public function preloadData(): void
	{
		try {
			// Загружаем суммы входящих платежей (INCOMING)
			$incomingPayments = $this->loadPaymentsByType('INCOMING');

			// Загружаем суммы возвратов (REFUND)
			$refundPayments = $this->loadPaymentsByType('REFUND');

			// Вычисляем итоговую сумму для каждой сделки: INCOMING - REFUND
			$this->calculatePaymentSums($incomingPayments, $refundPayments);

		} catch (\Exception $e) {
			throw new ReportException("Ошибка предзагрузки данных платежей: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Загружает суммы успешных платежей по типу (INCOMING или REFUND)
	 *
	 * @param string $paymentType Тип платежа (INCOMING или REFUND)
	 * @return array Массив [deal_id => total_amount]
	 * @throws ReportException
	 */
	private function loadPaymentsByType(string $paymentType): array
	{
		$sql = "
			SELECT 
				DEAL_ID,
				SUM(AMOUNT) as TOTAL_AMOUNT
			FROM brs_ecomm_incoming_payment_transaction
			WHERE PAYMENT_TYPE = ?
			AND STATUS = 'SUCCESS'
			GROUP BY DEAL_ID
		";

		$stmt = mysqli_prepare($this->connection, $sql);
		if (!$stmt) {
			throw new ReportException("Ошибка подготовки запроса для загрузки платежей типа {$paymentType}: " . mysqli_error($this->connection));
		}

		mysqli_stmt_bind_param($stmt, 's', $paymentType);
		mysqli_stmt_execute($stmt);

		$result = mysqli_stmt_get_result($stmt);
		$payments = [];

		while ($row = mysqli_fetch_assoc($result)) {
			$dealId = (int)$row['DEAL_ID'];
			$totalAmount = (float)$row['TOTAL_AMOUNT'];
			$payments[$dealId] = $totalAmount;
		}

		mysqli_stmt_close($stmt);

		return $payments;
	}

	/**
	 * Вычисляет итоговую сумму оплаты для каждой сделки
	 * Формула: INCOMING - REFUND
	 *
	 * @param array $incomingPayments Суммы входящих платежей [deal_id => amount]
	 * @param array $refundPayments Суммы возвратов [deal_id => amount]
	 * @return void
	 */
	private function calculatePaymentSums(array $incomingPayments, array $refundPayments): void
	{
		// Собираем все уникальные deal_id из обоих массивов
		$allDealIds = array_unique(array_merge(
			array_keys($incomingPayments),
			array_keys($refundPayments)
		));

		foreach ($allDealIds as $dealId) {
			$incomingAmount = $incomingPayments[$dealId] ?? 0;
			$refundAmount = $refundPayments[$dealId] ?? 0;

			// Вычисляем итоговую сумму
			$paymentSum = $incomingAmount - $refundAmount;

			// Сохраняем результат
			$this->paymentSums[$dealId] = $paymentSum;
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
		$paymentSum = '';

		// Если есть данные для этой сделки
		if (isset($this->paymentSums[$dealId])) {
			// Форматируем число с двумя знаками после запятой
			$paymentSum = number_format($this->paymentSums[$dealId], 2, '.', '');
		}

		return [
			self::COLUMN_NAME => $paymentSum
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