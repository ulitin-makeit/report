@echo off
echo Creating Bitrix24 Reports Module Structure...

REM Create main directories
mkdir lib 2>nul
mkdir lib\Iterator 2>nul
mkdir lib\Writer 2>nul
mkdir lib\Provider 2>nul
mkdir lib\Provider\Helper 2>nul
mkdir lib\Provider\Properties 2>nul
mkdir lib\Exception 2>nul

REM Create main files
echo. > lib\DealsReportGenerator.php

REM Create Iterator files
echo. > lib\Iterator\DealsIterator.php

REM Create Writer files
echo. > lib\Writer\CsvWriter.php

REM Create Exception files
echo. > lib\Exception\ReportException.php

REM Create Provider interface and main class
echo. > lib\Provider\DataProviderInterface.php
echo. > lib\Provider\UserFieldsDataProvider.php

REM Create Helper files
echo. > lib\Provider\Helper\UserFieldMetaHelper.php
echo. > lib\Provider\Helper\EnumFieldHelper.php
echo. > lib\Provider\Helper\StringFieldHelper.php

REM Create all Provider classes in Properties folder
echo. > lib\Provider\Properties\CategoryDataProvider.php
echo. > lib\Provider\Properties\DealStatusDataProvider.php
echo. > lib\Provider\Properties\DealResultDataProvider.php
echo. > lib\Provider\Properties\DealLostStageReasonDataProvider.php
echo. > lib\Provider\Properties\TlCommentDataProvider.php
echo. > lib\Provider\Properties\ResponsibleUserDataProvider.php
echo. > lib\Provider\Properties\RequestTypeDataProvider.php
echo. > lib\Provider\Properties\CommunicationChannelDataProvider.php
echo. > lib\Provider\Properties\ClientDataProvider.php
echo. > lib\Provider\Properties\ClientTypeDataProvider.php
echo. > lib\Provider\Properties\TravelersDataProvider.php
echo. > lib\Provider\Properties\StartDateDataProvider.php
echo. > lib\Provider\Properties\EndDateDataProvider.php
echo. > lib\Provider\Properties\ServiceDateDataProvider.php
echo. > lib\Provider\Properties\PaymentTypeDataProvider.php
echo. > lib\Provider\Properties\CardTypeDataProvider.php
echo. > lib\Provider\Properties\CountryDataProvider.php
echo. > lib\Provider\Properties\CityDataProvider.php
echo. > lib\Provider\Properties\HotelDataProvider.php
echo. > lib\Provider\Properties\ChainDataProvider.php
echo. > lib\Provider\Properties\NightsCountDataProvider.php
echo. > lib\Provider\Properties\TotalNightsCountDataProvider.php
echo. > lib\Provider\Properties\RoomsCountDataProvider.php
echo. > lib\Provider\Properties\AdultsCountDataProvider.php
echo. > lib\Provider\Properties\ChildrenCountDataProvider.php
echo. > lib\Provider\Properties\MarketingChannelDataProvider.php
echo. > lib\Provider\Properties\MarketingChannelReasonDataProvider.php
echo. > lib\Provider\Properties\RestaurantDataProvider.php
echo. > lib\Provider\Properties\CrossSaleDataProvider.php
echo. > lib\Provider\Properties\CrossSaleReasonDataProvider.php
echo. > lib\Provider\Properties\RelatedDealsDataProvider.php
echo. > lib\Provider\Properties\PartnerDataProvider.php
echo. > lib\Provider\Properties\OrganizationFullNameDataProvider.php
echo. > lib\Provider\Properties\ContractAvailabilityDataProvider.php
echo. > lib\Provider\Properties\AgentParticipationPercentDataProvider.php

echo.
echo Structure created successfully!
echo.
echo Created files:
echo - lib\DealsReportGenerator.php
echo - lib\Iterator\DealsIterator.php  
echo - lib\Writer\CsvWriter.php
echo - lib\Exception\ReportException.php
echo - lib\Provider\DataProviderInterface.php
echo - lib\Provider\UserFieldsDataProvider.php
echo - 3 Helper classes in lib\Provider\Helper\
echo - 35 DataProvider classes in lib\Provider\Properties\
echo.
echo Total: 43 files created!
echo Ready to start coding!

pause