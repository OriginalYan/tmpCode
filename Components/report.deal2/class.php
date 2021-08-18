<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\StatusTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\UI\Filter\DateType;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Main\UserGroupTable;
use Bitrix\Main\UserTable;
use \Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\UserField\FieldEnumTable;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/DevinoFields.php';


class reportDealComponentNew extends \CBitrixComponent {
    const CATEGORY_DEAL_ID = 17,
        DEFAULT_DEST = array(
        'enableDepartments' => 'N',
        'enableUsers' => 'N',
        'enableCrm' => 'Y',
        'crmPrefixType' => 'SHORT',
        'returnItemUrl' => 'Y',
    ),
        PLATFORMS_NAME = array(
        'DO' => 'Devino Online',
        'MD' => 'My Devino',
        'TOTAL' => 'Total'
    ),
        EXCLUDE_STAGES_DEAL = array("C" . self::CATEGORY_DEAL_ID . ":WON", "C" . self::CATEGORY_DEAL_ID . ":LOSE"),
        EXCLUDE_STAGES_COMPANY = array("C" . self::CATEGORY_DEAL_ID . ":WON", "C" . self::CATEGORY_DEAL_ID . ":LOSE", "C" . self::CATEGORY_DEAL_ID . ':NEW'),
        IBLOCK_DEPART = 5,
        IS_ALL_USER_CHECK = array('e.istomina', 'e.shuvalova'),
        ADMIN_REPORT_GROUP = 54;

