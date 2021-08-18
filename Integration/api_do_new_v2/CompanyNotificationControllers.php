<?php

use Bitrix\Crm\ContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use \Devino\Field\DevinoFields;

//регистрация = компания (компания без названия и реквизитов) + контакт
//регистрация = стадия страрт1
//передвигаться будет по агенту
//закрываться будет в конце месяца
//прилетает contract_id и создается сделка DO
//если типовая сделка не переросла в сделку DO, то она переходит на сл. сделку

class CompanyNotificationControllers extends NotificationController implements EntityNotificationInterface {

    //тип клиента = DO для всех сущностей
    const TYPE_CLIENT_DO_DEAL = 383;
    const TYPE_CLIENT_DO_CONTACT = 379;

    // пользователь для сделки
    const DEFAULT_USER_FOR_DEAL = 820;

    //начальная стадия типовой сделки
    const STAGE_DEAL = 'C17:NEW';

    const CATEGORY_DEAL_ID = 17;


    public function __construct($startDateNotification, $mode = self::PROD_MODE) {
        parent::__construct($mode);

        //устанавливаем даты (старта, окончания) сбора нотифкаций
        $this->setAllQueryDate($startDateNotification, self::NOTIFICATION_COMPANY);

        //сбор нотфикаций по КОНКРЕТНОЙ  сущности сущности
        $this->setNotificationsData(self::NOTIFICATION_COMPANY);
    }


