<?

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;
use const Bitrix\Main\SystemException;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/DevinoFields.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/devdo.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/devcrm.php';

require_once __DIR__ . '/EntityNotificationInterface.php';
require_once __DIR__ . '/ContractNotificationController.php';
require_once __DIR__ . '/CompanyNotificationControllers.php';
require_once __DIR__ . '/UserNotificationControllers.php';

Loader::includeModule('crm');
Loader::includeModule('iblock');

class NotificationController {
    public $LAST_ERROR = null; //переменная для ошибок

    protected $obDo; //переменная для класса
    protected $obCrm; //переменная для crm rest api
    protected $notificationsData = null;
    protected $notifications = null;

    private $arrayConst; //переменная для констант
    private $modeIsTest = false; // переменная для получения данных по
    private $startDateNotification = null;
    private $endDateNotification = null;

    const TEST_MODE = 'dev'; // режим для работы с http://kube.devinotest.local/
    const PROD_MODE = 'prod'; // режим для работы с http://kube.devinoprod.local/
    const BP_STATE_COMPLETE = 'Completed'; //статус, когда бизнес процесс завершен
    const NOTIFICATION_CONTRACT = 'contract';
    const NOTIFICATION_COMPANY = 'company';
    const NOTIFICATION_USER = 'user';
    const IBLOCK_PREDSTAV = '34';
    const UR_PREDSTAV_DEFAULT_ID = 267; //по умолчанию дефолтный id ur lica
    const DEFAULT_PREDSTAV = 258;
    const DEFAULT_UR_LICO_PREDSTAV = 10576;
    const SOURCE_REGISTER_NLK = 1;

    /**
     * NotificationController constructor.
     * @param string $mode
     */
    public function __construct($mode = self::PROD_MODE) {
        //определяем какой режим согласно переданной переменной
        if ($mode == self::TEST_MODE)
            $this->setModeIsTest(true);

        //объекты для запросов к DO и rest API Bitrix
        $this->obDo = new devDO();
        $this->obCrm = new devCRM();

        //записываем константу юрл путей, в зависимости от режима
        $this->arrayConst = $this->obDo->GetConstants($this->modeIsTest());
    }

    /**
     * @param $mode
     *
     * метод для установки тестового режима
     */
    public function setModeIsTest($mode) {
        $this->modeIsTest = $mode;
    }

    /**
     * @param $dateStart
     *
     * установка даты начала сбора нотификаций
     */
    public function setDateStartNotification($dateStart) {
        $this->startDateNotification = $dateStart;
    }

    /**
     * @param $dateEnd
     *
     * установка даты окончания сбора нотификаций
     */
    public function setDateEndNotification($dateEnd) {
        $this->endDateNotification = $dateEnd;
    }

    /**
     * @return |null
     *
     * получить дату начала сбора нотификаций
     */
    public function getStartDateNotification() {
        return $this->startDateNotification;
    }

    /**
     * @return |null
     *
     * получить дату окончания сбора нотифкаций
     */
    public function getEndDateNotification() {
        return $this->endDateNotification;
    }

    /**
     * @return array
     */
    public function getConstant() {
        return $this->arrayConst;
    }

    /**
     * @return bool
     */
    public function modeIsTest() {
        return $this->modeIsTest;
    }

    /**
     * @param $arrCompanies
     * @return array|bool|mixed|string
     */
    public function getCompaniesByCompanyIDs_DO(array $arrCompanies) {
        $queryStringUrl = prepareQueryString($arrCompanies, 'companyIds');
        $queryString = $this->getConstant()['API_URLS']['DO'] . '/internal/companies/by-company-ids' . $queryStringUrl;
        return $this->executeQueryDOWithLogged($queryString, 'getCompaniesByCompanyIDs_DO');
    }

    /**
     * @param $companyId
     * @return array|bool|mixed|string
     *
     * получение информации по компании
     */
    public function getCompanyByCompanyID_DO($companyId) {
        $queryString = $this->getConstant()['API_URLS']['DO'] . '/internal/companies/' . $companyId;
        return $this->executeQueryDOWithLogged($queryString, 'getCompanyByCompanyID_DO');
    }

    /**
     *
     * получение формированных данных из нотификаций
     */
    public function getNotificationsData() {
        return $this->notificationsData;
    }

    /**
     * @return |null
     *
     * простое получение нотификаций
     */
    public function getNotifications() {
        return $this->notifications;
    }

    /**
     * @param string $companyId
     * @param string|null $contractStatus
     * @return array
     *
     * получение договоров компании
     */
    public function getContractsByCompanyId(string $companyId, string $contractStatus = null) {
        if ($contractStatus != null) $queryGet = "?contractStatus={$contractStatus}";
        $queryString = $this->getConstant()['API_URLS']['DO'] . '/companies/current/contracts' . $queryGet;
        return $this->executeQueryDOWithLogged($queryString, 'getContractsByCompanyId', array("X-Company-Id: {$companyId}"));
    }

