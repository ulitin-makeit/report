<?php

namespace ReportsModule\Enricher\Helper;

use ReportsModule\Exception\ReportException;

/**
 * Хелпер для работы с пользовательскими полями типа "строка" (string)
 * Загружает строковые значения полей для сделок
 */
class StringFieldHelper
{
    /** @var \mysqli Подключение к БД */
    private \mysqli $connection;

    public function __construct(\mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Загружает данные для строкового поля
     * 
     * @param string $fieldCode Код поля
     * @param array $fieldInfo Информация о поле
     * @return array [deal_id => value]
     * @throws ReportException
     */
    public function loadFieldData(string $fieldCode, array $fieldInfo): array
    {
        if ($fieldInfo['multiple']) {
            return $this->loadMultipleStringData($fieldCode);
        } else {
            return $this->loadSingleStringData($fieldCode);
        }
    }

    /**
     * Загружает данные для одиночного строкового поля
     * 
     * @param string $fieldCode Код поля
     * @return array [deal_id => value]
     * @throws ReportException
     */
    public function loadSingleStringData(string $fieldCode): array
    {
        $sql = "SELECT VALUE_ID as DEAL_ID, `{$fieldCode}` as FIELD_VALUE FROM b_uts_crm_deal";
        
        $result = mysqli_query($this->connection, $sql);
        if (!$result) {
            throw new ReportException("Ошибка загрузки данных строкового поля {$fieldCode}: " . mysqli_error($this->connection));
        }
        
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $dealId = (int)$row['DEAL_ID'];
            $value = $row['FIELD_VALUE'];
            
            // Приводим к строке и очищаем
            $data[$dealId] = $value ? $this->cleanStringValue((string)$value) : '';
        }
        
        mysqli_free_result($result);
        return $data;
    }

    /**
     * Загружает данные для множественного строкового поля
     * 
     * @param string $fieldCode Код поля
     * @return array [deal_id => 'value1, value2, value3']
     * @throws ReportException
     */
    public function loadMultipleStringData(string $fieldCode): array
    {
        $tableName = "b_uts_crm_deal_" . strtolower($fieldCode);
        $sql = "SELECT VALUE_ID as DEAL_ID, VALUE as FIELD_VALUE FROM `{$tableName}` ORDER BY VALUE_ID, ID";
        
        $result = mysqli_query($this->connection, $sql);
        if (!$result) {
            // Таблица может не существовать если поле не использовалось
            return [];
        }
        
        $dealValues = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $dealId = (int)$row['DEAL_ID'];
            $value = $row['FIELD_VALUE'];
            
            if ($value) {
                if (!isset($dealValues[$dealId])) {
                    $dealValues[$dealId] = [];
                }
                $dealValues[$dealId][] = $this->cleanStringValue((string)$value);
            }
        }
        
        mysqli_free_result($result);
        
        // Объединяем множественные значения через запятую
        $data = [];
        foreach ($dealValues as $dealId => $values) {
            // Удаляем дубликаты и пустые значения
            $uniqueValues = array_filter(array_unique($values));
            $data[$dealId] = implode(', ', $uniqueValues);
        }
        
