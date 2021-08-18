<?php

use Bitrix\Crm\Attribute\Entity\FieldAttributeTable;
use Bitrix\Crm\WebForm\Internals\FieldDependenceTable;
use Bitrix\Crm\WebForm\Internals\FieldTable;
use Bitrix\Crm\WebForm\Internals\PresetFieldTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\OptionTable;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserFieldTable;
use Devino\Crmrules\RulesgroupsdetailTable;

//TODO::посмотреть какие типы файлов бывают и проверить на всех

class RenameCrmField {

    private array $excludeDirectories;
    private array $excludeFiles;
    private string $newField;
    private string $oldField;
    private string $entity;
    private int $fieldId;

    //доступные сущности для изменения
    const ACCESS_ENTITY = array(
        'CRM_COMPANY',
        'CRM_DEAL',
        'CRM_CONTACT',
        'CRM_LEAD',
        'CRM_REQUISITE'
    );

    const ACCESS_ENTITY_TYPE = array(
        'CRM_DEAL' => CCrmOwnerType::DealName,
        'CRM_COMPANY' => CCrmOwnerType::CompanyName,
        'CRM_LEAD' => CCrmOwnerType::LeadName,
        'CRM_CONTACT' => CCrmOwnerType::ContactName,
        'CRM_REQUISITE' => CCrmOwnerType::RequisiteName
    );

    const DOP_TABLES = array(
        'CRM_COMPANY' => 'b_uts_crm_company',
        'CRM_DEAL' => 'b_uts_crm_deal',
        'CRM_CONTACT' => 'b_uts_crm_contact',
        'CRM_LEAD' => 'b_uts_crm_lead',
        'CRM_REQUISITE' => 'b_uts_crm_requisite'
    );

    const TYPES_DB = array(
        'int' => 'int',
        'string' => 'text',
        'real' => 'double',
        'date' => 'date',
        'datetime' => 'datetime'
    );

    const ACCESS_ENTITY_ID = array(
        CCrmOwnerType::LeadName => CCrmOwnerType::Lead,
        CCrmOwnerType::DealName => CCrmOwnerType::Deal,
        CCrmOwnerType::CompanyName => CCrmOwnerType::Company,
        CCrmOwnerType::ContactName => CCrmOwnerType::Contact
    );

    public function __construct(
        string $newField,
        string $oldField,
        string $entity
    ) {
        try {
            if (!in_array($entity, $this->getAccessEntity()))
                throw new ArgumentException("UNDEFINED ENTITY");

            //устанавливаем id сущности
            $this->setEntityId($entity);
            //устанавливаем id нового поля
            $this->setNewField($newField);
            //устанавливаем id старого поля
            $this->setOldField($oldField);

        } catch (TypeError | ArgumentException $ex) {
            ShowError($ex);
        }
    }

