<?php

namespace Brs\ReportUniversal\Provider\Composite;

/**
 * Процессор бизнес-логики схем работы финансовых карт
 * 
 * Отвечает за:
 * - Определение типа схемы работы
 * - Применение правил в зависимости от схемы
 * - Расчет "Всего к оплате Поставщику" для SR_SUPPLIER_AGENT
 */
class FinancialCardSchemeProcessor
{
	/** @var array Маппинг значений схемы работы */
	private const SCHEME_WORK_MAP = [
		'BUYER_AGENT' => 'Агент покупателя',
		'SR_SUPPLIER_AGENT' => 'Агент Поставщика SR',
		'LR_SUPPLIER_AGENT' => 'Агент Поставщика LR',
		'PROVISION_SERVICES' => 'Оказание услуг',
		'RS_TLS_SERVICE_FEE' => 'Сервисный сбор РС ТЛС'
	];

	/** @var string Схема работы "Оказание услуг" */
	private const SCHEME_PROVISION_SERVICES = 'PROVISION_SERVICES';

	/** @var string Схема работы "Агент Поставщика SR" */
	private const SCHEME_SR_SUPPLIER_AGENT = 'SR_SUPPLIER_AGENT';

	/** @var string Название колонки "Дополнительная выгода" */
	public const COLUMN_ADDITIONAL_BENEFIT = 'Дополнительная выгода';

	/** @var string Название колонки "Дополнительная выгода в валюте" */
	public const COLUMN_ADDITIONAL_BENEFIT_CURRENCY = 'Дополнительная выгода в валюте';

	/** @var string Название колонки "Комиссия" */
	public const COLUMN_COMMISSION = 'Комиссия';

	/** @var string Название колонки "Комиссия в Валюте" */
	public const COLUMN_COMMISSION_CURRENCY = 'Комиссия в Валюте';

	/** @var string Название колонки "Всего к оплате Поставщику" */
	public const COLUMN_SUPPLIER_TOTAL_PAID = 'Всего к оплате Поставщику';

	/** @var string Название колонки "Всего к оплате Поставщику в валюте" */
	public const COLUMN_SUPPLIER_TOTAL_PAID_CURRENCY = 'Всего к оплате Поставщику в валюте';

	/**
	 * Получает отображаемое название схемы работы
	 *
	 * @param string $schemeCode Код схемы работы
	 * @return string Отображаемое название или сам код если не найден в маппинге
	 */
	public function getSchemeDisplayName(string $schemeCode): string
	{
		return self::SCHEME_WORK_MAP[$schemeCode] ?? $schemeCode;
	}

	/**
	 * Применяет логику переключения значений в зависимости от схемы работы
	 *
	 * Бизнес-правило:
	 * - Для PROVISION_SERVICES: обнуляется Комиссия, остается Дополнительная выгода
	 * - Для остальных схем: обнуляется Дополнительная выгода, остается Комиссия
	 *
	 * @param array &$result Массив данных сделки (изменяется напрямую)
	 * @param string $schemeWork Код схемы работы
	 * @return void
	 */
	public function applySchemeWorkLogic(array &$result, string $schemeWork): void
	{
		if ($this->isProvisionServicesScheme($schemeWork)) {
			$this->resetCommissionFields($result);
		} else {
			$this->resetAdditionalBenefitFields($result);
		}
	}

