<?php

namespace Brs\ReportUniversal;

use Bitrix\Main\Application;
use Brs\ReportUniversal\Iterator\DealsIterator;
use Brs\ReportUniversal\Writer\CsvWriter;
use Brs\ReportUniversal\Provider\DataProviderInterface;
use Brs\ReportUniversal\Exception\ReportException;

/**
 * Главный класс для генерации отчетов по сделкам Битрикс24 в формате CSV
 *
 * Координирует работу всех компонентов модуля:
 * - DealsIterator: выборка сделок из БД по одной записи (небуферизованный режим)
 * - DataProvider'ы: преобразование ID в читаемые значения (категории, пользователи и т.д.)
 * - CsvWriter: запись данных в CSV файл с поддержкой кириллицы
 *
 * Архитектура:
 * 1. Предзагружает все справочные данные (категории, пользователи, статусы)
 * 2. Итерируется по сделкам по одной
 * 3. Заполняет каждую сделку данными через provider'ы
 * 4. Записывает в CSV файл
 *
 * Пример использования:
 * <code>
 * $generator = new DealsReportGenerator('/path/to/report.csv');
 * $generator->generate();
 * </code>
 *
 * @see DealsIterator Итератор для выборки сделок
 * @see DataProviderInterface Интерфейс для provider'ов
 * @see CsvWriter Класс для записи CSV файлов
 */
class DealsReportGenerator
{
	/** @var \mysqli Нативное подключение mysqli */
	private \mysqli $nativeConnection;

	/** @var DataProviderInterface[] Массив provider'ов (автоматически загружаются из папки Properties) */
	private array $providers = [];

	/**
	 * Все поля которые выбираем из таблицы b_crm_deal
	 * Используются в DealsIterator для формирования SELECT запроса
	 * Доступны для чтения в Provider'ах через $dealData
	 *
	 * Примеры: 'ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'ASSIGNED_BY_ID'
	 *
	 * @var array
	 */
	private array $selectFields = [
		'ID',
		'TITLE',
		'STAGE_ID',
		'DATE_CREATE',
		'CATEGORY_ID',
		'ASSIGNED_BY_ID',
		'CONTACT_ID',
		'COMPANY_ID'
	];

	/**
	 * Маппинг полей которые идут в CSV НАПРЯМУЮ (без обработки provider'ами)
	 *
	 * Формат: 'Название колонки в CSV' => 'Поле из БД'
	 *
	 * Примеры:
	 * 'ID' => 'ID'                    - колонка "ID" содержит значение поля ID
	 * 'Название' => 'TITLE'           - колонка "Название" содержит значение поля TITLE
	 * 'Сумма' => 'OPPORTUNITY'        - колонка "Сумма" содержит значение поля OPPORTUNITY
	 *
	 * Поля которых НЕТ в этом маппинге, но есть в selectFields,
	 * обрабатываются через Provider'ы (например CATEGORY_ID → CategoryDataProvider)
	 *
	 * @var array
	 */
	private array $directCsvMapping = [
		'ID' => 'ID',
		'Название' => 'TITLE',
		'Дата создания' => 'DATE_CREATE',
		'ID клиента' => 'CONTACT_ID'
	];

	/** @var DealsIterator */
	private DealsIterator $dealsIterator;

	/** @var CsvWriter */
	private CsvWriter $csvWriter;

	/** @var string Путь к выходному файлу */
	private string $outputFilePath;

	/**
	 * Конструктор генератора отчетов
	 *
	 * @param string $outputFilePath Полный путь к выходному CSV файлу (например: /var/www/reports/deals.csv)
	 * @throws ReportException При ошибках подключения к БД или валидации конфигурации
	 */
	public function __construct(string $outputFilePath)
	{
		$this->outputFilePath = $outputFilePath;
		$this->initConnection();
		$this->validateConfiguration();
		$this->loadProviders();
		$this->dealsIterator = new DealsIterator($this->nativeConnection, $this->selectFields);
		$this->csvWriter = new CsvWriter($outputFilePath);
	}

	/**
	 * Инициализирует нативное mysqli соединение
	 *
	 * @return void
	 * @throws ReportException
	 */
	private function initConnection(): void
	{
		try {
			$connection = Application::getConnection();
			$this->nativeConnection = $connection->getResource();

			if (!$this->nativeConnection instanceof \mysqli) {
				throw new ReportException("Не удалось получить нативное mysqli соединение");
			}

		} catch (\Exception $e) {
			throw new ReportException("Ошибка подключения к БД: " . $e->getMessage());
		}
	}