    public function getStagesForm(): array {
        if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
            return array(
                'C' . self::CATEGORY_DEAL_ID . ':PREPARATION' => 'PROFIT FC / MAVG3 < 0.90',
                'C' . self::CATEGORY_DEAL_ID . ':DROP' => 'PROFIT FC / MAVG3 > 0.90',
                'C' . self::CATEGORY_DEAL_ID . ':NEGATIVE' => 'PROFIT FC / MAVG3 > 0.95',
                'C' . self::CATEGORY_DEAL_ID . ':ZERO' => 'PROFIT FC / MAVG3 ≥ 1.00',
                'C' . self::CATEGORY_DEAL_ID . ':LOW' => 'PROFIT FC / MAVG3 > 1.05',
                'C' . self::CATEGORY_DEAL_ID . ':MEDIUM' => 'PROFIT FC / MAVG3 > 1.10',
                'C' . self::CATEGORY_DEAL_ID . ':HIGHT' => 'PROFIT FC / MAVG3 > 1.20',
                'C' . self::CATEGORY_DEAL_ID . ':EXCELLENT' => 'PROFIT FC / MAVG3 > 1.30',
            );
        } elseif ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
            if ($this->arResult['FILTER_DATA']['STATUS_TYPE'] == 'UF_PROFIT_AVG3_STATUS' || $this->arResult['FILTER_DATA']['STATUS_TYPE'] == 'UF_SELLAMOUNT_AVG3_STATUS') {
                return array(
                    'C' . self::CATEGORY_DEAL_ID . ':PREPARATION' => 'PROFIT FC / MAVG3 < 0.90',
                    'C' . self::CATEGORY_DEAL_ID . ':DROP' => 'PROFIT FC / MAVG3 > 0.90',
                    'C' . self::CATEGORY_DEAL_ID . ':NEGATIVE' => 'PROFIT FC / MAVG3 > 0.95',
                    'C' . self::CATEGORY_DEAL_ID . ':ZERO' => 'PROFIT FC / MAVG3 ≥ 1.00',
                    'C' . self::CATEGORY_DEAL_ID . ':LOW' => 'PROFIT FC / MAVG3 > 1.05',
                    'C' . self::CATEGORY_DEAL_ID . ':MEDIUM' => 'PROFIT FC / MAVG3 > 1.10',
                    'C' . self::CATEGORY_DEAL_ID . ':HIGHT' => 'PROFIT FC / MAVG3 > 1.20',
                    'C' . self::CATEGORY_DEAL_ID . ':EXCELLENT' => 'PROFIT FC / MAVG3 > 1.30',
                );
            } elseif ($this->arResult['FILTER_DATA']['STATUS_TYPE'] == 'UF_QTY_AVG3_STATUS') {
                return array(
                    'C' . self::CATEGORY_DEAL_ID . ':PREPARATION' => 'TRAFFIC FC / MAVG3 < 0.50',
                    'C' . self::CATEGORY_DEAL_ID . ':DROP' => 'TRAFFIC FC / MAVG3 > 0.50',
                    'C' . self::CATEGORY_DEAL_ID . ':NEGATIVE' => 'TRAFFIC FC / MAVG3 > 0.75',
                    'C' . self::CATEGORY_DEAL_ID . ':ZERO' => 'TRAFFIC FC / MAVG3 ≥ 0.95',
                    'C' . self::CATEGORY_DEAL_ID . ':LOW' => 'TRAFFIC FC / MAVG3 > 1.10',
                    'C' . self::CATEGORY_DEAL_ID . ':MEDIUM' => 'TRAFFIC FC / MAVG3 > 1.15',
                    'C' . self::CATEGORY_DEAL_ID . ':HIGHT' => 'TRAFFIC FC / MAVG3 > 1.20',
                    'C' . self::CATEGORY_DEAL_ID . ':EXCELLENT' => 'TRAFFIC FC / MAVG3 > 1.30',
                );
            }
        }

        return [];
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function setViewUserType() {
        global $USER;

        $usersInGroup = array_column(UserGroupTable::getList(array(
            'select' => array('USER_ID'),
            'filter' => array('=GROUP_ID' => self::ADMIN_REPORT_GROUP),
            'group' => array('USER_ID'),
        ))->fetchAll(), 'USER_ID');

        if ($USER->IsAdmin() || in_array($USER->GetID(), $usersInGroup)) {
            //тогда берется план пользователя "Общий план"
            $this->arResult['TYPE_SALES_PLAN'] = array('TYPE' => 'ADMIN');
        } else {
            //тогда берется план пользователей, которые состоят в департаменте у кого текущий пользователь = глава
            $userId = $USER->GetID();
            $userLogin = $USER->GetLogin();

            $getDepartByHeads = array_column(SectionTable::getList(array(
                'select' => array('ID'),
                'filter' => array(
                    '=IBLOCK_ID' => self::IBLOCK_DEPART,

                    //TODO::костыль для отображения данных Шуваловой для Истоминой
                    '=UTS_SECTION_TABLE.UF_HEAD' => $userId == 854 ? array($userId, 784) : $userId
                ),
                'runtime' => array(
                    new ReferenceField(
                        'UTS_SECTION_TABLE',
                        \UtsIblockSectionTable::class,
                        Join::on('this.ID', 'ref.VALUE_ID')
                    )
                )
            ))->fetchAll(), 'ID');

            $whoInDepart = array_column(UserTable::getList(array(
                'select' => array('LOGIN'),
                'filter' => array('=UF_DEPARTMENT' => $getDepartByHeads)
            ))->fetchAll(), 'LOGIN');

            if (!$whoInDepart) {
                //если текущий пользователь не руководитель он видет только себя
                $this->arResult['TYPE_SALES_PLAN'] = array(
                    'TYPE' => 'ONLY_USER',
                    'USER_ID' => $userId,
                    'USER_LOGIN' => $userLogin
                );
            } else {
                if (!in_array($userLogin, $whoInDepart)) {
                    $whoInDepart[] = $userLogin;
                }

                $this->arResult['TYPE_SALES_PLAN'] = array(
                    'TYPE' => 'HEAD_DEPART',
                    'USER_ID' => $userId,
                    'WHO_IN_DEP' => $whoInDepart,
                    'USER_LOGIN' => $userLogin
                );
            }
        }
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getDepartmentStructure(): array {
        $departStructure = [];
        $userList = [];

        if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART') {
            $userList[] = $this->arResult['TYPE_SALES_PLAN']['USER_LOGIN'];

            //TODO::костыль, который позволяет Истоминой видеть Шувалову
            if ($this->arResult['TYPE_SALES_PLAN']['USER_LOGIN'] == 'e.istomina') {
                $userList[] = 'e.shuvalova';
            }
        }

        $filter = array(
            '=IBLOCK_ID' => self::IBLOCK_DEPART,
            'ACTIVE' => 'Y',
            '=USER_TABLE.ACTIVE' => 'Y',
            '!%USER_TABLE.ACTIVE' => 'imconnector_',
            '=GLOBAL_ACTIVE' => 'Y',
            '!=DEPTH_LEVEL' => array(1, 2)
        );

        if ($userList) {
            $filter['=USER_TABLE.LOGIN'] = $userList;
        }

        $departments = SectionTable::getList(array(
            'select' => array('ID', 'NAME', 'HEAD_DEPART_LOGIN' => 'USER_TABLE.LOGIN'),
            'filter' => $filter,
            'runtime' => array(
                new ReferenceField(
                    'UTS_DEPART',
                    UtsIblockSectionTable::class,
                    Join::on('this.ID', 'ref.VALUE_ID')
                ),
                new ReferenceField(
                    'USER_TABLE',
                    UserTable::class,
                    Join::on('this.UTS_DEPART.UF_HEAD', 'ref.ID')
                )
            )
        ))->fetchAll();

        $departmentsId = array_column($departments, 'ID');

        $whoInDep = UserTable::getList(array(
            'select' => array('UF_DEPARTMENT', 'LOGIN'),
            'filter' => array(
                '=ACTIVE' => 'Y',
                '!%LOGIN' => 'imconnector_',
                'UF_DEPARTMENT' => $departmentsId
            )
        ))->fetchAll();


        foreach ($departments as $depart) {
            $departStructure[$depart['HEAD_DEPART_LOGIN']][$depart['ID']]['DEPART_DATA'] = array(
                'ID' => $depart['ID'],
                'NAME' => $depart['NAME']
            );

            foreach ($whoInDep as $wd) {
                foreach ($wd['UF_DEPARTMENT'] as $depId) {
                    if ($depId == $depart['ID']) {

                        if (!in_array($depart['HEAD_DEPART_LOGIN'], $departStructure[$depart['HEAD_DEPART_LOGIN']][$depId]['USERS'])) {
                            $departStructure[$depart['HEAD_DEPART_LOGIN']][$depId]['USERS'][] = $depart['HEAD_DEPART_LOGIN'];
                        }

                        if (!in_array($wd['LOGIN'], $departStructure[$depart['HEAD_DEPART_LOGIN']][$depId]['USERS'])) {
                            $departStructure[$depart['HEAD_DEPART_LOGIN']][$depId]['USERS'][] = $wd['LOGIN'];
                        }
                    }
                }
            }
        }

        return $departStructure;
    }

    public function executeComponent(): void {

        try {

            //id фильтра
            $this->arResult['FILTER_ID'] = 'report_deal';

            $itemsNumsFor = array('UF_QTY_AVG3_STATUS' => 'По трафику', 'UF_PROFIT_AVG3_STATUS' => 'По профиту');

            if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
                $itemsNumsFor['UF_SELLAMOUNT_AVG3_STATUS'] = 'По выручке';
            }

            $this->setViewUserType();

            //назначаем поля для фильтра
            $this->arResult['FILTER_ROWS'] = array(
                array('id' => 'BEGINDATE', 'name' => 'Интервал Даты', 'default' => true, 'type' => 'date',
                    'exclude' => array(
                        DateType::YESTERDAY, DateType::CURRENT_DAY, DateType::CURRENT_WEEK, DateType::LAST_7_DAYS,
                        DateType::NEXT_DAYS, DateType::NEXT_MONTH, DateType::NEXT_WEEK, DateType::LAST_WEEK, DateType::TOMORROW,
                        DateType::PREV_DAYS, DateType::EXACT, DateType::LAST_30_DAYS, DateType::LAST_60_DAYS, DateType::LAST_90_DAYS,
                        DateType::RANGE
                    )),

                array('id' => 'COMPANY_ID', 'name' => 'Компания', 'default' => true,
                    'type' => 'dest_selector', 'params' => array_merge(self::DEFAULT_DEST, array('enableCrmCompanies' => 'Y'))),
                array('id' => 'EXCLUDE_COMPANY_ID', 'name' => 'Исключить компании', 'default' => true,
                    'type' => 'dest_selector', 'params' => array_merge(self::DEFAULT_DEST, array('enableCrmCompanies' => 'Y', 'multiple' => true))),
                array('id' => 'CHANNEL', 'name' => 'Канал', 'default' => true,
                    'type' => 'list', 'items' => $this->getChannels()),
                array('id' => 'PLATFORM', 'name' => 'Платформа', 'default' => true, 'type' => 'list',
                    'items' => array('DO' => 'Devino Online', 'MD' => 'My Devino', 'TOTAL' => 'Total')),
                array('id' => 'STATUS_TYPE', 'name' => 'График по',
                    'default' => true, 'type' => 'list', 'items' => $itemsNumsFor
                )
            );

            if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN') {
                $this->arResult['FILTER_ROWS'][] = array('id' => 'BRANCH', 'name' => 'Представительство', 'default' => true,
                    'type' => 'list', 'items' => $this->getBranches());
            }

            if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN' || $this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART') {
                $this->arResult['FILTER_ROWS'][] = array('id' => 'ASSIGNED_BY_ID', 'name' => 'Ответственный', 'default' => true,
                    'type' => 'dest_selector', 'params' => array('enableDepartments' => 'N'));
            }

            $this->arResult['STAGES'] = $this->getAllStagesDeal();

            $this->arResult['FILTER_DATA'] = $this->onPrepareFilterData((new Options($this->arResult['FILTER_ID']))->getFilter());

            if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {

                $this->arResult['NAME_REPORT'] = "Отчет по компаниям";
                $this->arResult['NAME_ENTITY_REPORT'] = "Компании";

                if (!$this->arResult['FILTER_DATA']['STATUS_TYPE']) {
                    $this->arResult['ERRORS_DATA']["NO_STATUS_TYPE"] = "Не заполнено обязательное поле 'Цифры по'";
                }

                if (!$this->arResult['FILTER_DATA']['CHANNEL']) {
                    $this->arResult['ERRORS_DATA']["NO_CHANNEL"] = "Не заполнено обязательное поле 'Канал'";
                }
            } elseif ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {

                $this->arResult['NAME_REPORT'] = "Отчет по сделкам";
                $this->arResult['NAME_ENTITY_REPORT'] = "Сделки";
            }

            //данные о наших пользователях
            $this->arResult['USERS_DATA'] = $this->getUserData();

            //получить руководителей департамента
            $this->arResult['DEPARTMENT_STRUCTURE'] = $this->getDepartmentStructure();

            foreach ($this->arResult['DEPARTMENT_STRUCTURE'] as $headLogin => $userDeps) {
                foreach ($userDeps as $depData) {
                    $this->arResult['DEP_USER_STRUCTURE'][$headLogin] = array_unique(array_merge($this->arResult['DEP_USER_STRUCTURE'][$headLogin] ?? [], $depData['USERS']));
                }
            }

            //данные о планах менеджеров
            $this->arResult['SALES_LIST_RESULT'] = $this->getSalesPlan();


            //все цифры по трафику и деньгам
            $tmpTrafficData = $this->getTrafficMoneyData();
            $key = null;
            $excludedStages = null;

            if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
                $key = 'STATUS_COMPANY';
                $excludedStages = self::EXCLUDE_STAGES_COMPANY;
            } elseif ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
                $key = "STAGE_DEAL";
                $excludedStages = self::EXCLUDE_STAGES_DEAL;
            }

            $isAccessUsers = array_column($tmpTrafficData, 'USER_LOGIN');

            //количество сделок
            $this->arResult['DEALS_COUNT'] = $this->getDealsCount($isAccessUsers);

            $this->arResult['STAGES_REPORT'] = (function () {
                return array_column(FieldEnumTable::getList(array(
                    'select' => array('ID', 'XML_ID'),
                    'filter' => array('=USER_FIELD_ID' => 2158)
                ))->fetchAll(), 'ID', 'XML_ID');
            })();

            $isProfitAccessDenied = false;

            if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY' &&
                $this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER' &&
                in_array($this->arResult['FILTER_DATA']['CHANNEL'], array('SMS', 'EMail', 'Viber', 'SMS-Short', 'SMS (Package)', 'SMS (Package)'))
            ) {
                $isProfitAccessDenied = true;
            }


            foreach ($tmpTrafficData as &$TRAFFIC_MONEY_DATUM) {
                if (!in_array($TRAFFIC_MONEY_DATUM[$key], $excludedStages)) {

                    $userLogin = $TRAFFIC_MONEY_DATUM['USER_LOGIN'];

                    //есои отчет по компаниям смотрит менеджер
                    if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER') {
                        if ($isProfitAccessDenied) {
                            $TRAFFIC_MONEY_DATUM['PROFIT'] = 0;
                            $TRAFFIC_MONEY_DATUM['PROGNOZ_PROFIT'] = 0;
                        } else {
                            $TRAFFIC_MONEY_DATUM['AMOUNT'] = 0;
                            $TRAFFIC_MONEY_DATUM['PROGNOZ_AMOUNT'] = 0;
                        }
                    }

                    $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['TRAFFIC'] += $TRAFFIC_MONEY_DATUM['QUANTITY'];
                    $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['TRAFFIC_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_QUANTITY'];
                    $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['PROFIT'] += $TRAFFIC_MONEY_DATUM['PROFIT'];
                    $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['PROFIT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_PROFIT'];

                    if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
                        //если отчет по компаниям - счиатем выручку
                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['AMOUNT'] += $TRAFFIC_MONEY_DATUM['AMOUNT'];
                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['AMOUNT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_AMOUNT'];
                    }

                    if ($this->arResult['FILTER_DATA']['SYSTEM'] && $this->arResult['FILTER_DATA']['SYSTEM'] != "TOTAL") {

                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin]["TOTAL"]['TRAFFIC'] += $TRAFFIC_MONEY_DATUM['QUANTITY'];
                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin]["TOTAL"]['TRAFFIC_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_QUANTITY'];
                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin]["TOTAL"]['PROFIT'] += $TRAFFIC_MONEY_DATUM['PROFIT'];
                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin]["TOTAL"]['PROFIT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_PROFIT'];

                        if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
                            //если отчет по компаниям - счиатем выручку
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin]["TOTAL"]['AMOUNT'] += $TRAFFIC_MONEY_DATUM['AMOUNT'];
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin]["TOTAL"]['AMOUNT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_AMOUNT'];
                        }
                    }

                    $isPlus = true;

                    if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN' || $this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART') {
                        //если отчет смотрит либо админ, либо руководитель, то складываем в руководителя цифры по всем его менеджерам
                        if (in_array($this->arResult['FILTER_DATA']['MANAGER_LOGIN'], array_keys($this->arResult['DEPARTMENT_STRUCTURE'])) || !$this->arResult['FILTER_DATA']['MANAGER_LOGIN']) {

                            foreach ($this->arResult['DEPARTMENT_STRUCTURE'] as $headLogin => $departsId) {

                                foreach ($departsId as $departData) {
                                    if (in_array($userLogin, $departData['USERS']) && $userLogin != $headLogin) {

                                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['TRAFFIC'] += $TRAFFIC_MONEY_DATUM['QUANTITY'];
                                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['TRAFFIC_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_QUANTITY'];
                                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['PROFIT'] += $TRAFFIC_MONEY_DATUM['PROFIT'];
                                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['PROFIT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_PROFIT'];

                                        if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
                                            //если отчет по компаниям - счиатем выручку
                                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['AMOUNT'] += $TRAFFIC_MONEY_DATUM['AMOUNT'];
                                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin][$TRAFFIC_MONEY_DATUM['SYSTEM']]['AMOUNT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_AMOUNT'];
                                        }

                                        if ($this->arResult['FILTER_DATA']['SYSTEM'] && $this->arResult['FILTER_DATA']['SYSTEM'] != "TOTAL") {
                                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin]["TOTAL"]['TRAFFIC'] += $TRAFFIC_MONEY_DATUM['QUANTITY'];
                                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin]["TOTAL"]['TRAFFIC_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_QUANTITY'];
                                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin]["TOTAL"]['PROFIT'] += $TRAFFIC_MONEY_DATUM['PROFIT'];
                                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin]["TOTAL"]['PROFIT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_PROFIT'];

                                            if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
                                                //если отчет по компаниям - счиатем выручку
                                                $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin]["TOTAL"]['AMOUNT'] += $TRAFFIC_MONEY_DATUM['AMOUNT'];
                                                $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$headLogin]["TOTAL"]['AMOUNT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_AMOUNT'];
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        //TODO::костыль для Истоминой
                        if ($this->arResult['TYPE_SALES_PLAN']['USER_LOGIN'] == 'e.istomina' && !$this->arResult['FILTER_DATA']['MANAGER_LOGIN']) {
                            foreach ($this->arResult['DEPARTMENT_STRUCTURE']['e.istomina'] as $depart) {
                                if (!in_array($userLogin, $depart['USERS'])) {
                                    $isPlus = false;
                                }
                            }
                        }
                    }

                    $keyCountCompany = null;

                    if (!$this->arResult['FILTER_DATA']['SYSTEM']) {
                        $keyCountCompany = "TOTAL";
                    } else {
                        $keyCountCompany = $this->arResult['FILTER_DATA']['SYSTEM'];
                    }

                    if ($isPlus === true) {

                        if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY' && $TRAFFIC_MONEY_DATUM['SYSTEM'] == $keyCountCompany) {

                            //для менеджеров выбравшего определнный канал график будет строиться только если выбран статус "по выручке"
                            if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER') {
                                if ($isProfitAccessDenied === true) {
                                    if ($this->arResult['FILTER_DATA']['STATUS_TYPE'] === 'UF_PROFIT_AVG3_STATUS') {
                                        continue;
                                    }
                                } else {
                                    if ($this->arResult['FILTER_DATA']['STATUS_TYPE'] === "UF_SELLAMOUNT_AVG3_STATUS") {
                                        continue;
                                    }
                                }
                            }

                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['STATUS_COUNT'][$TRAFFIC_MONEY_DATUM[$key]] += 1;
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['MAX_STATUS_COUNT'] += 1;

                            if (!$this->arResult['ERRORS_DATA']) {
                                $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES_COMPANY'][$TRAFFIC_MONEY_DATUM[$key]][] = $TRAFFIC_MONEY_DATUM['CUSTOMER_ID'];
                            }
                        }

                        if ($TRAFFIC_MONEY_DATUM['SYSTEM'] == $keyCountCompany) {
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES'][$TRAFFIC_MONEY_DATUM[$key]]['TRAFFIC'] += $TRAFFIC_MONEY_DATUM['QUANTITY'];
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES'][$TRAFFIC_MONEY_DATUM[$key]]['TRAFFIC_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_QUANTITY'];
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES'][$TRAFFIC_MONEY_DATUM[$key]]['PROFIT'] += $TRAFFIC_MONEY_DATUM['PROFIT'];
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES'][$TRAFFIC_MONEY_DATUM[$key]]['PROFIT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_PROFIT'];

                            if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
                                //если отчет по компаниям - счиатем выручку
                                $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES'][$TRAFFIC_MONEY_DATUM[$key]]['AMOUNT'] += $TRAFFIC_MONEY_DATUM['AMOUNT'];
                                $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES'][$TRAFFIC_MONEY_DATUM[$key]]['AMOUNT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_AMOUNT'];
                            }
                        }

                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS'][$TRAFFIC_MONEY_DATUM['SYSTEM']]['TRAFFIC'] += $TRAFFIC_MONEY_DATUM['QUANTITY'];
                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS'][$TRAFFIC_MONEY_DATUM['SYSTEM']]['TRAFFIC_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_QUANTITY'];
                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS'][$TRAFFIC_MONEY_DATUM['SYSTEM']]['PROFIT'] += $TRAFFIC_MONEY_DATUM['PROFIT'];
                        $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS'][$TRAFFIC_MONEY_DATUM['SYSTEM']]['PROFIT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_PROFIT'];

                        if ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
                            //если отчет по компаниям - счиатем выручку
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS'][$TRAFFIC_MONEY_DATUM['SYSTEM']]['AMOUNT'] += $TRAFFIC_MONEY_DATUM['AMOUNT'];
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS'][$TRAFFIC_MONEY_DATUM['SYSTEM']]['AMOUNT_PROGNOZ'] += $TRAFFIC_MONEY_DATUM['PROGNOZ_AMOUNT'];
                        }
                    }


                }
            }

            //добавление "нулевых" менеджеров в список
            if (($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN' ||
                $this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART')
            ) {
                foreach (self::IS_ALL_USER_CHECK as $userHeadLogin) {

                    if ($this->arResult['FILTER_DATA']['MANAGER_LOGIN'] && $this->arResult['FILTER_DATA']['MANAGER_LOGIN'] != $userHeadLogin) {
                        continue;
                    }

                    $currentUsersInDep = $this->arResult['DEP_USER_STRUCTURE'][$userHeadLogin];

                    foreach ($currentUsersInDep as $userLogin) {
                        if (!$this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin])
                            $this->arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$userLogin] = null;
                    }
                }
            }


