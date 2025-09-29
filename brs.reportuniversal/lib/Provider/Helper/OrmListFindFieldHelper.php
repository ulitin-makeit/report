<?php

namespace ReportsModule\Provider\Helper;

use ReportsModule\Exception\ReportException;

/**
 * Хелпер для работы с пользовательскими полями типа "orm_list_find"
 * Загружает ID записей связанных сущностей для сделок
 */
class OrmListFindFieldHelper
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
     * Загружает данные для поля типа orm_list_find
     * 
     * @param string $fieldCode Код поля (например: UF_CRM_ENTITY_ID)
     * @param array $fieldInfo Информация о поле из UserFieldMetaHelper
     * @return array Ассоциативный массив [deal_id => entity_ids]
     * @throws ReportException При ошибке выполнения SQL запроса
     */
    public function loadFieldData(string $fieldCode, array $fieldInfo): array
    {
        if ($fieldInfo['multiple']) {
            return $this->loadMultipleOrmData($fieldCode);
        } else {
            return $this->loadSingleOrmData($fieldCode);
        }
    }

    /**
     * Загружает данные для одиночного поля типа orm_list_find
     * 
     * @param string $fieldCode Код поля
     * @return array Ассоциативный массив [deal_id => entity_id]
     * @throws ReportException При ошибке выполнения SQL запроса
     */
    private function loadSingleOrmData(string $fieldCode): array
    {
        $sql = "SELECT VALUE_ID as DEAL_ID, `{$fieldCode}` as FIELD_VALUE FROM b_uts_crm_deal";
        
        $result = mysqli_query($this->connection, $sql);
        if (!$result) {
            throw new ReportException("Ошибка загрузки данных поля {$fieldCode}: " . mysqli_error($this->connection));
        }
        
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $dealId = (int)$row['DEAL_ID'];
            $entityId = $row['FIELD_VALUE'];
            
            if ($entityId && is_numeric($entityId)) {
                $data[$dealId] = (string)$entityId;
            } else {
                $data[$dealId] = '';
            }
        }
        
        mysqli_free_result($result);
        return $data;
    }

    /**
     * Загружает данные для множественного поля типа orm_list_find
     * 
     * @param string $fieldCode Код поля
     * @return array Ассоциативный массив [deal_id => 'id1, id2, id3']
     * @throws ReportException При ошибке выполнения SQL запроса
     */
    private function loadMultipleOrmData(string $fieldCode): array
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
            $entityId = $row['FIELD_VALUE'];
            
            if ($entityId && is_numeric($entityId)) {
                if (!isset($dealValues[$dealId])) {
                    $dealValues[$dealId] = [];
                }
                $dealValues[$dealId][] = (string)$entityId;
            }
        }
        
        mysqli_free_result($result);
        
        // Объединяем множественные значения через запятую
        $data = [];
        foreach ($dealValues as $dealId => $entityIds) {
            $uniqueIds = array_filter(array_unique($entityIds));
            // Сортируем ID для стабильного порядка
            sort($uniqueIds, SORT_NUMERIC);
            $data[$dealId] = implode(', ', $uniqueIds);
        }
        
        return $data;
    }
}