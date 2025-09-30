<?php

namespace Brs\ReportUniversal\Provider;

/**
 * Интерфейс для классов предоставления данных сделок
 * Каждый provider отвечает за одно или несколько связанных свойств
 */
interface DataProviderInterface
{
    /**
     * Конструктор provider'а
     * 
     * @param \mysqli $connection Нативное mysqli подключение к базе данных
     */
    public function __construct(\mysqli $connection);

    /**
     * Предзагружает все необходимые данные для заполнения сделок
     * Вызывается один раз перед началом обработки сделок
     * Данные сохраняются в свойствах класса для быстрого доступа
     * 
     * @return void
     */
    public function preloadData(): void;

    /**
     * Заполняет данными конкретную сделку
     * 
     * @param array $dealData Данные сделки (плоский ассоциативный массив)
     * @param int $dealId ID сделки
     * @return array Массив с дополнительными полями для этой сделки
     *               Ключи массива - названия колонок в CSV
     *               При ошибке возвращает "ERROR" для соответствующих полей
     *               Множественные значения объединяются через запятую
     */
    public function fillDealData(array $dealData, int $dealId): array;

    /**
     * Возвращает названия колонок, которые добавляет этот provider
     * Порядок колонок должен соответствовать порядку данных в fillDealData()
     * 
     * @return array Массив названий колонок для заголовков CSV
     */
    public function getColumnNames(): array;
}