    /**
     * @param string $login
     * @return array
     *
     * проверка на существование пользователя в системе
     */
    public function getUserIssetByLogin(string $login) {
        $queryString = $this->getConstant()['API_URLS']['DO'] . '/users/by-phone/' . $login . '/has-account';
        return $this->executeQueryDOWithLogged($queryString, 'getCompanyByCompanyID_DO');
    }

    /**
     * @param $contractId
     * @return array|bool|mixed|string
     *
     * получение информации по контракту
     */
    public function getContractByContractID_DO($contractId) {
        $queryString = $this->getConstant()['API_URLS']['DO'] . '/internal/contracts/' . $contractId;
        return $this->executeQueryDOWithLogged($queryString, 'getContractByContractID_DO');
    }

    /**
     * @param array $arWorkflowParameters
     * @param $leadId
     * @param $templateId
     *
     * выполнение шаблона БП на создание контакта и компании из лида
     * @return array
     */
    public function executeBizProc(array $arWorkflowParameters, $leadId, $templateId) {
        $paramsQuery = array(
            'TEMPLATE_ID' => $templateId,
            'DOCUMENT_ID' => array("crm", "CCrmDocumentLead", $leadId),
            'PARAMETERS' => $arWorkflowParameters
        );

        return $this->executeQueryCrmWithLogged('bizproc.workflow.start', $paramsQuery, 'executeBizProc');
    }

    /**
     * @param array $dataQuery
     * @return array
     *
     * выбор данных реквизитов
     */
    public function getRequisite(array $dataQuery) {
        return $this->executeQueryCrmWithLogged('crm.requisite.list', $dataQuery, 'getRequisite');
    }

    /**
     * @param $userId
     * @return array|bool|mixed|string
     *
     * получение иформации по пользователю
     */
    public function getUserByUserID_DO($userId) {
        $queryString = $this->getConstant()['API_URLS']['DO'] . '/users/current';
        return $this->executeQueryDOWithLogged($queryString, 'getUserByUserID_DO', array("X-User-Id: {$userId}"));
    }

    /**
     * @param $userId
     * @return array|bool|mixed|string
     *
     * получение настроек пользователя
     */
    public function getUserPreferencesByUserID_DO($userId) {
        $queryString = $this->getConstant()['API_URLS']['DO'] . '/internal/users/' . $userId . '/preferences';
        return $this->executeQueryDOWithLogged($queryString, 'getUserPreferencesByUserID_DO');
    }

    /**
     * @param $companyId
     * @return array|bool|mixed|string
     *
     * получение данных о пользователях привязанных к company_id
     */
    public function getUsersByCompanyID_DP($companyId) {
        $queryString['String'] = $this->getConstant()['API_URLS']['DO'] . '/internal/companies/current/users/all/extended?order=ASC';
        $query['Header'] = ['X-Company-Id: ' . $companyId];
        return $this->executeQueryDOWithLogged($queryString['String'], 'getUsersByCompanyID_DP', $query['Header']);
    }

    /**
     * @param $officeId
     * @return array|bool|mixed|string
     *
     * получение списка представительств
     */
    public function getOfficeByOfficeID_CRB($officeId) {
        $queryString = $this->getConstant()['API_URLS']['CRB'] . '/internal/offices/' . $officeId;
        return $this->executeQueryDOWithLogged($queryString, 'getOfficeByOfficeID_CRB');
    }

    /**
     * @param $companyId
     * @param $category
     * @param $fileId
     * @return array|bool|mixed|string
     *
     * получение данных о файле
     */
    public function getFileByFifeID_CF($companyId, $category, $fileId) {
        $query['String'] = $this->getConstant()['API_URLS']['CF'] . '/files/' . $fileId . '/info?category=' . $category;
        $query['Header'] = ['X-Company-Id: ' . $companyId];
        return $this->executeQueryDOWithLogged($query['String'], 'getFileByFifeID_CF', $query['Header']);
    }

    /**
     * @param $service
     * @param $entity
     * @param $queryGetString
     * @param bool $isSort
     * @return array|bool|mixed|string
     *
     * проверка нотификаций по конкретной сущности
     */
    private function checkNotifications($service, $entity, array $queryGetString, bool $isSort = true) {
        $queryString = $this->getConstant()['API_URLS'][$service] . '/internal/' . $entity . '-notifications' . prepareQueryString($queryGetString);
        $result = $this->obDo->Query($queryString, $entity);

        if ($isSort) usort($result['result'], array($this, 'sortDateCreate'));

        return $result;
    }

    /**
     * @param $queryString
     * @param $description
     * @param array $header
     * @return array
     *
     * выполнение запроса в DO с логированием
     */
    public function executeQueryDOWithLogged($queryString, $description = null, $header = array("accept: */*")) {
        $resultQuery = $this->obDo->Query($queryString, $header);
        return $this->loggedWrite($resultQuery, $queryString, $description, $header);
    }

