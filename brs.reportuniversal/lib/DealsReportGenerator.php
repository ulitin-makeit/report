<?php

namespace ReportsModule;

use Bitrix\Main\Application;
use ReportsModule\Iterator\DealsIterator;
use ReportsModule\Writer\CsvWriter;
use ReportsModule\Enricher\PropertyEnricherInterface;
use ReportsModule\Exception\ReportException;

/**
 * Главный класс для генерации отчетов по сделкам
 * Координирует работу всех компонентов модуля
 */
class DealsReportGenerator
{
    /** @var \mysqli Нативное подключение mysqli */
    private \mysqli $nativeConnection;
    
    /** @var PropertyEnricherInterface[] Массив enricher'ов */
    private array $enrichers = [];
    
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
        $this->loadEnrichers();
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
            // Предзагружаем данные во всех enricher'ах
            $this->preloadEnrichersData();
            
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
     * Автоматически загружает все enricher'ы из папки Enricher
     * 
     * @return void
     * @throws ReportException
     */
    private function loadEnrichers(): void
    {
        $enricherDir = __DIR__ . '/Enricher/Properties/';
        
        if (!is_dir($enricherDir)) {
            throw new ReportException("Папка с enricher'ами не найдена: " . $enricherDir);
        }
        
        $files = glob($enricherDir . '*Enricher.php');
        $enricherClasses = [];
        
        foreach ($files as $file) {
            $className = basename($file, '.php');
            $fullClassName = "\\ReportsModule\\Enricher\\Properties\\{$className}";
            
            if (class_exists($fullClassName)) {
                $enricherClasses[] = $fullClassName;
            }
        }
        
        // Сортируем по алфавиту для стабильного порядка
        sort($enricherClasses);
        
        // Создаем экземпляры enricher'ов
        foreach ($enricherClasses as $className) {
            try {
                $this->enrichers[] = new $className($this->nativeConnection);
            } catch (\Exception $e) {
                throw new ReportException("Ошибка при создании enricher'а {$className}: " . $e->getMessage());
            }
        }
    }

    /**
     * Вызывает preloadData() у всех enricher'ов
     * 
     * @return void
     */
    private function preloadEnrichersData(): void
    {
        foreach ($this->enrichers as $enricher) {
            $enricher->preloadData();
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
        
        // Добавляем заголовки от enricher'ов (уже отсортированы по алфавиту)
        foreach ($this->enrichers as $enricher) {
            $enricherHeaders = $enricher->getColumnNames();
            $headers = array_merge($headers, $enricherHeaders);
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
                // Обогащаем сделку через все enricher'ы
                $enrichedData = $this->enrichDeal($dealData);
                
                // Записываем строку в CSV
                $this->csvWriter->writeRow($enrichedData);
                
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
     * Обогащает данные сделки через все enricher'ы
     * 
     * @param array $dealData Базовые данные сделки
     * @return array Полные данные для записи в CSV
     */
    private function enrichDeal(array $dealData): array
    {
        $result = $dealData;
        
        foreach ($this->enrichers as $enricher) {
            try {
                $enrichedFields = $enricher->enrichDeal($dealData, (int)$dealData['ID']);
                $result = array_merge($result, $enrichedFields);
            } catch (\Exception $e) {
                // При ошибке в enricher'е добавляем ERROR для его полей
                $columnNames = $enricher->getColumnNames();
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
        
        // Заполняем поля enricher'ов значением ERROR
        foreach ($this->enrichers as $enricher) {
            $columnNames = $enricher->getColumnNames();
            foreach ($columnNames as $columnName) {
                $errorRow[$columnName] = 'ERROR';
            }
        }
        
        return $errorRow;
    }

    /**
     * Возвращает количество загруженных enricher'ов
     * 
     * @return int
     */
    public function getEnrichersCount(): int
    {
        return count($this->enrichers);
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