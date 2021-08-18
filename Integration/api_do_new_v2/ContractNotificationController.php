<?

use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\EntityBankDetail;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use \Devino\Field\DevinoFields;

class ContractNotificationController extends NotificationController implements EntityNotificationInterface {

    const TEMPLATE_ID = 268; //идентификатор шаблона БП
    const ENTITY_TYPE_ID = 4; //тип для кого создаются реквизиты(Компания)
    const STATUS_RQ = 'N'; // изначально реквизиты неактивны
    const PRESET_ID = 1; //идентификатор preset`a для реквизитов
    const ADDR_FACT = 6; //id фактического типа адреса
    const ADDR_LEG = 1; //id leg типа адреса
    const ADDR_POST = 11; //id post адреса
    const ANCHOR_ID = 4; //хз что это за поле
    const ENTITY_TYPE_ID_ADDR = 8; //id типа сущности для реквизитов

    const CATEGORY_DEAL_TYPE = 17;
    const CATEGORY_DEAL_DO = 0;

    const STAGE_DO_DEAL = 'NEW';


    public function __construct($startDateNotification, $mode = self::PROD_MODE) {
        parent::__construct($mode);

        //устанавливаем даты (старта, окончания) сбора нотифкаций
        $this->setAllQueryDate($startDateNotification, self::NOTIFICATION_CONTRACT);

        //сбор нотфикаций по КОНКРЕТНОЙ  сущности сущности
        $this->setNotificationsData(self::NOTIFICATION_CONTRACT);
    }