    public function executeRename(): bool {
        try {
            Loader::includeModule('devino.crmrules');

            global $DB;

            //проверяем существует ли такое поле
            $this->fieldIsExist();

            //проверяем существует ли поле, в которое оно переименовывается
            $this->fieldNewIsExist();

            try {
                $DB->StartTransaction();

                //ОБНОВЛЯЕМ ТАБЛИЦУ С ПОЛЬЗОВАТЕЛЬСКИМИ ПОЛЯМИ
                $DB->PrepareFields('b_user_field');
                $updateFieldTable = $DB->Update("b_user_field",
                    array(
                        'FIELD_NAME' => "'{$this->getNewField()}'",
                        'XML_ID' => "'{$this->getNewField()}'"
                    ),
                    "WHERE ID='{$this->getFieldId()}'");

                if (!$updateFieldTable) throw new ArgumentException;

                //ОБНОВЛЯЕМ ТАБЛИЦУ С ФИЛЬТРОМ
                $filterFieldInList = $this->getExistFieldInFilter();
                if ($filterFieldInList) {
                    $DB->PrepareFields('b_user_option');

                    foreach ($filterFieldInList as $idRow => $filterValues) {

                        $filter = unserialize($filterValues);

                        foreach ($filter['filters'] as $keyFilter => &$rowFilter) {
                            if (isset($rowFilter['fields']) && isset($rowFilter['fields'][$this->getOldField()])) {
                                $oldTmpValue = $rowFilter['fields'][$this->getOldField()];

                                unset($filter['filters'][$keyFilter]['fields'][$this->getOldField()]);

                                $filter['filters'][$keyFilter]['fields'][$this->getNewField()] = $oldTmpValue;
                            }

                            if ($rowFilter['filter_rows']) {
                                self::replaceContent($rowFilter['filter_rows'], $this->getNewField(), $this->getOldField());
                            }
                        }

                        $updateUserOptTable = $DB->Update("b_user_option", array('VALUE' => "'" . serialize($filter) . "'"), "WHERE ID='" . $idRow . "'");
                        if (!$updateUserOptTable) throw new ArgumentException;
                    }
                }

                //ОБНОВЛЯЕМ ТАБЛИЦУ С UTS ПОЛЯМИ, ЕСЛИ ТАКАЯ СУЩЕСТВУЕТ
                $dopTables = $this->getDopTables();

                if (isset($dopTables[$this->getEntityId()])) {
                    $DB->PrepareFields($dopTables[$this->getEntityId()]);

                    $utsTable = $dopTables[$this->getEntityId()];
                    $fieldList = $DB->GetTableFields($utsTable);

                    $typeField = $this->getTypesDb()[$fieldList[$this->getOldField()]['TYPE']];

                    //после того, как мы определелили тип поля обновляем его название
                    if (!$typeField) throw new ArgumentException;

                    $isUpdateColumnName = $DB->Query("ALTER TABLE " . $utsTable . " CHANGE " . $this->getOldField() . " " . $this->getNewField() . " " . $typeField);
                    if (!$isUpdateColumnName) throw new ArgumentException;
                }

                //ОБНОВЛЯЕМ ТАБЛИЦУ b_crm_field_attr
                if ($crFieldAttr = $this->getCrmFieldAttr()) {
                    $DB->PrepareFields('b_crm_field_attr');

                    $updatedAr = [];

                    foreach ($crFieldAttr as $row) {
                        $updatedAr['FIELD_NAME'] = '"' . $this->getNewField() . '"';

                        $isUpdatedFieldAttr = $DB->Update('b_crm_field_attr', $updatedAr, "WHERE ID = '{$row['ID']}'");
                        if (!$isUpdatedFieldAttr) throw new ArgumentException;
                    }
                }

                //ОБНОВЛЯЕМ ТАБЛИЦУ d_rules_groups_detail
                if ($rulesGroup = $this->getRulesGroupField()) {
                    $DB->PrepareFields('d_rules_groups_detail');

                    foreach ($rulesGroup as $row) {
                        $updatedAr['UF_CODE'] = '"' . $this->getNewField() . '"';

                        if (strripos($row['UF_FILTER'], $this->getOldField()) !== false) {
                            $updatedAr['UF_FILTER'] = "'" . str_replace($this->getOldField(), $this->getNewField(), $row['UF_FILTER']) . "'";
                        }

                        $isUpdatedRulesGroup = $DB->Update('d_rules_groups_detail', $updatedAr, "WHERE ID = '{$row['ID']}'");
                        if (!$isUpdatedRulesGroup) throw new ArgumentException;
                    }
                }

                $DB->Commit();

                return true;

            } catch (ArgumentException $ex) {
                $DB->Rollback();
                throw new ErrorException("ERROR QUERY, ROLLBACK RENAME");
            }

        } catch (ObjectPropertyException | LoaderException | ArgumentException | SystemException | ErrorException | TypeError  $e) {
            ShowError($e->getMessage());
        }

        return false;
    }


    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getCrmFieldAttr(): array {
        $entityId = $this->getAccessEntityId()[$this->getAccessEntityType()[$this->getEntityId()]];

        if (!$entityId)
            throw new ArgumentException("UNDEFINED ENTITY");

        return FieldAttributeTable::getList(array(
            'select' => array('ID'),
            'filter' => array(
                '=FIELD_NAME' => $this->getOldField(),
                '=ENTITY_TYPE_ID' => $entityId
            )
        ))->fetchAll();
    }


    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getRulesGroupField(): array {
        return RulesgroupsdetailTable::getList(array(
            'select' => array('ID', 'UF_FILTER'),
            'filter' => array(
                '=ENTITY' => $this->getEntityId(),
                array(
                    'LOGIC' => 'OR',
                    '=UF_CODE' => $this->getOldField(),
                    '%UF_FILTER' => $this->getOldField()
                )
            )
        ))->fetchAll();
    }