    /**
     * @param $method
     * @param array $dataQuery
     * @param null $description
     * @return array
     *
     * выполнение запроса в CRM с логированием
     */
    public function executeQueryCrmWithLogged($method, array $dataQuery, $description = null) {
        $resultQuery = $this->obCrm->Event($method, $dataQuery);
        return $this->loggedWrite($resultQuery, serialize($dataQuery), $description);
    }

    /**
     * @param array $resultQuery
     * @param $queryString
     * @param null $description
     * @param null $header
     * @return array
     *
     * логирование запросов в зависимости от статуса выполнения
     */
    private function loggedWrite($resultQuery, $queryString, $description = null, $header = null) {
        if ((isset($resultQuery['error']) && $resultQuery['error'] != null) ||
            (isset($resultQuery['description']) && $resultQuery['description'] != null)
        ) {
            writeToLog("/logs/agents/do/errors/", $resultQuery, $description, $queryString, $header);
        }

        return $resultQuery;
    }

    /**
     * @param $entity
     * @return mixed
     *
     * получение последней успешной нотификации
     */
    public function getLastTrueIntegration($entity) {
        global $DB;
        $resSelect = $DB->Query('SELECT * FROM entity_notification_controller WHERE UF_ENTITY = "' . $entity . '" ORDER BY ID DESC LIMIT 1');
        return $resSelect->Fetch();
    }

    /**
     * @param $timeStamp
     * @param array $arNotifications
     * @param $answerResult
     * @param $typeNotification
     * @return bool|string
     *
     * запись последней успешной интеграции в хайблок
     */
    public function addLastTrueIntegration($timeStamp, $arNotifications, $answerResult, $typeNotification) {
        global $DB;
        $err_mess = "";
        $DB->PrepareFields("entity_notification_controller");

        $arrDBFields = array(
            "UF_ENTITY" => "'" . $typeNotification . "'",
            "UF_START_DATE" => "'" . $timeStamp . "'",
            "UF_STATE" => "'Y'",
            "UF_ANSWER" => (!empty($answerResult) ? "'" . serialize(str_replace("'", '"', $answerResult)) . "'" : "'no result'"),
            "UF_NOTIFICATIONS" => "'" . serialize($arNotifications) . "'",
        );
        return $DB->Insert("entity_notification_controller", $arrDBFields, $err_mess . __LINE__);
    }

    /**
     * @param $service
     * @param $entity
     * @return array
     *
     * получения списка нотификаций
     */
    public function CheckNotificationController($service, $entity) {
        $arQueryStringNotifications = array(
            'startDate' => $this->getStartDateNotification(),
            'endDate' => $this->getEndDateNotification()
        );
        $checkNotificationResult = $this->checkNotifications($service, $entity, $arQueryStringNotifications, true);

        return array(
            'result' => $checkNotificationResult['result'],
            'rangeDate' => $arQueryStringNotifications,
            'errors' => $checkNotificationResult['description']
        );
    }

    public function getCompany(array $dataField) {
        return $this->executeQueryCrmWithLogged('crm.company.list', $dataField, 'getCompany');
    }

    public function waitCompleteStatusBizProc($idWorkflow, $elementId) {
        if (!$idWorkflow || !$elementId) return false;
        sleep(5);

        global $DB;
        $res = $DB->Query("SELECT STATE FROM b_bp_workflow_state WHERE ID = '{$idWorkflow}' AND DOCUMENT_ID = '{$elementId}'")->fetch();

        if ($res['STATE'] == self::BP_STATE_COMPLETE) return true;
        $this->waitCompleteStatusBizProc($idWorkflow, $elementId);
    }

    /**
     * @param $a
     * @param $b
     * @return int
     *
     * сортировка нотификаций по дате
     */
    private function sortDateCreate($a, $b) {
        if ($a['lastUpdate'] > $b['lastUpdate']) return 1;
    }

    /**
     * @param array $dataQuery
     * @return array
     *
     * получение данных по лиду по его ид
     */
    public function getLeadById(array $dataQuery) {
        return $this->executeQueryCrmWithLogged('crm.lead.get', $dataQuery, 'getLeadById');
    }

    /**
     * @param array $dataQuery
     * @return array
     *
     * получение данных по лиду с логированием
     */
    public function getLead(array $dataQuery) {
        return $this->executeQueryCrmWithLogged('crm.lead.list', $dataQuery, 'getLead');
    }

    /**
     * @param array $dataQuery
     * @return array
     *
     * обновление данных лида
     */
    public function updateLead(array $dataQuery) {
        return $this->executeQueryCrmWithLogged('crm.lead.update', $dataQuery, 'updateLead');
    }


    /**
     * @param array $arFieldsAdd
     * @return array
     *
     * добавление лида
     */
    public function addLead(array $arFieldsAdd) {
        return $this->executeQueryCrmWithLogged('crm.lead.add', $arFieldsAdd, 'addLead');
    }

