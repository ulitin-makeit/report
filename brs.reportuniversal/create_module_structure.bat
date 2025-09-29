@echo off
echo Creating Bitrix24 Reports Module Structure...

REM Create main directories
mkdir lib 2>nul
mkdir lib\Iterator 2>nul
mkdir lib\Writer 2>nul
mkdir lib\Enricher 2>nul
mkdir lib\Exception 2>nul

REM Create main files
echo. > lib\DealsReportGenerator.php

REM Create Iterator files
echo. > lib\Iterator\DealsIterator.php

REM Create Writer files
echo. > lib\Writer\CsvWriter.php

REM Create Exception files
echo. > lib\Exception\ReportException.php

REM Create Enricher interface
echo. > lib\Enricher\PropertyEnricherInterface.php

REM Create all Enricher classes
echo. > lib\Enricher\DealStatusEnricher.php
echo. > lib\Enricher\DealResultEnricher.php
echo. > lib\Enricher\DealLostStageReasonEnricher.php
echo. > lib\Enricher\CategoryEnricher.php
echo. > lib\Enricher\TlCommentEnricher.php
echo. > lib\Enricher\ResponsibleUserEnricher.php
echo. > lib\Enricher\RequestTypeEnricher.php
echo. > lib\Enricher\CommunicationChannelEnricher.php
echo. > lib\Enricher\ClientEnricher.php
echo. > lib\Enricher\ClientTypeEnricher.php
echo. > lib\Enricher\TravelersEnricher.php
echo. > lib\Enricher\StartDateEnricher.php
echo. > lib\Enricher\EndDateEnricher.php
echo. > lib\Enricher\ServiceDateEnricher.php
echo. > lib\Enricher\PaymentTypeEnricher.php
echo. > lib\Enricher\CardTypeEnricher.php
echo. > lib\Enricher\CountryEnricher.php
echo. > lib\Enricher\CityEnricher.php
echo. > lib\Enricher\HotelEnricher.php
echo. > lib\Enricher\ChainEnricher.php
echo. > lib\Enricher\NightsCountEnricher.php
echo. > lib\Enricher\TotalNightsCountEnricher.php
echo. > lib\Enricher\RoomsCountEnricher.php
echo. > lib\Enricher\AdultsCountEnricher.php
echo. > lib\Enricher\ChildrenCountEnricher.php
echo. > lib\Enricher\MarketingChannelEnricher.php
echo. > lib\Enricher\MarketingChannelReasonEnricher.php
echo. > lib\Enricher\RestaurantEnricher.php
echo. > lib\Enricher\CrossSaleEnricher.php
echo. > lib\Enricher\CrossSaleReasonEnricher.php
echo. > lib\Enricher\RelatedDealsEnricher.php
echo. > lib\Enricher\PartnerEnricher.php
echo. > lib\Enricher\OrganizationFullNameEnricher.php
echo. > lib\Enricher\ContractAvailabilityEnricher.php
echo. > lib\Enricher\AgentParticipationPercentEnricher.php

echo.
echo Structure created successfully!
echo.
echo Created files:
echo - lib\DealsReportGenerator.php
echo - lib\Iterator\DealsIterator.php  
echo - lib\Writer\CsvWriter.php
echo - lib\Exception\ReportException.php
echo - lib\Enricher\PropertyEnricherInterface.php
echo - 35 Enricher classes in lib\Enricher\
echo.
echo Ready to start coding!

pause