	/**
	 * Валидирует конфигурацию перед запуском генерации
	 *
	 * Проверяет что все поля из directCsvMapping присутствуют в selectFields.
	 * Это гарантирует что мы не пытаемся вывести в CSV поле которое не выбрали из БД.
	 *
	 * Пример ошибки:
	 * directCsvMapping = ['Регион' => 'REGION_ID']
	 * selectFields = ['ID', 'TITLE'] // REGION_ID отсутствует
	 * → Выбросит ReportException
	 *
	 * @return void
	 * @throws ReportException Если найдено поле из directCsvMapping отсутствующее в selectFields
	 */
	private function validateConfiguration(): void
	{
		foreach ($this->directCsvMapping as $csvColumn => $dbField) {
			if (!in_array($dbField, $this->selectFields, true)) {
				throw new ReportException(
					"Поле '{$dbField}' из directCsvMapping (колонка '{$csvColumn}') " .
					"отсутствует в selectFields. " .
					"Добавьте '{$dbField}' в массив selectFields или удалите из directCsvMapping."
				);
			}
		}
	}

	/**
	 * Запускает генерацию отчета по сделкам
	 *
	 * Последовательность выполнения:
	 * 1. Предзагружает данные во всех provider'ах (категории, пользователи, статусы)
	 * 2. Формирует и записывает заголовки CSV (прямые поля + поля от provider'ов)
	 * 3. Итерируется по сделкам из БД по одной записи
	 * 4. Для каждой сделки: заполняет данные через provider'ы и записывает в CSV
	 * 5. Закрывает CSV файл
	 *
	 * При ошибке обработки конкретной сделки - записывает строку с ERROR и продолжает
	 *
	 * @return void
	 * @throws ReportException При критических ошибках (БД, файловая система)
	 */
	public function generate(): void
	{
		try {
			// Предзагружаем данные во всех provider'ах
			$this->preloadProvidersData();

			// Формируем и записываем заголовки CSV
			$headers = $this->buildCsvHeaders();
			$this->csvWriter->writeHeaders($headers);

			// Обрабатываем сделки по одной
			$this->processDeals();

			// Закрываем CSV файл
			$this->csvWriter->close();

		} catch (\Exception $e) {
			throw new ReportException("Ошибка при генерации отчета: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Автоматически загружает все provider'ы из папки Provider/Properties/
	 *
	 * Сканирует папку и создает экземпляры всех классов с суффиксом *Provider.php
	 * Сортирует по алфавиту для стабильного порядка колонок в CSV
	 *
	 * Пример структуры:
	 * lib/Provider/Properties/
	 *   ├── CategoryDataProvider.php      → создаст экземпляр
	 *   ├── ClientDataProvider.php        → создаст экземпляр
	 *   └── ResponsibleUserDataProvider.php → создаст экземпляр
	 *
	 * Порядок provider'ов в CSV = алфавитный порядок имен классов
	 *
	 * @return void
	 * @throws ReportException Если папка не найдена или ошибка создания экземпляра
	 */
	private function loadProviders(): void
	{
		$providerDir = __DIR__ . '/Provider/Properties/';

		if (!is_dir($providerDir)) {
			throw new ReportException("Папка с provider'ами не найдена: " . $providerDir);
		}

		$files = glob($providerDir . '*Provider.php');
		$providerClasses = [];

		foreach ($files as $file) {
			$className = basename($file, '.php');
			$fullClassName = "\\Brs\\ReportUniversal\\Provider\\Properties\\{$className}";

			if (class_exists($fullClassName)) {
				$providerClasses[] = $fullClassName;
			}
		}

		// Сортируем по алфавиту для стабильного порядка
		sort($providerClasses);

		// Создаем экземпляры provider'ов
		foreach ($providerClasses as $className) {
			try {
				$this->providers[] = new $className($this->nativeConnection);
			} catch (\Exception $e) {
				throw new ReportException("Ошибка при создании provider'а {$className}: " . $e->getMessage());
			}
		}
	}

	/**
	 * Вызывает preloadData() у всех provider'ов
	 *
	 * @return void
	 */
	private function preloadProvidersData(): void
	{
		foreach ($this->providers as $provider) {
			$provider->preloadData();
		}
	}

	/**
	 * Формирует заголовки для CSV файла
	 *
	 * @return array
	 */
	private function buildCsvHeaders(): array
	{
		// Сначала прямые колонки из directCsvMapping (ключи - это названия колонок)
		$headers = array_keys($this->directCsvMapping);

		// Добавляем заголовки от provider'ов (уже отсортированы по алфавиту)
		foreach ($this->providers as $provider) {
			$providerHeaders = $provider->getColumnNames();
			$headers = array_merge($headers, $providerHeaders);
		}

		return $headers;
	}

	/**
	 * Обрабатывает все сделки
	 *
	 * @return void
	 */
	private function processDeals(): void
	{
		while (($dealData = $this->dealsIterator->getNextDeal()) !== null) {
			try {
				// Заполняем данными сделку через все provider'ы
				$filledData = $this->fillDealData($dealData);

				// Записываем строку в CSV
				$this->csvWriter->writeRow($filledData);

			} catch (\Exception $e) {
				// Логируем ошибку и продолжаем обработку
				error_log("Ошибка при обработке сделки ID {$dealData['ID']}: " . $e->getMessage());

				// Записываем строку с ошибками
				$errorRow = $this->buildErrorRow($dealData);
				$this->csvWriter->writeRow($errorRow);
			}
		}
	}

	/**
	 * Заполняет данными сделку через все provider'ы
	 *
	 * Процесс:
	 * 1. Мапит прямые поля из БД в CSV колонки через directCsvMapping
	 *    Пример: 'Название' => $dealData['TITLE']
	 *
	 * 2. Вызывает fillDealData() у каждого provider'а для получения дополнительных данных
	 *    Пример: CategoryDataProvider вернет ['Категория' => 'Туризм']
	 *
	 * 3. Объединяет все данные в единый массив для записи в CSV
	 *
	 * При ошибке в конкретном provider'е - записывает "ERROR" для его колонок,
	 * но продолжает обработку остальных provider'ов
	 *
	 * @param array $dealData Базовые данные сделки из DealsIterator (содержит все selectFields)
	 * @return array Полный массив данных для записи в CSV с правильными названиями колонок
	 */
	private function fillDealData(array $dealData): array
	{
		$result = [];

		// Проверяем наличие ID сделки
		if (!isset($dealData['ID'])) {
			throw new ReportException("Отсутствует обязательное поле ID в данных сделки");
		}

		$dealId = (int)$dealData['ID'];

		// Мапим прямые поля по конфигурации
		foreach ($this->directCsvMapping as $csvColumn => $dbField) {
			$result[$csvColumn] = $dealData[$dbField] ?? '';
		}

		// Добавляем данные от provider'ов
		foreach ($this->providers as $provider) {
			try {
				$additionalFields = $provider->fillDealData($dealData, $dealId);
				$result = array_merge($result, $additionalFields);
			} catch (\Exception $e) {
				// При ошибке в provider'е добавляем ERROR для его полей
				$columnNames = $provider->getColumnNames();
				foreach ($columnNames as $columnName) {
					$result[$columnName] = 'ERROR';
				}
			}
		}

		return $result;
	}

	/**
	 * Создает строку с ошибками для проблемной сделки
	 *
	 * Используется когда возникла критическая ошибка при обработке сделки.
	 * Пытается сохранить базовые данные (ID, название), но для остальных полей
	 * записывает "ERROR"
	 *
	 * @param array $dealData Базовые данные сделки (может быть неполным)
	 * @return array Массив данных для записи в CSV со значением ERROR для проблемных полей
	 */
	private function buildErrorRow(array $dealData): array
	{
		$errorRow = [];

		// Пытаемся заполнить прямые поля (если данные есть)
		foreach ($this->directCsvMapping as $csvColumn => $dbField) {
			$errorRow[$csvColumn] = $dealData[$dbField] ?? 'ERROR';
		}

		// Все поля provider'ов помечаем как ERROR
		foreach ($this->providers as $provider) {
			$columnNames = $provider->getColumnNames();
			foreach ($columnNames as $columnName) {
				$errorRow[$columnName] = 'ERROR';
			}
		}

		return $errorRow;
	}
}