<?php
/**
 * Класс для импорта данных из сторонней базы с помощью класса DwhConnected
 * !!!ТРЕБОВАНИЕ.
 *  1) Наличие orm класса у HL сущности и метода подключение к сторонней базе DwhConnected
 *  2) Наименование полей в HL и сторонней базе должно различаться только в наличие UF_*
 */


use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserFieldTable;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/DwhConnected.php';

/**
 * Class importToHB
 */
class importToHB {

    private $hbData;
    private $hlFields;
    private $pdoObj;

    const IS_ACCESS_TRUNCATE = array('DealsReport', 'TriggersReport');

    /**
     * importToHB constructor.
     * @param string $hbName
     * @param PDO $pdoObj
     * @throws ArgumentException
     * @throws LoaderException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws Exception
     */
    public function __construct(string $hbName, PDO $pdoObj) {
        Loader::includeModule('highloadblock');

        $hbData = HighloadBlockTable::getList(array('filter' => array('=NAME' => $hbName)))->fetch();
        if (!$hbData) throw new Exception("NO DATA HL");

        $this->setHbData($hbData);
        $this->setPdoObj($pdoObj);

        //получение всех полей hl блока
        $fieldsHl = UserFieldTable::getList(array(
            'select' => array('FIELD_NAME', 'XML_ID', 'USER_TYPE_ID', 'USER_TYPE_ID', 'MULTIPLE'),
            'filter' => array('=ENTITY_ID' => 'HLBLOCK_' . $this->getHbData()['ID'])
        ))->fetchAll();

        $this->setHlFields($fieldsHl);
    }


    /**
     * @param string $query
     * @param bool $isTruncatedHL
     * @param array $callbackForField
     * @throws ArgumentException
     */
    public function executeImport(string $query, bool $isTruncatedHL = false, array $callbackForField = []) {
        /**
         * @var DataManager $ormObj
         */

        $dataFromTable = $this->getPdoObj()->query($query);

        if (!$dataFromTable) return;
        $dataFromTable = $dataFromTable->fetchAll();


        $isCheckedFields = [];
        $newData = [];

        //класс orm сущности hl блока
        $hlClass = $this->getHbData()['NAME'] . 'Table';

        //создаем класс сущности
        $ormObj = new $hlClass();


        $i = 0;
        $maxDate = '';
        $minDate = '';

        foreach ($dataFromTable as $rowEl) {
            foreach ($rowEl as $fieldKey => $fieldEl) {

                if ($fieldKey == 'Period') {
                    $fieldKey = 'StatusDate';

                    if (!$maxDate) {
                        $maxDate = date('Y-m-d', strtotime($fieldEl));
                    }

                    if (!$minDate) {
                        $minDate = date('Y-m-d', strtotime($fieldEl));
                    }

                    if (strtotime($fieldEl) < strtotime($minDate)) {
                        $minDate = date('Y-m-d', strtotime($fieldEl));
                    }

                    if (strtotime($fieldEl) > strtotime($maxDate)) {
                        $maxDate = date('Y-m-d', strtotime($fieldEl));
                    }
                } elseif ($fieldKey == 'StatusDate') {
                    continue;
                }

                if (!isset($isCheckedFields[$fieldKey])) {
                    $isCheckedFields[$fieldKey] = array(
                        'IS_CHECKED' => $this->checkValidFieldKey($fieldKey),
                        'BX_CODE' => "UF_" . mb_strtoupper($fieldKey)
                    );
                }

                if (isset($isCheckedFields[$fieldKey]) && $isCheckedFields[$fieldKey]['IS_CHECKED'] == true) {
                    if (isset($callbackForField[$isCheckedFields[$fieldKey]['BX_CODE']])) {
                        $newData[$i][$isCheckedFields[$fieldKey]['BX_CODE']] = $callbackForField[$isCheckedFields[$fieldKey]['BX_CODE']]($fieldEl);
                    } else {
                        $newData[$i][$isCheckedFields[$fieldKey]['BX_CODE']] = $fieldEl;
                    }
                }
            }

            $i++;
        }

        if ($newData) {

            //если сущность входит в разрешенные по truncate, то очищаем вначале
            if ($isTruncatedHL === true && in_array($this->getHbData()['NAME'], self::IS_ACCESS_TRUNCATE)) {
                $ormObj::deleteCurrentMonth($minDate, $maxDate);
            }

            foreach ($newData as $newDatum) {

                //TODO::костыль временный для денег представительств
                if ($newDatum['UF_MANAGERBRANCH'] != 'Devino Russia') {
                    $newDatum['UF_PROFIT'] = 0;
                    $newDatum['UF_PROFIT_FC'] = 0;
                    $newDatum['UF_PROFIT_AVG3'] = 0;
                    $newDatum['UF_SELL_AMOUNT'] = 0;
                    $newDatum['UF_SELL_AMOUNT_FC'] = 0;
                }

                if (!$ormObj::add($newDatum)) {
                    throw new ArgumentException("Ошибка добавления строки в таблицу {$hlClass}");
                }
            }
        }
    }

    public function checkValidFieldKey(string $key): bool {
        foreach ($this->getHlFields() as $hlField) {
            if ("UF_" . mb_strtoupper($key) == $hlField['FIELD_NAME']) return true;
        }

        return false;
    }

    public function getHbData(): array {
        return $this->hbData;
    }

    public function setHbData(array $hbData): void {
        $this->hbData = $hbData;
    }

    public function getPdoObj(): PDO {
        return $this->pdoObj;
    }

    public function setPdoObj(PDO $pdoObj): void {
        $this->pdoObj = $pdoObj;
    }

    public function getHlFields(): array {
        return $this->hlFields;
    }

    public function setHlFields(array $hlFields): void {
        $this->hlFields = $hlFields;
    }
}