    public function executeNotification(bool $modifyCRMEntity = false) {
        //выбираем новые нотификации из DO со всеми дополнительными нам данными по сущностям
        $notifications = $this->getNotificationsData();

        //записываем айдишники полей нужных
        //нам сущностей
        $fieldsDeal = DevinoFields::getIdByKeys(CCrmOwnerType::Deal, array(
            'COMPANY_ID', 'CONTRACT_ID', 'CONTRACT_NUMBER', 'MAIN_CONTACT', 'PREDSTAV', 'UR_LICO_PREDSTAV', 'TYPE_COMPANY')); //uf поля для сделок

        $fieldsRequisite = DevinoFields::getIdByKeys(CCrmOwnerType::Requisite, array( //uf поля для реквизитов
            'BAILE_DOCUMENT', 'RQ_OPF', 'RQ_ACTIVE', 'RQ_CONTRACT_ID',
            'RQ_COMPANY_ID', 'RQ_DEAL_ID', 'RQ_SIGN_POS', 'CONTRACT_NUMBER'
        ));

        $fieldCompany = DevinoFields::getIdByKeys(CCrmOwnerType::Company, array('COMPANY_ID',
            'TYPE_COMPANY', 'PREDSTAV', 'UR_LICO_PREDSTAV', 'MAIN_CONTACT'));

        $answer = null;

        if ($modifyCRMEntity == true) {

            //проверяем заполнены ли uf поля
            if (!$fieldsDeal || !$fieldsRequisite) {
                $answer['errors'][] = "Не заполнены пользовательские поля для сделки или для реквизитов";
            }

            //создаем объект сделки
            $crmDeal = new CCrmDeal(false);
            $crmCompany = new CCrmCompany(false);

            $crmRq = new EntityRequisite();
            $crmBankDetail = new EntityBankDetail();

            try {
                Loader::includeModule('bizproc');

                //проходим все нотификации
                foreach ($notifications as $notificationsDatum) {
                    try {
                        $currentContractId = $notificationsDatum['CONTRACT']['contractId'];     //CONTRACT_ID
                        $currentCompanyId = $notificationsDatum['COMPANY']['companyId'];        //COMPANY_ID
                        $currentStatus = $notificationsDatum['STATUS'];                   //статус договора
                        $currentNumberDocument = $notificationsDatum['CONTRACT']['contractNum']; //номер договора
                        $companyName = $notificationsDatum['CONTRACT']['fullCompanyName']; //название компании берем из реквизитов

                        if (!$currentContractId || !$currentCompanyId || !$currentStatus || !$currentNumberDocument) {
                            throw new ArgumentException("Ошибка получения данных по CONTRACT_ID {$notificationsDatum['ORIGINAL_DATA']['CONTRACT_ID']}");
                        }

                        switch ($currentStatus) {
                            case 'IN_COORDINATION':
                                $currentCompanyIdBx = 0;

                                //проверяем существует ли сделка DO с таким CONTRACT_ID и COMPANY_ID
                                $arrayDeal = DealTable::getList(array(
                                    'select' => array('ID'),
                                    'filter' => array(
                                        "=" . $fieldsDeal['COMPANY_ID'] => $currentCompanyId,
                                        "=" . $fieldsDeal['CONTRACT_ID'] => $currentContractId,
                                        "=" . $fieldsDeal['CONTRACT_NUMBER'] => $currentNumberDocument,
                                        '=CATEGORY_ID' => self::CATEGORY_DEAL_DO
                                    )
                                ))->fetchAll();

                                //записываем представительство
                                $officeId = $notificationsDatum['COMPANY']['officeId'];
                                $getPredstav = $this->getPredstav($officeId);
                                $getUrLicoPredstav = $this->getUrLicoPredstav($getPredstav);


                                if (count($arrayDeal) == 0) {

                                    //такой сделки DO еще нет, значит создаем новую
                                    //ищем типовую сделку, которая еще не закрыта
                                    $arrayDealIds = DealTable::getList(array(
                                        'filter' => array(
                                            '=CATEGORY_ID' => self::CATEGORY_DEAL_TYPE,
                                            '!=STAGE_ID' => array('C17:WON'),
                                            "=" . $fieldsDeal['COMPANY_ID'] => $currentCompanyId,
                                        ),
                                        'select' => array('ID', 'CONTACT_ID', 'ASSIGNED_BY_ID', 'BEGINDATE', 'CLOSEDATE')
                                    ))->fetchAll();

                                    if (count($arrayDealIds) > 1) {
                                        //найдено больше 1 типовой сделки
                                        throw new ArgumentException("Существует больше одной типовой сделки для COMPANY_ID = {$currentCompanyId}");
                                    }

                                    if (!empty($arrayDealIds)) {
                                        $arrayDealId = end($arrayDealIds);

                                        //id типовой сделки из которой нам нужно создать сделку DO
                                        $currentTypeDealId = $arrayDealId['ID'];

                                        //id контакта привязанного к типовой сделке
                                        $currentContactId = $arrayDealId['CONTACT_ID'];

                                        //дата начала типовой сделки
                                        $dateBegin = $arrayDealId['BEGINDATE']->format('d.m.Y');

                                        //дата окончания типовой сделки
                                        $closeDate = $arrayDealId['CLOSEDATE']->format('d.m.Y');

                                        if (!$currentContactId) {
                                            throw new ArgumentException("К типовой сделке {$currentTypeDealId} не привязан основной пользователь для COMPANY_ID = {$currentCompanyId}");
                                        }

                                        //ответственный
                                        $assignedById = $arrayDealId['ASSIGNED_BY_ID'] ?? $this->getUserIdByLogin($notificationsDatum['COMPANY']['manager']);

                                        //создае сделку DO
                                        $arFieldsDealDOAdd = array(
                                            'TITLE' => "{$companyName} {$currentNumberDocument}",
                                            'ASSIGNED_BY_ID' => $assignedById,
                                            'STATUS_ID' => self::STAGE_DO_DEAL,
                                            'SOURCE_ID' => self::SOURCE_REGISTER_NLK,// Источник
                                            'CONTACT_IDS' => array($currentContactId),
                                            'CATEGORY_ID' => 0,
                                            'TYPE_ID' => 'SALE',
                                            $fieldsDeal['COMPANY_ID'] => $currentCompanyId,// COMPANY_ID
                                            $fieldsDeal['CONTRACT_ID'] => $currentContractId,// COMPANY_ID
                                            $fieldsDeal['CONTRACT_NUMBER'] => $currentNumberDocument,// COMPANY_ID
                                            $fieldsDeal['UR_LICO_PREDSTAV'] => $getUrLicoPredstav,// Юр. лицо представительства
                                            $fieldsDeal['PREDSTAV'] => $getPredstav, // Представительство из
                                            $fieldsDeal['TYPE_COMPANY'] => DevinoFields::getValueByList(CCrmOwnerType::Deal, 'TYPE_COMPANY', 'DO'),
                                            $fieldsDeal['MAIN_CONTACT'] => $currentContactId,// основное контактное лицо
                                        );

                                        if (!$isAddedDealDo = $crmDeal->Add($arFieldsDealDOAdd)) {
                                            throw new ArgumentException("Ошибка добавления сделки DO для COMPANY_ID = {$currentCompanyId} и CONTRACT_ID = {$currentContractId}. Ошибка - {$crmDeal->LAST_ERROR}");
                                        } else {
                                            $answer['success'][] = "Добавлена сделка DO ({$isAddedDealDo}) для COMPANY_ID = {$currentCompanyId} и CONTRACT_ID = {$currentContractId}";

                                            //если сделка DO добавлена, запускаем БП на 1 стадии
                                            $bpErrors = [];
                                            CBPDocument::StartWorkflow(14,
                                                array('crm', 'CCrmDocumentDeal', "DEAL_{$isAddedDealDo}"),
                                                [], $bpErrors
                                            );

                                            if ($bpErrors) {
                                                $errStr = implode(', ', $bpErrors);
                                                throw new ArgumentException("Ошибка запуска BP для типовой сделки. Ошибка {$errStr}");
                                            }
                                        }

                                        $currentDODealId = $isAddedDealDo;

                                        //прежде чем создать компанию нужно проверить ее на существование
                                        $checkCompany = CompanyTable::getList(array(
                                            'filter' => array('=' . $fieldCompany['COMPANY_ID'] => $currentCompanyId),
                                            'select' => array('ID')
                                        ))->fetchAll();

                                        if (count($checkCompany) > 1) {

                                            //если компания с таким COMPANY_ID больше 1 выводим ошибку
                                            throw new ArgumentException("Для COMPANY_ID = {$currentCompanyId} найдено больше одной компании");
                                        } elseif (count($checkCompany) == 0) {

                                            //иначе компании нет, создаем
                                            $fieldCompanyAdd = array(
                                                'TITLE' => $companyName,
                                                'ASSIGNED_BY_ID' => $assignedById,
                                                'COMPANY_TYPE' => 'CUSTOMER',
                                                'OPENED' => 'Y',
                                                'HAS_EMAIL' => 'N',
                                                'HAS_PHONE' => 'N',
                                                'CONTACT_ID' => array($currentContactId),

                                                $fieldCompany['COMPANY_ID'] => $currentCompanyId,
                                                $fieldCompany['MAIN_CONTACT'] => $currentContactId,
                                                $fieldCompany['PREDSTAV'] => $getPredstav,
                                                $fieldCompany['UR_LICO_PREDSTAV'] => $getUrLicoPredstav,
                                                $fieldCompany['TYPE_COMPANY'] => DevinoFields::getValueByList(CCrmOwnerType::Company, 'TYPE_COMPANY', 'DO'),
                                            );

                                            if (!$isAddedCompany = $crmCompany->Add($fieldCompanyAdd)) {
                                                throw new ArgumentException("Ошибка добавления компании для COMPANY_ID = {$currentCompanyId} и CONTRACT_ID = {$currentContractId}. Ошибка - {$crmCompany->LAST_ERROR}");
                                            } else {
                                                $currentCompanyIdBx = $isAddedCompany;

                                                //генерируем массив полей для создания реквизитов
                                                $arrayRQAdd = $this->generateFieldRQ($notificationsDatum, $fieldsRequisite, $currentDODealId, $currentCompanyIdBx);
                                                $arrayRQAdd['RQ_ADDR'] = array(
                                                    self::ADDR_POST => array('ADDRESS_1' => $notificationsDatum['CONTRACT']['postAddress']),
                                                    self::ADDR_FACT => array('ADDRESS_1' => $notificationsDatum['CONTRACT']['factualAddress']),
                                                    self::ADDR_LEG => array('ADDRESS_1' => $notificationsDatum['CONTRACT']['legalAddress'])
                                                );
                                                $isAddedRq = $crmRq->add($arrayRQAdd);

                                                if ($isAddedRq->isSuccess()) {
                                                    //добавляем банковские рекзвизиты, а потом адреса
                                                    $arrayRQBankDetail = $this->generateRQBankDetail($notificationsDatum, $isAddedRq->getId());
                                                    $isAddedRqBank = $crmBankDetail->add($arrayRQBankDetail);

                                                    if (!$isAddedRqBank->isSuccess()) {
                                                        throw new ArgumentException("Ошибка добавления банковских реквизитов для RQ_ID = {$isAddedRq->getId()}, для Компании {$currentCompanyIdBx}, COMPANY_ID = {$currentCompanyId}. Ошибка - {$isAddedRqBank->getErrorMessages()}");
                                                    }
                                                } else {
                                                    throw new ArgumentException("Ошибка добавления Реквизитов для компании {$currentCompanyIdBx} (COMPANY_ID = {$currentCompanyId}). Ошибка - {$isAddedRq->getErrorMessages()}");
                                                }

                                                $answer['success'][] = "Добавления компании {$currentCompanyIdBx} для COMPANY_ID = {$currentCompanyId} и CONTRACT_ID = {$currentContractId}";
                                            }
                                        } elseif (count($checkCompany) == 1) {
                                            //иначе такая компания есть и нужно записать ее id
                                            $currentCompanyIdBx = end($checkCompany)['ID'];
                                        }

                                        if (!$currentCompanyIdBx) {
                                            throw new ArgumentException("Ошибка получени компании из битрикса по COMPANY_ID = {$currentCompanyId}");
                                        }

                                        //обнловляем данные в сделках
                                        $arFieldsTypeDealUpdate = array(
                                            'TITLE' => "Типовая сделка для компании {$companyName} (c {$dateBegin} по {$closeDate})",
                                            'COMPANY_ID' => $currentCompanyIdBx
                                        );

                                        $arFieldsDODealUpdate = array('COMPANY_ID' => $currentCompanyIdBx);

                                        if (!$crmDeal->Update($currentTypeDealId, $arFieldsTypeDealUpdate)) {
                                            throw new ArgumentException("Ошибка обновления типовой сделки {$currentTypeDealId} для COMPANY_ID = {$currentCompanyId} и CONTRACT_ID = {$currentContractId}. Ошибка - {$crmDeal->LAST_ERROR}");
                                        } else {
                                            $answer['success'][] = "Успешное обновление типовой сделки {$currentTypeDealId} для COMPANY_ID = {$currentCompanyId} и CONTRACT_ID = {$currentContractId}";
                                        }

                                        if (!$isUpdatedDealDO = $crmDeal->Update($currentDODealId, $arFieldsDODealUpdate)) {
                                            throw new ArgumentException("Ошибка обновления сделки DO {$currentTypeDealId} для COMPANY_ID = {$currentCompanyId} и CONTRACT_ID = {$currentContractId}. Ошибка - {$crmDeal->LAST_ERROR}");
                                        } else {
                                            $answer['success'][] = "Успешное обновление сделки DO {$currentDODealId} для COMPANY_ID = {$currentCompanyId} и CONTRACT_ID = {$currentContractId}";
                                        }

                                    } else {
                                        throw new ArgumentException("Отсутствует типовая сделка для COMPANY_ID = {$currentCompanyId}");
                                    }
                                }

                                break;
                        }

                    } catch (ArgumentException | TypeError | SystemException | Error $ex) {
                        $answer['errors'][] = $ex->getMessage();
                    }
                }
            } catch (LoaderException $el) {
                $answer['errors'][] = 'Ошибка подключения bizproc модуля';
            }


            if ($answer['errors']) {
                writeToLog("/logs/agents/do/errors/", $answer['errors']);
            }

            //записываем выполненную интеграцию
            $this->addLastTrueIntegration($this->getEndDateNotification(), $this->getNotifications()['result'], $answer, self::NOTIFICATION_CONTRACT);
        }

        return $this->getNotifications();
    }

