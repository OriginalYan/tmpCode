<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

require_once __DIR__ . '/RenameCrmField.php';

$rc = new RenameCrmField('UF_TEST_QWE', 'UF_TEST_QWERTY', 'CRM_COMPANY');
$isUpdatedDB = $rc->executeRename();

if ($isUpdatedDB) {
    $isUpdatedFiles = $rc->editFieldInFIle(
        $_SERVER['DOCUMENT_ROOT'] . '/',
        $_SERVER['DOCUMENT_ROOT'] . '/dev/updateFields/bckp/',
        array(
            $_SERVER['DOCUMENT_ROOT'] . '/local/vendor/',
            $_SERVER['DOCUMENT_ROOT'] . '/bitrix/',
            $_SERVER['DOCUMENT_ROOT'] . '/upload/',
            $_SERVER['DOCUMENT_ROOT'] . '/images/',
            $_SERVER['DOCUMENT_ROOT'] . '/dev/updateFields/'
        )
    );

    $isUpdatedBp = $rc->editBpWorkflow();
    $isUpdatedForms = $rc->editFieldWebFormCrm();
}