    /**
     * @param $currentDateTime
     * @param $entity
     * @return mixed
     *
     * устанавливаем времена начала и окончания сбора нотифкаций
     */
    public function setAllQueryDate($currentDateTime, $entity) {
        $dateStart = null;
        $dateEnd = null;

        if (isset($GLOBALS['beginDateNotifications']) && isset($GLOBALS['endDateNotifications']) &&
            $GLOBALS['beginDateNotifications'] != null && $GLOBALS['endDateNotifications'] != null) {
            $dateStart = $GLOBALS['beginDateNotifications'];
            $dateEnd = $GLOBALS['endDateNotifications'];
        } else {
            $dateStart = $this->getLastTrueIntegration($entity)['UF_START_DATE'];
            $dateEnd = $currentDateTime;
        }

        $this->setDateStartNotification($dateStart);
        $this->setDateEndNotification($dateEnd);
    }

    /**
     * @param array $addFieldRQ
     * @return array
     *
     * метод добавления реквизитов
     */
    public function addRQ(array $addFieldRQ) {
        return $this->executeQueryCrmWithLogged('crm.requisite.add', $addFieldRQ, 'addRequisite');
    }

    /**
     * @param array $arFieldAddr
     * @return array
     *
     * метод добавления реквизитов связанных с адресом
     */
    public function addRQAddr(array $arFieldAddr) {
        return $this->executeQueryCrmWithLogged('crm.address.add', $arFieldAddr, 'addRQAddr');
    }

    /**
     * @param array $arFieldBank
     * @return array
     *
     * метод добавления банковских реквизитов
     */
    public function addRQBankDetail(array $arFieldBank) {
        return $this->executeQueryCrmWithLogged('crm.requisite.bankdetail.add', $arFieldBank, 'addRQBankDetail');
    }

    /**
     * @param array $dataQuery
     * @return array
     *
     * поулчение данных сделки с логированием
     */
    public function getDeal(array $dataQuery) {
        return $this->executeQueryCrmWithLogged('crm.deal.list', $dataQuery, 'getDeal');
    }

    /**
     * @param array $dataQuery
     * @return array
     *
     * получение контактов по фильтру
     */
    public function getContacts(array $dataQuery) {
        return $this->executeQueryCrmWithLogged('crm.contact.list', $dataQuery, 'getContacts');
    }

    /**
     * @param array $dataQuery
     * @return array
     *
     * добавление нового контакта
     */
    public function addContact(array $dataQuery) {
        return $this->executeQueryCrmWithLogged('crm.contact.add', $dataQuery, 'addContact');
    }

    /**
     * @param array $dataQuery
     * @return array
     *
     * обновление контакта
     */
    public function updateContact(array $dataQuery) {
        return $this->executeQueryCrmWithLogged('crm.contact.update', $dataQuery, 'updateContact');
    }

    /*
     * установка данных нотификации
     */
    public function setNotifications($data) {
        $this->notifications = $data;
    }

    /**
     * @param $officeId
     * @return int|mixed
     * получение idBx представительства
     */
    public function getPredstav($officeId) {
        $predstavId = CIBlockSection::GetList(array(),
            array(
                'IBLOCK_ID' => self::IBLOCK_PREDSTAV,
                'UF_OFFICE_ID' => $officeId,
                'CHECK_PERMISSIONS' => 'N'
            ), false,
            array(
                'ID',
                'IBLOCK_ID',
                'UF_OFFICE_ID'
            )
        )->fetch()['ID'];

        return ($predstavId) ? $predstavId : self::DEFAULT_PREDSTAV;
    }

    public function getUrLicoPredstav($predstavId) {
        $getAllUrPredstav = CIBlockElement::GetList(array('ID' => 'ASC'),
            array(
                'IBLOCK_ID' => self::IBLOCK_PREDSTAV,
                'CHECK_PERMISSIONS' => 'N',
                'SECTION_ID' => $predstavId
            ), false, false, array(
                'ID',
                'IBLOCK_ID',
                'PROPERTY_DEFAULT'
            )
        );

        while ($urLicoPredstav = $getAllUrPredstav->fetch()) {
            if ($urLicoPredstav['PROPERTY_DEFAULT_ENUM_ID'] == self::UR_PREDSTAV_DEFAULT_ID) return $urLicoPredstav['ID'];
            $allUrlica[] = $urLicoPredstav;
        }

        $urLicoDef = end($allUrlica);

        return ($urLicoDef['ID']) ? $urLicoDef['ID'] : self::DEFAULT_UR_LICO_PREDSTAV;
    }

    /**
     * @param array $dataPreference
     * @param string $entity
     * @return array
     * генерация fm полей (email, phone)
     */
    public function generateFmData($dataPreference, string $entity) {
        return array(
            'VALUE_TYPE' => 'WORK',
            'TYPE_ID' => mb_strtoupper($entity),
            'VALUE' => $dataPreference[$entity],
        );
    }