	/**
	 * Применяет логику расчёта "Всего к оплате Поставщику" для схемы SR_SUPPLIER_AGENT
	 *
	 * Бизнес-правило:
	 * - Если SUPPLIER_COMMISSION = 1 (ДА):
	 *   "Всего к оплате Поставщику" = "Сумма по счету Поставщика (БРУТТО)" + "Сбор поставщика"
	 * - Если SUPPLIER_COMMISSION != 1 (НЕТ):
	 *   "Всего к оплате Поставщику" = "Сумма по счету Поставщика (НЕТТО)" + "Сбор поставщика"
	 *
	 * @param array &$result Массив данных сделки (изменяется напрямую)
	 * @param array $cardData Данные карты (должен содержать SUPPLIER_COMMISSION)
	 * @param array $priceData Данные прайса (должен содержать SUPPLIER, SUPPLIER_CURRENCY, SUPPLIER_GROSS, SUPPLIER_GROSS_CURRENCY, SUPPLIER_NET, SUPPLIER_NET_CURRENCY)
	 * @return void
	 */
	public function applySRSupplierAgentLogic(array &$result, array $cardData, array $priceData): void
	{
		$supplierCommission = $cardData['SUPPLIER_COMMISSION'];
		
		// Проверяем, является ли SUPPLIER_COMMISSION равным 1 (как int или string)
		$isWithCommission = ($supplierCommission === 1 || $supplierCommission === '1');

		// Получаем сбор поставщика
		$supplierFee = (float)($priceData['SUPPLIER'] ?? 0);
		$supplierFeeCurrency = (float)($priceData['SUPPLIER_CURRENCY'] ?? 0);

		if ($isWithCommission) {
			// С комиссией: БРУТТО + Сбор поставщика
			$supplierGross = (float)($priceData['SUPPLIER_GROSS'] ?? 0);
			$supplierGrossCurrency = (float)($priceData['SUPPLIER_GROSS_CURRENCY'] ?? 0);
			
			$totalRub = round($supplierGross + $supplierFee, 2);
			$totalCurrency = round($supplierGrossCurrency + $supplierFeeCurrency, 2);
		} else {
			// Без комиссии: НЕТТО + Сбор поставщика
			$supplierNet = (float)($priceData['SUPPLIER_NET'] ?? 0);
			$supplierNetCurrency = (float)($priceData['SUPPLIER_NET_CURRENCY'] ?? 0);
			
			$totalRub = round($supplierNet + $supplierFee, 2);
			$totalCurrency = round($supplierNetCurrency + $supplierFeeCurrency, 2);
		}

		// Перезаписываем значения "Всего к оплате Поставщику"
		$result[self::COLUMN_SUPPLIER_TOTAL_PAID] = $this->formatValue($totalRub);
		$result[self::COLUMN_SUPPLIER_TOTAL_PAID_CURRENCY] = $this->formatValue($totalCurrency);
	}

	/**
	 * Проверяет, является ли схема работы "Оказание услуг"
	 *
	 * @param string $schemeWork Код схемы работы
	 * @return bool
	 */
	public function isProvisionServicesScheme(string $schemeWork): bool
	{
		return $schemeWork === self::SCHEME_PROVISION_SERVICES;
	}

	/**
	 * Проверяет, является ли схема работы "Агент Поставщика SR"
	 *
	 * @param string $schemeWork Код схемы работы
	 * @return bool
	 */
	public function isSRSupplierAgentScheme(string $schemeWork): bool
	{
		return $schemeWork === self::SCHEME_SR_SUPPLIER_AGENT;
	}

	/**
	 * Обнуляет поля комиссии
	 *
	 * @param array &$result Массив данных сделки
	 * @return void
	 */
	private function resetCommissionFields(array &$result): void
	{
		$result[self::COLUMN_COMMISSION] = 0;
		$result[self::COLUMN_COMMISSION_CURRENCY] = 0;
	}

	/**
	 * Обнуляет поля дополнительной выгоды
	 *
	 * @param array &$result Массив данных сделки
	 * @return void
	 */
	private function resetAdditionalBenefitFields(array &$result): void
	{
		$result[self::COLUMN_ADDITIONAL_BENEFIT] = 0;
		$result[self::COLUMN_ADDITIONAL_BENEFIT_CURRENCY] = 0;
	}

	/**
	 * Форматирует значение для записи в CSV
	 *
	 * @param mixed $value Значение для форматирования
	 * @return string
	 */
	private function formatValue($value): string
	{
		if ($value === null || $value === '') {
			return '';
		}

		return trim((string)$value);
	}
}