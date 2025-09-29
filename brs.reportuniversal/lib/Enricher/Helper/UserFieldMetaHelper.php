<?php

namespace ReportsModule\Enricher\Helper;

use ReportsModule\Exception\ReportException;

/**
 * Хелпер для работы с метаданными пользовательских полей
 * Загружает информацию о UF полях из таблицы b_user_field
 */
class UserFieldMetaHelper
{
    /** @var \mysqli Подключение к БД */
    private \mysqli $connection;
    
    /** @var array Кэш метаданных полей [field_code => field_info] */
    private array $fieldsCache = [];
    
    /** @var array Кэш полей по сущностям [entity_id => [field_code => field_info]] */
    private array $entityFieldsCache = [];

    public function __construct(\mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Получает информацию о пользовательском поле
     * 
     * @param string $fieldCode Код поля (например: UF_CRM_CATEGORY)
     * @return array|null Информация о поле или null если не найдено
     * @throws ReportException
     */
    public function getFieldInfo(string $fieldCode): ?array
    {
        // Проверяем кэш
        if (isset($this->fieldsCache[$fieldCode])) {
            return $this->fieldsCache[$fieldCode];
        }
        
        $sql = "
            SELECT 
                FIELD_NAME,
                ENTITY_ID,
                USER_TYPE_ID,
                MULTIPLE,
                MANDATORY,
                SORT,
                EDIT_FORM_LABEL,
                LIST_COLUMN_LABEL,
                SETTINGS
            FROM b_user_field 
            WHERE FIELD_NAME = ?
        ";
        
        $stmt = mysqli_prepare($this->connection, $sql);
        if (!$stmt) {
            throw new ReportException("Ошибка подготовки запроса: " . mysqli_error($this->connection));
        }
        
        mysqli_stmt_bind_param($stmt, 's', $fieldCode);
        mysqli_stmt_execute($stmt);
        
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        
        mysqli_stmt_close($stmt);
        
        if (!$row) {
            // Кэшируем отрицательный результат
            $this->fieldsCache[$fieldCode] = null;
            return null;
        }
        
        $fieldInfo = [
            'name' => $row['FIELD_NAME'],
            'entity_id' => $row['ENTITY_ID'],
            'type' => $row['USER_TYPE_ID'],
            'multiple' => $row['MULTIPLE'] === 'Y',
            'mandatory' => $row['MANDATORY'] === 'Y',
            'sort' => (int)$row['SORT'],
            'edit_label' => $row['EDIT_FORM_LABEL'],
            'list_label' => $row['LIST_COLUMN_LABEL'],
            'settings' => $row['SETTINGS'] ? unserialize($row['SETTINGS']) : []
        ];
        
        // Кэшируем результат
        $this->fieldsCache[$fieldCode] = $fieldInfo;
        
        return $fieldInfo;
    }

    /**
     * Проверяет существование поля
     * 
     * @param string $fieldCode Код поля
     * @return bool
     */
    public function fieldExists(string $fieldCode): bool
    {
        return $this->getFieldInfo($fieldCode) !== null;
    }

    /**
     * Получает все пользовательские поля для указанной сущности
     * 
     * @param string $entityId ID сущности (например: CRM_DEAL)
     * @param array $supportedTypes Поддерживаемые типы полей
     * @return array [field_code => field_info]
     * @throws ReportException
     */
    public function getAllFieldsForEntity(string $entityId, array $supportedTypes = []): array
    {
        $cacheKey = $entityId . ':' . implode(',', $supportedTypes);
        
        // Проверяем кэш
        if (isset($this->entityFieldsCache[$cacheKey])) {
            return $this->entityFieldsCache[$cacheKey];
        }
        
        $sql = "
            SELECT 
                FIELD_NAME,
                ENTITY_ID,
                USER_TYPE_ID,
                MULTIPLE,
                MANDATORY,
                SORT,
                EDIT_FORM_LABEL,
                LIST_COLUMN_LABEL,
                SETTINGS
            FROM b_user_field 
            WHERE ENTITY_ID = ?
        ";
        
        $params = [$entityId];
        $types = 's';
        
        // Добавляем фильтр по типам если указан
        if (!empty($supportedTypes)) {
            $placeholders = str_repeat('?,', count($supportedTypes) - 1) . '?';
            $sql .= " AND USER_TYPE_ID IN ({$placeholders})";
            $params = array_merge($params, $supportedTypes);
            $types .= str_repeat('s', count($supportedTypes));
        }
        
        $sql .= " ORDER BY SORT, FIELD_NAME";
        
        $stmt = mysqli_prepare($this->connection, $sql);
        if (!$stmt) {
            throw new ReportException("Ошибка подготовки запроса: " . mysqli_error($this->connection));
        }
        
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        
        $result = mysqli_stmt_get_result($stmt);
        $fields = [];
        
        while ($row = mysqli_fetch_assoc($result)) {
            $fieldCode = $row['FIELD_NAME'];
            $fieldInfo = [
                'name' => $row['FIELD_NAME'],
                'entity_id' => $row['ENTITY_ID'],
                'type' => $row['USER_TYPE_ID'],
                'multiple' => $row['MULTIPLE'] === 'Y',
                'mandatory' => $row['MANDATORY'] === 'Y',
                'sort' => (int)$row['SORT'],
                'edit_label' => $row['EDIT_FORM_LABEL'],
                'list_label' => $row['LIST_COLUMN_LABEL'],
                'settings' => $row['SETTINGS'] ? unserialize($row['SETTINGS']) : []
            ];
            
            $fields[$fieldCode] = $fieldInfo;
            
            // Также кэшируем в основном кэше
            $this->fieldsCache[$fieldCode] = $fieldInfo;
        }
        
        mysqli_stmt_close($stmt);
        
        // Кэшируем результат
        $this->entityFieldsCache[$cacheKey] = $fields;
        
        return $fields;
    }

    /**
     * Фильтрует поля по типу
     * 
     * @param array $fields Массив полей
     * @param string $type Тип поля
     * @return array Отфильтрованные поля
     */
    public function getFieldsByType(array $fields, string $type): array
    {
        return array_filter($fields, function($field) use ($type) {
            return $field['type'] === $type;
        });
    }

    /**
     * Сортирует названия полей по SORT из метаданных
     * 
     * @param array $fields Массив полей [field_code => field_info]
     * @return array Отсортированные названия полей
     */
    public function getSortedFieldNames(array $fields): array
    {
        // Сортируем по SORT, потом по имени
        uasort($fields, function($a, $b) {
            if ($a['sort'] === $b['sort']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['sort'] <=> $b['sort'];
        });
        
        return array_keys($fields);
    }

    /**
     * Получает читаемое название поля
     * 
     * @param string $fieldCode Код поля
     * @param string $labelType Тип метки: 'edit' или 'list'
     * @return string Название поля
     */
    public function getFieldLabel(string $fieldCode, string $labelType = 'list'): string
    {
        $fieldInfo = $this->getFieldInfo($fieldCode);
        
        if (!$fieldInfo) {
            return $fieldCode; // Возвращаем код если поле не найдено
        }
        
        $label = $labelType === 'edit' ? $fieldInfo['edit_label'] : $fieldInfo['list_label'];
        
        return $label ?: $fieldCode;
    }

    /**
     * Очищает кэш
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $this->fieldsCache = [];
        $this->entityFieldsCache = [];
    }

    /**
     * Возвращает статистику кэша
     * 
     * @return array
     */
    public function getCacheStats(): array
    {
        return [
            'fields_cached' => count($this->fieldsCache),
            'entity_queries_cached' => count($this->entityFieldsCache)
        ];
    }
}