    /**
     * @param array $arrayForAdded
     * @param array $arrayFromAdded
     * @param array $userPreference
     * @param string $entityType
     *
     * проверка на существование таких email или phone у сущности
     */
    public function ifNonIncludedFM(array &$arrayForAdded, $arrayFromAdded, $userPreference, string $entityType) {
        //вначале проходим те значения, которые у нас есть в качестве email или phone у сущности
        $entityValuesArray = array();
        foreach ($arrayFromAdded[mb_strtoupper($entityType)] as $entityValue) {
            $entityValuesArray[] = $entityValue['VALUE'];
        }
        //если у обнавляемой сущности нет таких номеров телефона или email, то добавляем в обновляемые поля
        if (!in_array($userPreference[mb_strtolower($entityType)], $entityValuesArray)) {
            $arrayForAdded[mb_strtoupper($entityType)][] = $this->generateFmData($userPreference, mb_strtolower($entityType));
        }
    }

    /**
     * @param string $entityType
     *
     * запись нотифкаций по сущности
     */
    public function setNotificationsData(string $entityType) {
        $this->setNotifications($this->CheckNotificationController('DO', $entityType));
    }

    /**
     * @param string $login
     * @return mixed
     *
     * получение id пользователя по логину
     */
    public function getUserIdByLogin($login) {
        return CUser::GetByLogin($login)->fetch()['ID'];
    }

    /**
     * @param array $forUpdateLead
     * @param string $dataFromUpdate
     * @param string $field
     * @param $newData
     *
     * проверка на схожесть обновляемых знчений
     */
    public function checkFieldForUpdated(array &$forUpdateLead, $dataFromUpdate, string $field, $newData) {
        if ($newData && $dataFromUpdate != $newData) {
            $forUpdateLead[$field] = $newData;
        }
    }

    /**
     * @version new 02.07 12:32
     */
    /**
     * @param $userId
     * @param string $type
     * @return array
     *
     * получение логинов user по типу
     */
    public function getCredentialsByUser(string $userId, string $type) {
        $query['String'] = $this->getConstant()['API_URLS']['CA'] . '/credentials?type=' . $type;
        $query['Header'] = ['X-User-Id: ' . $userId];
        return $this->executeQueryDOWithLogged($query['String'], 'getCredentialsByUser', $query['Header']);
    }

    /**
     * @param $userId
     * @param $credentialsArray
     * @return void получение всех логинов
     *
     * получение всех логинов
     */
    public function getAllCredentialsBYUserId($userId, &$credentialsArray) {
        $credentials = array('API', 'SITE', 'FTP', 'SMTP', 'SMPP');
        foreach ($credentials as $credential) {
            $curCredential = $this->getCredentialsByUser($userId, $credential)['result'];
            if (!empty($curCredential))
                array_push($credentialsArray, $curCredential);
        }
    }
    /**
     * @version new 02.07 12:32
     */

    /**
     * @param string|null $queryStr
     * @return array
     *
     *
     *
     * @version 21.07 16:00
     */
    public function getSourceAddress(string $queryStr = null) {
        $queryString = $this->getConstant()['API_URLS']['DP'] . '/internal/source-addresses/extended' . $queryStr;
        return $this->executeQueryDOWithoutLogged($queryString);
    }


    public function getOpportunity(string $inn) {
        $queryString = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/opportunities?inn=' . $inn;
        return $this->executeQueryDOWithoutLogged($queryString);
    }

    public function addSourceAddressOpportunities($opportunityId, $managerLogin, array $addressRequest) {
        $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/opportunities/sms-addresses';

        return $this->executeOtherDOWithoutLogged('POST', $url,
            array(
                "X-Manager-Account: {$managerLogin}",
                "X-Opportunity-Id: {$opportunityId}",
                "Content-Type: application/json"
            ),
            Json::encode($addressRequest)
        );
    }

    public function addSourceAddressCompanyId($companyId, $managerLogin, array $addressRequest) {
        $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-addresses';

        return $this->executeOtherDOWithoutLogged('POST', $url,
            array(
                "X-Manager-Account: {$managerLogin}",
                "X-Company-Id: {$companyId}",
                "Content-Type: application/json"
            ),
            Json::encode($addressRequest)
        );
    }

    public function addSourceAddressCustomerId($customerId, $managerLogin, array $addressRequest) {
        $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/' . $customerId . '/sms-addresses';

        return $this->executeOtherDOWithoutLogged('POST', $url,
            array(
                "X-Manager-Account: {$managerLogin}",
                "Content-Type: application/json"
            ),
            Json::encode($addressRequest)
        );
    }

    public function executeQueryDOWithoutLogged($queryString, $header = array("accept: */*")) {
        return $this->obDo->Query($queryString, $header);
    }

    public function executeOtherDOWithoutLogged(string $method, string $url, array $header = array("accept: */*"), $postFields = null) {
        $postFields = array(
            'REQUEST_TYPE' => $method,
            'POST_FIELDS' => $postFields,
            'URL' => $url,
            'HEADER_FIELDS' => $header
        );
        return $this->obDo->QuerySetData($postFields);
    }

    public function getMogNameById(string $mogId) {
        $queryString = $this->getConstant()['API_URLS']['CRB'] . '/internal/mobile-operator-groups/' . $mogId;
        return $this->executeQueryDOWithoutLogged($queryString);
    }

