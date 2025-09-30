<?php

namespace Brs\ReportUniversal\Iterator;

use Brs\ReportUniversal\Exception\ReportException;

/**
 * Итератор для получения сделок по одной из базы данных
 * Использует небуферизованный режим mysqli для максимальной производительности
 */
class DealsIterator
{
	/** @var \mysqli Нативное подключение mysqli */
	private \mysqli $connection;

	/** @var array Поля для выборки */
	private array $selectFields;

	/** @var \mysqli_result|null Результат запроса */
	private ?\mysqli_result $result = null;

	/** @var bool Флаг инициализации запроса */
	private bool $queryExecuted = false;

	/** @var int Счетчик обработанных сделок */
	private int $processedCount = 0;

	/** @var string|null WHERE условие */
	private ?string $whereCondition = null;

	/**
	 * @param \mysqli $connection Нативное mysqli подключение
	 * @param array $selectFields Массив полей для выборки
	 */
	public function __construct(\mysqli $connection, array $selectFields)
	{
		$this->connection = $connection;
		$this->selectFields = $selectFields;
	}

	/**
	 * Получает следующую сделку из базы данных
	 * При первом вызове выполняет SQL запрос
	 *
	 * @return array|null Данные сделки или null если сделки закончились
	 * @throws ReportException
	 */
	public function getNextDeal(): ?array
	{
		// Инициализируем запрос при первом обращении
		if (!$this->queryExecuted) {
			$this->executeQuery();
		}

		if (!$this->result) {
			return null;
		}

		// Получаем следующую строку
		$row = mysqli_fetch_assoc($this->result);

		if ($row === null) {
			// Данные закончились, освобождаем ресурсы
			$this->freeResult();
			return null;
		}

		if ($row === false) {
			// Ошибка при чтении данных
			$error = mysqli_error($this->connection);
			$this->freeResult();
			throw new ReportException("Ошибка при чтении данных сделки: " . $error);
		}

		$this->processedCount++;
		return $row;
	}

	/**
	 * Выполняет SQL запрос для получения сделок
	 *
	 * @return void
	 * @throws ReportException
	 */
	private function executeQuery(): void
	{
		try {
			// Экранируем названия полей
			$escapedFields = array_map([$this, 'escapeFieldName'], $this->selectFields);

			// Формируем SQL запрос
			$sql = 'SELECT ' . implode(', ', $escapedFields) . ' FROM b_crm_deal';

			// Добавляем WHERE условие если оно есть
			if ($this->whereCondition) {
				$sql .= ' WHERE ' . $this->whereCondition;
			}

			$sql .= ' ORDER BY ID';

			// Выполняем запрос в небуферизованном режиме
			$this->result = mysqli_query($this->connection, $sql, MYSQLI_USE_RESULT);

			if (!$this->result) {
				$error = mysqli_error($this->connection);
				throw new ReportException("Ошибка выполнения запроса: " . $error);
			}

			$this->queryExecuted = true;

		} catch (\Exception $e) {
			throw new ReportException("Ошибка при инициализации запроса сделок: " . $e->getMessage());
		}
	}

	/**
	 * Экранирует имя поля для безопасного использования в SQL
	 *
	 * @param string $fieldName Имя поля
	 * @return string Экранированное имя поля
	 */
	private function escapeFieldName(string $fieldName): string
	{
		// Удаляем потенциально опасные символы и добавляем обратные кавычки
		$clean = preg_replace('/[^A-Za-z0-9_]/', '', $fieldName);
		return "`{$clean}`";
	}

	/**
	 * Освобождает ресурсы результата запроса
	 *
	 * @return void
	 */
	private function freeResult(): void
	{
		if ($this->result) {
			mysqli_free_result($this->result);
			$this->result = null;
		}
	}

	/**
	 * Возвращает количество обработанных сделок
	 *
	 * @return int
	 */
	public function getProcessedCount(): int
	{
		return $this->processedCount;
	}

	/**
	 * Проверяет, выполнен ли запрос
	 *
	 * @return bool
	 */
	public function isQueryExecuted(): bool
	{
		return $this->queryExecuted;
	}

	/**
	 * Добавляет условие WHERE к запросу (опционально)
	 * Должен быть вызван до первого getNextDeal()
	 *
	 * @param string $whereCondition WHERE условие без слова WHERE
	 * @return void
	 * @throws ReportException
	 */
	public function setWhereCondition(string $whereCondition): void
	{
		if ($this->queryExecuted) {
			throw new ReportException("Нельзя изменить условие после выполнения запроса");
		}

		$this->whereCondition = $whereCondition;
	}

	/**
	 * Деструктор - освобождает ресурсы
	 */
	public function __destruct()
	{
		$this->freeResult();
	}
}