    /**
     * @param string $entityType
     * @return void |null
     *
     * запись формированных данных из нотификаций
     */
    public function setNotificationsData(string $entityType) {
        parent::setNotificationsData($entityType);

        if (!empty($this->getNotifications()['result'])) {
            foreach ($this->getNotifications()['result'] as $key => $notification) {
                $arContractFields = $this->getContractByContractID_DO($notification['contractId']);
                $arCompanyFields = $this->getCompanyByCompanyID_DO($arContractFields['result']['companyId']);

                $this->notificationsData[$key] = array(
                    'ORIGINAL_DATA' => array('CONTRACT_ID' => $notification['contractId']),
                    'CONTRACT' => $arContractFields['result'],
                    'STATUS' => $notification['contractStatus'],
                    'COMPANY' => $arCompanyFields['result'],
                );
            }
        }
    }

    /**
     * @param array $dataField
     * @param $requisiteId
     * @param $typeAddr
     * @return array
     *
     * генерация полей для адреса в реквизитах
     */
    public function generateFieldAddress(array $dataField, $requisiteId, $typeAddr) {
        $typeId = $addr = null;
        switch ($typeAddr) {
            case self::ADDR_LEG:
                $typeId = self::ADDR_LEG;
                $addr = $dataField['CONTRACT']['legalAddress'];
                break;
            case self::ADDR_FACT:
                $typeId = self::ADDR_FACT;
                $addr = $dataField['CONTRACT']['factualAddress'];
                break;
            case self::ADDR_POST:
                $typeId = self::ADDR_POST;
                $addr = $dataField['CONTRACT']['postAddress'];
                break;
        }

        return array(
            'TYPE_ID' => $typeId,
            'ENTITY_TYPE_ID' => self::ENTITY_TYPE_ID_ADDR,
            'ENTITY_ID' => $requisiteId,
            'ANCHOR_TYPE_ID' => self::ANCHOR_ID,
            'ADDRESS_1' => $addr
        );
    }

