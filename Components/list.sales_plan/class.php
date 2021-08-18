<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Iblock\SectionTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\UI\Filter\DateType;
use Bitrix\Main\UI\Filter\Options as FilterOptions;
use Bitrix\Main\Grid\Options as GridOptions;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\UserGroupTable;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\UserToGroupTable;

class salesList extends \CBitrixComponent {
    const DEP_GLOBAL = 175;

    const MONTH_LIST = array(
        '01' => 'Январь',
        '02' => 'Февраль',
        '03' => 'Март',
        '04' => 'Апрель',
        '05' => 'Май',
        '06' => 'Июнь',
        '07' => 'Июль',
        '08' => 'Август',
        '09' => 'Сентябрь',
        '10' => 'Октябрь',
        '11' => 'Ноябрь',
        '12' => 'Декабрь'
    ),
        ACCESS_DEPART = array(186, 278),
        SALES_PLAN_USER_ID = 2284,
        SALES_PLAN_USER_LOGIN = 'sales_plan',
        DEPART_IBLOCK = 5,
        ADMIN_REPORT_GROUP = 54;

    public static function executeComponentAjax() {
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            if ($method != 'POST') {
                throw new ArgumentException("Ошибка выполнения запроса");
            }

            $postData = $_POST;
            $action = $postData['action'];
            $errors = "";

            switch ($action) {
                case 'addSalesPlan':
                    //все проверки
                    if (!$postData['userList']) {
                        throw new ArgumentException("Не выбран пользователь");
                    }

                    if (!$postData['platformList']) {
                        throw new ArgumentException("Не выбрана платформа");
                    }

                    if (!$postData['channelList']) {
                        throw new ArgumentException("Не выбран канал");
                    }

                    if (!$postData['year'] || !$postData['month']) {
                        throw new ArgumentException("Не заполнена дата");
                    }

                    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/DwhConnected.php';
                    $dwhCon = DwhConnected::connectToDwh();

                    //ищем дубль записи
                    $curDate = new Date("{$postData['year']}.{$postData['month']}.01", 'Y.m.d');

                    $departmentsData = array_column(SectionTable::getList(array(
                        'select' => array(
                            'ID', 'IBLOCK_ID', 'PARENT_ID' => 'IBLOCK_SECTION_ID',
                            'UF_HEAD' => 'UTS_DEPARTMENT.UF_HEAD', 'NAME'
                        ),
                        'filter' => array('=IBLOCK_ID' => 5),
                        'runtime' => array(
                            new ReferenceField(
                                'UTS_DEPARTMENT',
                                UtsIblockSectionTable::class,
                                Join::on('this.ID', 'ref.VALUE_ID')
                            )
                        )
                    ))->fetchAll(), null, 'ID');

                    foreach ($postData['userList'] as $userLogin) {

                        if ($userLogin != 'sales_plan') {
                            $ar = UserTable::getList(array(
                                'select' => array('UF_DEPARTMENT'),
                                'filter' => array('=LOGIN' => $userLogin)
                            ))->fetch();

                            $departUserId = end($ar['UF_DEPARTMENT']);

                            if ($departUserId && $departmentsData) {
                                $branchName = self::getDepartName($departUserId, $departmentsData);
                            } else {
                                throw new ArgumentException("Ошибка получени представительства для пользователя {$userLogin}");
                            }
                        } else {
                            $branchName = $postData['branchName'];
                        }

                        foreach ($postData['platformList'] as $platform) {
                            foreach ($postData['channelList'] as $channel) {

                                $rData = array(
                                    '=UF_USER_LOGIN' => $userLogin,
                                    '=UF_PLATFORM' => $platform,
                                    '=UF_DATE' => $curDate,
                                    '=UF_CHANNEL' => $channel,
                                );

                                if ($branchName) {
                                    $rData['=UF_MANAGER_BRANCH'] = $branchName;
                                }

                                $alreadyExist = SalesPlanTable::getList(array(
                                    'filter' => $rData,
                                    'select' => array('ID')
                                ))->fetchAll();

                                if (count($alreadyExist) == 0) {

                                    //добавляем
                                    $arFieldAdd = array(
                                        'UF_USER_LOGIN' => $userLogin,
                                        'UF_PLATFORM' => $platform,
                                        'UF_DATE' => $curDate,
                                        'UF_CHANNEL' => $channel,
                                        'UF_PLAN_PROFIT' => $postData['profit'],
                                        'UF_PLAN_TRAFFIC' => $postData['traffic'],
                                        'UF_PLAN_AMOUNT' => $postData['amount']
                                    );

                                    if ($branchName) {
                                        $arFieldAdd['UF_MANAGER_BRANCH'] = $branchName;
                                    }

                                    $isAdded = SalesPlanTable::add($arFieldAdd);
                                    if (!$isAdded->isSuccess()) {
                                        throw new ArgumentException(serialize($isAdded->getErrorMessages()));
                                    } else {

                                        //добавляем запись в DWH
                                        if (!$dwhCon->query("insert into DevinoDWH.bitrix.ManagerPlan(PlanId, ManagerBranch, ManagerLogin, MonthStart, Channel, System, ProfitPlan, TrafficPlan) values('{$isAdded->getId()}', '{$branchName}', '{$userLogin}', '{$curDate->format('Y-d-m')}',  '{$channel}', '{$platform}', '{$postData['profit']}', '{$postData['traffic']}')")) {
                                            throw new ArgumentException("Ошибка добавления строки {$salesPlanListRow['ID']} в таблицу DevinoDWH.bitrix.ManagerPlan. Обратитесь к администратору.");
                                        }
                                    }

                                } elseif (count($alreadyExist) >= 1) {
                                    //выводим ошибку, что такой план уже есть
                                    $errors .= "У пользователя {$userLogin}, канал {$channel}, платформа {$platform} за дату 01.{$postData['month']}.{$postData['year']} уже имеется запись<br>";
                                }
                            }
                        }
                    }

                    break;
            }

            if ($errors) {
                echo ajaxDevinoResponse(false, [], $errors);
            } else {
                echo ajaxDevinoResponse(true);
            }

        } catch (ArgumentException | Exception | SystemException | TypeError $ex) {
            echo ajaxDevinoResponse(false, [], $ex->getMessage());
        }
    }


    public static function getDepartName($departmentId, array $departmentsData) {
        if (!$departmentsData[$departmentId]) return null;
        if ($departmentsData[$departmentId]['PARENT_ID'] == self::DEP_GLOBAL) {
            return $departmentsData[$departmentId]['NAME'];
        } else {
            return self::getDepartName((int)$departmentsData[$departmentId]['PARENT_ID'], $departmentsData);
        }
    }

    public function executeComponent() {
        try {
            $this->onPrepareComponentResult();
            $this->checkActionButtons();

            $this->arResult['FILTER_DATA'] = $this->prepareFilterData((new FilterOptions($this->arResult['FILTER_ID']))->getFilter());

            $grid_option = new GridOptions($this->arResult['GRID_ID']);

            $nav_params = $grid_option->GetNavParams();
            $nav = new PageNavigation($this->arResult['GRID_ID']);
            $nav->allowAllRecords(true)->setPageSize($nav_params['nPageSize'])->initFromUri();

            $planData = $this->getSalesPlanData($nav->getOffset(), $nav->getLimit(), $grid_option->getSorting()['sort']);

            $this->arResult['ITEMS'] = $planData['ITEMS'];

            $nav->setRecordCount($planData['COUNT']);
            $this->arResult['NAV_OBJECT'] = $nav;


        } catch (ArgumentException | Exception | SystemException | Error | ObjectException | TypeError $ex) {
            ShowError($ex->getMessage());
        }

        $this->includeComponentTemplate();
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function onPrepareComponentResult() {
        $this->arResult['FILTER_ID'] = 'sales_plan';

        $this->arResult['GRID_ID'] = 'grid_sales_plan';
        $this->arResult['JS_OBJECT'] = 'JsSalesPlanGrid';

        $this->arResult['BRANCHES_NAME'] = self::getBranchesName();

        $this->arResult['BRANCHES_HTML'] = (function () {
            $returned[] = array('NAME' => 'Не указано', 'VALUE' => 'EMPTY');

            foreach ($this->arResult['BRANCHES_NAME'] as $branchName) {
                $returned[] = array(
                    'NAME' => $branchName,
                    'VALUE' => $branchName
                );
            }

            return $returned;
        })();

        $this->arResult['FILTER_ROWS'] = array(
            array('id' => 'DATE', 'name' => 'Дата', 'default' => true, 'type' => 'date',
                'exclude' => array(
                    DateType::YESTERDAY, DateType::CURRENT_DAY, DateType::CURRENT_WEEK, DateType::LAST_7_DAYS,
                    DateType::NEXT_DAYS, DateType::NEXT_MONTH, DateType::NEXT_WEEK, DateType::LAST_WEEK, DateType::TOMORROW,
                    DateType::PREV_DAYS, DateType::EXACT, DateType::LAST_30_DAYS, DateType::LAST_60_DAYS, DateType::LAST_90_DAYS,
                    DateType::RANGE
                )),
            array('id' => 'USER', 'name' => 'Пользователь', 'default' => true, 'type' => 'dest_selector',
                'params' => array('enableDepartments' => 'Y', 'enableSonetgroups' => 'Y')),
            array('id' => 'CHANNEL', 'name' => 'Канал', 'default' => true, 'type' => 'list', 'items' => $this->getChannels(), 'params' => array('multiple' => 'Y')),
            array('id' => 'PLATFORM', 'name' => 'Платформа', 'default' => true, 'type' => 'list', 'items' => $this->getPlatforms())
        );

        $this->arResult['COLUMNS'] = array(
            array("id" => "ID", "name" => "ID записи", "default" => true, 'editable' => false, "sort" => "ID"),
            array("id" => "USER", "name" => "Пользователь", "default" => true, 'editable' => false, "sort" => "UF_USER_LOGIN"),
            array("id" => "DATE", "name" => "Дата", "default" => true, 'editable' => false, "sort" => "UF_DATE"),
            array("id" => "CHANNEL", "name" => "Канал", "default" => true, 'editable' => false, "sort" => "UF_CHANNEL"),
            array("id" => "MANAGER_BRANCH", "name" => "Представительство", "default" => true, 'editable' => false, "sort" => "UF_MANAGER_BRANCH"),
            array("id" => "PLATFORM", "name" => "Платформа", "default" => true, 'editable' => false, "sort" => "UF_PLATFORM"),
            array("id" => "PLAN_PROFIT", "name" => "План на профит", "default" => true, 'editable' => true, "sort" => "UF_PLAN_PROFIT"),
            array("id" => "PLAN_TRAFFIC", "name" => "План на трафик", "default" => true, 'editable' => true, "sort" => "UF_PLAN_TRAFFIC"),
            array("id" => "PLAN_AMOUNT", "name" => "План на выручку", "default" => true, 'editable' => true, "sort" => "UF_PLAN_AMOUNT")
        );

        global $USER;

        $usersInGroup = array_column(UserGroupTable::getList(array(
            'select' => array('USER_ID'),
            'filter' => array('=GROUP_ID' => self::ADMIN_REPORT_GROUP),
            'group' => array('USER_ID'),
        ))->fetchAll(), 'USER_ID');

        if ($USER->IsAdmin() || in_array($USER->GetID(), $usersInGroup)) {
            $this->arResult['TYPE_REPORT'] = array('TYPE' => 'ADMIN');
        } else {
            $curUserId = $USER->GetID();
            $curUserLogin = $USER->GetLogin();

            $getDepartByHeads = array_column(SectionTable::getList(array(
                'select' => array('ID'),
                'filter' => array(
                    '=IBLOCK_ID' => self::DEPART_IBLOCK,
                    '=UTS_SECTION_TABLE.UF_HEAD' => $curUserId
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

            if ($whoInDepart) {
                if (!in_array($curUserLogin, $whoInDepart)) {
                    $whoInDepart[] = $curUserLogin;
                }

                $this->arResult['TYPE_REPORT'] = array(
                    'TYPE' => 'HEAD_DEPART',
                    'USER_ID' => $curUserId,
                    'WHO_IN_DEP' => $whoInDepart,
                    'USER_LOGIN' => $curUserLogin
                );
            } else {
                throw new ArgumentException("Доступ запрещен");
            }
        }

        if ($this->arResult['TYPE_REPORT']['TYPE'] == 'ADMIN') {
            $this->arResult['FILTER_ROWS'][] = array('id' => 'MANAGER_BRANCH', 'name' => 'Представительство', 'default' => true, 'type' => 'list', 'items' => self::getBranchesName());
        }
    }


    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getChannels(): array {
        $returned = array();

        foreach (TriggersReportTable::getList(array(
            'select' => array(new ExpressionField('CHANNEL', 'distinct UF_CHANNEL'))
        )) as $channel) {
            $returned[$channel['CHANNEL']] = $channel['CHANNEL'];
        }

        return $returned;
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function getBranchesName(): array {
        return array_column(SectionTable::getList(array(
            'select' => array('ID', 'NAME'),
            'filter' => array('=IBLOCK_ID' => '5', '=DEPTH_LEVEL' => '2')
        ))->fetchAll(), 'NAME', 'NAME');
    }

    /**
     * @param $offset
     * @param $limit
     * @param $sort
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getSalesPlanData($offset, $limit, $sort): array {
        $returned = [];

        $salesPlanList = SalesPlanTable::getList(array(
            'select' => array(
                'ID', 'UF_CHANNEL', 'UF_DATE', 'UF_PLAN_PROFIT',
                'UF_PLAN_TRAFFIC', 'UF_PLATFORM', 'UF_USER_LOGIN',
                'USER_ID' => 'USER_TABLE.ID', 'USER_NAME' => 'USER_TABLE.NAME',
                'USER_LOGIN' => 'USER_TABLE.LOGIN', 'USER_LAST_NAME' => 'USER_TABLE.LAST_NAME',
                'UF_MANAGER_BRANCH', 'UF_PLAN_AMOUNT'
            ),
            'filter' => $this->arResult['FILTER_DATA'],
            'runtime' => array(
                new ReferenceField(
                    'USER_TABLE',
                    UserTable::class,
                    Join::on('this.UF_USER_LOGIN', 'ref.LOGIN')
                )
            ),
            'offset' => $offset,
            'limit' => $limit,
            'order' => $sort
        ))->fetchAll();

        foreach ($salesPlanList as $rowSalesPlan) {
            $userName = ($rowSalesPlan['USER_ID'] == self::SALES_PLAN_USER_ID) ? "Общий план" : $rowSalesPlan['USER_LAST_NAME'] . ' ' . $rowSalesPlan['USER_NAME'];

            $returned['ITEMS'][] = array(
                'id' => $rowSalesPlan['ID'],
                'data' => array(
                    'ID' => $rowSalesPlan['ID'],
                    'USER' => "<a href='/company/personal/user/" . $rowSalesPlan['USER_ID'] . "/'>" . $userName . "</a>",
                    'DATE' => $rowSalesPlan['UF_DATE'],
                    'CHANNEL' => $rowSalesPlan['UF_CHANNEL'],
                    'PLATFORM' => $rowSalesPlan['UF_PLATFORM'],
                    'PLAN_PROFIT' => $rowSalesPlan['UF_PLAN_PROFIT'],
                    'PLAN_TRAFFIC' => $rowSalesPlan['UF_PLAN_TRAFFIC'],
                    'PLAN_AMOUNT' => $rowSalesPlan['UF_PLAN_AMOUNT'],
                    'MANAGER_BRANCH' => $rowSalesPlan['UF_MANAGER_BRANCH']
                ),
                'editable' => true
            );
        }

        $returned['COUNT'] = SalesPlanTable::getList(array(
            'select' => array(new ExpressionField('COUNT_ROWS', 'COUNT(*)')),
            'filter' => $this->arResult['FILTER_DATA']
        ))->fetch()['COUNT_ROWS'];

        return $returned;
    }

    /**
     * @throws Exception
     */
    public function checkActionButtons() {
        $actionData = array('METHOD' => $_SERVER['REQUEST_METHOD']);

        CUtil::JSPostUnescape();

        if (($_POST['ID'] || $_POST['FIELDS']) && check_bitrix_sessid()) {

            if ($_POST['FIELDS']) {
                $actionData['FIELDS'] = $_POST['FIELDS'];
                unset($_POST['FIELDS'], $_REQUEST['FIELDS']);
            }

            $actionData['BUTTON'] = "action_button_{$this->arResult['GRID_ID']}";

            require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/DwhConnected.php';
            $dwhCon = DwhConnected::connectToDwh();

            switch ($_REQUEST[$actionData['BUTTON']]) {
                case 'edit':
                    foreach ($actionData['FIELDS'] as $idRow => $newData) {
                        $newFields = array(
                            'UF_PLAN_TRAFFIC' => $newData['PLAN_TRAFFIC'],
                            'UF_PLAN_PROFIT' => $newData['PLAN_PROFIT'],
                            'UF_PLAN_AMOUNT' => $newData['PLAN_AMOUNT']
                        );

                        $isEdit = SalesPlanTable::update($idRow, $newFields);

                        if (!$isEdit->isSuccess()) {
                            throw new ArgumentException(implode(',', $isEdit->getErrorMessages()));
                        } else {

                            //обновляем запись в DWH
                            if (!$dwhCon->query("update DevinoDWH.bitrix.ManagerPlan set ProfitPlan = '{$newData['PLAN_PROFIT']}', TrafficPlan = '{$newData['PLAN_TRAFFIC']}', AmountPlan = '{$newData['PLAN_AMOUNT']}' where PlanId = {$idRow}")) {
                                throw new ArgumentException("Ошибка обновления записи таблице DevinoDWH.bitrix.ManagerPlan, строчка {$idRow}. Обратитесь к администратору.");
                            }
                        }
                    }
                    break;

                case 'delete':
                    foreach ($_POST['ID'] as $idRow) {
                        $isRemove = SalesPlanTable::delete($idRow);

                        if (!$isRemove->isSuccess()) {
                            throw new ArgumentException(implode(',', $isRemove->getErrorMessages()));
                        } else {

                            //удаляем запись в DWH
                            if (!$dwhCon->query("delete from DevinoDWH.bitrix.ManagerPlan where PlanId = {$idRow}")) {
                                throw new ArgumentException("Ошибка удаления записи из таблицы DevinoDWH.bitrix.ManagerPlan, строчка {$idRow}. Обратитесь к администратору.");
                            }
                        }
                    }
                    break;
            }
        }
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getUsers(): array {
        if ($this->arResult['TYPE_REPORT']['TYPE'] == 'ADMIN') {
            $returned[] = array("NAME" => "Общий план", "VALUE" => self::SALES_PLAN_USER_LOGIN);
        }

        $usersList = UserTable::getList(array(
            'select' => array('ID', 'NAME', 'LAST_NAME', 'LOGIN'),
            'filter' => array(
                '=ACTIVE' => 'Y',
                '!%LOGIN' => 'imconnector_',
                '=UF_DEPARTMENT' => self::ACCESS_DEPART,
                '!=NAME' => null,
                '!=LAST_NAME' => null
            )
        ))->fetchAll();

        foreach ($usersList as $user) {
            if ($this->arResult['TYPE_REPORT']['TYPE'] == 'HEAD_DEPART' && !in_array($user['LOGIN'], $this->arResult['TYPE_REPORT']['WHO_IN_DEP'])) {
                continue;
            }

            $returned[] = array(
                'NAME' => $user['LAST_NAME'] . ' ' . $user['NAME'],
                'VALUE' => $user['LOGIN']
            );
        }

        return $returned;
    }

    public function getMonthsList(): array {
        $returned = [];

        foreach (self::MONTH_LIST as $keyMonth => $monthName) {
            $returned[] = array(
                'NAME' => $monthName,
                'VALUE' => $keyMonth
            );
        }

        return $returned;
    }

    public static function getCurrentMonth(): array {
        $curMonth = \date('m');
        return array('NAME' => self::MONTH_LIST[$curMonth], 'VALUE' => $curMonth);
    }

    public static function getCurrentYear(): string {
        return \date('Y');
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function getChannelsList(): array {
        $returned = [];

        foreach (TriggersReportTable::getList(array(
            'select' => array(new ExpressionField('CHANNEL', 'distinct UF_CHANNEL'))
        )) as $channel) {
            $returned[] = array(
                'NAME' => $channel['CHANNEL'],
                'VALUE' => $channel['CHANNEL']
            );
        }

        return $returned;
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function getPlatformsList(): array {
        $returned = [];

        foreach (TriggersReportTable::getList(array(
            'select' => array(new ExpressionField('SYSTEM', 'distinct UF_SYSTEM'))
        )) as $channel) {
            $returned[] = array(
                'NAME' => $channel['SYSTEM'],
                'VALUE' => $channel['SYSTEM']
            );
        }

        return $returned;
    }

    /**
     * @param string $userId
     * @return mixed
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getUserLoginById(string $userId) {
        return UserTable::getById($userId)->fetch()['LOGIN'];
    }

    /**
     * @param array $filterData
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function prepareFilterData(array $filterData): array {
        $newFilterData = [];


        if ($this->arResult['TYPE_REPORT']['TYPE'] == 'HEAD_DEPART') {
            $newFilterData['=UF_USER_LOGIN'] = $this->arResult['TYPE_REPORT']['WHO_IN_DEP'];
        }

        foreach ($filterData as $key => $filterParam) {
            switch ($key) {
                case 'DATE_from':
                    $newFilterData['>=UF_DATE'] = new Date($filterParam);
                    break;
                case 'DATE_to':
                    $newFilterData['<=UF_DATE'] = new Date($filterParam);
                    break;
                case 'USER':
                    $userLogin = $this->getUserLoginById(preg_replace("/[^0-9]/", '', $filterParam));

                    if ($this->arResult['TYPE_REPORT']['TYPE'] == 'HEAD_DEPART' &&
                        !in_array($userLogin, $this->arResult['TYPE_REPORT']['WHO_IN_DEP'])
                    ) {
                        throw new ArgumentException();
                    }

                    $newFilterData['=UF_USER_LOGIN'] = $userLogin;
                    break;
                case 'CHANNEL':
                    $newFilterData['=UF_CHANNEL'] = $filterParam;
                    break;
                case 'PLATFORM':
                    $newFilterData["=UF_PLATFORM"] = $filterParam;
                    break;

                case 'MANAGER_BRANCH':
                    if ($this->arResult['TYPE_REPORT']['TYPE'] == 'ADMIN') {
                        $newFilterData['=UF_MANAGER_BRANCH'] = $filterParam;
                    }
                    break;
            }
        }

        return $newFilterData;
    }

    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getPlatforms(): array {
        $returned["TOTAL"] = "Total";

        foreach (TriggersReportTable::getList(array(
            'select' => array(new ExpressionField('SYSTEM', 'distinct UF_SYSTEM'))
        )) as $channel) {
            $returned[$channel['SYSTEM']] = $channel['SYSTEM'];
        }

        return $returned;
    }
}