    public function executeNotification(bool $modifyCRMEntity = false) {
        //выбираем новые нотификации из DO со всеми дополнительными нам данными по сущностям
        $notifications = $this->getNotificationsData();

        //записываем айдишники полей нужных нам сущностей
        $ufFieldDeal = DevinoFields::getIdByKeys(CCrmOwnerType::Deal,
            array('COMPANY_ID', 'TYPE_COMPANY', 'PREDSTAV', 'UR_LICO_PREDSTAV', 'USER_ID', 'MAIN_CONTACT')); //uf поля для сделки

        $ufFieldContact = DevinoFields::getIdByKeys(CCrmOwnerType::Contact,
            array('COMPANY_ID', 'TYPE_CLIENT', 'PREDSTAV', 'UR_LICO_PREDSTAV', 'USER_ID')); //uf поля для контакта


        $answer = null;

        if ($modifyCRMEntity == true) {

            if (!$ufFieldDeal || !$ufFieldContact) {
                $answer['errors'][] = "Ошибка получения пользовательских полей для сделки и контакта";
            }

            $crmContactObject = new CCrmContact(false);
            $crmDealObject = new CCrmDeal(false);

            foreach ($notifications as $key => $entity) {
                try {
                    $contactId = 0;

                    //записываем представительство
                    $officeId = $entity['COMPANY']['officeId'];
                    $getPredstav = $this->getPredstav($officeId);
                    $getUrLicoPredstav = $this->getUrLicoPredstav($getPredstav);

                    //id companyId с платформы
                    $companyId = $entity['COMPANY']['companyId'];

                    if (!$companyId) {
                        throw new ArgumentException("Ошибка получения данных нотификации по COMPANY_ID - {$entity['ORIGINAL_DATA']['COMPANY_ID']}");
                    }

                    //userData  (первый зарегестрированный пользователь)
                    $userData = end($entity['USERS']);
                    if (!$userData) {
                        throw new ArgumentException("Нет данных по пользователям текущей компании COMPANY_ID = {$entity['ORIGINAL_DATA']['COMPANY_ID']}");
                    }

                    //идентификатор пользователя
                    $userId = $userData['userId'];

                    $firstDayMonth = new \Bitrix\Main\Type\DateTime(date('Y-m-d', strtotime('first day of ' . $this->getStartDateNotification())) . ' 00:00:00', 'Y-m-d H:i:s');
                    $lastDayMonth = new \Bitrix\Main\Type\DateTime(date('Y-m-d', strtotime('last day of ' . $this->getEndDateNotification())) . ' 00:00:00', 'Y-m-d H:i:s');

                    //проверяем, что такая типовая сделка уже есть. Если есть - обновляем, иначе создаем
                    $checkDealExist = DealTable::getList(array(
                        'select' => array('ID', 'ASSIGNED_BY_ID'),
                        'filter' => array(
                            '>=BEGINDATE' => $firstDayMonth,
                            '<=CLOSEDATE' => $lastDayMonth,
                            '=CATEGORY_ID' => self::CATEGORY_DEAL_ID,
                            '!=STAGE_ID' => array('C17:WON'),
                            '=' . $ufFieldDeal['COMPANY_ID'] => $companyId,
                            '=' . $ufFieldDeal['TYPE_COMPANY'] => self::TYPE_CLIENT_DO_DEAL
                        ),
                        'order' => array('ID' => 'DESC')
                    ))->fetchAll();

                    $checkContactByUserId = ContactTable::getList(array(
                        'select' => array('ID', 'NAME', 'LAST_NAME', 'POST', 'ASSIGNED_BY_ID'),
                        'filter' => array(
                            '=' . $ufFieldContact['USER_ID'] => $userId,
                            '=' . $ufFieldContact['COMPANY_ID'] => $companyId
                        ),
                        'order' => array('ID' => 'DESC')
                    ))->fetchAll();

                    if (count($checkContactByUserId) > 1) {
                        //если количество контактов с таким $UserId > 1
                        throw new ArgumentException('По USER_ID = ' . $userId . ' найдено больше одного контакта');

                    } elseif (count($checkContactByUserId) == 0) {

                        //иначе контаткта нет и его нужно создать
                        $fieldsContactAdd = array(
                            'FULL_NAME' => $userData['userPreferences']['firstName'] . ' ' . $userData['userPreferences']['lastName'],
                            'NAME' => $userData['userPreferences']['firstName'] ?? "неизв",
                            'LAST_NAME' => $userData['userPreferences']['lastName'],
                            'ASSIGNED_BY_ID' => ($entity['COMPANY']['manager']) ? $this->getUserIdByLogin($entity['COMPANY']['manager']) : self::DEFAULT_USER_FOR_DEAL,
                            'SOURCE_ID' => self::SOURCE_REGISTER_NLK,
                            'POST' => $userData['userPreferences']['position'],
                            'FM' => array(
                                'PHONE' => array(
                                    'n0' => array(
                                        'VALUE' => $userData['userPreferences']['phone'],
                                        'VALUE_TYPE' => 'WORK'
                                    )
                                ),
                                'EMAIL' => array(
                                    'n0' => array(
                                        'VALUE' => $userData['userPreferences']['email'],
                                        'VALUE_TYPE' => 'WORK'
                                    )
                                )
                            ),
                            $ufFieldContact['COMPANY_ID'] => $companyId, // COMPANY_ID
                            $ufFieldContact['USER_ID'] => $userId, // USER_ID
                            $ufFieldContact['TYPE_CLIENT'] => self::TYPE_CLIENT_DO_CONTACT,
                            $ufFieldContact['PREDSTAV'] => $getPredstav, //записываем поле из компании
                            $ufFieldContact['UR_LICO_PREDSTAV'] => $getUrLicoPredstav, //записываем поле из компании
                        );

                        if (!$isAdded = $crmContactObject->Add($fieldsContactAdd, true, array('REGISTER_SONET_EVENT' => true))) {
                            throw new ArgumentException("Ошибка добавления контакта по USER_ID = {$userId} и COMPANY_ID = {$companyId}. Ошибка - {$crmContactObject->LAST_ERROR}");
                        } else {
                            $answer['success'][] = "Добавлен контакт для USER_ID = {$userId} и COMPANY_ID = {$companyId}";
                        }

                        $contactId = $isAdded;
                    } elseif (count($checkContactByUserId) == 1) {
                        //то такой контакт 1 и его нужно обновить

                        $currentContact = end($checkContactByUserId);

                        $arFieldsTemp = array();

                        //проверяем нужно ли нам вообще обновление полей и fm полей
                        $this->checkFieldForUpdated($arFieldsTemp, $currentContact['NAME'], 'NAME', $userData['userPreferences']['firstName']);
                        $this->checkFieldForUpdated($arFieldsTemp, $currentContact['LAST_NAME'], 'LAST_NAME', $userData['userPreferences']['lastName']);
                        $this->checkFieldForUpdated($arFieldsTemp, $currentContact['ASSIGNED_BY_ID'], 'ASSIGNED_BY_ID', (int)$this->getUserIdByLogin($entity['COMPANY']['manager']));
                        $this->checkFieldForUpdated($arFieldsTemp, $currentContact['POST'], 'POST', $userData['userPreferences']['position']);

                        if ($arFieldsTemp) {
                            if (!$isUpdated = $crmContactObject->Update($currentContact['ID'], $arFieldsTemp, true, true, array('REGISTER_SONET_EVENT' => true))) {
                                throw new ArgumentException("Ошибка обновления контакта {$currentContact['ID']} по USER_ID = {$userId} и COMPANY_ID = {$companyId}. Ошибка - {$crmContactObject->LAST_ERROR}");
                            } else {
                                $answer['success'][] = "Обновлени контакт {$currentContact['ID']} по USER_ID = {$userId} и COMPANY_ID = {$companyId}";
                            }
                        }

                        $contactId = $currentContact['ID'];
                    }

                    if (count($checkDealExist) == 0) {
                        //если типовой сделки по такой companyId еще нет, то создаем сделку
                        $dealFieldsAdd = array(
                            'TITLE' => "Типовая сделка для пользователя " . $userData['userPreferences']['lastName'] . " " . $userData['userPreferences']['firstName'] . " (с {$firstDayMonth->format('d.m.Y')} по {$lastDayMonth->format('d.m.Y')})",
                            'ASSIGNED_BY_ID' => ($entity['COMPANY']['manager']) ? $this->getUserIdByLogin($entity['COMPANY']['manager']) : self::DEFAULT_USER_FOR_DEAL,
                            'STATUS_ID' => self::STAGE_DEAL,
                            'SOURCE_ID' => self::SOURCE_REGISTER_NLK,// Источник
                            'CONTACT_ID' => $contactId,
                            'CATEGORY_ID' => self::CATEGORY_DEAL_ID,
                            'FM' => array(
                                'PHONE' => array(
                                    'n0' => array(
                                        'VALUE' => $userData['userPreferences']['phone'],
                                        'VALUE_TYPE' => 'WORK'
                                    )
                                ),
                                'EMAIL' => array(
                                    'n0' => array(
                                        'VALUE' => $userData['userPreferences']['email'],
                                        'VALUE_TYPE' => 'WORK'
                                    )
                                )
                            ),
                            $ufFieldDeal['COMPANY_ID'] => $companyId,// COMPANY_ID
                            $ufFieldDeal['UR_LICO_PREDSTAV'] => $getUrLicoPredstav,// Юр. лицо представительства
                            $ufFieldDeal['PREDSTAV'] => $getPredstav, // Представительство из
                            $ufFieldDeal['USER_ID'] => $userId, // USER_ID
                            $ufFieldDeal['TYPE_COMPANY'] => self::TYPE_CLIENT_DO_DEAL,// Присваиваем сделке тип DO
                            $ufFieldDeal['MAIN_CONTACT'] => $contactId,// основное контактное лицо,
                        );


                        if (!$idAddedDeal = $crmDealObject->Add($dealFieldsAdd, true, array('REGISTER_SONET_EVENT' => true))) {
                            throw new ArgumentException("Ошибка добавления типовой сделки по COMPANY_ID = {$companyId}. Ошибка - {$crmDealObject->LAST_ERROR}");
                        } else {
                            $answer['success'][] = "Успешное добавление типовой сделки {$idAddedDeal} для COMPANY_ID = {$companyId}";

                            $dateUpdate = array('BEGINDATE' => $firstDayMonth, 'CLOSEDATE' => $lastDayMonth);

                            //обновляем дату начала и дату окончания
                            $isDealUpdated = DealTable::update($idAddedDeal, $dateUpdate);

                            if (!$isDealUpdated->isSuccess()) {
                                throw new ArgumentException("Ошибка обновления даты окончания и даты начала типовой сделки {$idAddedDeal} для COMPANY_ID = {$companyId}. Ошибка - " . serialize($isDealUpdated->getErrorMessages()) . "}");
                            }

                            //если сделка типовая добавлена => запускаем БП "ПРи создании" TODO::переделать
                            $bpErrors = [];
                            CBPDocument::StartWorkflow(578,
                                array('crm', 'CCrmDocumentDeal', "DEAL_{$idAddedDeal}"),
                                [], $bpErrors
                            );

                            if ($bpErrors) {
                                $errStr = implode(', ', $bpErrors);
                                throw new ArgumentException("Ошибка запуска BP для типовой сделки. Ошибка {$errStr}");
                            }

                        }
                    } else {
                        //сделка уже существует и ее нужно просто обнвоить
                        $currentDealData = end($checkDealExist);

                        $arFieldsTmpDeal = array();

                        //проверяем нужно ли нам вообще обновление полей и fm полей
                        $this->checkFieldForUpdated($arFieldsTmpDeal, $currentDealData['ASSIGNED_BY_ID'], 'ASSIGNED_BY_ID', (int)$this->getUserIdByLogin($entity['COMPANY']['manager']));

                        if ($arFieldsTmpDeal) {
                            if (!$isUpdated = $crmDealObject->Update($currentDealData['ID'], $arFieldsTmpDeal, true, true, array('REGISTER_SONET_EVENT' => true))) {
                                throw new ArgumentException("Ошибка обновления типовой сделки {$currentDealData['ID']} для COMPANY_ID = {$companyId} и CONTRACT_ID = {$contactId}. Ошибка - {$crmDealObject->LAST_ERROR}");
                            } else {
                                $answer['success'][] = "Успешное обновления полей в сделке {$currentDealData['ID']} для компании {$companyId}";
                            }
                        }
                    }
                } catch (ArgumentException | SystemException | Exception $exception) {
                    $answer['errors'][] = $exception->getMessage();
                }
            }

            if ($answer['errors']) {
                writeToLog("/logs/agents/deals/do/errors/", $answer['errors']);
            }

            //записываем выполненную интеграцию
            $this->addLastTrueIntegration($this->getEndDateNotification(), $this->getNotifications()['result'], $answer, self::NOTIFICATION_COMPANY);
        }

        return $this->getNotifications();
    }

    /**
     * @param string $entityType
     * @return mixed|void
     * запись формированных данных из нотификаций
     */
    public function setNotificationsData(string $entityType) {
        parent::setNotificationsData($entityType);

        if (!empty($this->getNotifications()['result'])) {
            foreach ($this->getNotifications()['result'] as $key => $compId) {
                //если компания не мигрирована из DY то записываем эту нотификацию
                $arCompanyFields = $this->getCompanyByCompanyID_DO($compId['companyId']);
                $arUsersFields = $this->getUsersByCompanyID_DP($compId['companyId']);

                //TODO::пока сделали чтобы лиды создавались для представительств
                if (($compId['isMigrated'] == false) || ($arCompanyFields['result']['officeId'] != 1)) {
                    $this->notificationsData[$key] = array(
                        'ORIGINAL_DATA' => array('COMPANY_ID' => $compId['companyId']),
                        'COMPANY' => $arCompanyFields['result'],
                        'USERS' => $arUsersFields['result'],
                    );
                }
            }
        }
    }
}