    /**
     * @param array $dataField
     * @param $requisiteId
     * @return array
     *
     * генерация полей для банковских реквизитов
     */
    public function generateRQBankDetail(array $dataField, $requisiteId) {
        return array(
            'NAME' => "Реквизиты банка " . $dataField['CONTRACT']['bankName'],
            'ENTITY_ID' => $requisiteId,
            'ENTITY_TYPE_ID' => 8,
            'RQ_BANK_NAME' => $dataField['CONTRACT']['bankName'],
            'RQ_BIK' => $dataField['CONTRACT']['bic'],
            'RQ_ACC_NUM' => $dataField['CONTRACT']['currentAccount'],
            'RQ_COR_ACC_NUM' => $dataField['CONTRACT']['corporateAccount'],
        );
    }

    /**
     * @param array $dataField
     * @param array $fieldsRequisite
     * @param $dealId
     * @param $entityId
     * @return array
     *
     * генерация полей для реквизитов
     */
    public function generateFieldRQ(array $dataField, array $fieldsRequisite, $dealId, $entityId) {
        $returnedField = array(
            'NAME' => $dataField['CONTRACT']['fullCompanyName'],
            'RQ_COMPANY_NAME' => $dataField['CONTRACT']['shortCompanyName'],
            'RQ_COMPANY_FULL_NAME' => $dataField['CONTRACT']['fullCompanyName'],
            'RQ_COMPANY_REG_DATE' => $dataField['CONTRACT']['startDate'],
            'RQ_INN' => $dataField['CONTRACT']['inn'],
            'RQ_KPP' => $dataField['CONTRACT']['kpp'],
            'ENTITY_TYPE_ID' => self::ENTITY_TYPE_ID,
            'ENTITY_ID' => $entityId,
            'PRESET_ID' => self::PRESET_ID,
            'ACTIVE' => self::STATUS_RQ,
            'RQ_NAME' => $dataField['CONTRACT']['baileeName'],
            'UF_PHONE' => $dataField['CONTRACT']['companyPhones'],
            'UF_EMAIL' => $dataField['CONTRACT']['companyEmails'],
            'UF_EMAIL_CLOSE_DOC' => $dataField['CONTRACT']['closeDocsEmails'],
            $fieldsRequisite['RQ_SIGN_POS'] => mb_strtolower($dataField['CONTRACT']['baileePosition']),
            $fieldsRequisite['BAILE_DOCUMENT'] => $dataField['CONTRACT']['baileeDocument'],
            $fieldsRequisite['RQ_OPF'] => $dataField['CONTRACT']['ownership'],
            $fieldsRequisite['RQ_ACTIVE'] => (self::STATUS_RQ == 'N') ? 0 : 1,
            $fieldsRequisite['RQ_CONTRACT_ID'] => $dataField['CONTRACT']['contractId'],
            $fieldsRequisite['RQ_COMPANY_ID'] => $dataField['CONTRACT']['companyId'],
            $fieldsRequisite['RQ_DEAL_ID'] => $dealId
        );

        if (strlen($dataField['CONTRACT']['ogrn']) > 13)
            $returnedField['RQ_OGRNIP'] = $dataField['CONTRACT']['ogrn'];
        else
            $returnedField['RQ_OGRN'] = $dataField['CONTRACT']['ogrn'];

        return $returnedField;
    }

    /**
     * @param $typeField
     * @param array $arrayRQAdd
     * @param array $arrayData
     *
     * добавление данных контактов в реквизиты
     */
    public function addedFmData($typeField, array &$arrayRQAdd, array $arrayData) {
        if (!empty($arrayData)) {
            $keyUF = DevinoFields::getIdByKey(CCrmOwnerType::Requisite, $typeField);

            foreach ($arrayData as $itemData) {
                $arrayRQAdd[$keyUF][] = $itemData;
            }
        }
    }
}