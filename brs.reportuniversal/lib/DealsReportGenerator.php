<?php

namespace ReportsModule;

use Bitrix\Main\Application;
use ReportsModule\Iterator\DealsIterator;
use ReportsModule\Writer\CsvWriter;
use ReportsModule\Provider\DataProviderInterface;
use ReportsModule\Exception\ReportException;

/**
 * Главный класс для генерации отчетов по сделкам
 * Координирует работу всех компонентов модуля
 */
class DealsReportGenerator
{
    /** @var \mysqli Нативное подключение mysqli */
    private \mysqli $nativeConnection;
    
    /** @var DataProviderInterface[] Массив provider'ов */
    private array $providers = [];
    
    /** @var array Прямые поля сделки из основной таблицы */
    private array $directDealFields = [
        'ID',
        'TITLE', 
        'LEAD_ID',
        'STAGE_ID',
        'DATE_CREATE',
        'CATEGORY_ID',
        'ASSIGNED_BY_ID',
        'CONTACT_ID',
        'COMPANY_ID',
        'PROBABILITY',
        'OPPORTUNITY',
        'CURRENCY_ID',
        'DATE_MODIFY',
        'OPENED',
        'CLOSED',
        'COMMENTS'
    ];
    
    /** @var DealsIterator */
    private DealsIterator $dealsIterator;
    
    /** @var CsvWriter */
    private CsvWriter $csvWriter;
    
    /** @var string Путь к выходному файлу */
    private string $outputFilePath;

    /**
     * @param string $outputFilePath Путь к выходному CSV файлу
     * @throws ReportException
     */
    public function __construct(string $outputFilePath)
    {
        $this->outputFilePath = $outputFilePath;
        $this->initConnection();
        $this->loadProviders();
        $this->dealsIterator = new DealsIterator($this->nativeConnection, $this->directDealFields);
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
     * Запускает генерацию отчета
     * 
     * @return void
     * @throws ReportException
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
     * Автоматически загружает все provider'ы из папки Provider/Properties
     * 
     * @return void
     * @throws ReportException
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
            $fullClassName = "\\ReportsModule\\Provider\\Properties\\{$className}";
            
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
        $headers = $this->directDealFields;
        
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
     * @param array $dealData Базовые данные сделки
     * @return array Полные данные для записи в CSV
     */
    private function fillDealData(array $dealData): array
    {
        $result = $dealData;
        
        foreach ($this->providers as $provider) {
            try {
                $additionalFields = $provider->fillDealData($dealData, (int)$dealData['ID']);
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
     * @param array $dealData Базовые данные сделки
     * @return array
     */
    private function buildErrorRow(array $dealData): array
    {
        $errorRow = $dealData;
        
        // Заполняем поля provider'ов значением ERROR
        foreach ($this->providers as $provider) {
            $columnNames = $provider->getColumnNames();
            foreach ($columnNames as $columnName) {
                $errorRow[$columnName] = 'ERROR';
            }
        }
        
        return $errorRow;
    }

    /**
     * Возвращает количество загруженных provider'ов
     * 
     * @return int
     */
    public function getProvidersCount(): int
    {
        return count($this->providers);
    }
    
    /**
     * Возвращает нативное mysqli соединение
     * 
     * @return \mysqli
     */
    public function getNativeConnection(): \mysqli
    {
        return $this->nativeConnection;
    }
}