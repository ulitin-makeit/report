<?php

namespace ReportsModule\Provider\Properties;

use ReportsModule\Provider\DataProviderInterface;
use ReportsModule\Provider\UserFieldsDataProvider;
use ReportsModule\Exception\ReportException;

/**
 * DataProvider для поля "Тип запроса" (UF_S_TYPE)
 */
class RequestTypeDataProvider implements DataProviderInterface
{
    /** @var \mysqli Подключение к БД */
    private \mysqli $connection;
    
    /** @var array Данные типов запросов [deal_id => value] */
    private array $data = [];
    
    /** @var string Код поля в Битрикс */
    private const FIELD_CODE = 'UF_S_TYPE';
    
    /** @var string Название колонки в CSV */
    private const COLUMN_NAME = 'Тип запроса';

    public function __construct(\mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Предзагружает данные поля
     */
    public function preloadData(): void
    {
        try {
            $helper = new UserFieldsDataProvider($this->connection);
            
            if ($helper->fieldExists(self::FIELD_CODE)) {
                $this->data = $helper->loadFieldData(self::FIELD_CODE);
            }
            
        } catch (\Exception $e) {
            throw new ReportException("Ошибка загрузки данных поля " . self::FIELD_CODE . ": " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Заполняет данными сделку
     */
    public function fillDealData(array $dealData, int $dealId): array
    {
        return [
            self::COLUMN_NAME => $this->data[$dealId] ?? ''
        ];
    }

    /**
     * Возвращает названия колонок
     */
    public function getColumnNames(): array
    {
        return [self::COLUMN_NAME];
    }
}