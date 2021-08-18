<?php

use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\ContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use \Devino\Field\DevinoFields;

class UserNotificationControllers extends NotificationController implements EntityNotificationInterface {
    const CATEGORY_DEAL_ID = 17;
    const TYPE_CLIENT_DO_DEAL = 383;

    public function __construct($startDateNotification, $mode = self::PROD_MODE) {
        parent::__construct($mode);

        //устанавливаем даты (старта, окончания) сбора нотифкаций
        $this->setAllQueryDate($startDateNotification, self::NOTIFICATION_USER);

        //сбор нотфикаций по КОНКРЕТНОЙ  сущности сущности
        $this->setNotificationsData(self::NOTIFICATION_USER);
    }

    public function executeNotification(bool $modifyCRMEntity = false) {
        //выбираем новые нотификации из DO со всеми дополнительными нам данными по сущностям
        $notifications = $this->getNotificationsData();

        $ufFieldContact = DevinoFields::getIdByKeys(CCrmOwnerType::Contact, array('USER_ID', 'COMPANY_ID', 'TYPE_CLIENT', 'PREDSTAV', 'UR_LICO_PREDSTAV'));
        $ufFieldCompany = DevinoFields::getIdByKeys(CCrmOwnerType::Company, array('COMPANY_ID', 'UR_LICO_PREDSTAV', 'PREDSTAV'));
        $ufFieldDeal = DevinoFields::getIdByKeys(CCrmOwnerType::Deal, array('COMPANY_ID', 'TYPE_COMPANY', 'PREDSTAV', 'UR_LICO_PREDSTAV', 'USER_ID', 'MAIN_CONTACT'));

        $typeClientDOContact = DevinoFields::getValueByList(CCrmOwnerType::Contact, 'TYPE_CLIENT', 'DO');

        $answer = null;

        if ($modifyCRMEntity) {

            //проверяем заполнены ли uf поля
            if (!$ufFieldContact || !$ufFieldCompany || !$typeClientDOContact) {
                $answer['errors'][] = 'Ошибка получения пользовательских полей';
            }

            $crmContact = new CCrmContact(false);

            //главный код
            foreach ($notifications as $key => $entity) {
                try {
                    //внчале проверяем существует ли контакт по userId
                    $userId = $entity['USER']['userId'];
                    $companyId = $entity['USER']['companyId'];

                    if (!$userId || !$companyId) {
                        throw new ArgumentException("Неизвестный USER_ID - {$entity['ORIGINAL_DATA']['USER_ID']}");
                    }

                    // проверяем существует ли такой контакт по userId
                    $getContactByUserId = ContactTable::getList(array(
                        'filter' => array("=" . $ufFieldContact['USER_ID'] => $userId, "=" . $ufFieldContact['COMPANY_ID'] => $companyId),
                        'select' => array("ID", "NAME", "LAST_NAME", $ufFieldContact['COMPANY_ID'], "POST")
                    ))->fetchAll();


                    if (count($getContactByUserId) == 1) {
                        //значит контакт по userId и companyId уже есть и его нужно обновить
                        $currentContact = end($getContactByUserId);

                        $arFieldsTemp = array();

                        //проверяем нужно ли нам вообще обновление полей и fm полей
                        $this->checkFieldForUpdated($arFieldsTemp, $currentContact['NAME'], 'NAME', $entity['PREFERENCES']['firstName']);
                        $this->checkFieldForUpdated($arFieldsTemp, $currentContact['LAST_NAME'], 'LAST_NAME', $entity['PREFERENCES']['lastName']);
                        $this->checkFieldForUpdated($arFieldsTemp, $currentContact['POST'], 'POST', $entity['PREFERENCES']['position']);

                        if ($arFieldsTemp) {
                            if (!$isUpdatedContact = $crmContact->Update($currentContact['ID'], $arFieldsTemp)) {
                                throw new ArgumentException($crmContact->LAST_ERROR);
                            } else {
                                $answer['success'][] = "Обновлены поля пользователь {$currentContact['ID']} (USER_ID = {$userId}) для COMPANY_ID = {$companyId}";
                            }
                        }
                    } elseif (count($getContactByUserId) == 0) {
                        //если контакт по userID не найден, значит ищем компанию по companyId
                        $getCompanyByCompanyIdDO = CompanyTable::getList(array(
                            'filter' => array("=" . $ufFieldCompany['COMPANY_ID'] => $companyId),
                            'select' => array("ID", "ASSIGNED_BY_ID", $ufFieldCompany['PREDSTAV'], $ufFieldCompany['UR_LICO_PREDSTAV'])
                        ))->fetchAll();

                        //такая компания существует, значит создаем контакт и привязываем к ней
                        if (count($getCompanyByCompanyIdDO) == 1) {
                            $currentCompany = end($getCompanyByCompanyIdDO);

                            $arFieldsTemp = array(
                                'TITLE' => $entity['PREFERENCES']['firstName'] . ' ' . $entity['PREFERENCES']['lastName'],
                                'NAME' => $entity['PREFERENCES']['firstName'],
                                'LAST_NAME' => $entity['PREFERENCES']['lastName'],
                                'ASSIGNED_BY_ID' => $currentCompany['ASSIGNED_BY_ID'],
                                'COMPANY_ID' => $currentCompany['ID'],
                                'FM' => array(
                                    'PHONE' => array(
                                        'n0' => array(
                                            'VALUE' => $entity['PREFERENCES']['phone'],
                                            'VALUE_TYPE' => 'WORK'
                                        )
                                    ),
                                    'EMAIL' => array(
                                        'n0' => array(
                                            'VALUE' => $entity['PREFERENCES']['email'],
                                            'VALUE_TYPE' => 'WORK'
                                        )
                                    )
                                ),
                                'SOURCE_ID' => self::SOURCE_REGISTER_NLK,
                                $ufFieldContact['COMPANY_ID'] => $companyId, // COMPANY_ID
                                $ufFieldContact['USER_ID'] => $userId, // USER_ID
                                $ufFieldContact['TYPE_CLIENT'] => $typeClientDOContact,
                                $ufFieldContact['PREDSTAV'] => $currentCompany[$ufFieldCompany['PREDSTAV']], //записываем поле из компании
                                $ufFieldContact['UR_LICO_PREDSTAV'] => $currentCompany[$ufFieldCompany['UR_LICO_PREDSTAV']] //записываем поле из компании
                            );

                            if (!$isAddContact = $crmContact->Add($arFieldsTemp)) {
                                throw new ArgumentException("Ошибка добавления пользователя (USER_ID = {$userId}) для COMPANY_ID = {$companyId}. Ошибка - {$crmContact->LAST_ERROR}");
                            } else {
                                $answer['success'][] = "Добавлен пользователь {$isAddContact} (USER_ID = {$userId}) для COMPANY_ID = {$companyId}";
                            }
                        } elseif (count($getCompanyByCompanyIdDO) == 0) {
                            //если по компании и контакту инфы не найдено, значит это типовая сделка, проверяем это и обновляем контакт из нее
                            $typeDeals = DealTable::getList(array(
                                'filter' => array(
                                    '=CATEGORY_ID' => self::CATEGORY_DEAL_ID,
                                    '!=STAGE_ID' => array('C17:WON'),
                                    '=' . $ufFieldDeal['COMPANY_ID'] => $companyId,
                                    '=' . $ufFieldDeal['TYPE_COMPANY'] => self::TYPE_CLIENT_DO_DEAL
                                ),
                                'select' => array('ID', 'CONTACT_ID', 'TITLE', 'BEGINDATE', 'CLOSEDATE')
                            ))->fetchAll();

                            if (count($typeDeals) == 1) {
                                //выбиаем данные по конаткту
                                $dealData = end($typeDeals);
                                $currentContactId = $dealData['CONTACT_ID'];

                                $currentContactData = ContactTable::getList(array(
                                    'filter' => array('=ID' => $currentContactId),
                                    'select' => array('NAME', 'LAST_NAME', 'ID', 'POST')
                                ))->fetch();

                                $arFieldsTemp = array();

                                //тут проверки на то, что обновляемые значение равные текущим и обновляеть нам их не нужно, если это так
                                $this->checkFieldForUpdated($arFieldsTemp, $currentContactData['NAME'], 'NAME', $entity['PREFERENCES']['firstName']);
                                $this->checkFieldForUpdated($arFieldsTemp, $currentContactData['LAST_NAME'], 'LAST_NAME', $entity['PREFERENCES']['lastName']);
                                $this->checkFieldForUpdated($arFieldsTemp, $currentContactData['POST'], 'POST', $entity['PREFERENCES']['position']);

                                if ($arFieldsTemp) {
                                    if (!$isContactUpdate = $crmContact->Update($currentContactData['ID'], $arFieldsTemp)) {
                                        throw new ArgumentException("Ошибка обновления контакта {$currentContactData['ID']} (USER_ID = {$userId}) для COMPANY_ID = {$companyId}. Ошибка - {$crmContact->LAST_ERROR}");
                                    } else {
                                        $answer['success'][] = "Обновлен пользователь {$currentContactData['ID']} (USER_ID = {$userId}) для COMPANY_ID = {$companyId}";
                                    }
                                }

                            } elseif (count($typeDeals) == 0) {
                                throw new ArgumentException("Не существует типовой сделки для COMPANY_ID = {$companyId}");
                            } elseif (count($typeDeals) > 1) {
                                throw new ArgumentException("Существует больше одной типовой сделки для COMPANY_ID = {$companyId}");
                            }
                        } elseif (count($getCompanyByCompanyIdDO) > 1) {
                            throw new ArgumentException("Компании в битриксе с COMPANY_ID = {$companyId} больше одной");
                        }
                    } elseif (count($getContactByUserId) > 1) {
                        //значит контактов по USER_ID больше одного
                        throw new ArgumentException("По USER_ID = {$userId} и COMPANY_ID = {$companyId} найдено больше одного контакта");
                    }
                } catch (ArgumentException | SystemException $e) {
                    $answer['errors'][] = $e->getMessage();
                }
            }

            if ($answer['errors']) {
                writeToLog("/logs/agents/deals/do/errors/", $answer['errors']);
            }

            //записываем выполненную интеграцию
            $this->addLastTrueIntegration($this->getEndDateNotification(), $this->getNotifications()['result'], $answer, self::NOTIFICATION_USER);
        }

        return $this->getNotifications();
    }

    public function setNotificationsData(string $entityType) {
        parent::setNotificationsData($entityType);

        if (!empty($this->getNotifications()['result'])) {
            foreach ($this->getNotifications()['result'] as $userId) {
                $arUserFields = $this->getUserByUserID_DO($userId['userId']);
                $arUserPreferencesFields = $this->getUserPreferencesByUserID_DO($userId['userId']);

                $this->notificationsData[] = array(
                    'ORIGINAL_DATA' => array('USER_ID' => $userId['userId']),
                    'USER' => $arUserFields['result'],
                    'PREFERENCES' => $arUserPreferencesFields['result']
                );
            }
        }
    }
}

