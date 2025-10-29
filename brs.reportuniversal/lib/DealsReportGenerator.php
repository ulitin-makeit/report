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
 * - DataProvider'ы (Properties): преобразование ID в читаемые значения (одна колонка)
 * - DataProvider'ы (Composite): загрузка связанных данных (множество колонок)
 * - CsvWriter: запись данных в CSV файл с поддержкой кириллицы
 *
 * Архитектура:
 * 1. Предзагружает все справочные данные (категории, пользователи, статусы)
 * 2. Итерируется по сделкам по одной
 * 3. Заполняет каждую сделку данными через provider'ы
 * 4. Записывает в CSV файл
 */
class DealsReportGenerator
{
	/** @var \mysqli Нативное подключение mysqli */
	private \mysqli $nativeConnection;

	/** @var DataProviderInterface[] Массив Properties provider'ов (одна колонка) */
	private array $providers = [];

	/** @var DataProviderInterface[] Массив Composite provider'ов (множество колонок) */
	private array $compositeProviders = [];

	/**
	 * Все поля которые выбираем из таблицы b_crm_deal
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
	 */
	public function __construct(string $outputFilePath)
	{
		$this->outputFilePath = $outputFilePath;
		$this->initConnection();
		$this->validateConfiguration();
		$this->loadProviders();
		$this->loadCompositeProviders(); // Загрузка Composite провайдеров
		$this->dealsIterator = new DealsIterator($this->nativeConnection, $this->selectFields);
		$this->csvWriter = new CsvWriter($outputFilePath);
	}

	/**
	 * Инициализирует нативное mysqli соединение
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
	 * Автоматически загружает все Properties provider'ы из папки Provider/Properties/
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
	 * Загружает предустановленные Composite provider'ы
	 *
	 * В отличие от Properties, здесь список провайдеров фиксированный
	 */
	private function loadCompositeProviders(): void
	{
		// Список предустановленных Composite провайдеров
		$compositeClasses = [
			\Brs\ReportUniversal\Provider\Composite\FinancialCardDataProvider::class,
			\Brs\ReportUniversal\Provider\Composite\RefundCardDataProvider::class,
			// Здесь можно добавить другие Composite провайдеры в будущем
		];

		foreach ($compositeClasses as $className) {
			try {
				if (class_exists($className)) {
					$this->compositeProviders[] = new $className($this->nativeConnection);
				}
			} catch (\Exception $e) {
				throw new ReportException("Ошибка при создании Composite provider'а {$className}: " . $e->getMessage());
			}
		}
	}

	/**
	 * Вызывает preloadData() у всех provider'ов (Properties + Composite)
	 */
	private function preloadProvidersData(): void
	{
		// Загружаем данные в Properties провайдерах
		foreach ($this->providers as $provider) {
			$provider->preloadData();
		}

		// Загружаем данные в Composite провайдерах
		foreach ($this->compositeProviders as $provider) {
			$provider->preloadData();
		}
	}

	/**
	 * Формирует заголовки для CSV файла
	 */
	private function buildCsvHeaders(): array
	{
		// Сначала прямые колонки из directCsvMapping
		$headers = array_keys($this->directCsvMapping);

		// Добавляем заголовки от Properties provider'ов
		foreach ($this->providers as $provider) {
			$providerHeaders = $provider->getColumnNames();
			$headers = array_merge($headers, $providerHeaders);
		}

		// Добавляем заголовки от Composite provider'ов
		foreach ($this->compositeProviders as $provider) {
			$providerHeaders = $provider->getColumnNames();
			$headers = array_merge($headers, $providerHeaders);
		}

		return $headers;
	}

	/**
	 * Обрабатывает все сделки
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
	 * Заполняет данными сделку через все provider'ы (Properties + Composite)
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

		// Добавляем данные от Properties provider'ов
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

		// Добавляем данные от Composite provider'ов
		foreach ($this->compositeProviders as $provider) {
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
	 */
	private function buildErrorRow(array $dealData): array
	{
		$errorRow = [];

		// Пытаемся заполнить прямые поля (если данные есть)
		foreach ($this->directCsvMapping as $csvColumn => $dbField) {
			$errorRow[$csvColumn] = $dealData[$dbField] ?? 'ERROR';
		}

		// Все поля Properties provider'ов помечаем как ERROR
		foreach ($this->providers as $provider) {
			$columnNames = $provider->getColumnNames();
			foreach ($columnNames as $columnName) {
				$errorRow[$columnName] = 'ERROR';
			}
		}

		// Все поля Composite provider'ов помечаем как ERROR
		foreach ($this->compositeProviders as $provider) {
			$columnNames = $provider->getColumnNames();
			foreach ($columnNames as $columnName) {
				$errorRow[$columnName] = 'ERROR';
			}
		}

		return $errorRow;
	}
}