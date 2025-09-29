<?php

namespace ReportsModule\Enricher;

/**
 * Интерфейс для классов обогащения данных сделок
 * Каждый enricher отвечает за одно или несколько связанных свойств
 */
interface PropertyEnricherInterface
{
    /**
     * Конструктор enricher'а
     * 
     * @param \mysqli $connection Нативное mysqli подключение к базе данных
     */
    public function __construct(\mysqli $connection);

    /**
     * Предзагружает все необходимые данные для обогащения сделок
     * Вызывается один раз перед началом обработки сделок
     * Данные сохраняются в свойствах класса для быстрого доступа
     * 
     * @return void
     */
    public function preloadData(): void;

    /**
     * Обогащает данные конкретной сделки
     * 
     * @param array $dealData Данные сделки (плоский ассоциативный массив)
     * @param int $dealId ID сделки
     * @return array Массив с дополнительными полями для этой сделки
     *               Ключи массива - названия колонок в CSV
     *               При ошибке возвращает "ERROR" для соответствующих полей
     *               Множественные значения объединяются через запятую
     */
    public function enrichDeal(array $dealData, int $dealId): array;

    /**
     * Возвращает названия колонок, которые добавляет этот enricher
     * Порядок колонок должен соответствовать порядку данных в enrichDeal()
     * 
     * @return array Массив названий колонок для заголовков CSV
     */
    public function getColumnNames(): array;
}