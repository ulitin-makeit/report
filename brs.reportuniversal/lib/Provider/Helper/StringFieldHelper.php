<?php

namespace ReportsModule\Provider;

use ReportsModule\Exception\ReportException;
use ReportsModule\Provider\Helper\UserFieldMetaHelper;
use ReportsModule\Provider\Helper\EnumFieldHelper;
use ReportsModule\Provider\Helper\StringFieldHelper;

/**
 * Утилитарный класс для загрузки данных пользовательских полей (UF_*)
 * Координирует работу хелперов для различных типов полей
 * Используется другими provider'ами для получения готовых данных по CODE поля
 */
class UserFieldsDataProvider
{
    /** @var \mysqli Подключение к БД */
    private \mysqli $connection;
    
    /** @var UserFieldMetaHelper Хелпер для метаданных */
    private UserFieldMetaHelper $metaHelper;
    
    /** @var EnumFieldHelper Хелпер для полей типа список */
    private EnumFieldHelper $enumHelper;
    
    /** @var StringFieldHelper Хелпер для строковых полей */
    private StringFieldHelper $stringHelper;

    public function __construct(\mysqli $connection)
    {
        $this->connection = $connection;
        $this->initHelpers();
    }

    /**
     * Инициализирует хелперы
     */
    private function initHelpers(): void
    {
        $this->metaHelper = new UserFieldMetaHelper($this->connection);
        $this->enumHelper = new EnumFieldHelper($this->connection);
        $this->stringHelper = new StringFieldHelper($this->connection);
    }

    /**
     * Загружает данные для указанного UF поля
     * 
     * @param string $fieldCode Код поля (например: UF_CRM_CATEGORY)
     * @return array Ассоциативный массив [deal_id => formatted_value]
     * @throws ReportException
     */
    public function loadFieldData(string $fieldCode): array
    {
        // Получаем информацию о поле через метахелпер
        $fieldInfo = $this->metaHelper->getFieldInfo($fieldCode);
        
        if (!$fieldInfo) {
            return []; // Поле не найдено
        }
        
        // Загружаем данные через соответствующий хелпер
        try {
            switch ($fieldInfo['type']) {
                case 'enumeration':
                    return $this->enumHelper->loadFieldData($fieldCode, $fieldInfo);
                    
                case 'string':
                case 'integer':
                    return $this->stringHelper->loadFieldData($fieldCode, $fieldInfo);
                    
                default:
                    throw new ReportException("Неподдерживаемый тип поля: " . $fieldInfo['type']);
            }
        } catch (\Exception $e) {
            throw new ReportException("Ошибка загрузки данных поля {$fieldCode}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Проверяет существование поля
     * 
     * @param string $fieldCode Код поля
     * @return bool
     */
    public function fieldExists(string $fieldCode): bool
    {
        return $this->metaHelper->fieldExists($fieldCode);
    }

    /**
     * Возвращает информацию о поле
     * 
     * @param string $fieldCode Код поля
     * @return array|null
     */
    public function getFieldInfo(string $fieldCode): ?array
    {
        return $this->metaHelper->getFieldInfo($fieldCode);
    }

    /**
     * Возвращает список всех UF полей для сделок
     * 
     * @param array $supportedTypes Поддерживаемые типы полей
     * @return array [field_code => field_info]
     */
    public function getAllDealFields(array $supportedTypes = ['string', 'enumeration', 'integer']): array
    {
        return $this->metaHelper->getAllFieldsForEntity('CRM_DEAL', $supportedTypes);
    }

    /**
     * Возвращает метахелпер для прямого использования
     * 
     * @return UserFieldMetaHelper
     */
    public function getMetaHelper(): UserFieldMetaHelper
    {
        return $this->metaHelper;
    }

    /**
     * Возвращает хелпер для полей типа список
     * 
     * @return EnumFieldHelper
     */
    public function getEnumHelper(): EnumFieldHelper
    {
        return $this->enumHelper;
    }

    /**
     * Возвращает хелпер для строковых полей
     * 
     * @return StringFieldHelper
     */
    public function getStringHelper(): StringFieldHelper
    {
        return $this->stringHelper;
    }
}