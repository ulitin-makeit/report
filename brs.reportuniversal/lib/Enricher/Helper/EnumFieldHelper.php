<?php

namespace ReportsModule\Enricher\Helper;

use ReportsModule\Exception\ReportException;

/**
 * Хелпер для работы с пользовательскими полями типа "список" (enumeration)
 * Загружает варианты списков и значения полей для сделок
 */
class EnumFieldHelper
{
    /** @var \mysqli Подключение к БД */
    private \mysqli $connection;
    
    /** @var array Кэш вариантов списков [field_code => [enum_id => value]] */
    private array $enumValuesCache = [];

    public function __construct(\mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Загружает данные для поля типа enumeration
     * 
     * @param string $fieldCode Код поля
     * @param array $fieldInfo Информация о поле
     * @return array [deal_id => formatted_value]
     * @throws ReportException
     */
    public function loadFieldData(string $fieldCode, array $fieldInfo): array
    {
        // Загружаем варианты списка
        $enumValues = $this->loadEnumValues($fieldCode);
        
        if ($fieldInfo['multiple']) {
            return $this->loadMultipleEnumData($fieldCode, $enumValues);
        } else {
            return $this->loadSingleEnumData($fieldCode, $enumValues);
        }
    }

    /**
     * Загружает варианты для поля типа список
     * 
     * @param string $fieldCode Код поля
     * @return array [enum_id => value]
     * @throws ReportException
     */
    public function loadEnumValues(string $fieldCode): array
    {
        // Проверяем кэш
        if (isset($this->enumValuesCache[$fieldCode])) {
            return $this->enumValuesCache[$fieldCode];
        }
        
        $sql = "
            SELECT 
                ue.ID as ENUM_ID,
                ue.VALUE,
                ue.XML_ID,
                ue.SORT
            FROM b_user_field uf
            INNER JOIN b_user_field_enum ue ON uf.ID = ue.USER_FIELD_ID
            WHERE uf.FIELD_NAME = ?
            ORDER BY ue.SORT, ue.VALUE
        ";
        
        $stmt = mysqli_prepare($this->connection, $sql);
        if (!$stmt) {
            throw new ReportException("Ошибка подготовки запроса для загрузки вариантов списка: " . mysqli_error($this->connection));
        }
        
        mysqli_stmt_bind_param($stmt, 's', $fieldCode);
        mysqli_stmt_execute($stmt);
        
        $result = mysqli_stmt_get_result($stmt);
        $enumValues = [];
        
        while ($row = mysqli_fetch_assoc($result)) {
            $enumValues[$row['ENUM_ID']] = [
                'value' => $row['VALUE'],
                'xml_id' => $row['XML_ID'],
                'sort' => (int)$row['SORT']
            ];
        }
        
        mysqli_stmt_close($stmt);
        
        // Кэшируем результат
        $this->enumValuesCache[$fieldCode] = $enumValues;
        
        return $enumValues;
    }

    /**
     * Загружает данные для одиночного поля типа список
     * 
     * @param string $fieldCode Код поля
     * @param array $enumValues Варианты списка
     * @return array [deal_id => value]
     * @throws ReportException
     */
    private function loadSingleEnumData(string $fieldCode, array $enumValues): array
    {
        $sql = "SELECT VALUE_ID as DEAL_ID, `{$fieldCode}` as FIELD_VALUE FROM b_uts_crm_deal";
        
        $result = mysqli_query($this->connection, $sql);
        if (!$result) {
            throw new ReportException("Ошибка загрузки данных поля {$fieldCode}: " . mysqli_error($this->connection));
        }
        
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $dealId = (int)$row['DEAL_ID'];
            $enumId = $row['FIELD_VALUE'];
            
            if ($enumId && isset($enumValues[$enumId])) {
                $data[$dealId] = $enumValues[$enumId]['value'];
            } else {
                $data[$dealId] = '';
            }
        }
        
        mysqli_free_result($result);
        return $data;
    }

