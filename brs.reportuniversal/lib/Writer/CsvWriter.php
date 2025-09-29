<?php

namespace ReportsModule\Writer;

use ReportsModule\Exception\ReportException;

/**
 * Класс для записи CSV файлов
 * Оптимизирован для работы с кириллицей и просмотра в Excel
 */
class CsvWriter
{
    /** @var resource|null Дескриптор файла */
    private $fileHandle = null;
    
    /** @var string Путь к выходному файлу */
    private string $filePath;
    
    /** @var string Разделитель колонок (; лучше работает с Excel в русской локали) */
    private string $delimiter = ';';
    
    /** @var string Символ обрамления полей */
    private string $enclosure = '"';
    
    /** @var bool Флаг записи заголовков */
    private bool $headersWritten = false;

    /**
     * @param string $filePath Путь к выходному CSV файлу
     * @throws ReportException
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->openFile();
    }

    /**
     * Открывает файл для записи
     * 
     * @return void
     * @throws ReportException
     */
    private function openFile(): void
    {
        // Создаем директорию если она не существует
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new ReportException("Не удалось создать директорию: " . $directory);
        }

        // Открываем файл для записи
        $this->fileHandle = fopen($this->filePath, 'w');
        
        if ($this->fileHandle === false) {
            throw new ReportException("Не удалось открыть файл для записи: " . $this->filePath);
        }

        // Записываем BOM для корректного отображения UTF-8 в Excel
        if (fwrite($this->fileHandle, "\xEF\xBB\xBF") === false) {
            throw new ReportException("Ошибка записи BOM в файл: " . $this->filePath);
        }
    }

    /**
     * Записывает заголовки CSV файла
     * 
     * @param array $headers Массив заголовков колонок
     * @return void
     * @throws ReportException
     */
    public function writeHeaders(array $headers): void
    {
        if ($this->headersWritten) {
            throw new ReportException("Заголовки уже были записаны");
        }

        $this->writeRow($headers);
        $this->headersWritten = true;
    }

    /**
     * Записывает строку данных в CSV
     * 
     * @param array $data Ассоциативный массив данных
     * @return void
     * @throws ReportException
     */
    public function writeRow(array $data): void
    {
        if (!$this->fileHandle) {
            throw new ReportException("Файл не открыт для записи");
        }

        // Преобразуем ассоциативный массив в индексированный (только значения)
        $values = array_values($data);
        
        // Обрабатываем каждое значение
        $processedValues = array_map([$this, 'processValue'], $values);
        
        // Формируем CSV строку
        $csvLine = implode($this->delimiter, $processedValues) . "\n";
        
        // Записываем в файл
        if (fwrite($this->fileHandle, $csvLine) === false) {
            throw new ReportException("Ошибка записи данных в файл: " . $this->filePath);
        }
    }

    /**
     * Обрабатывает значение для записи в CSV
     * 
     * @param mixed $value Значение для обработки
     * @return string Обработанное значение
     */
    private function processValue($value): string
    {
        // Преобразуем в строку
        if ($value === null) {
            return '';
        }
        
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }
        
        $stringValue = (string) $value;
        
        // Удаляем переносы строк и лишние пробелы
        $stringValue = preg_replace('/\s+/', ' ', $stringValue);
        $stringValue = trim($stringValue);
        
        // Проверяем нужно ли обрамление кавычками
        $needsEnclosure = $this->needsEnclosure($stringValue);
        
        if ($needsEnclosure) {
            // Экранируем кавычки внутри значения
            $escapedValue = str_replace($this->enclosure, $this->enclosure . $this->enclosure, $stringValue);
            return $this->enclosure . $escapedValue . $this->enclosure;
        }
        
        return $stringValue;
    }

    /**
     * Проверяет нужно ли обрамлять значение кавычками
     * 
     * @param string $value Значение для проверки
     * @return bool
     */
    private function needsEnclosure(string $value): bool
    {
        // Если значение содержит разделитель, кавычки, переносы строк - нужно обрамление
        return (
            strpos($value, $this->delimiter) !== false ||
            strpos($value, $this->enclosure) !== false ||
            strpos($value, "\n") !== false ||
            strpos($value, "\r") !== false ||
            // Также обрамляем если начинается или заканчивается пробелом
            $value !== trim($value)
        );
    }

    /**
     * Закрывает файл
     * 
     * @return void
     */
    public function close(): void
    {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }
    }

    /**
     * Возвращает путь к файлу
     * 
     * @return string
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Проверяет записаны ли заголовки
     * 
     * @return bool
     */
    public function areHeadersWritten(): bool
    {
        return $this->headersWritten;
    }

    /**
     * Устанавливает разделитель колонок
     * Должен быть вызван до записи данных
     * 
     * @param string $delimiter Разделитель (по умолчанию ;)
     * @return void
     * @throws ReportException
     */
    public function setDelimiter(string $delimiter): void
    {
        if ($this->headersWritten) {
            throw new ReportException("Нельзя изменить разделитель после записи заголовков");
        }
        
        $this->delimiter = $delimiter;
    }

    /**
     * Деструктор - автоматически закрывает файл
     */
    public function __destruct()
    {
        $this->close();
    }
}