//            global $USER;
//
//            if ($USER->IsAdmin()){
//                d($this->arResult);
//            }

        } catch (ArgumentException | Exception | SystemException $e) {
            ShowError($e->getMessage());
        }

        $this->includeComponentTemplate();
    }

    public static function executeComponentAjax() {
        try {

            $action = $_POST['action'];
            $typeReport = $_POST['typeComponent'];
            $data = [];

            switch (true) {
                case $action == 'setListCompanyFilter' && $typeReport == 'COMPANY':
                    session_start();

                    unset($_SESSION['REPORT_DATA']['LIST_COMPANY_PARAM']);

                    $_SESSION['REPORT_DATA']['LIST_COMPANY_PARAM'] = array(
                        'PARAM_REQUEST' => Json::decode($_POST['paramRequest']),
                        'TYPE_USER' => $_POST['typeUser'],
                        'CUSTOMER_IDS' => Json::decode($_POST['customerIds']),
                        'STAGE_ID' => $_POST['stageId']
                    );

                    session_commit();
                    break;

                case $action == 'setListDealFilter' && $typeReport == 'DEAL':
                    session_start();

                    unset($_SESSION['REPORT_DATA']['LIST_DEAL_PARAM']);

                    $_SESSION['REPORT_DATA']['LIST_DEAL_PARAM'] = array(
                        'PARAM_REQUEST' => Json::decode($_POST['paramRequest']),
                        'STAGE_ID' => $_POST['stageId']
                    );

                    session_commit();
                    break;
            }

            echo ajaxDevinoResponse(true, $data);
        } catch (ArgumentException $ex) {
            echo ajaxDevinoResponse(false, [], $ex->getMessage());
        }
    }

    public static function getPercentHeight(int $count, int $maxValue): int {
        if ($maxValue == 0 || $count == 0) return 1;
        if ($count == $maxValue) return 100;

        $percent = $count * 100 / $maxValue;

        if ($percent < 1) {
            return 2;
        } elseif ($percent >= 1) {
            return $percent;
        }

        return 1;
    }

    /**
     * @return array
     * @throws ObjectException
     */
    public function prepareTrafficMoneyData(): array {
        $returned = [];

        if ($this->arResult['FILTER_DATA']) {
            if ($this->arResult['FILTER_DATA']['BEGINDATE']) {

                $returned['>=UF_STATUSDATE'] = new Date($this->arResult['FILTER_DATA']['BEGINDATE'], 'd.m.Y');

                if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
                    $returned['>=DEALS_REPORT_TABLE.UF_STATUSDATE'] = new Date($this->arResult['FILTER_DATA']['BEGINDATE'], 'd.m.Y');
                }
            }

            if ($this->arResult['FILTER_DATA']['CLOSEDATE']) {

                $returned['<=UF_STATUSDATE'] = new Date($this->arResult['FILTER_DATA']['CLOSEDATE'], 'd.m.Y');

                if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
                    $returned['<=DEALS_REPORT_TABLE.UF_STATUSDATE'] = new Date($this->arResult['FILTER_DATA']['CLOSEDATE'], 'd.m.Y');
                }
            }

            if ($this->arResult['FILTER_DATA']['CUSTOMER_ID']) {

                $returned['=UF_CUSTOMERID'] = $this->arResult['FILTER_DATA']['CUSTOMER_ID'];

                if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
                    $returned['=DEALS_REPORT_TABLE.UF_CUSTOMERID'] = $this->arResult['FILTER_DATA']['CUSTOMER_ID'];
                }
            }

            if ($this->arResult['FILTER_DATA']['EXCLUDE_CUSTOMER_ID']) {

                $returned['!=UF_CUSTOMERID'] = $this->arResult['FILTER_DATA']['EXCLUDE_CUSTOMER_ID'];

                if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
                    $returned['!=DEALS_REPORT_TABLE.UF_CUSTOMERID'] = $this->arResult['FILTER_DATA']['EXCLUDE_CUSTOMER_ID'];
                }
            }

            if ($this->arResult['FILTER_DATA']['MANAGER_LOGIN']) {
                if (isset($this->arResult['DEPARTMENT_STRUCTURE'][$this->arResult['FILTER_DATA']['MANAGER_LOGIN']])) {

                    foreach ($this->arResult['DEPARTMENT_STRUCTURE'][$this->arResult['FILTER_DATA']['MANAGER_LOGIN']] as $departs) {
                        $returned['=UF_MANAGERLOGIN'] = array_merge($returned['=UF_MANAGERLOGIN'] ?? [], $departs['USERS']);
                    }

                    if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
                        foreach ($this->arResult['DEPARTMENT_STRUCTURE'][$this->arResult['FILTER_DATA']['MANAGER_LOGIN']] as $departs) {
                            $returned['=DEALS_REPORT_TABLE.UF_MANAGERLOGIN'] = array_merge($returned['=DEALS_REPORT_TABLE.UF_MANAGERLOGIN'] ?? [], $departs['USERS']);
                        }
                    }

                } else {

                    $returned['=UF_MANAGERLOGIN'] = $this->arResult['FILTER_DATA']['MANAGER_LOGIN'];

                    if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
                        $returned['=DEALS_REPORT_TABLE.UF_MANAGERLOGIN'] = $this->arResult['FILTER_DATA']['MANAGER_LOGIN'];
                    }
                }
            }

            if ($this->arResult['FILTER_DATA']['CHANNEL']) {
                $returned['=UF_CHANNEL'] = $this->arResult['FILTER_DATA']['CHANNEL'];
            }

            if ($this->arResult['FILTER_DATA']['SYSTEM']) {
                $returned['=UF_SYSTEM'] = $this->arResult['FILTER_DATA']['SYSTEM'];
            }

            if ($this->arResult['FILTER_DATA']['MANAGER_BRANCH']) {

                $returned['=UF_MANAGERBRANCH'] = $this->arResult['FILTER_DATA']['MANAGER_BRANCH'];

                if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
                    $returned['=DEALS_REPORT_TABLE.UF_MANAGERBRANCH'] = $this->arResult['FILTER_DATA']['MANAGER_BRANCH'];
                }
            }
        }

        if (!$this->arResult['FILTER_DATA']['MANAGER_LOGIN']) {
            if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART') {
                $returned['=UF_MANAGERLOGIN'] = $this->arResult['TYPE_SALES_PLAN']['WHO_IN_DEP'];
            } elseif ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER') {
                $returned['=UF_MANAGERLOGIN'] = $this->arResult['TYPE_SALES_PLAN']['USER_LOGIN'];
            }
        }

        $returned['!=UF_CUSTOMERID'][] = null;
        $returned['!=UF_MANAGERBRANCH'] = null;
        $returned['!=UF_MANAGERLOGIN'] = null;
        $returned['!=UF_SYSTEM'] = null;
        $returned['!=UF_CHANNEL'] = null;


        if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
            $returned['!=DEALS_REPORT_TABLE.UF_STATUS'] = null;
            $returned['!=DEALS_REPORT_TABLE.UF_CUSTOMERID'][] = null;
            $returned['!=DEALS_REPORT_TABLE.UF_MANAGERBRANCH'] = null;
            $returned['!=DEALS_REPORT_TABLE.UF_MANAGERLOGIN'] = null;
        }

        return $returned;
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getTrafficMoneyData(): array {
        $dealReport = [];
        if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {

            $dealReport = TriggersReportTable::getList(array(
                'select' => array(
                    'USER_LOGIN' => 'UF_MANAGERLOGIN',
                    'SYSTEM' => 'UF_SYSTEM',
                    'CUSTOMER_ID' => 'UF_CUSTOMERID',
                    'STAGE_DEAL' => 'STATUS_TABLE.STATUS_ID',
                    new ExpressionField('PROFIT', 'sum(%s)', 'UF_PROFIT'),
                    new ExpressionField('PROGNOZ_PROFIT', 'sum(%s)', 'UF_PROFIT_FC'),
                    new ExpressionField('QUANTITY', 'sum(%s)', 'UF_QTY'),
                    new ExpressionField('PROGNOZ_QUANTITY', 'sum(%s)', 'UF_QTY_FC')
                ),
                'filter' => $this->prepareTrafficMoneyData(),
                'runtime' => array(
                    new ReferenceField(
                        'DEALS_REPORT_TABLE',
                        DealsReportTable::class,
                        Join::on('this.UF_CUSTLOGINBRANCH', 'ref.UF_CUSTLOGINBRANCH')
                    ),
                    new ReferenceField(
                        'STATUS_TABLE',
                        StatusTable::class,
                        Join::on('this.DEALS_REPORT_TABLE.UF_STATUS', 'ref.NAME')
                    )
                ),
                'group' => array('UF_MANAGERLOGIN', 'UF_SYSTEM', 'UF_CUSTOMERID', 'STATUS_TABLE.STATUS_ID')
            ))->fetchAll();

        } elseif ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') {
            $runtime = [];
            $select = array(
                'USER_LOGIN' => 'UF_MANAGERLOGIN',
                'SYSTEM' => 'UF_SYSTEM',
                'CUSTOMER_ID' => 'UF_CUSTOMERID',
                new ExpressionField('PROFIT', 'sum(%s)', 'UF_PROFIT'),
                new ExpressionField('PROGNOZ_PROFIT', 'sum(%s)', 'UF_PROFIT_FC'),
                new ExpressionField('QUANTITY', 'sum(%s)', 'UF_QTY'),
                new ExpressionField('PROGNOZ_QUANTITY', 'sum(%s)', 'UF_QTY_FC'),
                new ExpressionField('AMOUNT', 'sum(%s)', 'UF_SELL_AMOUNT'),
                new ExpressionField('PROGNOZ_AMOUNT', 'sum(%s)', 'UF_SELL_AMOUNT_FC')
            );

            $group = array('UF_MANAGERLOGIN', 'UF_SYSTEM', 'UF_CUSTOMERID');

            if (!$this->arResult['ERRORS_DATA']) {
                $runtime = array(
                    new ReferenceField('STATUS_TABLE', StatusTable::class,
                        Join::on('this.' . $this->arResult['FILTER_DATA']['STATUS_TYPE'], 'ref.NAME')
                    )
                );

                $select['STATUS_COMPANY'] = 'STATUS_TABLE.STATUS_ID';
                $group[] = 'STATUS_TABLE.STATUS_ID';
            }

            $dealReport = TriggersReportTable::getList(array(
                'select' => $select,
                'runtime' => $runtime,
                'group' => $group,
                'filter' => $this->prepareTrafficMoneyData()
            ))->fetchAll();

        }

        return $dealReport;
    }

    /**
     * @param string $companyId
     * @return mixed
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     *
     * получение CUSTOMER_ID по COMPANY_ID для фильтра
     */
    public function getCustomerId(string $companyId): string {
        $customerId = CompanyTable::getList(array(
                'select' => array('UF_CUSTOMER_ID'),
                'filter' => array('=ID' => $companyId)
            ))->fetch()['UF_CUSTOMER_ID'] ?? "";

        if (!$customerId) {
            $this->arResult['WARNINGS'] .= "У выбранной компании не заполнено поле CUSTOMER_ID";
        }

        return $customerId;
    }


    /**
     * @param array $isAccessPlan
     * @return array
     * @throws ObjectException
     */
    public function prepareDealsCountFilter(array $isAccessPlan): array {
        $returned = [];

        if ($this->arResult['FILTER_DATA']) {
            if ($this->arResult['FILTER_DATA']['BEGINDATE']) {
                $returned['>=BEGINDATE'] = new DateTime($this->arResult['FILTER_DATA']['BEGINDATE'] . " 00:00:00", 'd.m.Y H:i:s');
            }

            if ($this->arResult['FILTER_DATA']['CLOSEDATE']) {
                $returned['<=CLOSEDATE'] = new DateTime($this->arResult['FILTER_DATA']['CLOSEDATE'] . ' 23:59:59', 'd.m.Y H:i:s');
            }

            if ($this->arResult['FILTER_DATA']['COMPANY_ID']) {
                $returned['=COMPANY_ID'] = $this->arResult['FILTER_DATA']['COMPANY_ID'];
            }

            if ($this->arResult['FILTER_DATA']['EXCLUDE_COMPANY_ID']) {
                $returned['!=COMPANY_ID'] = $this->arResult['FILTER_DATA']['EXCLUDE_COMPANY_ID'];
            }

            if ($this->arResult['FILTER_DATA']['CHANNEL']) {
                $returned['=TRIGGERS_TABLE.UF_CHANNEL'] = $this->arResult['FILTER_DATA']['CHANNEL'];
            }

            if ($this->arResult['FILTER_DATA']['MANAGER_LOGIN']) {
                if (isset($this->arResult['DEPARTMENT_STRUCTURE'][$this->arResult['FILTER_DATA']['MANAGER_LOGIN']])) {

                    foreach ($this->arResult['DEPARTMENT_STRUCTURE'][$this->arResult['FILTER_DATA']['MANAGER_LOGIN']] as $departs) {
                        $returned['=USER_TABLE.LOGIN'] = array_merge($returned['=USER_TABLE.LOGIN'] ?? [], $departs['USERS']);
                    }

                } else {
                    $returned['=USER_TABLE.LOGIN'] = $this->arResult['FILTER_DATA']['MANAGER_LOGIN'];
                }
            }

            if ($this->arResult['FILTER_DATA']['SYSTEM']) {
                $returned['=TRIGGERS_TABLE.UF_SYSTEM'] = $this->arResult['FILTER_DATA']['SYSTEM'];
            }

            if ($this->arResult['FILTER_DATA']['MANAGER_BRANCH']) {
                $returned['=TRIGGERS_TABLE.UF_MANAGERBRANCH'] = $this->arResult['FILTER_DATA']['MANAGER_BRANCH'];
            }
        }


        if (!$returned['=USER_TABLE.LOGIN']) {
            $returned['=USER_TABLE.LOGIN'] = array_merge($isAccessPlan ?? [], $returned['=USER_TABLE.LOGIN'] ?? []);
        }

        $returned['=CATEGORY_ID'] = self::CATEGORY_DEAL_ID;
        $returned['!=UF_STAGE_REPORT'] = null;

        return $returned;
    }

    /**
     * @param array $isAccessPlan
     * @return array
     * @throws ArgumentException
     * @throws ObjectException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getDealsCount(array $isAccessPlan): array {
        $returned = [];

        $dealsList = DealTable::getList(array(
            'select' => array(
                'ID_DEAL' => 'ID',
                'STAGE_REPORT' => 'ENUM_TABLE.XML_ID',
                'CUSTOMER_ID' => 'COMPANY_TABLE.UF_CUSTOMER_ID',
                'ID_COMPANY_BX' => 'COMPANY_TABLE.ID',
                'USER_LOGIN' => 'USER_TABLE.LOGIN'
            ),
            'filter' => $this->prepareDealsCountFilter($isAccessPlan),
            'runtime' => array(
                new ReferenceField(
                    'COMPANY_TABLE',
                    CompanyTable::class,
                    Join::on('this.COMPANY_ID', 'ref.ID')
                ),
                new ReferenceField(
                    'TRIGGERS_TABLE',
                    TriggersReportTable::class,
                    Join::on('this.CUSTOMER_ID', 'ref.UF_CUSTOMERID')
                ),
                new ReferenceField(
                    'USER_TABLE',
                    UserTable::class,
                    Join::on('this.ASSIGNED_BY_ID', 'ref.ID')
                ),
                new ReferenceField(
                    'ENUM_TABLE',
                    FieldEnumTable::class,
                    Join::on('this.UF_STAGE_REPORT', 'ref.ID')
                )
            ),
            'group' => array('ID', 'ENUM_TABLE.XML_ID', 'COMPANY_TABLE.UF_CUSTOMER_ID', 'COMPANY_TABLE.ID', 'USER_TABLE.LOGIN')
        ))->fetchAll();

        foreach ($dealsList as $item) {

            $keyLogin = $item['USER_LOGIN'];

            $returned['USERS'][$keyLogin] += 1;
            $returned[$item['STAGE_REPORT']] += 1;
            $returned['MAX_COUNT_DEALS'] += 1;
        }


        foreach ($returned['USERS'] as $keyLogin => $returnDealsCount) {
            if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN' || $this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART') {
                foreach ($this->arResult['DEPARTMENT_STRUCTURE'] as $headLogin => $departsId) {

                    foreach ($departsId as $departData) {
                        if (in_array($keyLogin, $departData['USERS']) && $keyLogin != $headLogin) {
                            $returned['USERS'][$headLogin] += $returnDealsCount;
                        }
                    }
                }
            }
        }

        return $returned;
    }

    /**
     * @return array
     * @throws ObjectException|ArgumentException
     */
    public function prepareSalesListFilter(): array {
        $filter = [];

        if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART') {
            $filter['=UF_USER_LOGIN'] = $this->arResult['TYPE_SALES_PLAN']['WHO_IN_DEP'];
        } elseif ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER') {
            $filter['=UF_USER_LOGIN'] = $this->arResult['TYPE_SALES_PLAN']['USER_LOGIN'];
        }

        if ($this->arResult['FILTER_DATA']) {
            if ($this->arResult['FILTER_DATA']['BEGINDATE']) {
                $filter['>=UF_DATE'] = new Date($this->arResult['FILTER_DATA']['BEGINDATE'], 'd.m.Y');
            }

            if ($this->arResult['FILTER_DATA']['CLOSEDATE']) {
                $filter['<=UF_DATE'] = new Date($this->arResult['FILTER_DATA']['CLOSEDATE'], 'd.m.Y');
            }

            if ($this->arResult['FILTER_DATA']['MANAGER_LOGIN']) {
                if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN' ||
                    $this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART' &&
                    in_array($this->arResult['FILTER_DATA']['MANAGER_LOGIN'], $this->arResult['TYPE_SALES_PLAN']['WHO_IN_DEP'])
                ) {
                    if (isset($this->arResult['DEPARTMENT_STRUCTURE'][$this->arResult['FILTER_DATA']['MANAGER_LOGIN']])) {
                        foreach ($this->arResult['DEPARTMENT_STRUCTURE'][$this->arResult['FILTER_DATA']['MANAGER_LOGIN']] as $departs) {
                            $filter['=UF_USER_LOGIN'] = array_merge($filter['=UF_USER_LOGIN'] ?? [], $departs['USERS']);
                        }
                    } else {
                        $filter['=UF_USER_LOGIN'] = $this->arResult['FILTER_DATA']['MANAGER_LOGIN'];
                    }
                } else {
                    throw new ArgumentException("Доступ запрещен");
                }
            }


            if ($this->arResult['FILTER_DATA']['MANAGER_BRANCH']) {
                $filter['=UF_MANAGER_BRANCH'] = $this->arResult['FILTER_DATA']['MANAGER_BRANCH'];
            }

            if ($this->arResult['FILTER_DATA']['CHANNEL']) {
                $filter['=UF_CHANNEL'] = $this->arResult['FILTER_DATA']['CHANNEL'];
            }

            if ($this->arResult['FILTER_DATA']['SYSTEM']) {
                $filter['=UF_PLATFORM'] = $this->arResult['FILTER_DATA']['SYSTEM'];
            }
        }

        $filter['!=UF_USER_LOGIN'] = null;
        $filter['!=UF_CHANNEL'] = null;
        $filter['!=UF_PLATFORM'] = null;

        return $filter;
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getSalesPlan(): array {
        $returned = [];

        $salesList = SalesPlanTable::getList(array(
            'select' => array('*'),
            'filter' => $this->prepareSalesListFilter()
        ))->fetchAll();

        foreach ($salesList as $itemSales) {
            $returned[$itemSales['UF_USER_LOGIN']][$itemSales['UF_PLATFORM']]['PLAN_TRAFFIC'] += $itemSales['UF_PLAN_TRAFFIC'];
            $returned[$itemSales['UF_USER_LOGIN']][$itemSales['UF_PLATFORM']]['PLAN_PROFIT'] += $itemSales['UF_PLAN_PROFIT'];
            $returned[$itemSales['UF_USER_LOGIN']][$itemSales['UF_PLATFORM']]['PLAN_AMOUNT'] += $itemSales['UF_PLAN_AMOUNT'];

            if ($itemSales['UF_PLATFORM'] != "TOTAL") {
                $returned[$itemSales['UF_USER_LOGIN']]['ALL_PLAN_TRAFFIC'] += $itemSales['UF_PLAN_TRAFFIC'];
                $returned[$itemSales['UF_USER_LOGIN']]['ALL_PLAN_PROFIT'] += $itemSales['UF_PLAN_PROFIT'];
                $returned[$itemSales['UF_USER_LOGIN']]['ALL_PLAN_AMOUNT'] += $itemSales['UF_PLAN_AMOUNT'];
            }
        }

        return $returned;
    }


    public function prepareUserFilter(): array {
        return array('=ACTIVE' => 'Y', '!%LOGIN' => 'imconnector_');
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getUserData(): array {
        $returned = [];
        $userList = UserTable::getList(array(
            'select' => array('NAME', 'LAST_NAME', 'PERSONAL_PHOTO', 'LOGIN', 'ID'),
            'filter' => $this->prepareUserFilter(),
        ))->fetchAll();

        foreach ($userList as $userItem) {
            $returned[$userItem['LOGIN']] = array(
                'ID' => $userItem['ID'],
                'LOGIN' => $userItem['LOGIN'],
                'NAME' => $userItem['LAST_NAME'] . ' ' . $userItem['NAME'],
                'PHOTO' => $userItem['PERSONAL_PHOTO']
            );
        }

        return $returned;
    }


    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     *
     * метод на получение всех стадий типовой сделки
     */
    public function getAllStagesDeal(): array {
        $returned = [];

        $filter = array(
            '=ENTITY_ID' => "DEAL_STAGE_" . self::CATEGORY_DEAL_ID,
            '!=STATUS_ID' => ($this->arParams['TYPE_COMPONENT'] == 'COMPANY') ? self::EXCLUDE_STAGES_COMPANY : self::EXCLUDE_STAGES_DEAL
        );

        if ($this->arParams['TYPE_COMPONENT'] == 'DEAL') {
            $filter['!=STATUS_ID'][] = "C" . self::CATEGORY_DEAL_ID . ":POTENTIAL";
        }

        foreach (StatusTable::getList(array(
            'select' => array('ID', 'NAME', 'STATUS_ID', 'COLOR'),
            'filter' => $filter,
            'order' => array('SORT' => 'ASC')
        ))->fetchAll() as $statusRow) {
            if ($statusRow['NAME']) {
                $returned[$statusRow['STATUS_ID']] = array(
                    'NAME' => $statusRow['NAME'],
                    'COLOR' => $statusRow['COLOR']
                );
            }
        }

        return $returned;
    }

    /**
     * @param array $filterData
     * @return array
     * @throws Exception
     *
     * подготовка фильтра для использования
     */
    public function onPrepareFilterData(array $filterData): array {
        $newFilterData = [];

        foreach ($filterData as $key => $filterParam) {
            switch ($key) {
                case 'STATUS_TYPE' :
                    $newFilterData['STATUS_TYPE'] = $filterParam;
                    break;
                case 'BEGINDATE_from':
                    $newFilterData['BEGINDATE'] = $filterParam;
                    break;
                case 'BEGINDATE_to':
                    $newFilterData['CLOSEDATE'] = $filterParam;
                    break;
                case 'COMPANY_ID':
                    $customerId = $this->getCustomerId(preg_replace("/[^0-9]/", '', $filterParam));

                    if (!$customerId) throw new ArgumentException("У компании отсутсвует CUSTOMER_ID");

                    $newFilterData["CUSTOMER_ID"] = $customerId;
                    $newFilterData["COMPANY_ID"] = preg_replace("/[^0-9]/", '', $filterParam);
                    break;
                case 'EXCLUDE_COMPANY_ID':
                    foreach ($filterParam as $excludeCompany) {
                        $newFilterData["EXCLUDE_CUSTOMER_ID"][] = $this->getCustomerId(preg_replace("/[^0-9]/", '', $excludeCompany));
                        $newFilterData["EXCLUDE_COMPANY_ID"][] = preg_replace("/[^0-9]/", '', $excludeCompany);
                    }
                    break;
                case 'ASSIGNED_BY_ID':
                    $newFilterData['MANAGER_LOGIN'] = $this->getUserLoginById(preg_replace("/[^0-9]/", '', $filterParam));
                    $newFilterData['USER_ID'] = preg_replace("/[^0-9]/", '', $filterParam);
                    break;
                case 'CHANNEL':
                    $newFilterData['CHANNEL'] = $filterParam;
                    break;
                case 'BRANCH':
                    $newFilterData['MANAGER_BRANCH'] = $filterParam;
                    break;
                case 'PLATFORM':
                    $newFilterData['SYSTEM'] = $filterParam;
                    break;

            }
        }

        return $newFilterData;
    }

    /**
     * @param string $branchName
     * @return int
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getBranchIdByName(string $branchName): int {
        return SectionTable::getList(array(
            'select' => array('ID'),
            'filter' => array('NAME' => $branchName)
        ))->fetch()['ID'];
    }

    /**
     * @param string $userId
     * @return mixed
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     *
     * получение login a пользователя по его ID
     */
    public function getUserLoginById(string $userId) {
        return UserTable::getById($userId)->fetch()['LOGIN'];
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     *
     * получение представительств из отчета
     */
    public function getBranches(): array {
        $returned = array();

        foreach (DealsReportTable::getList(array(
            'select' => array(new ExpressionField('BRANCH', 'distinct UF_MANAGERBRANCH')),
        ))->fetchAll() as $branch) {
            $returned[$branch['BRANCH']] = $branch['BRANCH'];
        }

        return $returned;
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     *
     * получение каналов из отчета
     */
    public function getChannels(): array {
        $returned = array();

        foreach (TriggersReportTable::getList(array(
            'select' => array(new ExpressionField('CHANNEL', 'distinct UF_CHANNEL'))
        )) as $channel) {
            //дл менеджеров убираем пару каналов
            if ($this->arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER' && ($channel['CHANNEL'] == 'SMS-ServicePay' || $channel['CHANNEL'] == 'HLR'))
                continue;

            $returned[$channel['CHANNEL']] = $channel['CHANNEL'];
        }

        return $returned;
    }
}