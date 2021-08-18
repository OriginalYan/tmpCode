<?php

use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/classes/importToHB.php';

function updateReports(): string {
    $callbackFunctions = array(
        'UF_STATUSDATE' => function ($val) {
            if (!$val) return "";
            try {
                return new Date(date('d.m.Y', strtotime($val)));
            } catch (ObjectException $oe) {

                return "";
            }
        },
        'UF_CUSTLOGINBRANCH' => function($val){
            $tmpVal = explode(';', $val);
            $tmpVal[2] = str_replace('Devino ', '', $tmpVal[2]);
            return implode('_', $tmpVal);
        }
    );

    try {
        $importDealsReports = new importToHB('DealsReport', DwhConnected::connectToDwh());
        $importDealsReports->executeImport('set nocount on; exec DevinoDWH.bitrix.DealsReport;', true, array_merge(
            $callbackFunctions,
            array('UF_STATUS' => function ($val) {
                if ($val == 'HIGH') return 'HIGHT';
                return $val;
            })
        ));


        $importTriggersReports = new importToHB('TriggersReport', DwhConnected::connectToDwh());
        $importTriggersReports->executeImport('set nocount on; exec DevinoDWH.bitrix.TriggersReport;', true, array_merge(
            $callbackFunctions,
            array(
                'UF_PROFIT_AVG3_STATUS' => function ($val) {
                    if ($val == 'HIGH') return 'HIGHT';
                    return $val;
                },
                'UF_QTY_AVG3_STATUS' => function ($val) {
                    if ($val == 'HIGH') return 'HIGHT';
                    return $val;
                },
                'UF_SELLAMOUNT_AVG3_STATUS' => function ($val) {
                    if ($val == 'HIGH') return 'HIGHT';
                    return $val;
                }
            )
        ));

    } catch (ArgumentException | SystemException | Exception | TypeError | LoaderException $e) {
        writeToLog("/logs/agents/reports/", $e->getMessage());
    }

    return "updateReports();";
}