    public function getOfficeNameById(string $officeId) {
        $queryString = $this->getConstant()['API_URLS']['CRB'] . '/internal/offices/' . $officeId;
        return $this->executeQueryDOWithoutLogged($queryString);
    }

    public function getMogList() {
        $queryString = $this->getConstant()['API_URLS']['CRB'] . '/mobile-operator-groups';
        return $this->executeQueryDOWithoutLogged($queryString);
    }

    public function getCompanyByInn(string $inn) {
        $queryString = $this->getConstant()['API_URLS']['DO'] . '/internal/companies/with-active-contract?likeInn=' . $inn;
        return $this->executeQueryDOWithoutLogged($queryString);
    }

    public function getContractByContractIdDO_WL(string $contractId) {
        $queryString = $this->getConstant()['API_URLS']['DO'] . '/internal/contracts/' . $contractId;
        return $this->executeQueryDOWithoutLogged($queryString);
    }

    /**
     * @version 21.07 16:00
     */


    /**
     * @version 12.09
     */

    public function getOffices() {
        $queryString = $this->getConstant()['API_URLS']['CRB'] . '/internal/offices';
        return $this->executeQueryDOWithoutLogged($queryString);
    }

    public function getMogByIds(array $mogsId) {
        $mogQuery = null;
        foreach ($mogsId as $itemMog) $mogQuery .= "&mogIds={$itemMog}";
        $mogQuery[0] = '?';

        $queryString = $this->getConstant()['API_URLS']['CRB'] . '/internal/mobile-operator-groups' . $mogQuery;
        return $this->executeQueryDOWithoutLogged($queryString);
    }

    /**
     * @version 12.09
     */


    /**
     * @param string $login
     * @return array
     *
     * @version 31.08
     */

    public function getAllCredentialsBYLogin(string $login) {
        $arTypes = array('SITE', 'API', 'FTP', 'SMPP', 'SMTP');
        $allLogins = [];

        foreach ($arTypes as $arTypeLogin) {
            $curLoginsTypes = $this->getCredentialsBYLoginType($login, $arTypeLogin)['result'];
            if (!empty($curLoginsTypes)) {
                $allLogins[] = $curLoginsTypes;
            }
        }

        return $allLogins;
    }

    public function getCredentialsBYLoginType(string $login, string $type) {
        $queryString = $this->getConstant()['API_URLS']['CA'] . '/internal/credentials/login/' . $login . '/type/' . $type;
        return $this->executeQueryDOWithoutLogged($queryString);
    }

    /**
     * @version 31.08
     */


    public function getCountryByIds(array $countryIds) {
        $http_build_query = implode('&countryIds=', $countryIds);
        $http_build_query[0] = '?';

        return $this->executeOtherDOWithoutLogged(
            'GET',
            $this->getConstant()['API_URLS']['CRB'] . '/internal/countries' . $http_build_query
        );
    }

    public function getCountryById(string $countryId) {
        return $this->getCountryByIds(array($countryId));
    }


    /**
     * @param $companyId
     * @return mixed|string[]
     *
     *
     * @version 22.09
     */

    public function getContractByCompanyId_WL($companyId) {
        $url = $this->getConstant()['API_URLS']['DO'] . "/companies/current/contracts";
        return $this->executeOtherDOWithoutLogged('GET', $url, array("X-Company-Id: {$companyId}"));
    }

    /**
     * @version 22.09
     */

    public static function getNotificationControllers($modifyCRMEntity, $mode, $agent = false) {
        //текущее время по Гринвичу, для записи его в таблицу
        $currentDateTime = gmdate('Y-m-d H:i:s');

        $arCompanies = (new CompanyNotificationControllers($currentDateTime, $mode))->executeNotification($modifyCRMEntity);
        $arContracts = (new ContractNotificationController($currentDateTime, $mode))->executeNotification($modifyCRMEntity);
        $arUsers = (new UserNotificationControllers($currentDateTime, $mode))->executeNotification($modifyCRMEntity);

        if ($agent == true) {
            return "NotificationController::getNotificationControllers(true, NOTIFICATION_CONTROLLER_OBJ, true);";
        } else {

            echo '<hr><h3>Companies</h3>';
            d($arCompanies);

            echo '<hr><h3>Users</h3>';
            d($arUsers);

            echo '<hr><h3>Contracts</h3>';
            d($arContracts);
        }
    }

    /*
     * BO++
     */


    /**
     * @param string $type
     * @param array $params
     * @return mixed|string[]
     *
     */