    /**
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getWebFormFieldForUpdate(): array {

        $result = [];

        if ($this->getEntityId() == 'CRM_REQUISITE')
            throw new ArgumentException("UNDEFINED ENTITY FOR WEBFORM");

        $entityName = $this->getAccessEntityType()[$this->getEntityId()];

        $result['FIELD_LIST'] = FieldTable::getList(array(
            'select' => array('ID', 'CODE', 'FORM_ID'),
            'filter' => array(
                "=CODE" => "{$entityName}_{$this->getOldField()}"
            )
        ))->fetchAll();

        $result['FIELD_LIST_PRESET'] = PresetFieldTable::getList(array(
            'select' => array('FIELD_NAME', 'FORM_ID', 'ENTITY_NAME'),
            'filter' => array(
                '=ENTITY_NAME' => $entityName,
                '=FIELD_NAME' => $this->getOldField()
            )
        ))->fetchAll();


        $result['FIELD_LIST_DEP'] = FieldDependenceTable::getList(array(
            'select' => array('ID', 'IF_FIELD_CODE', 'DO_FIELD_CODE'),
            'filter' => array(
                array(
                    'LOGIC' => 'OR',
                    '%IF_FIELD_CODE' => $this->getOldField(),
                    '%DO_FIELD_CODE' => $this->getOldField()
                )
            )
        ))->fetchAll();

        return $result;
    }


    public function editFieldWebFormCrm() {
        global $DB;

        $updatedForm = [];

        try {
            $getField = $this->getWebFormFieldForUpdate();

            try {
                if ($getField['FIELD_LIST'] || $getField['FIELD_LIST_PRESET']) {
                    $DB->StartTransaction();

                    if ($getField['FIELD_LIST']) {

                        //обновляем поля таблицы b_crm_webform_field
                        $DB->PrepareFields('b_crm_webform_field');

                        foreach ($getField['FIELD_LIST'] as $field) {
                            $updateAr = [];

                            $updateAr['CODE'] = "'" . str_replace($this->getOldField(), $this->getNewField(), $field['CODE']) . "'";

                            $isUpdate = $DB->Update('b_crm_webform_field', $updateAr, "WHERE ID = '{$field['ID']}'");
                            if (!$isUpdate) throw new ArgumentException;

                            $updatedForm[] = "UPDATE TABLE b_crm_webform_field FORM FORM ID = {$field['FORM_ID']}";
                        }
                    }

                    if ($getField['FIELD_LIST_PRESET']) {

                        //обновляем поля таблицы b_crm_webform_field_preset
                        $DB->PrepareFields('b_crm_webform_field_preset');

                        foreach ($getField['FIELD_LIST_PRESET'] as $field) {
                            $updateAr = [];

                            $updateAr['FIELD_NAME'] = "'" . $this->getNewField() . "'";

                            $isUpdate = $DB->Update('b_crm_webform_field_preset', $updateAr, "WHERE FORM_ID = '{$field['FORM_ID']}'");
                            if (!$isUpdate) throw new ArgumentException;

                            $updatedForm[] = "UPDATE TABLE b_crm_webform_field_preset FOR FORM ID = {$field['FORM_ID']}";
                        }
                    }

                    if ($getField['FIELD_LIST_DEP']) {
                        //обновляем поля таблицы b_crm_webform_field_dep
                        $DB->PrepareFields('b_crm_webform_field_dep');

                        foreach ($getField['FIELD_LIST_DEP'] as $field) {
                            $updateAr = [];

                            if (strripos($field['IF_FIELD_CODE'], $this->getOldField()) !== false) {
                                $updateAr['IF_FIELD_CODE'] = "'" . str_replace($this->getOldField(), $this->getNewField(), $field['IF_FIELD_CODE']) . "'";
                            }

                            if (strripos($field['DO_FIELD_CODE'], $this->getOldField()) !== false) {
                                $updateAr['DO_FIELD_CODE'] = "'" . str_replace($this->getOldField(), $this->getNewField(), $field['DO_FIELD_CODE']) . "'";
                            }

                            $isUpdate = $DB->Update('b_crm_webform_field_dep', $updateAr, "WHERE ID = '{$field['ID']}'");
                            if (!$isUpdate) throw new ArgumentException;

                            $updatedForm[] = "UPDATE TABLE b_crm_webform_field_dep ID = {$field['ID']}";
                        }
                    }

                    $DB->Commit();
                    return $updatedForm;
                }

            } catch (ArgumentException $e) {
                $DB->Rollback();
                throw new ArgumentException("ERROR QUERY, ROLLBACK EDIT FIELD");
            }

        } catch (ArgumentException | SystemException $ex) {
            ShowError($ex->getMessage());
        }

        return false;
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function fieldIsExist() {
        $fieldData = UserFieldTable::getList(array(
            'select' => array('ID'),
            'filter' => array(
                '=ENTITY_ID' => $this->getEntityId(),
                '=FIELD_NAME' => $this->getOldField()
            )
        ))->fetch();


        if (!$fieldData['ID']) {
            throw new ArgumentException("UNDEFINED FIELD {$this->getOldField()} FOR ENTITY {$this->getEntityId()}");
        }

        $this->setFieldId((int)$fieldData['ID']);
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function fieldNewIsExist() {
        $fieldData = UserFieldTable::getList(array(
            'select' => array('ID'),
            'filter' => array(
                '=ENTITY_ID' => $this->getEntityId(),
                '=FIELD_NAME' => $this->getNewField()
            )
        ))->fetch();


        if ($fieldData['ID']) {
            throw new ArgumentException("FIELD {$this->getNewField()} FOR ENTITY {$this->getEntityId()} ALREADY EXIST");
        }
    }

    public function getStructure($path, &$returned) {

        if (is_dir($path)) {
            $scanDirs = scandir($path);

            foreach ($scanDirs as $dir) {

                if (in_array($dir, array('.', '..'))) continue;

                if (is_dir($path . $dir . '/') &&
                    !in_array($path . $dir . '/', $this->getExcludeDirectories())
                ) {
                    $this->getStructure($path . $dir . '/', $returned);
                } elseif (is_file($path . $dir) &&
                    !in_array($path . $dir, $this->getExcludeFiles())
                ) {
                    $returned[] = $path . $dir;
                }
            }
        }
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getExistFieldInFilter(): array {
        return array_column(OptionTable::getList(array(
                'select' => array('VALUE', 'ID'),
                'filter' => array(
                    '=CATEGORY' => 'main.ui.filter',
                    '%VALUE' => $this->getOldField(),
                    '%NAME' => $this->getEntityId()
                )
            ))->fetchAll(), 'VALUE', 'ID') ?? [];
    }

    public static function replaceContent(&$contentStr, $newField, $oldField): void {
        if (mb_stripos($contentStr, $oldField) !== false) {
            $contentStr = str_replace($oldField, $newField, $contentStr);
        }
    }

    public function editFieldInFIle(string $startPath, string $bckpPathDir, array $excludeDirectories = array(), array $excludeFiles = array()) {

        //устанавливаем директории, которые не нужно учитываться при поиске
        $this->setExcludeDirectories($excludeDirectories);

        //устанавливаем файлы, которые не нужно учитываться при поиске
        $this->setExcludeFiles($excludeFiles);

        $structureList = [];
        $this->getStructure($startPath, $structureList);

        $errorsM = [];

        try {

            $sshConnection = $this->getSshConnection();
            if (!$sshConnection)
                throw new ArgumentException("ERROR CONNECT TO SSH FOR RENAME FILE");

            if (!is_dir($bckpPathDir))
                throw new ArgumentException("ERROR BCKP DIRECTORIES");

            if (!ssh2_sftp_chmod($sshConnection, $bckpPathDir, 0777))
                throw new ArgumentException("ERROR CHMOD BCKP DIR $bckpPathDir to 777");

            foreach ($structureList as $filePath) {
                try {
                    $newFileContent = file_get_contents($filePath);

                    if (!$newFileContent && (filesize($filePath) > 0))
                        throw new ArgumentException("ERROR READING FILE CONTENT $filePath");

                    $oldFileContent = $newFileContent;

                    self::replaceContent($newFileContent, $this->getNewField(), $this->getOldField());

                    if ($oldFileContent != $newFileContent) {
                        //делаем бекап файла
                        $filePathBckp = $_SERVER['DOCUMENT_ROOT'] . '/dev/updateFields/bckp/' . date('d_m_Y') . '|' . base64_encode($filePath) . '|' . basename($filePath);

                        if (file_exists($filePathBckp)) {
                            if (!ssh2_sftp_chmod($sshConnection, $filePathBckp, 0777))
                                throw new ArgumentException("ERROR CHMOD FILE to 777" . PHP_EOL . $filePathBckp);
                        }

                        //создаем новый бекап файл/изменяем текущий файл
                        if (!file_put_contents($filePathBckp, $oldFileContent))
                            throw new ArgumentException("ERROR ADD/EDIT BCKP FILE" . PHP_EOL . $filePathBckp);

                        //меняем в нем права
                        if (!ssh2_sftp_chmod($sshConnection, $filePathBckp, 0644))
                            throw new ArgumentException("ERROR CHMOD FILE TO 644" . PHP_EOL . $filePathBckp);


                        //записываем измененные поля в файле
                        if (!file_exists($filePath))
                            throw new ArgumentException("NOT EXISTS MAIN FILE " . PHP_EOL . $filePath);

                        //даем права на изменение в файле
                        if (!ssh2_sftp_chmod($sshConnection, $filePath, 0777))
                            throw new ArgumentException("ERROR CHMOD MAIN FILE to 777" . PHP_EOL . $filePath);

                        //меняем содержимое
                        if (!file_put_contents($filePath, $newFileContent))
                            throw new ArgumentException("ERROR UPDATE MAIN FILE" . PHP_EOL . $filePath);

                        //возвращаем обратно права
                        if (!ssh2_sftp_chmod($sshConnection, $filePath, 0644))
                            throw new ArgumentException("ERROR CHMOD MAIN FILE to 644" . PHP_EOL . $filePath);
                    }
                } catch (ArgumentException $ex) {
                    $errorsM[] = $ex->getMessage();
                }
            }

            if (!ssh2_sftp_chmod($sshConnection, $bckpPathDir, 0755))
                throw new ArgumentException("ERROR CHMOD BCKP DIR to 755" . PHP_EOL . $bckpPathDir);

            //удаляем директорию cache и managed_cache, если такие существуют
            if (is_dir($_SERVER['DOCUMENT_ROOT'] . '/bitrix/cache'))
                if (!$this->removeDirectory($_SERVER['DOCUMENT_ROOT'] . '/bitrix/cache', $sshConnection))
                    throw new ArgumentException("ERROR DELETE CACHE DIRECTORIES");

            if (is_dir($_SERVER['DOCUMENT_ROOT'] . '/bitrix/managed_cache'))
                if (!$this->removeDirectory($_SERVER['DOCUMENT_ROOT'] . '/bitrix/managed_cache', $sshConnection))
                    throw new ArgumentException("ERROR DELETE CACHE DIRECTORIES");

        } catch (ArgumentException $e) {
            $errorsM[] = $e->getMessage();
        }

        if ($errorsM) return $errorsM;

        return true;
    }

    public function editBpWorkflow(): array {
        $errorsM = [];
        $idTemplatesUpdate = [];

        try {

            if ($this->getEntityId() == 'CRM_REQUISITE')
                throw new ArgumentException("UNDEFINED ENTITY FOR BP");

            $entityBp = $this->getAccessEntityType()[$this->getEntityId()];

            $templateList = [];

            $entityName = ucfirst(strtolower($entityBp));
            $templateObj = CBPWorkflowTemplateLoader::GetList(
                array(
                    'DOCUMENT_TYPE' => array('crm', "CCrmDocument{$entityName}", $entityBp),
                    'TEMPLATE' => "%{$this->getOldField()}%"
                )
            );

            while ($row = $templateObj->fetch()) {
                $templateList[$row['ID']] = $row;
            }


            foreach ($templateList as &$template) {

                $isUpdatedTemplate = false;

                $this->setTemplateUpdateFieldBp($template['TEMPLATE'], $isUpdatedTemplate);

                if ($isUpdatedTemplate === true) {
                    $idTemplatesUpdate[] = CBPWorkflowTemplateLoader::Update($template['ID'], array('TEMPLATE' => $template['TEMPLATE']), true);
                }
            }

        } catch (ArgumentException $ex) {
            $errorsM[] = $ex->getMessage();
        }

        if ($errorsM) return $errorsM;

        return $idTemplatesUpdate;
    }


    private function setTemplateUpdateFieldBp(&$templates, &$isUpdatedTemplate) {
        foreach ($templates as &$template) {

            if (isset($template['Children'])) {
                $this->setTemplateUpdateFieldBp($template['Children'], $isUpdatedTemplate);
            }

            if ($template['Properties']['EntityFields']) {
                foreach ($template['Properties']['EntityFields'] as $keyField => $propertyValue) {
                    if ($keyField === $this->getOldField()) {
                        $template['Properties']['EntityFields'][$this->getNewField()] = $propertyValue;
                        unset($template['Properties']['EntityFields'][$this->getOldField()]);

                        $isUpdatedTemplate = true;
                    }
                }
            }

            if ($template['Type'] == 'SocNetMessageActivity' && $template['Name'] == 'A28408_86101_91944_9210') {
                $template['MessageUserFrom'][0] = '[810]';
                $template['MessageUserTo'][0] = '[810]';
            }

            if ($template['Properties']['FieldValue']) {
                foreach ($template['Properties']['FieldValue'] as $keyField => $propertyValue) {
                    if ($keyField === $this->getOldField()) {
                        $template['Properties']['FieldValue'][$this->getNewField()] = $propertyValue;
                        unset($template['Properties']['FieldValue'][$this->getOldField()]);

                        $isUpdatedTemplate = true;
                    }
                }
            }

            if ($template['Properties']['fieldcondition']) {
                foreach ($template['Properties']['fieldcondition'] as &$condition) {
                    foreach ($condition as $keyCondition => $valueCondition) {
                        if ($this->getOldField() === $valueCondition) {
                            $condition[$keyCondition] = $this->getNewField();

                            $isUpdatedTemplate = true;
                        }
                    }
                }
            }

            if ($template['Properties']['VariableValue']) {
                foreach ($template['Properties']['VariableValue'] as $keyField => $propertyValue) {
                    if (strripos($propertyValue, "{=Document:{$this->getOldField()}}") !== false) {
                        $template['Properties']['VariableValue'][$keyField] = str_replace($this->getOldField(), $this->getNewField(), $propertyValue);

                        $isUpdatedTemplate = true;
                    }
                }
            }
        }
    }

    public function removeDirectory(string $path, &$ssh): bool {

        $files = glob($path . '/*');
        foreach ($files as $file) {
            if (is_dir($file))
                $this->removeDirectory($file, $ssh);
            elseif (is_file($file)) {
                if (!unlink($file)) return false;
            }
        }

        if (!ssh2_sftp_rmdir($ssh, $path)) return false;

        return true;
    }

    public function getSshConnection() {
        $connection = ssh2_connect(SFT_DOMAIN, 22);
        ssh2_auth_password($connection, SFT_USER_NAME, SFT_USER_PASS);
        return ssh2_sftp($connection);
    }

    /**
     * @return mixed
     */
    public function getExcludeDirectories(): array {
        return $this->excludeDirectories;
    }

