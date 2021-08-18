<?php

/**
 * Класс для работы с агентом по обновлению/добавлению типовых сделок
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/DevinoFields.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/integrationBoPlus.php';

use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\ContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\StatusTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\UserField\FieldEnumTable;
use Devino\Field\DevinoFields;

class TypingDealAgent {

    private $ufCompany;
    private $ufDeal;
    private $dateParams;
    private $warningMessages = [];
    private $predstav;
    private $urLicoPredstav;
    private $dealStages;
    private $ourContacts;

    private $sentWarnings;

    private $dealObj;

    private $boPlusIntegration;

    const
        DEAL_CATEGORY = 17, //категория сделки
        WHO_MESSAGES = array(810), //кому отправлять все предупреждения уведомления
        PREDSTAV_DEFAULT = 208, //дефолтное представительство
        PREDSTAV_UR_DEFAULT = 1470, //дефолтное юр. лицо представительства
        IBLOCK_PREDSTAV = 34, //ID инфоблока предствительств
        STAGE_DEAL_WON = 'C17:WON',
        STAGE_DEAL_LOSE = 'C17:LOSE',
        EXCLUDE_CUSTOMERS = array(10098, 6233, 9723, 9774, 9860, 9861); //компании, которые не нужно обозревать, тк они имеют тип (PARTNER_CLIENT or AGGREGATOR_CLIENT)


    /**
     * TypingDealAgent constructor.
     *
     * @param string $beginDate
     * @param string $closeDate
     * @param bool $sentWarnings
     * @throws ArgumentException
     * @throws ObjectException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function __construct(string $beginDate, string $closeDate, bool $sentWarnings = false) {
        $dateParams = array(
            'BEGINDATE' => new DateTime($beginDate, 'Y-m-d H:i:s'),
            'CLOSEDATE' => new DateTime($closeDate, 'Y-m-d H:i:s')
        );

        foreach ($dateParams as $dateParam) {
            if (!$dateParam) throw new ArgumentException("ERROR DATE PARAM");
        }

        $this->setSentWarnings($sentWarnings);

        $this->setDateParams($dateParams);
        $this->setUfFields();
        $this->setDealObj(new CCrmDeal(false));
        $this->setDealStages();
        $this->setOurContacts();
        $this->setPredstav();
        $this->setUrLicoPredstav();

        $this->setIntegrationBoPlusObj(new integrationBoPlus(NOTIFICATION_CONTROLLER_OBJ));
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getTypeCompanies(): array {
        return array_column(TriggersReportTable::getList(array(
                'select' => array('UF_SYSTEM', 'UF_CUSTOMERID'),
                'group' => array('UF_SYSTEM', 'UF_CUSTOMERID'),
                'filter' => array('!=UF_SYSTEM' => 'TOTAL')
            ))->fetchAll(), 'UF_SYSTEM', 'UF_CUSTOMERID') ?? [];
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws Exception
     */
    public function executeAgent() {
        $listReport = $this->getDealsReportData();
        $listCompany = $this->getOurCompany();
        $listDeals = $this->getOurDeal();
        $typeCompanies = $this->getTypeCompanies();
        $ufDealLocal = $this->getUfDeal();

        //Кусок кода на закрытие типовые сделок в начале следующего месяца
        $numberDay = date('j');

        //если это первый день месяца, то выполняем агента
        if ($numberDay == 1) {

            //1 и последний день прошлого месяца (для поиска)
            $beginDate = new DateTime(date('Y-m-d 00:00:00', strtotime('first day of last month')), 'Y-m-d H:i:s');
            $closeDate = new DateTime(date('Y-m-d 23:59:59', strtotime('last day of last month')), 'Y-m-d H:i:s');

            //выбираем сделки
            $dealList = DealTable::getList(array(
                'select' => array(
                    'ID', 'COMPANY_TITLE' => 'b_crm_company.TITLE',
                    'STAGE_ID', 'CONTACT_TITLE' => 'b_crm_contact.FULL_NAME'
                ),
                'filter' => array(
                    '=CATEGORY_ID' => 17,
                    '>=BEGINDATE' => $beginDate,
                    '<=CLOSEDATE' => $closeDate,
                    '!=STAGE_ID' => array(self::STAGE_DEAL_WON, self::STAGE_DEAL_LOSE)
                ),
                'runtime' => array(
                    new ReferenceField(
                        'b_crm_company',
                        CompanyTable::class,
                        Join::on('this.COMPANY_ID', 'ref.ID')
                    ),
                    new ReferenceField(
                        'b_crm_contact',
                        ContactTable::class,
                        Join::on('this.CONTACT_ID', 'ref.ID')
                    )
                )
            ))->fetchAll();

            if ($dealList) {
                //1 и последний день текущего месяца
                $dateParams = $this->getDateParams();

                $curBeginDate = $dateParams['BEGINDATE'];
                $curCloseDate = $dateParams['CLOSEDATE'];

                $crmDeal = new CCrmDeal(false);

                foreach ($dealList as $deal) {
                    $idDeal = $deal['ID'];
                    $companyTitle = str_replace('"', '\'', $deal['COMPANY_TITLE']);
                    $stageDeal = $deal['STAGE_ID'];
                    $contactTitle = $deal['CONTACT_TITLE'];

                    $fieldDealUpdate = [];

                    if ($stageDeal != 'C17:NEW') {
                        //закрываем сделку в "договор заключен"
                        $fieldDealUpdate = array('STAGE_ID' => self::STAGE_DEAL_WON);

                        $isUpdatedStage = $crmDeal->Update($idDeal, $fieldDealUpdate);
                        if (!$isUpdatedStage) throw new ArgumentException("{$crmDeal->LAST_ERROR} . DEAL_ID - {$idDeal}");

                        $isUpdateDates = DealTable::update($idDeal, array('BEGINDATE' => $beginDate, 'CLOSEDATE' => $closeDate));
                        if (!$isUpdateDates->isSuccess()) {
                            throw new ArgumentException(serialize($isUpdateDates->getErrorMessages()));
                        }
                    } else {
                        //обновляем дату начала и предполагаемую дату закрытия на
                        // текущую через ORM, потому что через объект не дает записать часы и секунды

                        if ($companyTitle) {
                            $title = "Типовая сделка для компании {$companyTitle} (с {$curBeginDate->format('d.m.Y')} по {$curCloseDate->format('d.m.Y')})";
                        } elseif ($contactTitle) {
                            $title = "Типовая сделка для пользователя {$contactTitle} (с {$curBeginDate->format('d.m.Y')} по {$curCloseDate->format('d.m.Y')})";
                        } else {
                            $title = "Типовая сделка (с {$curBeginDate->format('d.m.Y')} по {$curCloseDate->format('d.m.Y')})";
                        }

                        $fieldDealUpdate = array(
                            'BEGINDATE' => $curBeginDate,
                            'CLOSEDATE' => $curCloseDate,
                            'TITLE' => $title
                        );

                        $isUpdated = DealTable::update($idDeal, $fieldDealUpdate);
                        if (!$isUpdated->isSuccess()) throw new ArgumentException("{$isUpdated->getErrorMessages()} . DEAL_ID - {$idDeal}");
                    }
                }
            }
        }


        $dealPlatformDO = DevinoFields::getValueByList(CCrmOwnerType::Deal, 'TYPE_COMPANY', 'DO');
        $dealPlatformDY = DevinoFields::getValueByList(CCrmOwnerType::Deal, 'TYPE_COMPANY', 'DY');

        //стадии для поля UF_STAGE_REPORT
        $stageReport = array_column(FieldEnumTable::getList(array(
            'select' => array('ID', 'VALUE'),
            'filter' => array('=USER_FIELD_ID' => 2158)
        ))->fetchAll(), 'ID', 'VALUE');

        foreach ($listReport as $customerId => $reportRowData) {
            $curStageReport = $stageReport[$reportRowData['UF_STATUS']];

            if (!$curStageReport) {
                $this->warningMessages[] = array(
                    "TITLE" => "Ошибка определения статуса для сделки (поле UF_STAGE_REPORT). ROW_ID = {$reportRowData['ID']}",
                    "CODE" => "error_set_stage_report"
                );

                continue;
            }

            if (in_array($customerId, self::EXCLUDE_CUSTOMERS)) continue;

            //ищем id компании(ий) по customerId, из ранее сформированного массива
            $companyBXIds = $listCompany[$customerId];

            if (count($companyBXIds) == 1) {
                //если в битриксе 1 компания с таким customerId и все нормально

                //данные по компании
                $companyData = end($companyBXIds);


                //выбираем id сделки по companyId
                $dealData = $listDeals[$companyData['ID']];

                //контакт из компании
                $primaryContact = $this->preparePrimaryContact($companyData['CONTACTS'] ?? []);


                try {
                    $companyIdDO = 0;

                    //получаем буквенный идентфикатор платформы по CUSTOMER_ID
                    if (!$currentPlatformStr = $typeCompanies[$reportRowData['UF_CUSTOMERID']]) {
                        throw new ArgumentException("Ошибка получения платформы по CUSTOMER_ID = {$reportRowData['UF_CUSTOMERID']}");
                    }

                    if ($currentPlatformStr == 'DO') {
                        $currentPlatform = $dealPlatformDO;

                        //если компания = DO, то COMPANY_ID из BO++
                        $companyDataFromBoPlus = $this->getIntegrationBoPlusObj()->getCustomersList(array('customerId' => $reportRowData['UF_CUSTOMERID']))['result']['data'];
                        $companyDataFromBoPlus = end($companyDataFromBoPlus);

                        //записываем COMPANY_ID из DO
                        $companyIdDO = $companyDataFromBoPlus['companyId'];

                        if (!$companyIdDO) {
                            throw new ArgumentException("Ошибка получения COMPANY_ID по CUSTOMER_ID = {$reportRowData['UF_CUSTOMERID']}");
                        }
                    } elseif ($currentPlatformStr == 'MD') {
                        $currentPlatform = $dealPlatformDY;
                    } else {
                        throw new ArgumentException("Ошибка распознавания платформы для сделки по буквенному идендификатору {$currentPlatformStr}");
                    }
                } catch (ArgumentException $exception) {
                    writeToLog("/logs/agents/deals/", $exception->getMessage());
                    continue;
                }

                //новая стадия сделки из таблицы с отчетом
                $newDealStage = $this->prepareDealStages($reportRowData['UF_STATUS'], $reportRowData['ID']);

                if (count($dealData) == 1) {

                    //если сделка по COMPANY_ID 1, то ее нужно просто обновить
                    $updateDeal = [];

                    $currentDeal = end($dealData);

                    //поле UF_STAGE_REPORT
                    $this->checkFieldUpdate(
                        $updateDeal,
                        $currentDeal['STAGE_REPORT'],
                        $curStageReport,
                        'UF_STAGE_REPORT'
                    );

                    //получаем стадию из HL
                    $this->checkFieldUpdate(
                        $updateDeal,
                        $currentDeal['STAGE_ID'],
                        $newDealStage,
                        'STAGE_ID'
                    );

                    //представительство
                    $this->checkFieldUpdate(
                        $updateDeal,
                        $currentDeal['PREDSTAV'],
                        $this->preparePredstav($companyData['PREDSTAV']),
                        $ufDealLocal['PREDSTAV']
                    );


                    if ($currentPlatformStr == 'DO' && $companyIdDO) {
                        //COMPANY_ID
                        $this->checkFieldUpdate(
                            $updateDeal,
                            $currentDeal['COMPANY_DO_ID'],
                            $companyIdDO,
                            $ufDealLocal['COMPANY_DO_ID']
                        );
                    }

                    //поле юр лицо. представительства
                    $this->checkFieldUpdate(
                        $updateDeal,
                        $currentDeal['UR_LICO_PREDSTAV'],
                        $this->prepareUrLicoPredstav($companyData['UR_LICO_PREDSTAV']),
                        $ufDealLocal['UR_LICO_PREDSTAV']
                    );


                    //получаем платфому из компании и проверяем нужно ли ее обновлять
                    $this->checkFieldUpdate(
                        $updateDeal,
                        $currentDeal['TYPE_COMPANY'],
                        $currentPlatform,
                        $ufDealLocal['TYPE_COMPANY']
                    );

                    //поле контакта
                    $this->checkFieldUpdate(
                        $updateDeal,
                        $currentDeal['CONTACT_ID'],
                        $primaryContact,
                        'CONTACT_ID'
                    );

                    //поле основного контакта
                    $this->checkFieldUpdate(
                        $updateDeal,
                        $currentDeal['MAIN_CONTACT'],
                        $this->prepareContact($companyData['MAIN_CONTACT']),
                        $ufDealLocal['MAIN_CONTACT']
                    );

                    //поле ответственный
                    $this->checkFieldUpdate(
                        $updateDeal,
                        $currentDeal['ASSIGNED_BY_ID'],
                        $companyData['ASSIGNED_BY_ID'],
                        'ASSIGNED_BY_ID'
                    );

                    //если есть поля для обновления - обновляем сделку
                    if ($updateDeal) {
                        $this->updateDeal($currentDeal['ID'], $updateDeal);
                    }

                } elseif (count($dealData) == 0) {

                    // если сделок по такой COMPANY_ID нет, то ее нужно добавить
                    $fields = array(
                        'COMPANY_ID' => $companyData['ID'],
                        'TITLE' => "Типовая сделка для компании {$companyData['TITLE']} (с {$this->getDateParams()['BEGINDATE']->format('d.m.Y')} по {$this->getDateParams()['CLOSEDATE']->format('d.m.Y')})",
                        'CATEGORY_ID' => self::DEAL_CATEGORY,
                        'ASSIGNED_BY_ID' => $companyData['ASSIGNED_BY_ID'] ?? 810,
                        'OPENED' => 'Y',
                        'STAGE_ID' => $newDealStage,
                        'CONTACT_ID' => $primaryContact,
                        'UF_STAGE_REPORT' => $curStageReport,

                        $ufDealLocal['MAIN_CONTACT'] => $this->prepareContact($companyData['MAIN_CONTACT']),
                        $ufDealLocal['PREDSTAV'] => $this->preparePredstav($companyData['PREDSTAV']),
                        $ufDealLocal['UR_LICO_PREDSTAV'] => $this->prepareUrLicoPredstav($companyData['UR_LICO_PREDSTAV']),
                        $ufDealLocal['TYPE_COMPANY'] => $currentPlatform
                    );

                    if ($currentPlatformStr == 'DO' && $companyIdDO) {
                        $fields[$ufDealLocal['COMPANY_DO_ID']] = $companyIdDO;
                    }

                    $newDealId = $this->addDeal($fields);

                    //после добавления нужно обновить массив данных с нащими сделками
                    $ufDeal = $this->getUfDeal();
                    $dateParams = $this->getDateParams();

                    $allDealsData = DealTable::getList(array(
                        'select' => array_merge($ufDeal,
                            array('ID', 'ASSIGNED_BY_ID', 'OPPORTUNITY', 'CONTACT_ID', 'STAGE_ID', 'COMPANY_BX_ID' => 'COMPANY_ID', 'TITLE')
                        ),
                        'filter' => array(
                            '=CATEGORY_ID' => self::DEAL_CATEGORY,
                            '>=BEGINDATE' => $dateParams['BEGINDATE'],
                            '<=CLOSEDATE' => $dateParams['CLOSEDATE'],
                            '!=COMPANY_ID' => null,
                            '=ID' => $newDealId
                        )
                    ))->fetchAll();

                    foreach ($allDealsData as $dealRow) {
                        $listDeals[$dealRow['COMPANY_BX_ID']][] = $dealRow;
                    }

                } elseif (count($dealData) > 1) {

                    //иначе таких сделок больше 1
                    $this->warningMessages[] = array(
                        "TITLE" => "У компании {$companyData['ID']} больше 1 типовой сделки",
                        "CODE" => "more_deals_company"
                    );
                }

            } elseif (count($companyBXIds) > 1) {

                //иначе если в битрисе больше 1 компании с таким customerId
                $this->warningMessages[] = array(
                    "TITLE" => "Компания с CUSTOMER_ID {$customerId} больше 1 в битриксе",
                    "CODE" => "more_company"
                );
            } elseif (count($companyBXIds) == 0) {

                //иначе такой компании нет
                $this->warningMessages[] = array(
                    "TITLE" => "Компания с CUSTOMER_ID {$customerId} отсутствует в битриксе",
                    "CODE" => "no_company"
                );
            }
        }

        if ($this->warningMessages) {
            writeToLog("/logs/agents/deals/", $this->warningMessages);

//            if ($this->isSentWarnings() == true) {
//                $this->prepareMessageWarnings();
//            }
        }
    }




    //----------------------------------
    //------------Ф-ИИ------------------
    //----------------------------------

    public function prepareMessageWarnings() {
        $messStr = "[b]Предупреждения по обновлению/добавлению типовых сделок:[/b][br]";

        foreach ($this->warningMessages as $warningMessage) {
            $messStr .= $warningMessage['TITLE'] . "[br]";
        }

        sendNotificationToUsers($messStr, self::WHO_MESSAGES);
    }

    public function prepareContact($contact) {
        if (!$contact) return null;

        if ($this->getOurContacts()[$contact]) {
            return $contact;
        }

        return null;
    }

    public function checkFieldUpdate(array &$returnedUpdate, $fromValue, $toValue, string $keyAr) {
        if ($fromValue != $toValue) {
            $returnedUpdate[$keyAr] = $toValue;
        }
    }

    public static function writeToLogWithMail(string $message, string $code = "") {
        writeToLog("/logs/agents/deals/", $message, $code);
    }

    /**
     * @param $stageStatus
     * @param $rowId
     * @return string
     * @throws ArgumentException
     */
    public function prepareDealStages($stageStatus, $rowId): string {
        if (!$stageStatus) throw new ArgumentException("ERROR STATUS. ROW_ID - {$rowId}");

        if ($this->getDealStages()[$stageStatus]) {
            return $this->getDealStages()[$stageStatus];
        }

        throw new ArgumentException("INVALID STATUS REPORT. ROW_ID - {$rowId}");
    }

    /**
     * @param array $arFields
     * @return false|int
     * @throws ArgumentException
     * @throws Exception
     */
    public function addDeal(array $arFields) {
        //если сделка успешно добавлена нужно обновить параметры BEGINDATE и CLOSEDATE
        if ($isAdded = $this->getDealObj()->Add($arFields)) {
            DealTable::update($isAdded, array(
                'BEGINDATE' => $this->getDateParams()['BEGINDATE'],
                'CLOSEDATE' => $this->getDateParams()['CLOSEDATE']
            ));

            return $isAdded;
        } else {
            throw new ArgumentException($this->getDealObj()->LAST_ERROR);
        }
    }

    /**
     * @param int $dealId
     * @param array $arFields
     * @throws ArgumentException
     */
    public function updateDeal(int $dealId, array $arFields) {
        if (!$isUpdated = $this->getDealObj()->Update($dealId, $arFields)) {
            throw new ArgumentException($this->getDealObj()->LAST_ERROR);
        }
    }


    /**
     * установка пользовательских полей для сделки и компании
     */
    public function setUfFields() {
        $this->setUfCompany(
            DevinoFields::getIdByKeys(CCrmOwnerType::Company, array(
                'MAIN_CONTACT', 'UR_LICO_PREDSTAV', 'PREDSTAV', 'TYPE_COMPANY', 'CUSTOMER_ID'
            ))
        );

        $ufDeal = DevinoFields::getIdByKeys(CCrmOwnerType::Deal, array(
            'UR_LICO_PREDSTAV', 'PREDSTAV', 'MAIN_CONTACT', 'TYPE_COMPANY', 'COMPANY_ID'
        ));

        $ufDeal['COMPANY_DO_ID'] = $ufDeal['COMPANY_ID'];
        unset($ufDeal['COMPANY_ID']);

        $this->setUfDeal($ufDeal);
    }

    public function preparePrimaryContact(array $contactsId) {
        if (!$contactsId) return null;

        foreach ($contactsId as $contact) {
            if ($contact['IS_PRIMARY'] == "Y") {
                return $this->prepareContact($contact['ID']);
            }
        }

        return end($contactsId)['ID'];
    }


    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getOurCompany(): array {
        $returnedData = [];
        $ufCompany = $this->getUfCompany();

        $allCompaniesData = CompanyTable::getList(array(
            'select' => array_merge($ufCompany,
                array(
                    'ID', 'ASSIGNED_BY_ID', 'TITLE',
                    'CONTACT_ID' => 'contact_company.CONTACT_ID',
                    'IS_PRIMARY_CONTACT' => 'contact_company.IS_PRIMARY'
                )
            ),
            'filter' => array("!={$ufCompany['CUSTOMER_ID']}" => null),
            'runtime' => array(
                new ReferenceField(
                    'contact_company',
                    ContactCompanyTable::class,
                    Join::on('this.ID', 'ref.COMPANY_ID')
                )
            )
        ))->fetchAll();

        foreach ($allCompaniesData as $companyRow) {
            if (!isset($returnedData[$companyRow['CUSTOMER_ID']][$companyRow['ID']])) {
                $returnedData[$companyRow['CUSTOMER_ID']][$companyRow['ID']] = array(
                    'ID' => $companyRow['ID'],
                    'ASSIGNED_BY_ID' => $companyRow['ASSIGNED_BY_ID'],
                    'TITLE' => $companyRow['TITLE'],
                    'MAIN_CONTACT' => $companyRow['MAIN_CONTACT'],
                    'UR_LICO_PREDSTAV' => $companyRow['UR_LICO_PREDSTAV'],
                    'PREDSTAV' => $companyRow['PREDSTAV'],
                    'TYPE_COMPANY' => $companyRow['TYPE_COMPANY'],
                    'CUSTOMER_ID' => $companyRow['CUSTOMER_ID'],
                );
            }

            $returnedData[$companyRow['CUSTOMER_ID']][$companyRow['ID']]['CONTACTS'][$companyRow['CONTACT_ID']] = array(
                "ID" => $companyRow['CONTACT_ID'],
                "IS_PRIMARY" => $companyRow['IS_PRIMARY_CONTACT']
            );
        }

        return $returnedData;
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getOurDeal(): array {
        $returnedData = [];

        $ufDeal = $this->getUfDeal();
        $dateParams = $this->getDateParams();

        $allDealsData = DealTable::getList(array(
            'select' => array_merge($ufDeal, array(
                'ID', 'STAGE_REPORT' => 'UF_STAGE_REPORT', 'ASSIGNED_BY_ID', 'OPPORTUNITY',
                'CONTACT_ID', 'STAGE_ID', 'COMPANY_BX_ID' => 'COMPANY_ID', 'TITLE'
            )),
            'filter' => array(
                '=CATEGORY_ID' => self::DEAL_CATEGORY,
                '>=BEGINDATE' => $dateParams['BEGINDATE'],
                '<=CLOSEDATE' => $dateParams['CLOSEDATE'],
                '!=COMPANY_ID' => null
            )
        ))->fetchAll();

        foreach ($allDealsData as $dealRow) {
            $returnedData[$dealRow['COMPANY_BX_ID']][] = $dealRow;
        }

        return $returnedData;
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getDealsReportData(): array {
        $returnedData = [];

        $listReport = DealsReportTable::getList(array(
            'select' => array('*'),
            'filter' => array(
                '>=UF_STATUSDATE' => $this->getDateParams()['BEGINDATE'],
                '<=UF_STATUSDATE' => $this->getDateParams()['CLOSEDATE'],
                '!=UF_STATUS' => null
            )
        ));

        foreach ($listReport as $rowReport) {
            $returnedData[$rowReport['UF_CUSTOMERID']] = $rowReport;
        }

        return $returnedData;
    }

    /**
     * @return array
     */
    public function getUfCompany(): array {
        return $this->ufCompany;
    }

    /**
     * @param array $ufCompany
     */
    public function setUfCompany(array $ufCompany): void {
        $this->ufCompany = $ufCompany;
    }

    /**
     * @return array
     */
    public function getUfDeal(): array {
        return $this->ufDeal;
    }

    /**
     * @param array $ufDeal
     */
    public function setUfDeal(array $ufDeal): void {
        $this->ufDeal = $ufDeal;
    }

    /**
     * @return array
     */
    public function getDateParams(): array {
        return $this->dateParams;
    }

    /**
     * @param array $dateParams
     */
    public function setDateParams(array $dateParams): void {
        $this->dateParams = $dateParams;
    }

    /**
     * @return CCrmDeal
     */
    public function getDealObj(): CCrmDeal {
        return $this->dealObj;
    }

    /**
     * @param CCrmDeal $dealObj
     */
    public function setDealObj(CCrmDeal $dealObj): void {
        $this->dealObj = $dealObj;
    }

    /**
     * @return array
     */
    public function getPredstav(): array {
        return $this->predstav;
    }

    /**
     * @return array
     */
    public function getUrLicoPredstav(): array {
        return $this->urLicoPredstav;
    }

    public function setPredstav(): void {
        $listPredstavObj = CIBlockSection::GetList(array(), array('IBLOCK_ID' => self::IBLOCK_PREDSTAV), false, array('ID'));

        while ($listPredstav = $listPredstavObj->fetch()) {
            $this->predstav[] = $listPredstav['ID'];
        }
    }

    public function setUrLicoPredstav(): void {
        $listUrPredstavObj = CIBlockElement::GetList(array(), array('IBLOCK_ID' => self::IBLOCK_PREDSTAV), false, false, array('ID'));

        while ($listUrPredstav = $listUrPredstavObj->fetch()) {
            $this->urLicoPredstav[] = $listUrPredstav['ID'];
        }
    }

    public function preparePredstav($predstavId): ?int {
        if (in_array($predstavId, $this->getPredstav())) {
            return (int)$predstavId;
        }

        return self::PREDSTAV_DEFAULT;
    }

    public function prepareUrLicoPredstav($urLicoPredstavId): ?int {
        if (in_array($urLicoPredstavId, $this->getUrLicoPredstav())) {
            return (int)$urLicoPredstavId;
        }

        return self::PREDSTAV_UR_DEFAULT;
    }

    /**
     * @param $companyPlatformId
     * @return mixed|null
     *
     * получение id платформы для сделки из компании
     */
    public function getPlatformDeal($companyPlatformId) {
        if (!$companyPlatformId) return null;
        $companyPlatformCode = array_search($companyPlatformId, DevinoFields::getArrayFieldByKey(CCrmOwnerType::Company, 'TYPE_COMPANY')['VALUE']);
        if (!$companyPlatformCode) return null;

        return DevinoFields::getListValueByFrom(
            CCrmOwnerType::Deal,
            'TYPE_COMPANY',
            $companyPlatformCode
        );
    }

    public function preparePlatform($platformId) {
        if (!$platformId) return null;
        return $this->getPlatformDeal($platformId);
    }

    /**
     * @return array
     */
    public function getDealStages(): array {
        return $this->dealStages;
    }


    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function setDealStages(): void {
        $statusList = StatusTable::getList(array(
            'select' => array('ID', 'NAME', 'STATUS_ID'),
            'filter' => array(
                '=ENTITY_ID' => 'DEAL_STAGE_' . self::DEAL_CATEGORY,
                '!=STATUS_ID' => array(self::DEAL_CATEGORY . ":WON", self::DEAL_CATEGORY . ":LOSE")
            )
        ))->fetchAll();

        foreach ($statusList as $statusRow) {
            $this->dealStages[$statusRow['NAME']] = $statusRow['STATUS_ID'];
        }
    }

    /**
     * @return array
     */
    public function getOurContacts(): array {
        return $this->ourContacts;
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function setOurContacts(): void {
        $contactsList = ContactTable::getList(array('select' => array('ID')))->fetchAll();
        $this->ourContacts = array_column($contactsList, null, 'ID');
    }

    /**
     * @return bool
     */
    public function isSentWarnings(): bool {
        return $this->sentWarnings;
    }

    /**
     * @param bool $sentWarnings
     */
    public function setSentWarnings(bool $sentWarnings): void {
        $this->sentWarnings = $sentWarnings;
    }

    /**
     * @return integrationBoPlus
     */
    public function getIntegrationBoPlusObj(): integrationBoPlus {
        return $this->boPlusIntegration;
    }

    /**
     * @param integrationBoPlus $ncObj
     */
    public function setIntegrationBoPlusObj(integrationBoPlus $ncObj): void {
        $this->boPlusIntegration = $ncObj;
    }
}