    public function getTemplateByBoPlus(string $type, array $params = []) {
        $query = [];

        if ($params['inn']) $query['inn'] = $params['inn'];
        if ($params['companyId']) $query['companyId'] = $params['companyId'];
        if ($params['customerId']) $query['customerId'] = $params['customerId'];
        if ($params['mogId']) $query['mogId'] = $params['mogId'];
        if ($params['searchPattern']) $query['searchPattern'] = $params['searchPattern'];
        if ($params['smsAddress']) $query['smsAddress'] = $params['smsAddress'];
        if ($params['viberAddress']) $query['viberAddress'] = $params['viberAddress'];
        if ($params['smsAddressId']) $query['smsAddressId'] = $params['smsAddressId'];
        if ($params['viberAddressId']) $query['viberAddressId'] = $params['viberAddressId'];
        if ($params['status']) $query['status'] = $params['status'];

        $query['offset'] = $params['offset'] ?? 0;
        $query['limit'] = $params['limit'] ?? 1000;

        $query = http_build_query($query);
        $queryString = null;

        if ($type === 'sms') {
            $queryString = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-templates?' . $query;
        } elseif ($type === 'viber') {
            $queryString = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/viber-templates?' . $query;
        }
        return $this->executeQueryDOWithoutLogged($queryString) ?? null;
    }

