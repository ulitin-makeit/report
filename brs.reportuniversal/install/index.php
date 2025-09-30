<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Entity\Base;
use Brs\FinancialCard\Models\IndividualFieldsTable;

Loc::loadMessages(__FILE__);

class brs_reportuniversal extends CModule
{
	public function __construct()
	{
		$this->MODULE_ID = 'brs.reportuniversal';

		$arModuleVersion = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];

		$this->MODULE_NAME = 'BRS Универсальный отчёт';
		$this->MODULE_DESCRIPTION = 'Выгрузка всех данных по сделкам';
		$this->PARTNER_NAME = 'Отдел разработки BRS';
		$this->PARTNER_URI = 'https://www.rsb.ru';
	}

	public function doInstall(): void
	{
		ModuleManager::registerModule($this->MODULE_ID);
	}

	public function doUninstall(): void
	{
		ModuleManager::unRegisterModule($this->MODULE_ID);
	}
}