    /**
     * Загружает данные для множественного поля типа список
     * 
     * @param string $fieldCode Код поля
     * @param array $enumValues Варианты списка
     * @return array [deal_id => 'value1, value2, value3']
     * @throws ReportException
     */
    private function loadMultipleEnumData(string $fieldCode, array $enumValues): array
    {
        $tableName = "b_uts_crm_deal_" . strtolower($fieldCode);
        $sql = "SELECT VALUE_ID as DEAL_ID, VALUE as FIELD_VALUE FROM `{$tableName}`";
        
        $result = mysqli_query($this->connection, $sql);
        if (!$result) {
            // Таблица может не существовать если поле не использовалось
            return [];
        }
        
        $dealValues = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $dealId = (int)$row['DEAL_ID'];
            $enumId = $row['FIELD_VALUE'];
            
            if ($enumId && isset($enumValues[$enumId])) {
                if (!isset($dealValues[$dealId])) {
                    $dealValues[$dealId] = [];
                }
                $dealValues[$dealId][] = [
                    'value' => $enumValues[$enumId]['value'],
                    'sort' => $enumValues[$enumId]['sort']
                ];
            }
        }
        
        mysqli_free_result($result);
        
        // Сортируем и объединяем множественные значения
        $data = [];
        foreach ($dealValues as $dealId => $values) {
            // Сортируем по sort, потом по значению
            usort($values, function($a, $b) {
                if ($a['sort'] === $b['sort']) {
                    return strcmp($a['value'], $b['value']);
                }
                return $a['sort'] <=> $b['sort'];
            });
            
            // Извлекаем только значения и объединяем
            $sortedValues = array_column($values, 'value');
            $data[$dealId] = implode(', ', $sortedValues);
        }
        
        return $data;
    }

    /**
     * Получает значение варианта списка по ID
     * 
     * @param string $fieldCode Код поля
     * @param string $enumId ID варианта
     * @return string|null Значение или null если не найдено
     */
    public function getEnumValueById(string $fieldCode, string $enumId): ?string
    {
        $enumValues = $this->loadEnumValues($fieldCode);
        return $enumValues[$enumId]['value'] ?? null;
    }

    /**
     * Получает все варианты списка для поля
     * 
     * @param string $fieldCode Код поля
     * @return array [enum_id => ['value' => ..., 'xml_id' => ..., 'sort' => ...]]
     */
    public function getEnumValues(string $fieldCode): array
    {
        return $this->loadEnumValues($fieldCode);
    }

    /**
     * Получает варианты списка отсортированные по sort
     * 
     * @param string $fieldCode Код поля
     * @return array [enum_id => value] отсортированный массив
     */
    public function getSortedEnumValues(string $fieldCode): array
    {
        $enumValues = $this->loadEnumValues($fieldCode);
        
        // Сортируем по sort, потом по значению
        uasort($enumValues, function($a, $b) {
            if ($a['sort'] === $b['sort']) {
                return strcmp($a['value'], $b['value']);
            }
            return $a['sort'] <=> $b['sort'];
        });
        
        // Возвращаем только ID => value
        $sorted = [];
        foreach ($enumValues as $enumId => $enumData) {
            $sorted[$enumId] = $enumData['value'];
        }
        
        return $sorted;
    }

    /**
     * Проверяет существование варианта списка
     * 
     * @param string $fieldCode Код поля
     * @param string $enumId ID варианта
     * @return bool
     */
    public function enumValueExists(string $fieldCode, string $enumId): bool
    {
        $enumValues = $this->loadEnumValues($fieldCode);
        return isset($enumValues[$enumId]);
    }

    /**
     * Очищает кэш вариантов списков
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $this->enumValuesCache = [];
    }

    /**
     * Возвращает статистику кэша
     * 
     * @return array
     */
    public function getCacheStats(): array
    {
        return [
            'enum_fields_cached' => count($this->enumValuesCache),
            'total_enum_values' => array_sum(array_map('count', $this->enumValuesCache))
        ];
    }
}