    public function updateTemplateBoPlusWithoutLogged(string $type, string $templateId, array $fields) {
        global $USER;

        if ($type == 'sms') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-templates/' . (int)$templateId;
        } elseif ($type == 'viber') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/viber-templates/' . (int)$templateId;
        }

        if ($url) {
            return $this->executeOtherDOWithoutLogged('PATCH', $url,
                array(
                    "X-Manager-Account: {$USER->GetLogin()}",
                    "Content-Type: application/json"
                ),
                Json::encode($fields)
            );
        }
    }

    public function addFilesToBoPlusByAddressIds(string $type, array $sourceAddressId, $fileName, $filePath) {
        if ($sourceAddressId) {
            $url = null;

            if ($type === 'sms') {
                $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-addresses/attachments';
            } elseif ($type === 'viber') {
                $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/viber-addresses/attachments';
            }

            if ($url) {
                $strUrl = null;

                foreach ($sourceAddressId as $address) {
                    $strUrl .= ((int)$address > 0) ? '&smsAddressIds=' . (int)$address : '';
                }

                $strUrl[0] = '?';
                $url = $url . $strUrl;

                return $this->executeOtherDOWithoutLogged('POST', $url,
                    array("Content-Type: multipart/form-data",),
                    array('file' => new CURLFile($filePath, mime_content_type($filePath), $fileName))
                );
            }
        }

        return null;
    }

    public function addFilesToBoPlusByAddressId(string $type, string $sourceAddressId, $fileName, $filePath) {
        $url = null;

        if ($type === 'sms') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-addresses/' . (int)$sourceAddressId . '/attachments';
        } elseif ($type === 'viber') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/viber-addresses/' . (int)$sourceAddressId . '/attachments';
        }

        if ($url) {
            return $this->executeOtherDOWithoutLogged('POST', $url,
                array("Content-Type: multipart/form-data"),
                array("file" => new CURLFile($filePath, mime_content_type($filePath), $fileName))
            );
        }

        return null;
    }


    public function getCountries() {
        return $this->executeOtherDOWithoutLogged('GET', $this->getConstant()['API_URLS']['CRB'] . '/countries');
    }

    public function getCustomersListId($paramFilter = []) {
        $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/customers';

        if ($paramFilter) {
            $url = $url . '?' . http_build_query($paramFilter);
        }

        return $this->executeOtherDOWithoutLogged('GET', $url);
    }

    public function getEndAggregatorList(string $customerId) {
        $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/customers/' . $customerId . '/end-customers';

        return $this->executeOtherDOWithoutLogged('GET', $url);
    }

    public function getCustomerById(string $customerId) {
        return $this->executeOtherDOWithoutLogged('GET', $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/customers/' . $customerId);
    }

    public function addTemplateBoPlus(string $type, array $fields, $managerLogin) {
        if ($type == 'sms') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-templates';
        } elseif ($type == 'viber') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/viber-templates';
        }

        if ($url) {
            return $this->executeOtherDOWithoutLogged('POST', $url,
                array(
                    "Content-Type: application/json",
                    "X-Manager-Account: {$managerLogin}",
                ),
                Json::encode($fields)
            );
        }

        return [];
    }

    public function addViberAddressByCustomerId(string $customerId, string $managerLogin, array $fields) {
        $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/' . (int)$customerId . '/viber-addresses';

        return $this->executeOtherDOWithoutLogged('POST', $url,
            array(
                "Content-Type: application/json",
                "X-Manager-Account: {$managerLogin}",
            ),
            Json::encode($fields)
        );
    }

    public function getFilesAOBoPlus(string $type, string $sourceAddress) {
        $url = null;

        if ($type === 'sms') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-addresses/' . $sourceAddress . '/attachments';
        } elseif ($type === 'viber') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/viber-addresses/' . $sourceAddress . '/attachments';
        }

        return $this->executeOtherDOWithoutLogged('GET', $url);
    }

    public function getSourceAddressByBoPlus($type, array $params) {
        $query = [];

        if ($params['inn']) $query['inn'] = $params['inn'];
        if ($params['customerId']) $query['customerId'] = $params['customerId'];

        $query['offset'] = $params['offset'] ?? 0;
        $query['limit'] = $params['limit'] ?? 50;


        $query = http_build_query($query);
        $queryString = null;

        if ($type === 'sms') {
            $queryString = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-addresses?' . $query;
        } elseif ($type === 'viber') {
            $queryString = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/viber-addresses?' . $query;
        }

        if ($queryString)
            return $this->executeQueryDOWithoutLogged($queryString);

        return null;
    }

    public function addCustomerToBoPlus(string $customerId, array $fields) {
        global $USER;
        $url = $this->getConstant()['API_URLS']['BO_PLUS'] . "/internal/customers/{$customerId}/end-customers";

        if ($url) {
            return $this->executeOtherDOWithoutLogged('POST', $url,
                array(
                    "X-Manager-Account: {$USER->GetLogin()}",
                    "Content-Type: application/json"
                ),
                Json::encode($fields)
            );
        }

        return [];
    }

    public function deleteFileFromAo(string $type, string $fileId, string $addressId) {
        $url = null;

        if ($type === 'sms') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . "/internal/sms-addresses/{$addressId}/attachments/{$fileId}";
        } elseif ($type === 'viber') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . "/internal/viber-addresses/{$addressId}/attachments/{$fileId}";
        }

        if ($url) {
            return $this->executeOtherDOWithoutLogged('DELETE', $url);
        }

        return [];
    }

    public function updateSourceAddressBoPlus(string $type, string $addressId, string $manager, array $fields): array {
        $url = null;

        if ($type === 'sms') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . "/internal/sms-addresses/{$addressId}";
        } elseif ($type === 'viber') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . "/internal/viber-addresses/{$addressId}";
        }

        if ($url) {
            return $this->executeOtherDOWithoutLogged('PATCH', $url,
                array(
                    "X-Manager-Account: {$manager}",
                    "Content-Type: application/json"
                ),
                Json::encode($fields)
            );
        }

        return [];
    }

    public function updateCompanyDO(array $fields, string $companyId) {
        if ((int)$companyId > 0) {
            $url = $this->getConstant()['API_URLS']['DO'] . '/internal/companies/' . $companyId;

            if ($url) {
                return $this->executeOtherDOWithoutLogged('PUT', $url,
                    array("Content-Type: application/json"),
                    Json::encode($fields)
                );
            }
        }

        return [];
    }

    public function getCompaniesListDO(string $limit, string $offset) {
        $url = $this->getConstant()['API_URLS']['DO'] . '/internal/companies?limit=' . $limit . '&' . 'offset=' . $offset;

        if ($url) {
            return $this->executeOtherDOWithoutLogged('GET', $url);
        }

        return [];
    }

    public function getSourceAddressById(string $type, string $addressId) {
        $url = null;

        if ($type === 'sms') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . "/internal/sms-addresses/{$addressId}";
        } elseif ($type === 'viber') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . "/internal/viber-addresses/{$addressId}";
        }

        if ($url) {
            return $this->executeOtherDOWithoutLogged('GET', $url);
        }

        return [];
    }

    /**
     * @param string $type
     * @param string $filePath
     * @param string $fileName
     * @return array
     */
    public function addTemplateFromFile(string $type, string $filePath, string $fileName): array {
        global $USER;

        if ($type == 'sms') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-templates/import?hasHeader=true';
        } elseif ($type == 'viber') {
            $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/viber-templates/import?hasHeader=true';
        }

        if ($url) {
            return $this->executeOtherDOWithoutLogged('POST', $url,
                array(
                    "X-Manager-Account: {$USER->GetLogin()}",
                    "Content-Type: multipart/form-data"
                ),
                array('file' => new CURLFile($filePath, mime_content_type($filePath), $fileName))
            );
        }

        return [];
    }

    public function addAOFromFile(string $filepath, string $fileName): array {
        global $USER;
        $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/sms-addresses/import?hasHeader=true';

        return $this->executeOtherDOWithoutLogged('POST', $url,
            array(
                "X-Manager-Account: {$USER->GetLogin()}",
                "Content-Type: multipart/form-data"
            ),
            array('file' => new CURLFile($filepath, mime_content_type($filepath), $fileName))
        );
    }

    /**
     * @param string $filePath
     * @param $fileName
     * @param int $aggregatorCustomerId
     * @return array
     * @throws ArgumentException
     */
    public function addCustomersFromFile(string $filePath, $fileName, int $aggregatorCustomerId): array {
        global $USER;

        if ($aggregatorCustomerId <= 0) throw new ArgumentException("Ошибка в значении customerId Агрегатора");

        $url = $this->getConstant()['API_URLS']['BO_PLUS'] . '/internal/customers/' . $aggregatorCustomerId . '/end-customers/import?hasHeader=true';

        return $this->executeOtherDOWithoutLogged('POST', $url,
            array(
                "X-Manager-Account:{$USER->GetLogin()}",
                "Content-Type: multipart/form-data"
            ),
            array('file' => new CURLFile($filePath, mime_content_type($filePath), $fileName))
        );
    }

    /**
     * END BO++
     */
}