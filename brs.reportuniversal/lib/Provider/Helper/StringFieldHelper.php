<?php

namespace ReportsModule\Provider\Helper;

use ReportsModule\Exception\ReportException;

/**
 * Хелпер для работы с пользовательскими полями типа "строка" (string)
 * Загружает строковые значения полей для сделок
 */
class StringFieldHelper
{
    /** @var \mysqli Подключение к БД */
    private \mysqli $connection;

    /**
     * @param \mysqli $connection Нативное подключение mysqli
     */
    public function __construct(\mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Загружает данные для строкового поля
     * 
     * @param string $fieldCode Код поля (например: UF_CRM_COMMENT)
     * @param array $fieldInfo Информация о поле из UserFieldMetaHelper
     * @return array Ассоциативный массив [deal_id => value]
     * @throws ReportException При ошибке выполнения SQL запроса
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
     * @return array Ассоциативный массив [deal_id => cleaned_value]
     * @throws ReportException При ошибке выполнения SQL запроса
     */
    private function loadSingleStringData(string $fieldCode): array
    {
        $sql = "SELECT VALUE_ID as DEAL_ID, `{$fieldCode}` as FIELD_VALUE FROM b_uts_crm_deal";
        
        $result = mysqli_query($this->connection, $sql);
        if (!$result) {
            throw new ReportException("Ошибка загрузки данных поля {$fieldCode}: " . mysqli_error($this->connection));
        }
        
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $dealId = (int)$row['DEAL_ID'];
            $value = $row['FIELD_VALUE'];
            
            $data[$dealId] = $value ? $this->cleanStringValue((string)$value) : '';
        }
        
        mysqli_free_result($result);
        return $data;
    }

    /**
     * Загружает данные для множественного строкового поля
     * 
     * @param string $fieldCode Код поля
     * @return array Ассоциативный массив [deal_id => 'value1, value2, value3']
     * @throws ReportException При ошибке выполнения SQL запроса
     */
    private function loadMultipleStringData(string $fieldCode): array
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
            $uniqueValues = array_filter(array_unique($values));
            $data[$dealId] = implode(', ', $uniqueValues);
        }
        
        return $data;
    }

    /**
     * Очищает строковое значение для корректного отображения в CSV
     * 
     * @param string $value Исходное строковое значение
     * @return string Очищенное значение без HTML тегов, лишних пробелов и переносов
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
}