        return $data;
    }

    /**
     * Очищает строковое значение для CSV
     * 
     * @param string $value Исходное значение
     * @return string Очищенное значение
     */
    private function cleanStringValue(string $value): string
    {
        // Удаляем переносы строк и лишние пробелы
        $cleaned = preg_replace('/\s+/', ' ', $value);
        $cleaned = trim($cleaned);
        
        // Удаляем HTML теги если есть
        $cleaned = strip_tags($cleaned);
        
        // Декодируем HTML entities
        $cleaned = html_entity_decode($cleaned, ENT_QUOTES, 'UTF-8');
        
        return $cleaned;
    }

    /**
     * Получает значение строкового поля для конкретной сделки
     * 
     * @param string $fieldCode Код поля
     * @param int $dealId ID сделки
     * @param bool $isMultiple Множественное ли поле
     * @return string Значение поля
     * @throws ReportException
     */
    public function getFieldValueForDeal(string $fieldCode, int $dealId, bool $isMultiple = false): string
    {
        if ($isMultiple) {
            $tableName = "b_uts_crm_deal_" . strtolower($fieldCode);
            $sql = "SELECT VALUE as FIELD_VALUE FROM `{$tableName}` WHERE VALUE_ID = ? ORDER BY ID";
            
            $stmt = mysqli_prepare($this->connection, $sql);
            if (!$stmt) {
                return '';
            }
            
            mysqli_stmt_bind_param($stmt, 'i', $dealId);
            mysqli_stmt_execute($stmt);
            
            $result = mysqli_stmt_get_result($stmt);
            $values = [];
            
            while ($row = mysqli_fetch_assoc($result)) {
                if ($row['FIELD_VALUE']) {
                    $values[] = $this->cleanStringValue((string)$row['FIELD_VALUE']);
                }
            }
            
            mysqli_stmt_close($stmt);
            return implode(', ', array_unique($values));
            
        } else {
            $sql = "SELECT `{$fieldCode}` as FIELD_VALUE FROM b_uts_crm_deal WHERE VALUE_ID = ?";
            
            $stmt = mysqli_prepare($this->connection, $sql);
            if (!$stmt) {
                return '';
            }
            
            mysqli_stmt_bind_param($stmt, 'i', $dealId);
            mysqli_stmt_execute($stmt);
            
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            
            mysqli_stmt_close($stmt);
            
            if (!$row || !$row['FIELD_VALUE']) {
                return '';
            }
            
            return $this->cleanStringValue((string)$row['FIELD_VALUE']);
        }
    }

    /**
     * Проверяет существование значения для поля и сделки
     * 
     * @param string $fieldCode Код поля
     * @param int $dealId ID сделки
     * @param bool $isMultiple Множественное ли поле
     * @return bool
     */
    public function hasValueForDeal(string $fieldCode, int $dealId, bool $isMultiple = false): bool
    {
        if ($isMultiple) {
            $tableName = "b_uts_crm_deal_" . strtolower($fieldCode);
            $sql = "SELECT COUNT(*) as CNT FROM `{$tableName}` WHERE VALUE_ID = ? AND VALUE IS NOT NULL AND VALUE != ''";
        } else {
            $sql = "SELECT COUNT(*) as CNT FROM b_uts_crm_deal WHERE VALUE_ID = ? AND `{$fieldCode}` IS NOT NULL AND `{$fieldCode}` != ''";
        }
        
        $stmt = mysqli_prepare($this->connection, $sql);
        if (!$stmt) {
            return false;
        }
        
        mysqli_stmt_bind_param($stmt, 'i', $dealId);
        mysqli_stmt_execute($stmt);
        
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        
        mysqli_stmt_close($stmt);
        
        return (int)$row['CNT'] > 0;
    }

    /**
     * Получает статистику по строковому полю
     * 
     * @param string $fieldCode Код поля
     * @param bool $isMultiple Множественное ли поле
     * @return array Статистика
     * @throws ReportException
     */
    public function getFieldStats(string $fieldCode, bool $isMultiple = false): array
    {
        if ($isMultiple) {
            $tableName = "b_uts_crm_deal_" . strtolower($fieldCode);
            $sql = "
                SELECT 
                    COUNT(*) as total_values,
                    COUNT(DISTINCT VALUE_ID) as deals_with_values,
                    AVG(LENGTH(VALUE)) as avg_length,
                    MAX(LENGTH(VALUE)) as max_length
                FROM `{$tableName}` 
                WHERE VALUE IS NOT NULL AND VALUE != ''
            ";
        } else {
            $sql = "
                SELECT 
                    COUNT(*) as total_deals,
                    SUM(CASE WHEN `{$fieldCode}` IS NOT NULL AND `{$fieldCode}` != '' THEN 1 ELSE 0 END) as deals_with_values,
                    AVG(LENGTH(`{$fieldCode}`)) as avg_length,
                    MAX(LENGTH(`{$fieldCode}`)) as max_length
                FROM b_uts_crm_deal
            ";
        }
        
        $result = mysqli_query($this->connection, $sql);
        if (!$result) {
            throw new ReportException("Ошибка получения статистики поля {$fieldCode}: " . mysqli_error($this->connection));
        }
        
        $stats = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        
        return [
            'field_code' => $fieldCode,
            'is_multiple' => $isMultiple,
            'total_values' => (int)$stats[$isMultiple ? 'total_values' : 'total_deals'],
            'deals_with_values' => (int)$stats['deals_with_values'],
            'avg_length' => round((float)$stats['avg_length'], 2),
            'max_length' => (int)$stats['max_length']
        ];
    }
}