    /**
     * @param array $excludeDirectories
     */
    private function setExcludeDirectories(array $excludeDirectories): void {
        $this->excludeDirectories = $excludeDirectories;
    }

    /**
     * @return array
     */
    public function getExcludeFiles(): array {
        return $this->excludeFiles;
    }

    /**
     * @param array $excludeFiles
     */
    private function setExcludeFiles(array $excludeFiles): void {
        $this->excludeFiles = $excludeFiles;
    }

    /**
     * @return string
     */
    public function getNewField(): string {
        return $this->newField;
    }

    /**
     * @param string $newField
     * @throws ArgumentException
     */
    private function setNewField(string $newField): void {
        if (!$newField) throw new ArgumentException("UNDEFINED CODE new FIELD");
        $this->newField = $newField;
    }

    /**
     * @return string
     */
    public function getOldField(): string {
        return $this->oldField;
    }

    /**
     * @param string $oldField
     * @throws ArgumentException
     */
    private function setOldField(string $oldField): void {
        if (!$oldField) throw new ArgumentException("UNDEFINED CODE CURRENT FIELD");
        $this->oldField = $oldField;
    }

    /**
     * @return string
     */
    public function getEntityId(): string {
        return $this->entity;
    }

    /**
     * @param string $entity
     * @throws ArgumentException
     */
    private function setEntityId(string $entity): void {
        if (!$entity) throw new ArgumentException("UNDEFINED TYPE ENTITY");
        $this->entity = $entity;
    }

    /**
     * @return int
     */
    public function getFieldId(): int {
        return $this->fieldId;
    }

    /**
     * @param int $fieldId
     */
    private function setFieldId(int $fieldId): void {
        $this->fieldId = $fieldId;
    }

    public function getAccessEntity(): array {
        return self::ACCESS_ENTITY;
    }

    public function getDopTables(): array {
        return self::DOP_TABLES;
    }

    public function getTypesDb(): array {
        return self::TYPES_DB;
    }

    public function getAccessEntityType(): array {
        return self::ACCESS_ENTITY_TYPE;
    }

    public function getAccessEntityId(): array {
        return self::ACCESS_ENTITY_ID;
    }

}